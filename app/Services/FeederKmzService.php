<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\HtNetworkUpdate;
use App\Models\Mdb\SurveyBatch;
use App\Models\Transformer;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\ProjectionService;
use App\Support\FieldTransformerIdentity;
use Illuminate\Support\Collection;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class FeederKmzService
{
    private const NS = 'http://www.opengis.net/kml/2.2';

    /** Only current, approved snapshots contribute LT geometry. Never read meter records. */
    public function prepare(Feeder $feeder): array
    {
        $issues = $warnings = $networks = $coverage = $candidates = $observed = $duties = $reasons = [];
        $registry = Transformer::where('feeder_id', $feeder->id)->orderBy('transformer_code')->get();
        $ht = app(HtKmzService::class);
        $baseline = $ht->baseline($feeder->id);
        if (! $baseline) {
            $issues[] = 'Import the original HT feeder KMZ first.';
        } else {
            try { $ht->path($baseline); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $issues[] = $exception->getMessage(); }
        }
        $pendingHt = HtNetworkUpdate::where('feeder_id', $feeder->id)->where('status', '!=', 'verified')->count();
        if ($pendingHt) $warnings[] = "{$pendingHt} pending or returned HT updates are excluded. The original HT network and verified HT updates remain included.";
        foreach ($registry as $record) $duties[$record->id] = $this->duty($record->equipment_use);
        // Live observations supply progress only. Export geometry always comes from an approved snapshot.
        $observe = function (array $record) use ($registry, &$observed, &$duties): void {
            $id = $this->registeredId($record, $registry);
            if (! $id) return;
            $observed[$id] = true;
            $duty = $this->duty($record['header']['service_category'] ?? $record['header']['duty'] ?? null);
            if ($duty && ! $duties[$id]) $duties[$id] = $duty;
        };
        foreach (FieldSurvey::where('feeder_id', $feeder->id)->get() as $survey) {
            $observe(['code' => $survey->transformer_code, 'header' => ($survey->header ?? []) + ['registered_transformer_id' => $survey->transformer_id]]);
        }
        $batches = SurveyBatch::where('feeder_id', $feeder->id)->with(['approvedRevision', 'transformers', 'decisions'])->orderBy('id')->get();
        foreach ($batches as $batch) {
            foreach ($batch->transformers as $record) $observe($record->toArray());
            $revision = $batch->approvedRevision;
            if ($batch->status !== 'approved' || ! $revision) continue;
            $snapshot = $revision->snapshot;
            $approval = $batch->decisions->where('revision_id', $revision->id)->where('decision', 'approve')->sortByDesc('id')->first();
            $invalid = null;
            if ($revision->batch_id !== $batch->id || $revision->number !== $batch->revision
                || ! hash_equals($revision->sha256, app(SnapshotService::class)->hash($snapshot))) {
                $invalid = 'Approval is outdated or the snapshot integrity check failed.';
            } elseif ($batch->staged_workflow && ($batch->survey_status !== 'approved'
                || ! $batch->decisions->where('revision_id', $revision->id)->where('decision', 'approve')
                    ->whereNotIn('decided_by', $batch->entry_actor_ids ?? [])->count())) {
                $invalid = 'Independent survey verification is required.';
            } elseif (($snapshot['batch']['feeder_id'] ?? null) != $feeder->id) {
                $invalid = 'Snapshot feeder does not match.';
            }
            foreach ($snapshot['transformers'] ?? [] as $transformer) {
                $code = $transformer['code'] ?? '?';
                $id = $this->registeredId($transformer, $registry);
                $duty = $this->duty($transformer['header']['service_category'] ?? $transformer['header']['duty'] ?? null)
                    ?? ($id ? $duties[$id] : null);
                if ($duty === 'dedicated') continue;
                if (! $id || $duty !== 'general') {
                    $warnings[] = "Batch #{$batch->id}, transformer {$code}: LT excluded; link to a registered transformer and record its service category.";
                    continue;
                }
                $observed[$id] = true;
                $duties[$id] = 'general';
                if ($invalid) {
                    $reasons[$id][] = "Batch #{$batch->id}: {$invalid}";
                    continue;
                }
                $candidates[$id][] = ['transformer' => $transformer, 'points' => $snapshot['waypoints'] ?? [],
                    'epsg' => (int) ($snapshot['configuration']['epsg'] ?? 32643), 'batch_id' => $batch->id,
                    'revision' => $revision->number, 'revision_sha256' => $revision->sha256,
                    'survey_date' => $snapshot['batch']['survey_date'] ?? $batch->survey_date?->format('Y-m-d'),
                    'approval_date' => $batch->survey_verified_at?->utc()->toIso8601String() ?? $approval?->created_at?->utc()->toIso8601String()];
            }
        }
        foreach ($candidates as $id => $records) {
            if (count($records) !== 1) {
                $reasons[$id][] = 'Multiple approved LT surveys exist; reconcile duplicate records. No LT network was chosen automatically.';
                continue;
            }
            $record = $records[0];
            try {
                $network = $this->network($record['transformer'], $record['points'], $record['epsg']);
                $networks[$id] = $network + ['registered_transformer_id' => $id] + array_diff_key($record, array_flip(['transformer', 'points', 'epsg']));
            } catch (ValidationException $exception) {
                $reasons[$id][] = 'LT excluded: '.$exception->validator->errors()->first();
            }
        }
        $counts = ['total_transformers' => $registry->count(), 'general_duty' => 0, 'dedicated' => 0, 'unclassified' => 0,
            'approved_lt' => count($networks), 'pending_surveys' => 0, 'unsurveyed_general' => 0, 'excluded_lt' => 0];
        foreach ($registry as $transformer) {
            $id = $transformer->id;
            $network = $networks[$id] ?? null;
            $duty = $duties[$id];
            $counts[$duty === 'general' ? 'general_duty' : ($duty === 'dedicated' ? 'dedicated' : 'unclassified')]++;
            $reason = implode(' ', $reasons[$id] ?? []);
            if ($network) $status = 'Approved LT included';
            elseif ($duty === 'dedicated') $status = 'Dedicated - HT only';
            elseif ($reason) { $status = 'Approved LT excluded - needs attention'; $counts['excluded_lt']++; }
            elseif (isset($observed[$id])) { $status = 'Survey in progress / awaiting approval - HT only'; if ($duty === 'general') $counts['pending_surveys']++; }
            else { $status = 'Not surveyed - HT only'; if ($duty === 'general') $counts['unsurveyed_general']++; }
            if ($reason) $warnings[] = "Transformer {$transformer->transformer_code}: {$reason}";
            $coverage[] = ['id' => $id, 'code' => $transformer->transformer_code, 'source_feature_id' => $transformer->source_feature_id,
                'aliases' => array_merge([$transformer->source_transformer_code, $transformer->temporary_reference], $transformer->code_aliases ?? []),
                'duty' => $duty === 'general' ? 'General Duty' : ($duty === 'dedicated' ? 'Dedicated' : 'Not classified'),
                'capacity_kva' => $transformer->capacity_kva, 'location' => $transformer->equipment_location,
                'ready' => (bool) $network, 'status' => $status, 'reason' => $reason,
                'batch_id' => $network['batch_id'] ?? null, 'revision' => $network['revision'] ?? null,
                'survey_date' => $network['survey_date'] ?? null, 'approval_date' => $network['approval_date'] ?? null,
                'poles' => count($network['points'] ?? []), 'lines' => count($network['lines'] ?? [])];
        }
        $counts['completion_percent'] = $counts['general_duty'] ? round(100 * $counts['approved_lt'] / $counts['general_duty'], 1) : null;

        return ['ready' => ! $issues, 'issues' => array_values(array_unique($issues)), 'warnings' => array_values(array_unique($warnings)),
            'counts' => $counts, 'coverage' => $coverage, 'networks' => array_values($networks)];
    }

    private function registeredId(array $transformer, Collection $registry): ?int
    {
        $id = $transformer['header']['registered_transformer_id'] ?? null;
        if ($id) return $registry->contains('id', $id) ? (int) $id : null;
        $key = FieldTransformerIdentity::key($transformer['code'] ?? '');
        if (! $key) return null;
        $matches = $registry->filter(fn ($record) => in_array($key, array_map(FieldTransformerIdentity::key(...),
            array_merge([$record->transformer_code, $record->temporary_reference], $record->code_aliases ?? [])), true));
        return $matches->count() === 1 ? $matches->first()->id : null;
    }

    private function duty(?string $value): ?string
    {
        return match (strtolower(preg_replace('/[^a-z0-9]/i', '', $value ?? ''))) {
            'generalduty', 'generaldutytransformer', 'gd' => 'general',
            'dedicated', 'dedicatedtransformer' => 'dedicated',
            default => null,
        };
    }

    public function network(array $transformer, array $waypoints, int $epsg = 32643): array
    {
        $fail = fn ($message) => throw ValidationException::withMessages(['kmz' => $message]);
        $resolved = app(SurveyWaypoint::class)->network($transformer, array_column($waypoints, null, 'id'));
        $points = $lines = $adjacent = [];
        foreach ($resolved['sections'] as $section) {
            $ends = [];
            foreach (['start', 'end'] as $end) {
                $id = (string) ($section[$end.'_waypoint_id'] ?? '');
                $id = (string) ($resolved['aliases'][$id] ?? $id);
                if (! isset($resolved['points'][$id])) $fail('A distribution line has an unresolved '.$end.' waypoint.');
                $point = $this->point($resolved['points'][$id]);
                $point['label'] = $point['survey_identifier'] ?? $section[$end.'_reference'] ?? $point['name'] ?? $id;
                $row = collect($transformer['entry_rows'] ?? [])->sortBy(fn ($r) => ($r['designation'] ?? '') === 'E' ? 0 : 1)
                    ->first(fn ($r) => ! empty($r['composite_identifier']) && $r['composite_identifier'] === $point['label']);
                $point['pole_details'] = [
                    'Pole class' => $row['pole_class'] ?? ($end === 'start' ? ($section['pole_class'] ?? null) : null),
                    'Pole height' => $row['pole_height'] ?? ($end === 'start' ? ($section['pole_height'] ?? null) : null),
                    'Pole height unit' => $row['pole_height_unit'] ?? ($end === 'start' ? ($section['pole_height_unit'] ?? null) : null),
                    'Recorded equipment type' => $row['equipment_type'] ?? null,
                ];
                $points[$id] = $point;
                $ends[] = $id;
            }
            if ($ends[0] === $ends[1]) $fail('A distribution line connects a waypoint to itself.');
            $route = [$points[$ends[0]]];
            foreach ($section['geometry'] ?? [] as $point) $route[] = $this->point($point);
            $route[] = $points[$ends[1]];
            $lines[] = ['id' => $section['id'], 'from' => $ends[0], 'to' => $ends[1], 'route' => $route,
                'phases' => $section['phases'] ?? [], 'conductors' => $section['conductors'] ?? [],
                'pole_class' => $section['pole_class'] ?? '', 'pole_height' => $section['pole_height'] ?? '',
                'pole_height_unit' => $section['pole_height_unit'] ?? ''] + $this->lineLength($section, $route, $epsg);
            $adjacent[$ends[0]][] = $ends[1];
            $adjacent[$ends[1]][] = $ends[0];
        }
        if (! $lines) $fail('No LT distribution lines have been recorded.');
        $root = (string) $resolved['root'];
        $root = (string) ($resolved['aliases'][$root] ?? $root);
        if (! isset($points[$root])) $fail('The transformer starting point is not connected to its LT lines.');
        $visited = [];
        $queue = [$root];
        while ($queue) {
            $id = array_pop($queue);
            if (isset($visited[$id])) continue;
            $visited[$id] = true;
            foreach ($adjacent[$id] ?? [] as $next) if (! isset($visited[$next])) $queue[] = $next;
        }
        if (count($visited) !== count($points)) $fail('Some LT lines are disconnected from the transformer.');

        return ['code' => $transformer['code'], 'root' => $root, 'points' => $points, 'lines' => $lines,
            'capacity_kva' => $transformer['capacity_kva'] ?? null,
            'location' => $transformer['header']['location'] ?? null, 'duty' => 'General Duty'];
    }

    private function lineLength(array $section, array $route, int $epsg): array
    {
        $measured = $section['measured_length_m'] ?? null;
        if (is_numeric($measured) && is_finite((float) $measured) && $measured > 0) {
            return ['length_m' => round((float) $measured, 2), 'length_method' => 'Recorded measured length'];
        }
        try {
            $details = app(ProjectionService::class)->lengthDetails($route, $epsg);
            return ['length_m' => round($details['length_m'], 2), 'length_method' =>
                (! empty($section['geometry']) ? 'Recorded route estimate' : 'Endpoint distance estimate').
                ($details['elevation_status'] === 'elevation_adjusted' ? ' with recorded elevation' : ' (horizontal; elevation unavailable)').'; no sag allowance'];
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            return ['length_m' => null, 'length_method' => 'Estimate unavailable for the survey projection'];
        }
    }

    private function point(array $point): array
    {
        if (! empty($point['correction'])) {
            if (empty($point['correction_approved_by']) || empty($point['correction_approved_at'])) {
                throw ValidationException::withMessages(['kmz' => 'Coordinate corrections require approval.']);
            }
            $point = array_replace($point, $point['correction']);
        }
        if (! app(SurveyWaypoint::class)->validCoordinates($point) || ((float) $point['latitude'] === 0.0 && (float) $point['longitude'] === 0.0)) {
            throw ValidationException::withMessages(['kmz' => 'A pole or route point has missing or invalid coordinates.']);
        }

        return $point;
    }

    public function export(Feeder $feeder): string
    {
        return $this->generate($feeder)['path'];
    }

    public function generate(Feeder $feeder): array
    {
        $prepared = $this->prepare($feeder);
        if (! $prepared['ready']) throw ValidationException::withMessages(['kmz' => $prepared['issues']]);
        $path = app(HtKmzService::class)->export($feeder);
        $zip = new ZipArchive;
        try {
            if ($zip->open($path) !== true) throw new \RuntimeException('Unable to open the feeder KMZ.');
            $name = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (str_ends_with(strtolower($zip->getNameIndex($i)), '.kml')) { $name = $zip->getNameIndex($i); break; }
            }
            if (! $name) throw new \RuntimeException('The HT KMZ contains no KML document.');
            $xml = $this->appendLt($zip->getFromName($name), $prepared['networks'], $prepared['coverage'], $prepared['counts']);
            if (! $zip->addFromString($name, $xml) || ! $zip->close()) throw new \RuntimeException('Unable to write the complete feeder KMZ.');
        } catch (\Throwable $exception) {
            try { $zip->close(); } catch (\Throwable) { /* Preserve the original write error. */ }
            @unlink($path);
            throw $exception;
        }

        $baseline = app(HtKmzService::class)->baseline($feeder->id);
        return ['path' => $path, 'prepared' => $prepared, 'baseline_sha256' => $baseline?->file_sha256];
    }

    public function appendLt(string $xml, array $networks, array $coverage = [], array $counts = []): string
    {
        $doc = new DOMDocument;
        if (str_contains(strtoupper($xml), '<!DOCTYPE') || ! $doc->loadXML($xml, LIBXML_NONET)) throw new \RuntimeException('The HT KML is invalid.');
        $xp = new DOMXPath($doc);
        $container = $xp->query('//*[local-name()="Document"]')->item(0);
        if (! $container) throw new \RuntimeException('The HT KML has no Document.');
        $prefix = 'survey-lt-'.bin2hex(random_bytes(6));
        $add = function ($parent, string $tag, ?string $text = null) use ($doc): DOMElement {
            $node = $doc->createElementNS(self::NS, $tag);
            if ($text !== null) $node->appendChild($doc->createTextNode($text));
            $parent->appendChild($node);
            return $node;
        };
        // Styles precede features, while retaining all original styles and assets.
        $style = $doc->createElementNS(self::NS, 'Style'); $style->setAttribute('id', $prefix);
        $line = $add($style, 'LineStyle'); $add($line, 'color', 'ff00aa00'); $add($line, 'width', '3');
        $firstFeature = $xp->query('./*[local-name()="ExtendedData" or local-name()="Folder" or local-name()="Placemark" or local-name()="NetworkLink"]', $container)->item(0);
        $container->insertBefore($style, $firstFeature);
        // Enrich existing HT transformer balloons without replacing client attributes or geometry.
        foreach ($xp->query('//*[local-name()="Placemark"]') as $feature) {
            $attributes = app(HtKmzService::class)->attributes($feature);
            $normal = [];
            foreach ($attributes as $key => $value) $normal[strtolower(preg_replace('/[^a-z0-9]/i', '', $key))] = $value;
            $matches = array_values(array_filter($coverage, function ($row) use ($feature, $normal) {
                if (! empty($row['source_feature_id']) && $row['source_feature_id'] === $feature->getAttribute('id')) return true;
                if (! str_contains(strtolower($normal['equipmenttype'] ?? $normal['equiptype'] ?? ''), 'transformer')) return false;
                $key = FieldTransformerIdentity::key($normal['equipmentnumber'] ?? '');
                return $key && in_array($key, array_map(FieldTransformerIdentity::key(...), array_merge([$row['code']], $row['aliases'] ?? [])), true);
            }));
            if (count($matches) !== 1) continue;
            $row = $matches[0];
            $this->mapDetails($feature, ['Transformer number' => $row['code'], 'Duty' => $row['duty'],
                'Capacity (kVA)' => $row['capacity_kva'], 'Location' => $row['location'], 'LT survey status' => $row['status'],
                'Survey date' => $row['survey_date'], 'Batch number' => $row['batch_id'], 'Approved revision' => $row['revision'],
                'Approval date (UTC)' => $row['approval_date'], 'LT exclusion reason' => $row['reason'] ?: null]);
        }
        $lt = $add($container, 'Folder'); $add($lt, 'name', 'Verified LT networks');
        $add($lt, 'description', 'Completed LT distribution surveys. Meter points and service cables are excluded. Lines follow recorded endpoints and any recorded route geometry.');
        if ($counts) $this->mapDetails($lt, ['HT transformer records' => $counts['total_transformers'], 'Approved LT networks included' => $counts['approved_lt'], 'General Duty transformers' => $counts['general_duty'], 'Dedicated transformers' => $counts['dedicated'], 'Exported at (UTC)' => now('UTC')->toIso8601String()]);
        $coordinate = fn ($point) => (float) $point['longitude'].','.(float) $point['latitude'].',0';
        foreach ($networks as $network) {
            $folder = $add($lt, 'Folder'); $add($folder, 'name', 'Transformer '.$network['code']);
            $common = ['Transformer number' => $network['code'], 'Duty' => $network['duty'] ?? 'General Duty',
                'Capacity (kVA)' => $network['capacity_kva'] ?? null, 'Location' => $network['location'] ?? null,
                'LT survey status' => 'Approved LT included', 'RegisteredTransformerId' => $network['registered_transformer_id'],
                'Survey date' => $network['survey_date'] ?? null, 'Batch number' => $network['batch_id'],
                'Approved revision' => $network['revision'], 'Approval date (UTC)' => $network['approval_date'] ?? null,
                'RevisionSHA256' => $network['revision_sha256']];
            $this->mapDetails($folder, $common);
            $poles = $add($folder, 'Folder'); $add($poles, 'name', 'LT poles');
            foreach ($network['points'] as $id => $point) {
                $mark = $add($poles, 'Placemark'); $add($mark, 'name', (string) $point['label']);
                $this->mapDetails($mark, $common + ['EquipmentType' => $id == $network['root'] ? 'Transformer connection' : 'Secondary Pole',
                    'Complete waypoint' => $point['label'], 'Coordinate source' => $point['coordinate_source'] ?? 'Survey GPX',
                    'Latitude' => $point['latitude'], 'Longitude' => $point['longitude']] + ($point['pole_details'] ?? []));
                $geometry = $add($mark, 'Point'); $add($geometry, 'altitudeMode', 'clampToGround'); $add($geometry, 'coordinates', $coordinate($point));
            }
            $lines = $add($folder, 'Folder'); $add($lines, 'name', 'LT distribution lines');
            foreach ($network['lines'] as $line) {
                $mark = $add($lines, 'Placemark');
                $add($mark, 'name', $network['points'][$line['from']]['label'].' to '.$network['points'][$line['to']]['label']);
                $add($mark, 'styleUrl', '#'.$prefix);
                $this->mapDetails($mark, $common + ['SectionId' => $line['id'], 'LineType' => 'LT distribution', 'Phases' => $line['phases'],
                    'Conductors' => $line['conductors'], 'Start waypoint' => $network['points'][$line['from']]['label'],
                    'End waypoint' => $network['points'][$line['to']]['label'],
                    'Length (m)' => $line['length_m'] ?? null, 'Length method' => $line['length_method'] ?? null,
                    'Pole class' => $line['pole_class'], 'Pole height' => $line['pole_height'], 'Pole height unit' => $line['pole_height_unit']]);
                $geometry = $add($mark, 'LineString'); $add($geometry, 'tessellate', '1'); $add($geometry, 'altitudeMode', 'clampToGround');
                $add($geometry, 'coordinates', implode(' ', array_map($coordinate, $line['route'])));
            }
        }

        return $doc->saveXML();
    }

    /** Safe HTML balloons plus structured attributes for Earth and GIS readers. */
    private function mapDetails(DOMElement $feature, array $values): void
    {
        $doc = $feature->ownerDocument;
        $xp = new DOMXPath($doc);
        $description = $xp->query('./*[local-name()="description"]', $feature)->item(0);
        $html = $description?->textContent ?? '';
        if (! $description) {
            $description = $doc->createElementNS(self::NS, 'description');
            $next = $xp->query('./*[not(local-name()="name" or local-name()="visibility" or local-name()="open" or local-name()="Snippet")]', $feature)->item(0);
            $feature->insertBefore($description, $next);
        }
        $data = $xp->query('./*[local-name()="ExtendedData"]', $feature)->item(0);
        if (! $data) {
            $data = $doc->createElementNS(self::NS, 'ExtendedData');
            $next = $xp->query('./*[local-name()="Point" or local-name()="LineString" or local-name()="MultiGeometry" or local-name()="Folder" or local-name()="Placemark"]', $feature)->item(0);
            $feature->insertBefore($data, $next);
        }
        $html .= '<h3>Survey information</h3><table>';
        foreach ($values as $key => $value) {
            if (is_array($value)) $value = implode(', ', array_map(fn ($v, $k) => is_string($k) ? $k.': '.$v : (string) $v, $value, array_keys($value)));
            $text = $value === null || $value === '' ? 'Not recorded' : (string) $value;
            $escape = fn ($text) => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<tr><th>'.$escape((string) $key).'</th><td>'.$escape($text).'</td></tr>';
            $item = $doc->createElementNS(self::NS, 'Data'); $item->setAttribute('name', (string) $key);
            $node = $doc->createElementNS(self::NS, 'value'); $node->appendChild($doc->createTextNode($text));
            $item->appendChild($node); $data->appendChild($item);
        }
        while ($description->firstChild) $description->removeChild($description->firstChild);
        $description->appendChild($doc->createTextNode($html.'</table>'));
    }

}
