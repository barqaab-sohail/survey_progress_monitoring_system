<?php

namespace App\Services\Mdb;

use Illuminate\Support\Str;

/** Mobile source evidence substitutes only for PDF provenance, never model checks. */
class MobileSurveyProvenance
{
    public static function cable(array $snapshot, array $cable, array $transformer): bool
    {
        $rows = self::rows($snapshot);
        if (!$rows || ($cable['mobile_survey_uuid'] ?? null) !== ($snapshot['mobile_survey']['uuid'] ?? null)) return false;
        $original = collect($snapshot['mobile_survey']['data']['service_cables'] ?? [])->firstWhere('cable_id', $cable['mobile_cable_id'] ?? null);
        $pole = collect($transformer['entry_rows'] ?? [])->firstWhere('id', $cable['entry_row_id'] ?? null);
        if (!$original || !$pole || !self::row($snapshot, $pole, $rows) || ($pole['mobile_row_id'] ?? null) !== $original['pole_row_id']) return false;
        foreach (['category'=>'category', 'consumer_number'=>'consumer_number', 'group_number'=>'group', 'row_date'=>'date', 'waypoint_reference'=>'gps_waypoint',
            'meter_number'=>'meter_number', 'consumer_reference'=>'consumer_reference', 'phase'=>'phase', 'conductor_code'=>'conductor_code',
            'measured_length_m'=>'measured_length_m', 'length_evidence'=>'length_evidence',
            'route_points'=>'route_points', 'route_length_m'=>'route_length_m'] as $key => $source) {
            if (($cable[$key] ?? null) != ($original[$source] ?? null)) return false;
        }
        return true;
    }

    public static function rows(array $snapshot): array
    {
        $mobile = $snapshot['mobile_survey'] ?? [];
        $data = $mobile['data'] ?? null;
        if (! Str::isUuid($mobile['uuid'] ?? '') || ! is_array($data)
            || ($mobile['revision'] ?? null) !== ($snapshot['batch']['revision'] ?? null)
            || ! hash_equals((string) ($mobile['data_sha256'] ?? ''), app(SnapshotService::class)->hash($data))) {
            return [];
        }
        $rows = $data['rows'] ?? [];
        $ids = array_column($rows, 'row_id');
        if (count($rows) !== count($ids) || count($ids) !== count(array_unique($ids)) || ! collect($ids)->every(fn ($id) => is_string($id) && Str::isUuid($id))) {
            return [];
        }

        return array_column($rows, null, 'row_id');
    }

    public static function row(array $snapshot, array $row, ?array $rows = null): bool
    {
        $original = ($rows ?? self::rows($snapshot))[$row['mobile_row_id'] ?? ''] ?? null;

        return $original && ($row['mobile_survey_uuid'] ?? null) === ($snapshot['mobile_survey']['uuid'] ?? null)
            && ($row['designation'] ?? null) === ($original['se'] ?? null)
            && ($row['group_number'] ?? null) === ($original['group'] ?? null)
            && ($row['row_date'] ?? null) === ($original['date'] ?? null)
            && ($row['original_waypoint_reference'] ?? null) === ($original['gps_waypoint'] ?? null);
    }
}
