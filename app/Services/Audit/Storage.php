<?php

namespace App\Services\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Storage
{
    public const COLUMNS = ['old_values', 'new_values', 'reason', 'user_agent'];

    /** Allocation metadata only: never scan or load the audit JSON to render this page. */
    public function statistics(): array
    {
        $stats = ['available' => false, 'database_bytes' => null, 'audit_bytes' => null,
            'audit_data_bytes' => null, 'audit_index_bytes' => null];
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return $stats;
        }
        try {
            $row = DB::selectOne('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) AS database_bytes,
                MAX(CASE WHEN TABLE_NAME = ? THEN DATA_LENGTH END) AS audit_data_bytes,
                MAX(CASE WHEN TABLE_NAME = ? THEN INDEX_LENGTH END) AS audit_index_bytes
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
                ['audit_logs', 'audit_logs', 'BASE TABLE']);
            if ($row && $row->audit_data_bytes !== null && $row->audit_index_bytes !== null) {
                return ['available' => true, 'database_bytes' => (int) $row->database_bytes,
                    'audit_bytes' => (int) $row->audit_data_bytes + (int) $row->audit_index_bytes,
                    'audit_data_bytes' => (int) $row->audit_data_bytes, 'audit_index_bytes' => (int) $row->audit_index_bytes];
            }
        } catch (\Throwable) {
            // Statistics permissions must not break the audit viewer or expose connection details.
        }

        return $stats;
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Not available';
        }
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $unit = $bytes > 0 ? min(4, (int) floor(log($bytes, 1024))) : 0;

        return number_format($bytes / (1024 ** $unit), $unit === 0 ? 0 : 2).' '.$units[$unit];
    }

    public function length(string $column): string
    {
        return DB::getDriverName() === 'sqlite'
            ? 'length(CAST(COALESCE('.$column.", '') AS BLOB))"
            : 'OCTET_LENGTH(COALESCE('.$column.", ''))";
    }

    public function sizes(Builder $query): Builder
    {
        foreach (self::COLUMNS as $column) {
            $query->selectRaw($this->length($column).' AS '.$column.'_bytes');
        }

        return $query;
    }

    public function expired(?string $at = null): Builder
    {
        if ((int) config('audit.routine_days') < 1 || (int) config('audit.important_days') < (int) config('audit.routine_days')) {
            throw new RuntimeException('Audit retention requires positive days and important history must be kept at least as long as routine logs.');
        }
        $at = $at ? CarbonImmutable::parse($at) : now()->toImmutable();
        $routine = $at->subDays(max(1, (int) config('audit.routine_days')));
        $important = $at->subDays(max(1, (int) config('audit.important_days')));

        return DB::table('audit_logs')->whereNotIn('action', config('audit.protected_actions'))->where(function ($q) use ($routine, $important) {
            $q->where(fn ($q) => $q->whereIn('action', config('audit.routine_actions'))->where('created_at', '<', $routine))
                ->orWhere(fn ($q) => $q->whereNotIn('action', config('audit.routine_actions'))->where('created_at', '<', $important));
        });
    }

    public function identity(): string
    {
        $db = DB::connection();

        return hash('sha256', implode('|', [$db->getDriverName(), $db->getConfig('host'), $db->getConfig('port'), $db->getDatabaseName()]));
    }

    /** Never fetch a legacy multi-megabyte JSON value to render a 50-row page. */
    public function boundedJson(string $column): string
    {
        $limit = max(2048, (int) config('audit.payload_bytes'));
        $notice = json_encode(['_audit' => ['details_omitted' => true,
            'reason' => 'Legacy payload exceeds display limit; original remains in database.']]);
        $quoted = DB::connection()->getPdo()->quote($notice);

        return 'CASE WHEN '.$this->length($column).' <= '.$limit.' THEN '.$column.' ELSE '.$quoted.' END AS '.$column;
    }
}
