<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\HtNetworkUpdate;
use App\Models\Transformer;
use App\Models\TransformerKmzImport;
use App\Models\FieldSurvey;
use App\Models\Mdb\NetworkTransformer;
use App\Models\User;
use App\Support\FieldTransformerIdentity;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

class TransformerKmzImportService
{
    private const MAX_ARCHIVE_ENTRIES = 250;

    private const MAX_UNCOMPRESSED_BYTES = 50_000_000;

    private const MAX_KML_BYTES = 20_000_000;

    private const COORDINATE_TOLERANCE = 0.001;

    public function import(TransformerKmzImport $import, string $absolutePath): TransformerKmzImport
    {
        $this->assertReadableFile($absolutePath);
        $import->update([
            'status' => 'processing',
            'file_sha256' => hash_file('sha256', $absolutePath),
            'errors' => null,
        ]);

        try {
            $inspection = $this->inspect($absolutePath);

            DB::transaction(function () use ($import, $inspection): void {
                $feeder = Feeder::query()->lockForUpdate()->findOrFail($import->feeder_id);
                if (TransformerKmzImport::where('feeder_id', $feeder->id)->where('status', 'completed')->exists()
                    && HtNetworkUpdate::where('feeder_id', $feeder->id)->exists()
                    && ! TransformerKmzImport::where('feeder_id', $feeder->id)->where('status', 'completed')->where('file_sha256', $import->file_sha256)->exists()) {
                    throw new RuntimeException('This feeder has HT field updates. Reconcile the new client baseline with those updates before replacing it; existing HT/LT connections must be preserved.');
                }
                $this->guardWorkflowTotals($feeder, $inspection['transformer_count']);

                $created = 0;
                $updated = 0;
                $ids = [];
                $pending = 0;
                foreach ($inspection['transformers'] as $attributes) {
                    $transformer = $this->existingPoint($feeder, $attributes, $ids) ?? new Transformer(['feeder_id' => $feeder->id]);
                    $before = $transformer->only(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status']);
                    $sourceCode = $attributes['transformer_code'];
                    $reference = $transformer->temporary_reference ?? 'KMZ-'.$feeder->id.'-'.strtoupper(substr($attributes['source_identity_key'], 0, 12));
                    if ($attributes['code_verification_status'] === 'pending_client') {
                        $featureLabel = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $attributes['source_feature_id'] ?? ''), 0, 20);
                        $attributes['transformer_code'] = mb_substr($sourceCode, 0, 60).'~PENDING-'.($featureLabel ? $featureLabel.'-' : '').substr($reference, -6);
                        $attributes['temporary_reference'] = $reference;
                    }
                    if ($transformer->code_confirmed_at) {
                        $attributes['transformer_code'] = $transformer->transformer_code;
                        $attributes['code_verification_status'] = 'confirmed';
                    } elseif ($transformer->exists && $transformer->code_verification_status === 'pending_client') {
                        // Only client confirmation may clear a pending identity, even if a later KMZ looks unique.
                        $attributes['transformer_code'] = $transformer->transformer_code;
                        $attributes['code_verification_status'] = 'pending_client';
                    } elseif ($transformer->exists && $attributes['transformer_code'] !== $transformer->transformer_code
                        && $attributes['code_verification_status'] !== 'pending_client') {
                        $attributes['transformer_code'] = $transformer->transformer_code;
                        $attributes['code_verification_status'] = 'pending_client';
                    }
                    if ($transformer->capacity_verified_at) {
                        $attributes['capacity_kva'] = $transformer->capacity_kva;
                        $attributes['capacity_verification_status'] = 'confirmed';
                    } elseif ($transformer->exists && $transformer->capacity_verification_status === 'pending_field') {
                        $attributes['capacity_kva'] = $transformer->capacity_kva;
                        $attributes['capacity_verification_status'] = 'pending_field';
                    }
                    $attributes['source_transformer_code'] = $transformer->source_transformer_code ?? $sourceCode;
                    $attributes['source_capacity_kva'] = $transformer->source_identity_key ? $transformer->source_capacity_kva : $attributes['source_capacity_kva'];
                    $aliases = $transformer->code_aliases ?? [];
                    if ($transformer->exists && $transformer->transformer_code !== $attributes['transformer_code'] && $attributes['code_verification_status'] !== 'pending_client') $aliases[] = $transformer->transformer_code;
                    $attributes['code_aliases'] = array_values(array_unique($aliases));
                    $wasExisting = $transformer->exists;
                    $transformer->fill($attributes + ['feeder_id' => $feeder->id, 'kmz_import_id' => $import->id]);
                    $coreChanged = $wasExisting && $transformer->isDirty(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status']);
                    if ($coreChanged) $transformer->verification_revision++;
                    if ($wasExisting && $transformer->isDirty(['capacity_kva', 'capacity_verification_status'])) $transformer->capacity_revision++;
                    $transformer->save();
                    if ($coreChanged) {
                        \App\Models\TransformerRegistryChange::create(['transformer_id' => $transformer->id, 'changed_by' => $import->imported_by,
                            'kind' => 'source_reimport', 'old_values' => $before,
                            'new_values' => $transformer->only(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status']),
                            'reason' => 'Client KMZ reference reimport #'.$import->id, 'evidence_reference' => $import->file_name.' SHA-256 '.$import->file_sha256, 'created_at' => now()]);
                        app(TransformerRegistryService::class)->refreshLinkedWork($transformer, $before);
                    }
                    $wasExisting ? $updated++ : $created++;
                    $pending += $transformer->hasPendingVerification() ? 1 : 0;
                    $ids[] = $transformer->id;
                }
                // A warning/provisional import is additive. Never delete skipped or unresolved records.
                $removed = 0;
                if (! $inspection['warnings'] && ! $pending) {
                    $linkedCodes = NetworkTransformer::whereHas('batch', fn ($q) => $q->where('feeder_id', $feeder->id))->get(['code', 'header']);
                    foreach (Transformer::where('feeder_id', $feeder->id)->whereNotIn('id', $ids)->get() as $obsolete) {
                        $linked = $linkedCodes->contains(fn ($t) => ($t->header['registered_transformer_id'] ?? null) == $obsolete->id
                            || FieldTransformerIdentity::key($t->code) === FieldTransformerIdentity::key($obsolete->transformer_code));
                        if (! $obsolete->hasPendingVerification() && ! $obsolete->code_confirmed_at && ! $obsolete->capacity_verified_at && ! $linked
                            && ! FieldSurvey::withTrashed()->where('transformer_id', $obsolete->id)->exists()
                            && ! HtNetworkUpdate::where('transformer_id', $obsolete->id)->exists()
                            && ! $obsolete->capacityObservations()->exists() && ! $obsolete->registryChanges()->exists()) {
                            $obsolete->delete();
                            $removed++;
                        }
                    }
                }

                $feeder->update([
                    'total_transformers' => $inspection['transformer_count'],
                    'baseline_pending' => false,
                    'demo_baseline' => false,
                ]);

                $importWarnings = $inspection['warnings'];
                if ($pending && ! $importWarnings) $importWarnings[] = ['message' => $pending.' existing transformer reference(s) still require client or field verification.'];
                $import->update([
                    'source_feeder_name' => $inspection['source_feeder_name'],
                    'source_substation_name' => $inspection['source_substation_name'],
                    'status' => 'completed',
                    'total_placemarks' => $inspection['total_placemarks'],
                    'point_placemarks' => $inspection['point_placemarks'],
                    'transformer_count' => $inspection['transformer_count'],
                    'created_rows' => $created,
                    'updated_rows' => $updated,
                    'removed_rows' => $removed,
                    'pending_rows' => $pending,
                    'skipped_rows' => $inspection['transformer_count'] - count($inspection['transformers']),
                    'errors' => $importWarnings ?: null,
                    'imported_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'errors' => [['message' => $exception->getMessage()]],
                'imported_at' => now(),
            ]);

            throw $exception;
        }

        return $import->fresh();
    }

    /**
     * Inspect and validate a KMZ without writing transformer data.
     *
     * @return array{source_feeder_name:?string,source_substation_name:?string,total_placemarks:int,point_placemarks:int,transformer_count:int,count_only:bool,warnings:array,transformers:array<int,array<string,mixed>>}
     */
    public function inspect(string $absolutePath): array
    {
        $this->assertReadableFile($absolutePath);
        $kml = $this->readKml($absolutePath);
        $document = $this->loadXml($kml);
        $xpath = new DOMXPath($document);
        $placemarks = $xpath->query('//*[local-name()="Placemark"]');

        if ($placemarks === false) {
            throw new RuntimeException('The KMZ placemarks could not be read.');
        }

        $transformers = [];
        $sourceFeeders = [];
        $sourceSubstations = [];
        $seenCodes = [];
        $pointCount = 0;
        $transformerCount = 0;
        $warnings = [];

        foreach ($placemarks as $placemark) {
            if (! $placemark instanceof DOMElement) {
                continue;
            }

            $coordinateNode = $xpath->query('.//*[local-name()="Point"]/*[local-name()="coordinates"]', $placemark)?->item(0);
            if ($coordinateNode === null) {
                continue;
            }
            $pointCount++;

            $description = $xpath->query('./*[local-name()="description"]', $placemark)?->item(0)?->textContent ?? '';
            $raw = $this->descriptionAttributes($description);
            if (strcasecmp($this->value($raw, 'equipmenttype') ?? '', 'Transformer') !== 0) {
                continue;
            }

            $transformerCount++;
            $sourceFeeder = $this->value($raw, 'feeder');
            if ($sourceFeeder !== null) {
                $sourceFeeders[mb_strtolower($sourceFeeder)] = $sourceFeeder;
            }
            $sourceSubstation = $this->value($raw, 'substation');
            $code = $this->value($raw, 'equipmentnumber');
            $codeKey = $code === null ? null : mb_strtoupper($code);

            if (isset($seenCodes[$codeKey])) {
                $warnings[] = ['message' => "Duplicate transformer number '{$code}' in the KMZ; separate working references require client confirmation."];
            }
            if ($codeKey !== null) {
                $seenCodes[$codeKey] = ($seenCodes[$codeKey] ?? 0) + 1;
            }
            if ($sourceSubstation !== null) {
                $sourceSubstations[mb_strtolower($sourceSubstation)] = $sourceSubstation;
            }

            try {
                [$longitude, $latitude, $altitude] = $this->coordinates($coordinateNode->textContent);
                $sourceFeeder = $this->required($raw, 'feeder', 'source feeder');
                $code = $this->required($raw, 'equipmentnumber', 'transformer/equipment number');
                $this->validateAttributeCoordinates($raw, $longitude, $latitude, $code);
                $capacity = $this->number($this->value($raw, 'equipmentsize'));
                if ($capacity !== null && $capacity < 0) {
                    throw new RuntimeException("Transformer '{$code}' has an invalid capacity.");
                }

                $transformers[] = [
                    'source_feature_id' => trim($placemark->getAttribute('id')) ?: null,
                    'transformer_code' => $code,
                    'gps_waypoint_number' => $this->value($raw, 'gpswaypointnumber'),
                    'source_substation_name' => $sourceSubstation,
                    'source_feeder_name' => $sourceFeeder,
                    'line_voltage' => $this->value($raw, 'linevoltage'),
                    'feeders_on_pole' => $this->unsignedInteger($raw, 'feedersonpole'),
                    'pole_number' => $this->value($raw, 'polenumber'),
                    'pole_phase' => $this->value($raw, 'polephase'),
                    'pole_use' => $this->value($raw, 'poleuse'),
                    'pole_height' => $this->number($this->value($raw, 'polehight') ?? $this->value($raw, 'poleheight')),
                    'pole_type' => $this->value($raw, 'poletype'),
                    'conductor_phase_r' => $this->value($raw, 'conductorsizephaser'),
                    'conductor_phase_y' => $this->value($raw, 'conductorsizephasey'),
                    'conductor_phase_b' => $this->value($raw, 'conductorsizephaseb'),
                    'conductor_neutral' => $this->value($raw, 'conductorsizeneutral'),
                    'capacity_kva' => $capacity > 0 ? $capacity : null,
                    'source_capacity_kva' => $capacity,
                    'capacity_verification_status' => $capacity > 0 ? 'confirmed' : 'pending_field',
                    'equipment_unit' => $this->unsignedInteger($raw, 'equipmentunit'),
                    'equipment_phase' => $this->value($raw, 'equipmentphase'),
                    'equipment_use' => $this->value($raw, 'equipmentuse'),
                    'equipment_status' => $this->value($raw, 'equipmentstatus'),
                    'equipment_make' => $this->value($raw, 'equipmentmake'),
                    'equipment_name' => $this->value($raw, 'equipmentname'),
                    'equipment_location' => $this->value($raw, 'equipmentlocation'),
                    'equipment_mounting' => $this->value($raw, 'equipmentmounting'),
                    'end_type' => $this->value($raw, 'endtype'),
                    'residential_single' => $this->unsignedInteger($raw, 'residentialconsumersingle') ?? 0,
                    'residential_three' => $this->unsignedInteger($raw, 'residentialconsumerthree') ?? 0,
                    'residential_total' => $this->unsignedInteger($raw, 'residentialconsumer') ?? 0,
                    'small_commercial' => $this->unsignedInteger($raw, 'smallcommercialconsumer') ?? 0,
                    'large_commercial' => $this->unsignedInteger($raw, 'largecommercialconsumer') ?? 0,
                    'small_industries' => $this->unsignedInteger($raw, 'smallindustries') ?? 0,
                    'large_industries' => $this->unsignedInteger($raw, 'largeindustries') ?? 0,
                    'public_use' => $this->unsignedInteger($raw, 'publicuse') ?? 0,
                    'agricultural' => $this->unsignedInteger($raw, 'agriculturalconsumer') ?? 0,
                    'street_lights' => $this->unsignedInteger($raw, 'streetlight') ?? 0,
                    'remarks' => $this->value($raw, 'remarks'),
                    'source_picture_path' => $this->value($raw, 'transformerpicturepath'),
                    'longitude' => $longitude,
                    'latitude' => $latitude,
                    'altitude' => $altitude,
                    'raw_attributes' => $raw,
                ];
            } catch (RuntimeException $exception) {
                $warnings[] = ['message' => $exception->getMessage()];
            }
        }

        if ($transformerCount === 0) {
            throw new RuntimeException('No valid transformer point records were found in the KMZ.');
        }
        if (count($sourceFeeders) > 1) {
            throw new RuntimeException('A KMZ must contain transformer records for exactly one source feeder.');
        }

        $featureIds = array_count_values(array_filter(array_column($transformers, 'source_feature_id')));
        $identities = [];
        foreach ($transformers as &$transformer) {
            $code = $transformer['transformer_code'];
            $transformer['code_verification_status'] = ($seenCodes[mb_strtoupper($code)] ?? 0) > 1 ? 'pending_client' : 'confirmed';
            $feature = $transformer['source_feature_id'];
            $identity = $feature && ($featureIds[$feature] ?? 0) === 1 ? 'feature:'.$feature
                : 'point:'.json_encode([$transformer['gps_waypoint_number'], $transformer['longitude'], $transformer['latitude'], mb_strtoupper($code)]);
            $key = hash('sha256', $identity);
            if (isset($identities[$key])) throw new RuntimeException('Two transformer points have the same source identity and coordinates. Resolve their source feature IDs before import.');
            $identities[$key] = true;
            $transformer['source_identity_key'] = $key;
            if ($transformer['capacity_verification_status'] === 'pending_field') {
                $warnings[] = ['message' => "Transformer '{$code}' has unknown/zero source capacity; field verification is required unless a locally approved rating already exists."];
            }
        }
        unset($transformer);

        return [
            'source_feeder_name' => array_values($sourceFeeders)[0] ?? null,
            'source_substation_name' => count($sourceSubstations) === 1 ? array_values($sourceSubstations)[0] : null,
            'total_placemarks' => $placemarks->length,
            'point_placemarks' => $pointCount,
            'transformer_count' => $transformerCount,
            'count_only' => $transformers === [],
            'warnings' => $warnings,
            'transformers' => $transformers,
        ];
    }

    private function existingPoint(Feeder $feeder, array $attributes, array $usedIds = []): ?Transformer
    {
        $bySource = Transformer::where('feeder_id', $feeder->id)->where('source_identity_key', $attributes['source_identity_key'])->first();
        if ($bySource) {
            if (($bySource->hasPendingVerification() || $bySource->code_confirmed_at || $attributes['code_verification_status'] === 'pending_client')
                && $bySource->gps_waypoint_number && $attributes['gps_waypoint_number'] && $bySource->gps_waypoint_number !== $attributes['gps_waypoint_number']
                && (abs((float) $bySource->latitude - $attributes['latitude']) > 0.0000002 || abs((float) $bySource->longitude - $attributes['longitude']) > 0.0000002)) {
                throw new RuntimeException('A provisional/corrected transformer feature now has a different GPS point and location. Reconcile the source identity before reimporting; existing work was preserved.');
            }
            if (FieldTransformerIdentity::key($bySource->source_transformer_code ?? $bySource->transformer_code) !== FieldTransformerIdentity::key($attributes['transformer_code'])
                && $bySource->gps_waypoint_number !== $attributes['gps_waypoint_number']) {
                throw new RuntimeException('A KMZ feature ID now identifies a different transformer. Existing work was preserved; reconcile its source identity before reimporting.');
            }
            return $bySource;
        }
        $matches = Transformer::where('feeder_id', $feeder->id)->whereNotIn('id', $usedIds)->get()->filter(function ($record) use ($attributes) {
            $codeMatches = FieldTransformerIdentity::key($record->transformer_code) === FieldTransformerIdentity::key($attributes['transformer_code'])
                || FieldTransformerIdentity::key($record->source_transformer_code) === FieldTransformerIdentity::key($attributes['transformer_code']);
            return $codeMatches && abs((float) $record->latitude - $attributes['latitude']) < 0.0000002
                && abs((float) $record->longitude - $attributes['longitude']) < 0.0000002;
        });
        if ($matches->count() === 1) return $matches->first();
        if ($matches->count() > 1) throw new RuntimeException('Multiple existing transformers match one KMZ point. Reconcile their internal IDs before reimporting.');
        if ($attributes['code_verification_status'] === 'confirmed') {
            return Transformer::where('feeder_id', $feeder->id)->whereNotIn('id', $usedIds)->where('transformer_code', $attributes['transformer_code'])->first();
        }
        return null;
    }

    public function readKml(string $absolutePath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('The uploaded file is not a readable KMZ archive.');
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new RuntimeException('The KMZ has an unsafe or unsupported number of files.');
            }

            $totalBytes = 0;
            $kmlIndexes = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new RuntimeException('The KMZ archive directory is damaged.');
                }
                $totalBytes += (int) ($stat['size'] ?? 0);
                if ($totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('The KMZ expands beyond the safe import limit.');
                }
                if (str_ends_with(strtolower((string) ($stat['name'] ?? '')), '.kml')) {
                    $kmlIndexes[] = $index;
                }
            }

            if (count($kmlIndexes) !== 1) {
                throw new RuntimeException('The KMZ must contain exactly one KML document.');
            }
            $stat = $zip->statIndex($kmlIndexes[0]);
            if ((int) ($stat['size'] ?? 0) > self::MAX_KML_BYTES) {
                throw new RuntimeException('The KML document is larger than the safe import limit.');
            }
            $kml = $zip->getFromIndex($kmlIndexes[0]);
            if (! is_string($kml) || trim($kml) === '') {
                throw new RuntimeException('The KML document is empty or unreadable.');
            }

            return $kml;
        } finally {
            $zip->close();
        }
    }

    private function loadXml(string $kml): DOMDocument
    {
        // Some client exports use xsi:schemaLocation without declaring xsi. It is
        // metadata only, so remove it before parsing while keeping all GIS data.
        $kml = preg_replace('/\s+xsi:schemaLocation\s*=\s*(["\']).*?\1/is', '', $kml) ?? $kml;
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;

        try {
            if (! $document->loadXML($kml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) {
                $message = libxml_get_last_error()?->message ?? 'unknown XML error';
                throw new RuntimeException('The KML document is malformed: '.trim($message));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    /** @return array<string, string> */
    private function descriptionAttributes(string $description): array
    {
        $attributes = [];
        preg_match_all('/<tr\b[^>]*>\s*<td\b[^>]*>(.*?)<\/td>\s*<td\b[^>]*>(.*?)<\/td>\s*<\/tr>/is', $description, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $label = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $value = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $key = $this->key($label);
            if ($key !== '') {
                $attributes[$key] = $value;
            }
        }

        $aliases = [
            'equiptype' => 'equipmenttype', 'equipsize' => 'equipmentsize',
            'equipunit' => 'equipmentunit', 'equipuse' => 'equipmentuse',
            'equipmake' => 'equipmentmake', 'gpsno' => 'gpswaypointnumber',
            'consizea' => 'conductorsizephaser', 'consizeb' => 'conductorsizephasey',
            'consizec' => 'conductorsizephaseb', 'consizen' => 'conductorsizeneutral',
            'rscon1' => 'residentialconsumersingle', 'rscon3' => 'residentialconsumerthree',
            'rscon' => 'residentialconsumer', 'sccon' => 'smallcommercialconsumer',
            'lccon' => 'largecommercialconsumer', 'sicon' => 'smallindustries',
            'licon' => 'largeindustries', 'pbcon' => 'publicuse',
            'agcon' => 'agriculturalconsumer', 'stcon' => 'streetlight',
            'picpath' => 'transformerpicturepath',
        ];
        foreach ($aliases as $alias => $canonical) {
            if (isset($attributes[$alias]) && ! isset($attributes[$canonical])) {
                $attributes[$canonical] = $attributes[$alias];
            }
        }

        return $attributes;
    }

    /** @return array{0:float,1:float,2:?float} */
    private function coordinates(string $coordinates): array
    {
        $first = preg_split('/\s+/', trim($coordinates))[0] ?? '';
        $parts = array_map('trim', explode(',', $first));
        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            throw new RuntimeException('A transformer has invalid point coordinates.');
        }
        $longitude = (float) $parts[0];
        $latitude = (float) $parts[1];
        $altitude = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null;
        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            throw new RuntimeException('A transformer point is outside valid longitude/latitude bounds.');
        }

        return [$longitude, $latitude, $altitude];
    }

    /** @param array<string, string> $raw */
    private function validateAttributeCoordinates(array $raw, float $longitude, float $latitude, string $code): void
    {
        $pointX = $this->number($this->value($raw, 'pointx'));
        $pointY = $this->number($this->value($raw, 'pointy'));
        if ($pointX !== null && abs($pointX - $longitude) > self::COORDINATE_TOLERANCE) {
            throw new RuntimeException("Transformer '{$code}' has conflicting longitude values.");
        }
        if ($pointY !== null && abs($pointY - $latitude) > self::COORDINATE_TOLERANCE) {
            throw new RuntimeException("Transformer '{$code}' has conflicting latitude values.");
        }
    }

    private function guardWorkflowTotals(Feeder $feeder, int $transformerCount): void
    {
        $surveyed = (int) $feeder->surveyItems()->sum('transformers_surveyed');
        $mdbCreated = (int) $feeder->mdbItems()->sum('mdb_files_created');
        $minimum = max($surveyed, $mdbCreated);
        if ($transformerCount < $minimum) {
            throw new RuntimeException("KMZ contains {$transformerCount} transformers, below {$minimum} already recorded in workflow. The feeder was not changed.");
        }
    }

    /** @param array<string, string> $raw */
    private function required(array $raw, string $key, string $label): string
    {
        $value = $this->value($raw, $key);
        if ($value === null) {
            throw new RuntimeException("Missing {$label} in a transformer record.");
        }

        return $value;
    }

    /** @param array<string, string> $raw */
    private function value(array $raw, string $key): ?string
    {
        $value = trim($raw[$key] ?? '');

        return $value === '' ? null : $value;
    }

    /** @param array<string, string> $raw */
    private function unsignedInteger(array $raw, string $key): ?int
    {
        $number = $this->number($this->value($raw, $key));
        if ($number === null) {
            return null;
        }
        if ($number < 0 || floor($number) !== $number) {
            throw new RuntimeException("Field '{$key}' must be a non-negative whole number.");
        }

        return (int) $number;
    }

    private function number(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = str_replace(',', '', $value);
        if (! preg_match('/-?\d+(?:\.\d+)?/', $value, $match)) {
            return null;
        }

        return (float) $match[0];
    }

    private function key(string $label): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $label));
    }

    private function assertReadableFile(string $absolutePath): void
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('The uploaded KMZ file could not be read.');
        }
    }
}
