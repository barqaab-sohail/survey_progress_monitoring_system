<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class DataEntryMdbExport
{
    public function __construct(private SnapshotService $snapshots, private DataEntryPayload $mapper) {}

    public function prepare(SurveyBatch $batch): array
    {
        $revision = $batch->approvedRevision;
        $this->require($revision instanceof Revision && $revision->batch_id === $batch->id && $batch->status === 'approved'
            && $revision->number === $batch->revision, 'A current approved PDF entry snapshot is required.');
        if ($batch->staged_workflow) {
            $this->require($batch->survey_status === 'approved' && $batch->decisions()->where('revision_id', $revision->id)->where('decision', 'approve')
                ->whereNotIn('decided_by', $batch->entry_actor_ids ?? [])->exists(), 'The current snapshot requires independent survey verification.');
        }
        $this->require(hash_equals($revision->sha256, $this->snapshots->hash($revision->snapshot)), 'The approved snapshot integrity check failed.');
        // Deliberately no live rows, source processing, generation inputs or engineering template lookup.
        $payload = $this->mapper->build($revision->snapshot);

        return $payload + ['revision_id' => $revision->id, 'revision_sha256' => $revision->sha256, 'revision_number' => $revision->number];
    }

    public function generate(array $payload): string
    {
        $powershell = config('data_entry_mdb.powershell');
        $this->require(PHP_OS_FAMILY === 'Windows' && is_file((string) $powershell), 'Data Entry MDB export requires Windows PowerShell and Access DAO/ACE.');
        $directory = storage_path('app/private/data-entry-mdb');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $base = $directory.'/'.Str::uuid();
        $input = $base.'.json';
        $output = $base.'.mdb';
        try {
            file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', base_path('scripts/mdb/export-data-entry.ps1'),
                '-InputPath', $input, '-TemplatePath', resource_path('mdb/data-entry/empty.mdb'), '-SchemaPath', resource_path('mdb/data-entry/schema.json'), '-OutputPath', $output]);
            $process->setTimeout(config('data_entry_mdb.timeout'));
            $process->run();
            $this->require($process->isSuccessful(), 'Data Entry MDB validation failed: '.mb_substr(trim($process->getErrorOutput()), 0, 1500));
            $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $this->require(($result['validated'] ?? false) === true && ($result['observations'] ?? -1) === count($payload['rows'])
                && is_file($output) && hash_equals($result['sha256'], hash_file('sha256', $output)), 'Generated MDB integrity/count validation failed.');

            return $output;
        } catch (Throwable $exception) {
            if (is_file($output)) {
                unlink($output);
            }
            throw $exception;
        } finally {
            if (is_file($input)) {
                unlink($input);
            }
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['data_entry_mdb' => $message]);
        }
    }
}
