<?php

namespace App\Console\Commands;

use App\Enums\RecordStatus;
use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkSection;
use App\Models\Mdb\ServiceCable;
use App\Models\Mdb\SurveyBatch;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Mdb\ApprovedWarehouse;
use App\Services\Mdb\SnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NormalizeLtConductors extends Command
{
    protected $signature = 'mdb:normalize-lt-conductors
        {--batch= : Restrict to one batch ID}
        {--project= : Restrict to one project ID}
        {--admin= : Active Super Admin user ID required with --apply}
        {--apply : Update records; otherwise preview only}';

    protected $description = 'Preview or convert legacy LT conductor aliases to exact approved warehouse IDs';

    private const ALIASES = ['A' => 'ANT', 'W' => 'WASP', 'G' => 'GNAT', 'GN' => 'GNAT'];

    public function handle(): int
    {
        foreach (['batch', 'project', 'admin'] as $option) {
            if ($this->option($option) !== null && (! ctype_digit((string) $this->option($option)) || (int) $this->option($option) < 1)) {
                $this->error('--'.$option.' must be a positive ID.');

                return self::FAILURE;
            }
        }
        if (array_diff(array_values(self::ALIASES), ApprovedWarehouse::conductors())) {
            $this->error('The approved warehouse does not contain all migration targets. Nothing changed.');

            return self::FAILURE;
        }
        $actor = $this->option('admin') ? User::find($this->option('admin')) : null;
        if ($this->option('apply') && (! $actor || $actor->status !== RecordStatus::Active || ! $actor->hasRole('super_admin'))) {
            $this->error('--apply requires --admin=<active Super Admin user ID>.');

            return self::FAILURE;
        }
        $query = SurveyBatch::query()->when($this->option('batch'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('project'), fn ($q, $id) => $q->where('project_id', $id));
        if (! $query->exists()) {
            $this->error('No matching batches. Nothing changed.');

            return self::FAILURE;
        }
        $this->info($this->option('apply') ? 'APPLY: converting LT conductor aliases.' : 'PREVIEW ONLY: database records will not change.');
        $changed = 0;
        $unknown = [];
        foreach ($query->orderBy('id')->pluck('id') as $id) {
            $count = DB::transaction(function () use ($id, $actor, &$unknown) {
                $batch = SurveyBatch::whereKey($id)->lockForUpdate()->firstOrFail();
                $transformers = $batch->transformers()->pluck('id');
                $count = 0;
                $affected = [];
                foreach ([EntryRow::class, NetworkSection::class, ServiceCable::class] as $class) {
                    $field = $class === ServiceCable::class ? 'conductor_code' : 'conductors';
                    foreach ($class::whereIn('transformer_id', $transformers)->lockForUpdate()->get() as $record) {
                        $before = $record->$field;
                        $after = $field === 'conductors' ? $before : ['cable' => $before];
                        foreach ($after ?? [] as $phase => $value) {
                            if (isset(self::ALIASES[$value ?? ''])) {
                                $after[$phase] = self::ALIASES[$value];
                                $this->line('Batch '.$id.' / '.$record->getTable().' #'.$record->id.' '.$phase.': '.$value.' -> '.$after[$phase]);
                            } elseif ($value !== null && $value !== '' && ! in_array($value, ApprovedWarehouse::conductors(), true)) {
                                $unknown[$value] = ($unknown[$value] ?? 0) + 1;
                            }
                        }
                        if ($field !== 'conductors') {
                            $after = $after['cable'];
                        }
                        if ($before === $after) {
                            continue;
                        }
                        $count++;
                        if ($this->option('apply')) {
                            app(AuditService::class)->record($actor, 'mdb.conductor_alias_migrated', $record,
                                [$field => $before], [$field => $after], 'Legacy LT conductor alias conversion to approved warehouse IDs.');
                            $record->update([$field => $after] + ($record instanceof EntryRow ? ['manually_verified' => false] : []));
                            $affected[$record->transformer_id] = true;
                        }
                    }
                }
                if ($count && $this->option('apply')) {
                    app(SnapshotService::class)->invalidate($batch);
                    $batch->increment('revision');
                    if ($batch->staged_workflow && $batch->survey_status === 'awaiting_verification') {
                        $batch->update(['survey_status' => 'returned', 'survey_remarks' => 'Conductor aliases converted to approved warehouse IDs. Review and resubmit.']);
                    }
                    foreach ($batch->transformers()->whereKey(array_keys($affected))->get() as $transformer) {
                        $header = $transformer->header ?? [];
                        $header['manually_verified'] = false;
                        $transformer->update(['header' => $header]);
                    }
                    DB::table('mdb_review_checks')->whereIn('transformer_id', array_keys($affected))->delete();
                }

                return $count;
            });
            $changed += $count;
        }
        $this->info($changed.' record(s) '.($this->option('apply') ? 'updated.' : 'would change.'));
        if ($unknown) {
            $this->warn('Unmapped conductor values left unchanged; review these separately:');
            foreach ($unknown as $code => $count) {
                $this->line($code.': '.$count.' occurrence(s)');
            }
        }
        if ($changed && $this->option('apply')) {
            $this->warn('Changed batches require review; old exports were superseded. Frozen snapshots and source observations were preserved.');
        }

        return self::SUCCESS;
    }
}
