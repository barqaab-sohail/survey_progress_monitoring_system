<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\Mdb\NetworkTransformer;
use App\Support\FieldTransformerIdentity;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ServiceCableExcelExport
{
    public const TARGET_TRANSFORMERS = 5;

    public function filename(Feeder $feeder): string
    {
        return 'Service_Cables_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $feeder->feeder_code).'_'.now('Asia/Karachi')->format('Ymd_His').'.xlsx';
    }

    /** Current saved observations, including drafts; no approval, GPX or sample-size gate. */
    public function records(Feeder $feeder): Collection
    {
        $records = collect();
        foreach (FieldSurvey::where('feeder_id', $feeder->id)->orderBy('id')->cursor() as $survey) {
            foreach ($survey->service_cables ?? [] as $index => $cable) {
                $pole = collect($survey->rows ?? [])->firstWhere('row_id', $cable['pole_row_id'] ?? null);
                $records->push($this->record($cable, [
                    'record_id' => 'mobile:'.$survey->client_uuid.':'.($cable['cable_id'] ?? $index),
                    'transformer' => $survey->transformer_code, 'rating' => $survey->header['capacity_kva'] ?? null,
                    'pole' => $pole['gps_waypoint'] ?? null, 'meter_waypoint' => $cable['gps_waypoint'] ?? null,
                    'survey_date' => $survey->survey_date?->format('Y-m-d'), 'status' => $survey->status,
                    'source' => 'Mobile survey', 'source_id' => $survey->client_uuid,
                ]));
            }
        }
        foreach (NetworkTransformer::whereHas('batch', fn ($q) => $q->where('feeder_id', $feeder->id))
            ->with(['batch.waypoints', 'serviceCables', 'entryRows'])->orderBy('id')->get() as $transformer) {
            $status = $transformer->batch->survey_status ?? $transformer->batch->status;
            $points = $transformer->batch->waypoints->toArray();
            $meters = $transformer->batch->waypoints->keyBy('id');
            $poleCoordinates = [];
            foreach ($transformer->serviceCables as $cable) {
                $pole = $transformer->entryRows->firstWhere('id', $cable->entry_row_id);
                $data = $cable->surveyData();
                if (empty($data['route_points']) && ($data['measured_length_m'] ?? null) === null && $pole) {
                    $service = app(\App\Services\Mdb\ServiceCableService::class);
                    if (! array_key_exists($pole->id, $poleCoordinates)) {
                        $matches = app(\App\Services\Mdb\SurveyWaypoint::class)->matches($pole->surveyData(), $points);
                        $poleCoordinates[$pole->id] = count($matches) === 1 ? $service->previewCoordinates($matches[0]) : null;
                    }
                    $meter = $meters->get($cable->meter_waypoint_id);
                    $a = $poleCoordinates[$pole->id];
                    $b = $meter ? $service->previewCoordinates($meter->toArray()) : null;
                    if ($a && $b) $data['coordinate_length_m'] = hypot($a['x'] - $b['x'], $a['y'] - $b['y']);
                }
                $records->push($this->record($data, [
                    'record_id' => 'pdf:'.$cable->id, 'transformer' => $transformer->code,
                    'rating' => $transformer->capacity_kva ?? $transformer->header['capacity_kva'] ?? null,
                    'pole' => $pole?->composite_identifier ?? $pole?->waypoint_reference,
                    'meter_waypoint' => $cable->composite_identifier,
                    'survey_date' => $cable->row_date, 'status' => $status,
                    'source' => 'PDF survey', 'source_id' => (string) $transformer->batch_id,
                ]));
            }
        }

        return $records->sortBy(fn ($row) => $row['transformer_key'].'|'.$row['record_id'])->values();
    }

    private function record(array $cable, array $context): array
    {
        $route = $cable['route_points'] ?? [];
        // Prefer saved route or measured lengths over the entry preview coordinate estimate.
        $length = $route ? (count($route) >= 2 ? ($cable['route_length_m'] ?? null) : null) : ($cable['measured_length_m'] ?? $cable['coordinate_length_m'] ?? null);
        $length = is_numeric($length) && is_finite((float) $length) && (float) $length > 0 ? (float) $length : null;
        return $context + [
            'transformer_key' => FieldTransformerIdentity::key($context['transformer']) ?: $context['transformer'],
            'cable_id' => $cable['cable_id'] ?? $cable['id'] ?? null,
            'category' => $cable['category'] ?? null, 'consumer_number' => $cable['consumer_number'] ?? null,
            'consumer_reference' => $cable['consumer_reference'] ?? null, 'meter_number' => $cable['meter_number'] ?? null,
            'phase' => $cable['phase'] ?? null, 'conductor' => $cable['conductor_code'] ?? null,
            'length_m' => $length, 'length_source' => $length === null ? 'Not recorded' : ($route ? 'Mapped route' : (isset($cable['measured_length_m']) ? 'Recorded measurement' : 'Coordinate estimate')),
            'evidence' => $cable['length_evidence'] ?? null, 'route_points' => $route,
            'remarks' => $cable['remarks'] ?? null,
            'group' => $cable['group'] ?? $cable['group_number'] ?? null,
            'cable_date' => $cable['date'] ?? $cable['row_date'] ?? null,
            'waypoint_reference' => $cable['gps_waypoint'] ?? $cable['waypoint_reference'] ?? null,
            'measured_length_m' => isset($cable['measured_length_m']) ? (float) $cable['measured_length_m'] : null,
            'route_length_m' => count($route) >= 2 && isset($cable['route_length_m']) ? (float) $cable['route_length_m'] : null,
            'source_pdf_id' => $cable['source_pdf_id'] ?? null, 'source_page' => $cable['source_page'] ?? null,
            'source_row' => $cable['source_row'] ?? null,
        ];
    }

    public function workbook(Feeder $feeder, ?Collection $records = null, ?string $filename = null): Spreadsheet
    {
        $records ??= $this->records($feeder);
        $book = IOFactory::load(resource_path('templates/lt-network-gis-output-5oct2026.xlsx'));
        $consumer = $book->getSheetByName('Consumer Sheet');
        $lengths = $book->getSheetByName('Length');
        $groups = $records->groupBy('transformer_key');
        $footer = 49;
        if ($groups->count() > 46) {
            $lengths->insertNewRowBefore(49, $groups->count() - 46);
            $footer += $groups->count() - 46;
        }
        $filename ??= $this->filename($feeder);
        $index = 0;
        foreach ($groups as $cables) {
            $first = $cables->first();
            $single = $cables->whereIn('phase', ['R', 'Y', 'B']);
            $three = $cables->where('phase', 'RYB');
            $unknown = $cables->whereNull('length_m')->count();
            $row = 3 + $index;
            $lengths->duplicateStyle($lengths->getStyle('A3:F3'), 'A'.$row.':F'.$row);
            $this->row($lengths, $row, [$index + 1, $first['transformer'], $filename,
                $this->sum($cables), $this->sum($single), $this->sum($three)]);
            $row = 8 + $index;
            $consumer->duplicateStyle($consumer->getStyle('A8:K8'), 'A'.$row.':K'.$row);
            $consumer->getRowDimension($row)->setRowHeight($consumer->getRowDimension(8)->getRowHeight());
            $ratings = $cables->pluck('rating')->filter(fn ($rating) => $rating !== null && $rating !== '')->unique();
            $this->row($consumer, $row, [$first['transformer'], $single->where('phase', 'R')->count(),
                $single->where('phase', 'Y')->count(), $single->where('phase', 'B')->count(),
                $ratings->count() === 1 ? (float) $ratings->first() : null, $single->count(), $three->count(),
                null, null, null, $cables->count().' saved cables; '.$unknown.' without length. Counts include recorded service cables only.']);
            $index++;
        }
        $this->cell($lengths, 'A'.$footer, 'Total recorded length (m)');
        $this->cell($lengths, 'D'.$footer, $this->sum($records));

        $details = $this->detailSheet($book, 'Service Cable Records', [
            'Record ID', 'Feeder code', 'Feeder name', 'Transformer ID', 'Survey date', 'Survey status', 'Source', 'Source ID',
            'Consumer category', 'Consumer number', 'Consumer reference', 'Meter number', 'Starting pole waypoint', 'Meter waypoint',
            'Phase', 'Cable conductor', 'Service cable length (m)', 'Length source', 'Length evidence', 'Route point count', 'Remarks',
            'Survey group', 'Cable observation date', 'Meter waypoint reference', 'Recorded measured length (m)',
            'Saved mapped route length (m)', 'Source PDF ID', 'PDF page', 'PDF row / reference',
        ]);
        $routes = $this->detailSheet($book, 'Route Points', ['Record ID', 'Feeder code', 'Transformer ID', 'Consumer reference',
            'Point order', 'Waypoint', 'Latitude (degrees)', 'Longitude (degrees)']);
        $pointRow = 2;
        foreach ($records as $i => $record) {
            $this->row($details, $i + 2, [$record['record_id'], $feeder->feeder_code, $feeder->feeder_name, $record['transformer'],
                $record['survey_date'], $record['status'], $record['source'], $record['source_id'], $record['category'],
                $record['consumer_number'], $record['consumer_reference'], $record['meter_number'], $record['pole'],
                $record['meter_waypoint'], $record['phase'], $record['conductor'], $record['length_m'], $record['length_source'],
                $record['evidence'], count($record['route_points']), $record['remarks'], $record['group'], $record['cable_date'],
                $record['waypoint_reference'], $record['measured_length_m'], $record['route_length_m'], $record['source_pdf_id'],
                $record['source_page'], $record['source_row']]);
            foreach ($record['route_points'] as $position => $point) {
                $this->row($routes, $pointRow++, [$record['record_id'], $feeder->feeder_code, $record['transformer'],
                    $record['consumer_reference'], $position + 1, $point['waypoint'] ?? null, $point['latitude'], $point['longitude']]);
            }
        }
        $details->getStyle('Q2:Q'.max(2, $records->count() + 1))->getNumberFormat()->setFormatCode('0.000');
        $details->getStyle('Y2:Z'.max(2, $records->count() + 1))->getNumberFormat()->setFormatCode('0.000');
        $details->setAutoFilter('A1:AC'.max(1, $records->count() + 1));
        $routes->setAutoFilter('A1:H'.max(1, $pointRow - 1));
        $routes->getStyle('G2:H'.max(2, $pointRow - 1))->getNumberFormat()->setFormatCode('0.000000000');
        $info = $this->detailSheet($book, 'Export Info', ['Field', 'Value']);
        foreach ([['Feeder code', $feeder->feeder_code], ['Feeder name', $feeder->feeder_name],
            ['Survey planning target (transformers)', self::TARGET_TRANSFORMERS], ['Transformers with saved cables', $groups->count()],
            ['Saved service cable records', $records->count()], ['Records without a saved actual length', $records->whereNull('length_m')->count()],
            ['Exported at (Asia/Karachi)', now('Asia/Karachi')->format('Y-m-d H:i:s')],
            ['Scope', 'All current saved mobile and PDF service cable records for the selected transformers in this feeder, including drafts. Deleted surveys are excluded.'],
            ['Lengths', 'Saved mapped-route lengths or recorded measurements take priority. Available PDF pole-to-meter coordinate estimates are included and labeled Coordinate estimate. Blank means unavailable. No consumer extrapolation.'],
            ['Summary totals', 'Sum of known saved lengths only; unknown lengths are not zero. Phase subtotals use recorded phases only.'],
            ['Consumer Sheet', 'Counts reflect saved service cable records only. Unrecorded loads, PV counts and installed capacity remain blank.'],
            ['Planning', 'Five transformers is a planning target, never an export minimum. No transformer or cable count limit is applied.'],
        ] as $i => $values) $this->row($info, $i + 2, $values);
        $info->getColumnDimension('A')->setWidth(40);
        $info->getColumnDimension('B')->setWidth(110);
        $info->getStyle('B2:B13')->getAlignment()->setWrapText(true);
        $book->setActiveSheetIndex(1);

        return $book;
    }

    private function sum(Collection $records): ?float
    {
        $known = $records->whereNotNull('length_m');
        return $known->isEmpty() ? null : (float) $known->sum('length_m');
    }

    private function detailSheet(Spreadsheet $book, string $title, array $headers): Worksheet
    {
        $sheet = $book->createSheet()->setTitle($title);
        $this->row($sheet, 1, $headers);
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A1:'.$last.'1')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '18314D']], 'alignment' => ['wrapText' => true]]);
        $sheet->getRowDimension(1)->setRowHeight(32);
        foreach (range(1, count($headers)) as $col) $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setWidth(24);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$last.'1');
        return $sheet;
    }

    private function row(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $i => $value) $this->cell($sheet, Coordinate::stringFromColumnIndex($i + 1).$row, $value);
    }

    private function cell(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        // Codes/references retain leading zeroes and cannot become Excel formulas.
        $sheet->setCellValueExplicit($coordinate, $value, $value === null ? DataType::TYPE_NULL : (is_int($value) || is_float($value) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING));
    }

    public function write(Feeder $feeder, string $path, ?string $filename = null, ?Collection $records = null): void
    {
        $book = $this->workbook($feeder, $records, $filename);
        try {
            (new Xlsx($book))->save($path);
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
