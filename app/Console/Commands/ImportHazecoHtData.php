<?php

namespace App\Console\Commands;

use App\Models\HtDataImport;
use App\Models\Project;
use App\Models\User;
use App\Services\HazecoHtDataImportService;
use Illuminate\Console\Command;

class ImportHazecoHtData extends Command
{
    protected $signature = 'hazeco:import-ht-data {file : Absolute path to the XLSX workbook} {--project=HAZECO-TDL : Project code} {--user= : Importing user email}';

    protected $description = 'Import the HAZECO feeder worksheet into the HT feeder master data';

    public function handle(HazecoHtDataImportService $service): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Workbook is not readable: {$path}");

            return self::FAILURE;
        }

        $project = Project::where('code', $this->option('project'))->first();
        if (! $project) {
            $this->error('Project not found: '.$this->option('project'));

            return self::FAILURE;
        }

        $user = $this->option('user')
            ? User::where('email', $this->option('user'))->first()
            : User::where('role', 'super_admin')->first();

        $import = HtDataImport::create([
            'project_id' => $project->id,
            'imported_by' => $user?->id,
            'file_name' => basename($path),
            'sheet_name' => HazecoHtDataImportService::SHEET,
        ]);

        try {
            $result = $service->import($import, $path);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Import', 'Status', 'Rows', 'Created', 'Updated', 'Rejected'],
            [[$result->id, $result->status, $result->total_rows, $result->created_rows, $result->updated_rows, $result->rejected_rows]],
        );

        return $result->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
