<?php

namespace App\Services;

use App\Services\Mdb\EntryTopology;
use App\Support\FieldSurveyPairs;

class FieldSurveyEntryValidation
{
    public function errors(array $data): array
    {
        $errors = [];
        $rows = array_values($data['rows'] ?? []);
        $canonical = [];
        foreach (array_values(FieldSurveyPairs::groups($rows)) as $pair => $indices) {
            $start = null;
            foreach ($indices as $index) {
                if (($rows[$index]['se'] ?? null) === 'S') $start = $rows[$index];
            }
            foreach ($indices as $index) {
                $row = $rows[$index];
                if (($row['waypoint_check_required'] ?? false) && ($row['waypoint_confirmation'] ?? null) !== (($row['group'] ?? '').'|'.($row['date'] ?? '').'|'.($row['gps_waypoint'] ?? ''))) {
                    $errors["rows.$index.waypoint_confirmation"] = 'Check the waypoint against your GPS or survey sheet and confirm it again.';
                }
                if ($start && ($row['se'] ?? null) === 'E') {
                    foreach (['r', 'y', 'b', 'neutral'] as $phase) {
                        $from = $start['conductor_'.$phase] ?? null;
                        $to = $row['conductor_'.$phase] ?? null;
                        if ($from !== null && $from !== '' && $to !== null && $to !== '' && $from !== $to) {
                            $errors["rows.$index.conductor_$phase"] = 'S and E conductor selections must match within the pair.';
                        }
                    }
                }
                $identity = !empty($row['group']) && !empty($row['date']) && isset($row['gps_waypoint']) && $row['gps_waypoint'] !== ''
                    ? $row['group'].'|'.$row['date'].'|'.$row['gps_waypoint'] : null;
                $canonical[] = ['pair_number' => $pair + 1, 'designation' => $row['se'] ?? null,
                    'composite_identifier' => $identity, 'waypoint_reference' => $row['gps_waypoint'] ?? '', '_index' => $index];
            }
        }
        foreach (app(EntryTopology::class)->issues($canonical) as $issue) {
            $errors['rows.'.$issue['row']['_index'].'.gps_waypoint'] = $issue['message'];
        }
        foreach ($data['solar'] ?? [] as $index => $pv) {
            if (trim((string) ($pv['consumer_reference'] ?? '')) === '' && trim((string) ($pv['meter_number'] ?? '')) === '') {
                $errors["solar.$index.consumer_reference"] = 'Enter Consumer No. or Meter No. for this PV observation.';
            }
        }
        return $errors;
    }
}
