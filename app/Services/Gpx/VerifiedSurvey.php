<?php

namespace App\Services\Gpx;

use App\Models\Mdb\SurveyBatch;
use App\Services\Mdb\EntryTopology;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use Illuminate\Validation\ValidationException;

class VerifiedSurvey
{
    public function source(SurveyBatch $batch, int $transformerId): array
    {
        $fail = fn ($message) => throw ValidationException::withMessages(['transformer_id' => $message]);
        $revision = $batch->approvedRevision;
        if ($batch->status !== 'approved' || ! $revision || $revision->batch_id !== $batch->id
            || $revision->number !== $batch->revision || ($batch->staged_workflow && $batch->survey_status !== 'approved')) {
            $fail('Select a transformer with a current approved survey revision.');
        }
        if (! hash_equals($revision->sha256, app(SnapshotService::class)->hash($revision->snapshot))) {
            $fail('The approved revision checksum differs. Reverify the survey.');
        }
        $snapshot = $revision->snapshot;
        $transformer = collect($snapshot['transformers'] ?? [])->firstWhere('id', $transformerId);
        if (! $transformer) {
            $fail('The selected transformer is not in the approved revision.');
        }
        $rows = $transformer['entry_rows'] ?? [];
        if (! $rows || count($rows) > 20002) {
            $fail('The transformer needs complete S/E rows (at most 10,000 line spans).');
        }
        $issues = app(EntryTopology::class)->issues($rows);
        if ($issues) {
            $fail(implode(' ', array_column($issues, 'message')));
        }
        $pairs = collect($rows)->groupBy('pair_number')->sortKeys(SORT_NUMERIC);
        $points = $sequences = [];
        $root = null;
        foreach ($pairs as $number => $pair) {
            if ($pair->count() !== 2 || $pair->where('designation', 'S')->count() !== 1 || $pair->where('designation', 'E')->count() !== 1) {
                $fail('Pair '.$number.' needs one S and one E row.');
            }
            $ends = [];
            foreach ($pair as $row) {
                if ($number == $pairs->keys()->first() && $row['designation'] === 'S') continue;
                $identity = $row['composite_identifier'] ?? null;
                if (! $identity) $fail('Pair '.$number.' needs complete group/date/waypoint identifiers.');
                $matches = app(SurveyWaypoint::class)->matches($row, $snapshot['waypoints'] ?? []);
                if (count($matches) !== 1 || ! app(SurveyWaypoint::class)->validCoordinates($matches[0])) {
                    $fail('Pair '.$number.' '.$row['designation'].': waypoint '.$row['waypoint_reference'].' has missing, ambiguous or invalid stored coordinates.');
                }
                $point = $matches[0];
                if (! empty($point['correction']) && ! empty($point['correction_approved_by']) && ! empty($point['correction_approved_at']) && ! empty($point['correction']['reason'])) {
                    $point = array_replace($point, $point['correction']);
                }
                $id = 's'.$identity;
                $value = ['id' => $id, 'name' => $row['waypoint_reference'], 'lat' => (float) $point['latitude'],
                    'lon' => (float) $point['longitude'], 'elevation' => isset($point['elevation']) ? (float) $point['elevation'] : null,
                    'valid' => true, 'group' => 'survey', 'position' => count($points), 'kind' => 'waypoints'];
                if (isset($points[$id]) && array_intersect_key($points[$id], array_flip(['lat', 'lon', 'elevation'])) !== array_intersect_key($value, array_flip(['lat', 'lon', 'elevation']))) {
                    $fail('The same survey identifier has conflicting stored coordinates.');
                }
                $points[$id] = $value;
                $ends[$row['designation']] = $id;
            }
            if ($root === null) $root = $ends['E'];
            else $sequences[] = $ends['S'].' > '.$ends['E'];
        }
        if (! $sequences) $fail('The transformer has no surveyed line spans.');
        $sequence = implode("\n", $sequences);
        return ['points' => $points, 'groups' => [['id' => 'survey', 'name' => 'Transformer '.$transformer['code'], 'kind' => 'waypoints', 'points' => array_keys($points)]],
            'warnings' => [], 'survey' => ['verified' => true, 'transformer_code' => $transformer['code'], 'batch_id' => $batch->id,
                'revision' => $revision->number, 'revision_sha256' => $revision->sha256, 'root_point_id' => $root, 'span_sequences' => $sequence]];
    }
}
