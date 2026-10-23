<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

class DataEntryMdbExport
{
    public function __construct(private SnapshotService $snapshots, private DataEntryPayload $mapper) {}

    public function prepare(SurveyBatch $batch, ?int $transformerId = null): array
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
        $snapshot = $revision->snapshot;
        if ($transformerId !== null) {
            $snapshot['transformers'] = array_values(array_filter($snapshot['transformers'] ?? [], fn ($transformer) => (int) $transformer['id'] === $transformerId));
            $this->require(count($snapshot['transformers']) === 1, 'Choose a transformer from this approved revision.');
        }
        $payload = $this->mapper->build($snapshot);

        return $payload + ['revision_id' => $revision->id, 'revision_sha256' => $revision->sha256, 'revision_number' => $revision->number];
    }

    public function generate(array $payload, ?string $driver = null): string
    {
        $driver ??= config('data_entry_mdb.driver', 'auto');
        if ($driver === 'auto') {
            $driver = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'http';
        }
        $this->require(in_array($driver, ['windows', 'http'], true), 'Choose a supported Data Entry MDB export driver (auto, windows or http).');
        $powershell = config('data_entry_mdb.powershell');
        if ($driver === 'windows') {
            $this->require(PHP_OS_FAMILY === 'Windows' && is_file((string) $powershell), 'Local Data Entry MDB export requires Windows PowerShell and Access DAO/ACE. On Linux/cPanel, configure DATA_ENTRY_MDB_DRIVER=http and a Windows MDB worker.');
        }
        $directory = storage_path('app/private/data-entry-mdb');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $base = $directory.'/'.Str::uuid();
        $input = $base.'.json';
        $output = $base.'.mdb';
        try {
            if ($driver === 'http') {
                $result = $this->generateHttp($payload, $output);
            } else {
                file_put_contents($input, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
                $process = new Process([$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', base_path('scripts/mdb/export-data-entry.ps1'),
                    '-InputPath', $input, '-TemplatePath', resource_path('mdb/data-entry/empty.mdb'), '-SchemaPath', resource_path('mdb/data-entry/schema.json'), '-OutputPath', $output]);
                $process->setTimeout(config('data_entry_mdb.timeout'));
                $process->run();
                $this->require($process->isSuccessful(), 'Data Entry MDB validation failed: '.mb_substr(trim($process->getErrorOutput()), 0, 1500));
                $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            }
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

    private function generateHttp(array $payload, string $output): array
    {
        $url = (string) config('data_entry_mdb.http_url');
        $secret = (string) config('data_entry_mdb.secret');
        $this->require(str_starts_with($url, 'https://') && strlen($secret) >= 32,
            'Data Entry MDB generation on Linux/cPanel needs a Windows MDB worker. Configure DATA_ENTRY_MDB_WORKER_URL (HTTPS) and DATA_ENTRY_MDB_WORKER_SECRET (at least 32 characters), then refresh the Laravel configuration cache.');
        $body = json_encode(['version' => $payload['version'], 'rows' => $payload['rows'],
            'schema_sha256' => hash('sha256', str_replace("\r\n", "\n", file_get_contents(resource_path('mdb/data-entry/schema.json'))))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $stamp = (string) time();
        $signature = hash_hmac('sha256', $stamp."\n".hash('sha256', $body), $secret);
        try {
            $response = Http::timeout((int) config('data_entry_mdb.timeout', 180))->connectTimeout(15)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['Authorization' => 'MDB-HMAC '.$stamp.':'.$signature])
                ->withBody($body, 'application/json')->post(rtrim($url, '/').'/v1/data-entry-exports');
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['data_entry_mdb' => 'The Data Entry MDB worker could not be reached or timed out. Check the Windows worker service and HTTPS connection, then retry.']);
        }
        $limit = (int) config('data_entry_mdb.max_output_bytes', 104857600);
        $responseBody = $response->body();
        $this->require(strlen($responseBody) <= $limit * 1.4 + 1048576, 'Data Entry MDB worker response exceeds the size limit.');
        $this->require(hash_equals(hash_hmac('sha256', $responseBody, $secret), (string) $response->header('X-MDB-Signature')), 'Data Entry MDB worker response authentication failed. Check that both servers use the same worker secret.');
        $this->require($response->successful(), $response->status() === 404
            ? 'Update and restart the Windows MDB worker: it does not support Data Entry MDB exports yet.'
            : 'The Windows Data Entry MDB worker could not generate the file. Check its service logs and Access DAO/ACE installation, then retry.');
        $result = json_decode($responseBody, true);
        $this->require(is_array($result) && ($result['payload_sha256'] ?? null) === hash('sha256', $body), 'Data Entry MDB worker returned an invalid response or a different payload.');
        $this->require(is_string($result['mdb_base64'] ?? null), 'Data Entry MDB worker response does not contain an MDB file.');
        $bytes = base64_decode($result['mdb_base64'], true);
        $this->require(is_string($bytes) && strlen($bytes) >= 20 && strlen($bytes) <= $limit
            && substr($bytes, 0, 20) === "\x00\x01\x00\x00Standard Jet DB\x00", 'Data Entry MDB worker returned an invalid or oversized MDB file.');
        $this->require(($result['validated'] ?? false) === true && ($result['observations'] ?? -1) === count($payload['rows'])
            && ($result['gps_points'] ?? -1) === 0 && ($result['sha256'] ?? null) === hash('sha256', $bytes), 'Data Entry MDB worker integrity/count validation failed.');
        file_put_contents($output, $bytes);
        unset($result['mdb_base64']);

        return $result;
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['data_entry_mdb' => $message]);
        }
    }
}
