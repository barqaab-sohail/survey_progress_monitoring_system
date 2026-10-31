<?php

namespace App\Console\Commands;

use App\Services\Audit\Storage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditReport extends Command
{
    protected $signature = 'audit:report {--after-id=0} {--limit=5000} {--seconds=45}';

    protected $description = 'Read-only audit payload sizes by column/action; no payloads, names, reasons or credentials';

    public function handle(Storage $storage): int
    {
        $started = microtime(true);
        $seconds = max(1, min(120, (int) $this->option('seconds')));
        $limit = max(1, min(10000, (int) $this->option('limit')));
        $cursor = max(0, (int) $this->option('after-id'));
        $maximum = (int) DB::table('audit_logs')->max('id');
        $report = ['database_fingerprint' => $storage->identity(), 'start_after_id' => $cursor,
            'snapshot_max_id' => $maximum, 'rows_measured' => 0, 'column_bytes' => [], 'events' => [], 'largest' => [],
            'shape_counts' => ['batch_snapshots' => 0, 'top_level_base64' => 0, 'dataset_details' => 0, 'equal_nonempty_details' => 0]];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
            $report['allocated'] = DB::selectOne('SELECT ENGINE, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes, DATA_FREE AS free_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['audit_logs']);
            $report['columns'] = DB::select('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['audit_logs']);
            $report['indexes'] = DB::select('SHOW INDEX FROM audit_logs');
        }
        while ($report['rows_measured'] < $limit && microtime(true) - $started < $seconds) {
            $query = $storage->sizes(DB::table('audit_logs')->select('id', 'action')->where('id', '>', $cursor)->where('id', '<=', $maximum)
                ->orderBy('id')->limit(min(25, $limit - $report['rows_measured'])));
            foreach (['batch_snapshots' => ['batch', 'transformers'], 'top_level_base64' => ['template_base64', 'mdb_base64', 'pdf_base64'], 'dataset_details' => ['source_data', 'payload', 'rows']] as $flag => $keys) {
                $checks = [];
                foreach (['old_values', 'new_values'] as $column) {
                    $side = array_map(fn ($key) => "JSON_EXTRACT($column, '$.$key') IS NOT NULL", $keys);
                    $checks[] = '('.implode($flag === 'batch_snapshots' ? ' AND ' : ' OR ', $side).')';
                }
                $query->selectRaw('CASE WHEN '.implode(' OR ', $checks).' THEN 1 ELSE 0 END AS '.$flag);
            }
            $query->selectRaw('CASE WHEN old_values IS NOT NULL AND new_values IS NOT NULL AND old_values = new_values THEN 1 ELSE 0 END AS equal_nonempty_details');
            $rows = $query->get();
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                foreach (array_keys($report['shape_counts']) as $flag) {
                    $report['shape_counts'][$flag] += (int) $row->$flag;
                }
                $bytes = 0;
                foreach (Storage::COLUMNS as $column) {
                    $size = (int) $row->{$column.'_bytes'};
                    $report['column_bytes'][$column] = ($report['column_bytes'][$column] ?? 0) + $size;
                    $bytes += $size;
                }
                $event = $report['events'][$row->action] ?? ['rows' => 0, 'bytes' => 0, 'maximum_bytes' => 0, 'column_bytes' => []];
                $event['rows']++;
                $event['bytes'] += $bytes;
                $event['maximum_bytes'] = max($event['maximum_bytes'], $bytes);
                foreach (Storage::COLUMNS as $column) {
                    $event['column_bytes'][$column] = ($event['column_bytes'][$column] ?? 0) + (int) $row->{$column.'_bytes'};
                }
                $report['events'][$row->action] = $event;
                $report['largest'][] = ['id' => $row->id, 'action' => $row->action, 'bytes' => $bytes,
                    'old_bytes' => $row->old_values_bytes, 'new_bytes' => $row->new_values_bytes];
                usort($report['largest'], fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
                $report['largest'] = array_slice($report['largest'], 0, 10);
                $cursor = (int) $row->id;
                $report['rows_measured']++;
            }
        }
        uasort($report['events'], fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        $report['next_after_id'] = $cursor;
        $report['complete_range'] = ! DB::table('audit_logs')->where('id', '>', $cursor)->where('id', '<=', $maximum)->exists();
        $report['note'] = 'Logical column bytes for the measured ID range only; not InnoDB allocated disk size. Shape flags detect selected keys, not every nested file/secret. Batch/time limits are cooperative between queries.';
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
