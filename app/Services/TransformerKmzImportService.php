<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\Transformer;
use App\Models\TransformerKmzImport;
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
                $this->guardWorkflowTotals($feeder, count($inspection['transformers']));

                $created = 0;
                $updated = 0;
                $codes = [];

                foreach ($inspection['transformers'] as $attributes) {
                    $codes[] = $attributes['transformer_code'];
                    $transformer = Transformer::firstOrNew([
                        'feeder_id' => $feeder->id,
                        'transformer_code' => $attributes['transformer_code'],
                    ]);
                    $transformer->fill($attributes + [
                        'feeder_id' => $feeder->id,
                        'kmz_import_id' => $import->id,
                    ]);
                    $transformer->exists ? $updated++ : $created++;
                    $transformer->save();
                }

                $removed = Transformer::query()
                    ->where('feeder_id', $feeder->id)
                    ->whereNotIn('transformer_code', $codes)
                    ->delete();

                $feeder->update([
                    'total_transformers' => count($codes),
                    'baseline_pending' => false,
                    'demo_baseline' => false,
                ]);

                $import->update([
                    'source_feeder_name' => $inspection['source_feeder_name'],
                    'source_substation_name' => $inspection['source_substation_name'],
                    'status' => 'completed',
                    'total_placemarks' => $inspection['total_placemarks'],
                    'point_placemarks' => $inspection['point_placemarks'],
                    'transformer_count' => count($codes),
                    'created_rows' => $created,
                    'updated_rows' => $updated,
                    'removed_rows' => $removed,
                    'errors' => null,
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
     * @return array{source_feeder_name:string,source_substation_name:?string,total_placemarks:int,point_placemarks:int,transformers:array<int,array<string,mixed>>}
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

            [$longitude, $latitude, $altitude] = $this->coordinates($coordinateNode->textContent);
            $sourceFeeder = $this->required($raw, 'feeder', 'source feeder');
            $sourceSubstation = $this->value($raw, 'substation');
            $code = $this->required($raw, 'equipmentnumber', 'transformer/equipment number');
            $codeKey = mb_strtoupper($code);

            if (isset($seenCodes[$codeKey])) {
                throw new RuntimeException("Duplicate transformer number '{$code}' in the KMZ.");
            }
            $seenCodes[$codeKey] = true;
            $sourceFeeders[mb_strtolower($sourceFeeder)] = $sourceFeeder;
            if ($sourceSubstation !== null) {
                $sourceSubstations[mb_strtolower($sourceSubstation)] = $sourceSubstation;
            }

            $this->validateAttributeCoordinates($raw, $longitude, $latitude, $code);
            $capacity = $this->number($this->required($raw, 'equipmentsize', "capacity for {$code}"));
            if ($capacity === null || $capacity <= 0) {
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
                'capacity_kva' => $capacity,
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
        }

        if ($transformers === []) {
            throw new RuntimeException('No valid transformer point records were found in the KMZ.');
        }
        if (count($sourceFeeders) !== 1) {
            throw new RuntimeException('A KMZ must contain transformer records for exactly one source feeder.');
        }

        return [
            'source_feeder_name' => array_values($sourceFeeders)[0],
            'source_substation_name' => count($sourceSubstations) === 1 ? array_values($sourceSubstations)[0] : null,
            'total_placemarks' => $placemarks->length,
            'point_placemarks' => $pointCount,
            'transformers' => $transformers,
        ];
    }

    private function readKml(string $absolutePath): string
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
