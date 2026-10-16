<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
use App\Services\Mdb\ReferenceEngineeringImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportEngineeringReferences extends Command
{
    protected $signature = 'mdb:import-engineering-references {inspection_directory} {--project= : Exact project code} {--admin= : Admin ID for the audit entry} {--source-directory= : Original MDB directory for SHA-256 verification} {--report= : Separate JSON report path} {--apply : Populate an empty project configuration as an unapproved import}';

    protected $description = 'Compare ten read-only MDB inspections and optionally populate verified engineering observations';

    public function handle(ReferenceEngineeringImport $importer): int
    {
        $directory = realpath($this->argument('inspection_directory'));
        if (! $directory) {
            $this->error('Inspection directory does not exist.');

            return self::FAILURE;
        }
        $documents = [];
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.mdb.json') as $file) {
            $documents[] = json_decode(ltrim(File::get($file), "\xEF\xBB\xBF"), true, 64, JSON_THROW_ON_ERROR);
        }
        $analysis = $importer->analyze($documents);
        $sourceDirectory = realpath((string) $this->option('source-directory'));
        if ($this->option('apply') && ! $sourceDirectory) {
            $this->error('Applying requires the original source directory for integrity verification.');

            return self::FAILURE;
        }
        if ($sourceDirectory) {
            foreach ($analysis['settings']['reference_import']['sources'] as $source) {
                if (basename($source['file']) !== $source['file'] || ! is_file($sourceDirectory.DIRECTORY_SEPARATOR.$source['file'])
                    || ! hash_equals($source['sha256'], hash_file('sha256', $sourceDirectory.DIRECTORY_SEPARATOR.$source['file']))) {
                    $this->error('Original reference hash differs: '.$source['file']);

                    return self::FAILURE;
                }
            }
        }
        if ($this->option('report')) {
            $reportPath = $this->option('report');
            if (strtolower(pathinfo($reportPath, PATHINFO_EXTENSION)) !== 'json' || file_exists($reportPath)) {
                $this->error('Report must be a new, separate JSON file.');

                return self::FAILURE;
            }
            File::ensureDirectoryExists(dirname($reportPath));
            File::put($reportPath, json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        }
        if ($this->option('apply')) {
            $project = Project::where('code', $this->option('project'))->firstOrFail();
            $actor = User::findOrFail($this->option('admin'));
            $configuration = $importer->import($project, $actor, $analysis);
            $this->info('Imported configuration '.$configuration->id.' revision '.$configuration->revision.' for '.$project->code.'; Admin review remains required.');
        }
        $this->info('Compared ten reference MDBs; '.$analysis['settings']['reference_import']['consistent_parameter_count'].' nonempty configuration parameters are consistent across all ten.');

        return self::SUCCESS;
    }
}
