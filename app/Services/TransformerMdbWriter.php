<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TransformerMdbWriter
{
    public function write(array $network): string
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_file(config('mdb.powershell')) || ! is_file(config('mdb.template'))) {
            throw new RuntimeException('MDB export requires the Windows server, 32-bit Microsoft Access Database Engine, and the configured empty MDB template.');
        }
        $directory = storage_path('app/private/mdb-exports/'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $input = $directory.'/network.json';
        $output = $directory.'/transformer.mdb';
        try {
            File::put($input, json_encode($network, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
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
                base_path('scripts/mdb/write-mdb.ps1'), '-InputPath', $input, '-TemplatePath', config('mdb.template'), '-OutputPath', $output], null, $environment);
            $process->setTimeout((int) config('mdb.timeout', 90));
            $process->run();
            if (! $process->isSuccessful() || ! is_file($output) || filesize($output) < 4096) {
                report(new RuntimeException('MDB writer failed: '.$process->getErrorOutput()));
                throw new RuntimeException('The MDB could not be created. Check the server Access Database Engine and MDB export log. Your saved survey has been retained.');
            }
            File::delete($input);

            return $output;
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }
}
