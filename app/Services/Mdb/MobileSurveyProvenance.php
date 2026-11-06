<?php

namespace App\Services\Mdb;

use Illuminate\Support\Str;

/** Mobile source evidence substitutes only for PDF provenance, never model checks. */
class MobileSurveyProvenance
{
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
