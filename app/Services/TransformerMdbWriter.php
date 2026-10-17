<?php

namespace App\Services;

use App\Models\Mdb\Template;
use App\Services\Mdb\ApprovedSchema;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TransformerMdbWriter
{
    public function write(array $network): string
    {
        $templatePath = config('mdb.template');
        if (isset($network['engineering_template_id'])) {
            $template = Template::findOrFail($network['engineering_template_id']);
            $templatePath = Storage::disk($template->disk)->path($template->path);
            $simpleSchema = ($network['simple_mdb_generation'] ?? false) === true && ($template->metadata['system_reference_schema'] ?? false) === true;
            if (! $template->active || (! $simpleSchema && (! $template->approved_by || ! $template->approved_at)) || ! is_file($templatePath) || ! hash_equals($network['engineering_template_sha256'], hash_file('sha256', $templatePath))) {
                throw new RuntimeException('The centrally approved MDB template is unavailable or changed.');
            }
        }
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('mdb.powershell')) || ! is_file($templatePath)) {
            throw new RuntimeException('MDB export requires Windows PowerShell matching the installed Access DAO/ACE engine and the configured empty MDB template.');
        }
        $directory = storage_path('app/private/mdb-exports/'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $input = $directory.'/network.json';
        $output = $directory.'/transformer.mdb';
        try {
            File::put($input, json_encode($network, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            // Web SAPIs can omit Windows runtime variables from $_SERVER.
            // Preserve them explicitly for .NET/ACE startup in the child process.
            $environment = [];
            foreach (['SystemRoot', 'WINDIR', 'COMSPEC', 'PATH', 'TEMP', 'TMP', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'ProgramData', 'PSModulePath'] as $key) {
                $value = getenv($key);
                if ($value !== false) {
                    $environment[$key] = $value;
                }
            }
            $process = new Process([config('mdb.powershell'), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File',
                base_path('scripts/mdb/write-mdb.ps1'), '-InputPath', $input, '-TemplatePath', $templatePath, '-OutputPath', $output], null, $environment);
            $process->setTimeout((int) config('mdb.timeout', 90));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) < 4096) {
                report(new RuntimeException('MDB writer failed: '.$process->getErrorOutput()));
                throw new RuntimeException('The MDB could not be created. Check the server Access Database Engine and MDB export log. Your saved survey has been retained.');
            }
            $result = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            $readback = $result['readback'] ?? [];
            ApprovedSchema::assertReadback($readback);
            foreach (['readable', 'schema_verified', 'references_verified', 'mapped_values_verified'] as $flag) {
                if (($readback[$flag] ?? false) !== true) {
                    throw new RuntimeException('MDB readback verification is incomplete: '.$flag.'.');
                }
            }
            foreach ($network['tables'] as $table => $rows) {
                if (($readback['counts'][$table] ?? -1) !== count($rows)) {
                    throw new RuntimeException('MDB readback count differs for '.$table.'.');
                }
            }
            if (! hash_equals(hash_file('sha256', $input), (string) ($result['payload_sha256'] ?? '')) ||
                ! hash_equals(hash_file('sha256', $output), (string) ($result['output_sha256'] ?? ''))) {
                throw new RuntimeException('MDB readback is not bound to the saved survey export.');
            }
            // Preserve the complete survey and schema evidence outside the sample MDB.
            File::put($directory.'/readback.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return $output;
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }
}
