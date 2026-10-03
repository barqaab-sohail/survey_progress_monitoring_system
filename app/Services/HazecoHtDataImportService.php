<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\Circle;
use App\Models\Division;
use App\Models\Feeder;
use App\Models\GridStation;
use App\Models\HtDataImport;
use App\Models\SubDivision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class HazecoHtDataImportService
{
    public const SHEET = '27_Feeders';

    public function import(HtDataImport $import, string $absolutePath): HtDataImport
    {
        $import->update([
            'status' => 'processing',
            'file_sha256' => hash_file('sha256', $absolutePath),
            'sheet_name' => self::SHEET,
            'errors' => null,
        ]);

        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($absolutePath);
            $sheet = $spreadsheet->getSheetByName(self::SHEET);

            if (! $sheet) {
                throw new RuntimeException('Required worksheet "'.self::SHEET.'" was not found.');
            }

            $this->validateHeaders($sheet);
            $total = $created = $updated = $rejected = 0;
            $errors = [];

            for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
                $values = [];
                foreach (range(1, 14) as $column) {
                    $values[$column] = trim((string) $sheet->getCell([$column, $row])->getFormattedValue());
                }

                if (collect($values)->filter(fn (string $value) => $value !== '')->isEmpty()) {
                    continue;
                }

                // The supplied workbook ends with a totals-only row (load and consumers,
                // but no serial or feeder identity). It is a summary, not feeder data.
                if ($values[1] === '' && $values[2] === '') {
                    continue;
                }

                $total++;

                try {
                    $wasCreated = DB::transaction(fn (): bool => $this->importRow($import, $values));
                    $wasCreated ? $created++ : $updated++;
                } catch (Throwable $exception) {
                    $rejected++;
                    if (count($errors) < 200) {
                        $errors[] = ['row' => $row, 'message' => $exception->getMessage()];
                    }
                }
            }

            $spreadsheet->disconnectWorksheets();
            $import->update([
                'status' => $rejected > 0 ? 'completed_with_errors' : 'completed',
                'total_rows' => $total,
                'created_rows' => $created,
                'updated_rows' => $updated,
                'rejected_rows' => $rejected,
                'errors' => $errors ?: null,
                'imported_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $import->update([
                'status' => 'failed',
                'errors' => [['row' => null, 'message' => $exception->getMessage()]],
                'imported_at' => now(),
            ]);

            throw $exception;
        }

        return $import->fresh();
    }

    /** @param array<int, string> $values */
    private function importRow(HtDataImport $import, array $values): bool
    {
        $serial = $this->integer($values[1]);
        $feederName = $values[2];
        $sourceFeederCode = $this->code($values[3]);
        $gridCode = $this->code($values[6]);
        $gridName = $values[7];
        $circleName = $values[8];
        $circleCode = $this->code($values[9]);
        $divisionName = $values[10];
        $divisionCode = $this->code($values[11]);
        $subDivisionName = $values[12];
        $subDivisionCode = $this->code($values[13]);

        foreach ([
            'serial number' => $serial,
            'feeder name' => $feederName,
            'grid name' => $gridName,
            'circle name' => $circleName,
            'division name' => $divisionName,
            'subdivision name' => $subDivisionName,
        ] as $label => $value) {
            if ($value === null || $value === '') {
                throw new RuntimeException("Missing {$label}.");
            }
        }

        $circleCode ??= $this->pendingCode('CIRCLE', $circleName);
        $divisionCode ??= $this->pendingCode('DIVISION', $divisionName);
        $subDivisionCode ??= $this->pendingCode('SUBDIVISION', $subDivisionName);
        $gridCode ??= $this->pendingCode('GRID', $gridName);
        $feederCode = $sourceFeederCode ?: 'PENDING-'.str_pad((string) $serial, 4, '0', STR_PAD_LEFT);

        $circle = Circle::updateOrCreate(
            ['project_id' => $import->project_id, 'code' => $circleCode],
            ['name' => $circleName],
        );
        $division = Division::updateOrCreate(
            ['project_id' => $import->project_id, 'code' => $divisionCode],
            ['circle_id' => $circle->id, 'name' => $divisionName],
        );
        $subDivision = SubDivision::updateOrCreate(
            ['project_id' => $import->project_id, 'code' => $subDivisionCode],
            ['division_id' => $division->id, 'name' => $subDivisionName],
        );
        $grid = GridStation::updateOrCreate(
            ['project_id' => $import->project_id, 'code' => $gridCode],
            ['sub_division_id' => $subDivision->id, 'name' => $gridName],
        );

        $feeder = Feeder::firstOrNew([
            'project_id' => $import->project_id,
            'feeder_code' => $feederCode,
        ]);
        $isNew = ! $feeder->exists;
        $feeder->fill([
            'circle_id' => $circle->id,
            'division_id' => $division->id,
            'sub_division_id' => $subDivision->id,
            'grid_station_id' => $grid->id,
            'feeder_name' => $feederName,
            'source_serial' => $serial,
            'source_feeder_code' => $sourceFeederCode,
            'load_kw' => $this->decimal($values[4]),
            'number_of_consumers' => $this->integer($values[5]),
            'nature' => $values[14] ?: null,
            'source_file' => $import->file_name,
            'source_sheet' => self::SHEET,
            'imported_at' => now(),
            'processing_required' => true,
            'status' => RecordStatus::Active,
        ]);

        if ($isNew) {
            $feeder->total_transformers = 0;
            $feeder->baseline_pending = true;
        }

        $feeder->save();

        return $isNew;
    }

    private function validateHeaders($sheet): void
    {
        $expected = ['Sr', 'Feeder Name', 'Feeder Code', 'Load (kW)', 'No of Consumers', 'Grid Code', 'Grid Name', 'Circle', 'Circle Code', 'Division', 'Division Code', 'Subdivision', 'Subdivision Code', 'Nature'];
        foreach ($expected as $index => $header) {
            $actual = trim((string) $sheet->getCell([$index + 1, 1])->getFormattedValue());
            if (Str::lower($actual) !== Str::lower($header)) {
                throw new RuntimeException('Unexpected header in column '.($index + 1).": expected '{$header}', found '{$actual}'.");
            }
        }
    }

    private function code(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return preg_replace('/\.0+$/', '', $value);
    }

    private function integer(string $value): ?int
    {
        $value = str_replace([',', ' '], '', trim($value));

        return $value === '' || ! is_numeric($value) ? null : (int) round((float) $value);
    }

    private function decimal(string $value): ?float
    {
        $value = str_replace([',', ' '], '', trim($value));

        return $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    private function pendingCode(string $prefix, string $name): string
    {
        return $prefix.'-PENDING-'.strtoupper(substr(sha1(Str::lower(trim($name))), 0, 10));
    }
}
