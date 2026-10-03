<?php

namespace App\Services;

use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\Project;
use App\Models\SubDivision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FeederImportService
{
    public function __construct(private readonly AuditService $audit) {}

    public function import(UploadedFile $file, User $actor): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        $headers = array_map(fn ($value) => trim((string) $value), fgetcsv($handle) ?: []);
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        $required = ['project_code', 'circle_code', 'division_code', 'sub_division_code', 'grid_station_code', 'feeder_code', 'feeder_name', 'total_transformers'];
        if (array_diff($required, $headers)) {
            fclose($handle);

            return ['total' => 0, 'imported' => 0, 'updated' => 0, 'rejected' => 1, 'errors' => ['Header is missing: '.implode(', ', array_diff($required, $headers))]];
        }

        $result = ['total' => 0, 'imported' => 0, 'updated' => 0, 'rejected' => 0, 'errors' => []];
        $line = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $line++;
            if (! array_filter($values, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }
            $result['total']++;
            $values = array_pad($values, count($headers), null);
            $row = array_combine($headers, array_slice($values, 0, count($headers)));
            $validator = Validator::make($row, [
                'project_code' => ['required', 'string', 'max:50'], 'circle_code' => ['required', 'string', 'max:50'],
                'division_code' => ['required', 'string', 'max:50'], 'sub_division_code' => ['required', 'string', 'max:50'],
                'grid_station_code' => ['required', 'string', 'max:50'], 'feeder_code' => ['required', 'string', 'max:100'],
                'feeder_name' => ['required', 'string', 'max:255'], 'total_transformers' => ['required', 'integer', 'min:1'],
                'survey_drive_url' => ['nullable', 'url:http,https'], 'mdb_drive_url' => ['nullable', 'url:http,https'], 'processing_drive_url' => ['nullable', 'url:http,https'],
            ]);
            if ($validator->fails()) {
                $result['rejected']++;
                $result['errors'][] = "Row {$line}: ".$validator->errors()->first();

                continue;
            }

            try {
                DB::transaction(function () use ($row, $actor, &$result) {
                    $project = Project::firstOrCreate(['code' => trim($row['project_code'])], ['name' => trim(($row['project_name'] ?? null) ?: $row['project_code']), 'timezone' => 'Asia/Karachi', 'processing_required' => true, 'status' => 'active']);
                    $circle = Circle::firstOrCreate(['project_id' => $project->id, 'code' => trim($row['circle_code'])], ['name' => trim(($row['circle_name'] ?? null) ?: $row['circle_code'])]);
                    $division = Division::firstOrCreate(['project_id' => $project->id, 'code' => trim($row['division_code'])], ['circle_id' => $circle->id, 'name' => trim(($row['division_name'] ?? null) ?: $row['division_code'])]);
                    $subDivision = SubDivision::firstOrCreate(['project_id' => $project->id, 'code' => trim($row['sub_division_code'])], ['division_id' => $division->id, 'name' => trim(($row['sub_division_name'] ?? null) ?: $row['sub_division_code'])]);
                    $grid = GridStation::firstOrCreate(['project_id' => $project->id, 'code' => trim($row['grid_station_code'])], ['sub_division_id' => $subDivision->id, 'name' => trim(($row['grid_station_name'] ?? null) ?: $row['grid_station_code'])]);
                    $feeder = Feeder::firstOrNew(['project_id' => $project->id, 'feeder_code' => trim($row['feeder_code'])]);
                    $exists = $feeder->exists;
                    $old = $exists ? $feeder->toArray() : [];
                    $feeder->fill([
                        'circle_id' => $circle->id, 'division_id' => $division->id, 'sub_division_id' => $subDivision->id, 'grid_station_id' => $grid->id,
                        'feeder_name' => trim($row['feeder_name']), 'total_transformers' => (int) $row['total_transformers'],
                        'baseline_pending' => false, 'demo_baseline' => false,
                        'survey_drive_url' => $row['survey_drive_url'] ?: null, 'mdb_drive_url' => $row['mdb_drive_url'] ?: null,
                        'processing_drive_url' => $row['processing_drive_url'] ?: null,
                        'processing_required' => ! in_array(strtolower((string) ($row['processing_required'] ?? 'yes')), ['0', 'no', 'false'], true),
                        'status' => strtolower((string) ($row['status'] ?? 'active')) === 'inactive' ? 'inactive' : 'active',
                    ])->save();
                    $this->audit->record($actor, $exists ? 'feeder.import_updated' : 'feeder.imported', $feeder, $old, $feeder->toArray());
                    $exists ? $result['updated']++ : $result['imported']++;
                });
            } catch (\Throwable $exception) {
                $result['rejected']++;
                $result['errors'][] = "Row {$line}: ".$exception->getMessage();
            }
        }
        fclose($handle);

        return $result;
    }
}
