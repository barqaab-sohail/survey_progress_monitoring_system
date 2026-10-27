<?php

require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book = new Spreadsheet;
$book->getProperties()->setTitle('Simple SynerGEE input form');
$s = $book->getActiveSheet();
$s->setTitle('Simple Form');
$s->getDefaultRowDimension()->setRowHeight(25);
foreach (['A' => 10, 'B' => 32, 'C' => 19, 'D' => 19, 'E' => 29] as $column => $width) {
    $s->getColumnDimension($column)->setWidth($width);
}
$s->getStyle('A1:E29')->getFont()->setName('Calibri')->setSize(11);
$s->getStyle('A1:E29')->getAlignment()->setVertical('center')->setWrapText(true);
$merge = static function (string $range, string $value) use ($s): void {
    $s->mergeCells($range);
    $s->setCellValue(explode(':', $range)[0], $value);
};
$heading = static function (int $row, string $value) use ($s, $merge): void {
    $merge('A'.$row.':E'.$row, $value);
    $s->getStyle('A'.$row.':E'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF164D78');
    $s->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
};
$input = static function (string $range) use ($s): void {
    $s->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF2CC');
};
$heading(1, 'SIMPLE SYNERGEE INPUT FORM — DEPARTMENT CONFIRMATION');
$s->getRowDimension(1)->setRowHeight(31);
$merge('A2:E2', 'Fill yellow cells for department and equipment confirmation. Consumer kVA rates are fixed.');
$s->getRowDimension(2)->setRowHeight(32);
$merge('A3:B3', 'Department / project:');
$s->mergeCells('C3:D3');
$input('C3:D3');
$heading(5, '1. LOAD DEMAND');
$merge('C4:D4', 'kVA per consumer');
$s->setCellValue('E4', 'Allocation');
$s->getStyle('C4:E4')->getFont()->setBold(true);
$merge('A6:B6', 'Single-phase consumer');
$s->mergeCells('C6:D6');
$s->setCellValue('C6', 2);
$s->setCellValue('E6', 'Direct kVA');
$merge('A7:B7', 'Three-phase consumer');
$s->mergeCells('C7:D7');
$s->setCellValue('C7', 5);
$s->setCellValue('E7', 'Total across three phases');
$s->getRowDimension(7)->setRowHeight(33);
$merge('A8:E8', 'Power factor does not apply. Use kVA directly; kW and kvar remain unset.');
$s->getRowDimension(8)->setRowHeight(33);
$s->fromArray([['Code', 'Consumer category', 'kVA per consumer', 'Phase type', 'Allocation']], null, 'A9');
$s->getStyle('A9:E9')->getFont()->setBold(true);
$s->getRowDimension(9)->setRowHeight(32);
$s->fromArray([
    ['RS', 'Single-phase residential'], ['RL', 'Three-phase residential'],
    ['SC', 'Single-phase commercial'], ['LC', 'Commercial — confirm class'],
    ['SI', 'Industrial (SI) — confirm class'], ['LI', 'Industrial (LI) — confirm class'],
    ['PB', 'Public buildings'], ['AG', 'Agriculture'], ['ST', 'Street lights'],
], null, 'A10');
$s->getStyle('C6:D7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EDF2');
$s->getStyle('C10:D18')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EDF2');
foreach (range(10, 18) as $r) {
    $single = in_array($r, [10, 12, 18], true);
    $s->setCellValue('C'.$r, $single ? 2 : 5);
    $s->setCellValue('D'.$r, $single ? 'Single-phase' : 'Three-phase');
    $s->setCellValue('E'.$r, 'Direct kVA');
    $s->getRowDimension($r)->setRowHeight(31);
}
$merge('A19:E19', 'RS, SC and ST use 2 kVA. RL, LC, SI, LI, PB and AG use 5 kVA per consumer.');
$s->getRowDimension(19)->setRowHeight(36);
$heading(21, '2. TRANSFORMER TYPE — 11140223003, capacity 100 kVA');
$s->getRowDimension(21)->setRowHeight(31);
$merge('A22:B22', 'Exact SynerGEE library type ID for 100 kVA');
$s->mergeCells('C22:D22');
$input('C22:D22');
$s->setCellValue('E22', '100 KVA (exact reference ID)');
$s->getRowDimension(22)->setRowHeight(33);
$merge('A23:E23', 'Confirm the exact type ID against your installed SynerGEE library, or write its correct replacement.');
$s->getRowDimension(23)->setRowHeight(32);
$heading(25, '3. CONFIRMATION');
$merge('A26:B26', 'Confirmed by (name / designation):');
$s->mergeCells('C26:D26');
$input('C26:D26');
$merge('A27:B27', 'Date / department reference:');
$s->mergeCells('C27:D27');
$input('C27:D27');
$merge('A29:E29', 'Three-phase kVA is shared across connected phases. Each consumer contributes its kVA allocation once.');
$s->getRowDimension(29)->setRowHeight(32);
foreach (['C6' => 2, 'C7' => 5] as $cell => $rate) {
    $s->getCell($cell)->getDataValidation()->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_EQUAL)
        ->setFormula1((string) $rate)->setAllowBlank(false)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP);
}
foreach (range(10, 18) as $r) {
    $rate = in_array($r, [10, 12, 18], true) ? 2 : 5;
    $s->getCell('C'.$r)->getDataValidation()->setType(DataValidation::TYPE_DECIMAL)->setOperator(DataValidation::OPERATOR_EQUAL)
        ->setFormula1((string) $rate)->setAllowBlank(false)->setShowErrorMessage(true)->setErrorStyle(DataValidation::STYLE_STOP);
}
$s->getStyle('E6:E7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EDF2');
$s->getStyle('E10:E18')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EDF2');
$s->getStyle('E22')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE8EDF2');
$s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_PORTRAIT)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(1)->setPrintArea('A1:E29');
$s->getPageMargins()->setTop(0.25)->setBottom(0.25)->setLeft(0.25)->setRight(0.25);
$s->setShowGridlines(false);
$s->freezePane('C10');
$directory = __DIR__.'/../../public/templates';
if (! is_dir($directory)) {
    mkdir($directory, 0775, true);
}
$writer = new Xlsx($book);
foreach (['Simple-Synergee-Inputs.xlsx', 'Synergee-Department-Input-Proforma.xlsx'] as $name) {
    $writer->save($directory.'/'.$name);
}
echo 'Created single-sheet, single-page form: '.$directory.'/Simple-Synergee-Inputs.xlsx'.PHP_EOL;
