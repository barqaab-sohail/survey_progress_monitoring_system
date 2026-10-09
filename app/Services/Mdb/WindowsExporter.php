<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

class WindowsExporter implements Exporter
{
    public function configured(): bool
    {
        return PHP_OS_FAMILY === 'Windows' && is_file((string) config('mdb_workflow.export.powershell'));
    }

    public function export(array $payload, Template $template, string $outputPath): array
    {
        if (! $this->configured()) {
            throw new WorkerNotConfigured('MDB export worker not configured: Windows PowerShell with Access DAO/ACE is required.');
        }
        $templatePath = Storage::disk($template->disk)->path($template->path);
        if (! is_file($templatePath) || ! hash_equals($template->sha256, hash_file('sha256', $templatePath))) {
            throw new RuntimeException('Approved MDB template file is missing or its SHA-256 has changed.');
        }
        $inputPath = dirname($outputPath).'/payload.json';
        file_put_contents($inputPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $process = new Process([
            config('mdb_workflow.export.powershell'), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', base_path('scripts/mdb/export-reviewed-mdb.ps1'), '-InputPath', $inputPath,
            '-TemplatePath', $templatePath, '-OutputPath', $outputPath,
        ]);
        $process->setTimeout((int) config('mdb_workflow.export.timeout', 180));
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('MDB worker failed: '.mb_substr(trim($process->getErrorOutput()), 0, 4000));
        }

        return json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
    }
}
