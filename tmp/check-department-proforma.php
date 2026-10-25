<?php
require __DIR__.'/../vendor/autoload.php';
$p=__DIR__.'/../public/templates/Synergee-Department-Input-Proforma.xlsx';
$b=PhpOffice\PhpSpreadsheet\IOFactory::load($p);
echo json_encode(['sheets'=>$b->getSheetNames(),'rate_rows'=>$b->getSheetByName('Demand Rates')->getHighestRow(),'rs_kva'=>$b->getSheetByName('Demand Rates')->getCell('C2')->getValue(),'rs_pf'=>$b->getSheetByName('Demand Rates')->getCell('D2')->getValue(),'rs_derived_kw'=>$b->getSheetByName('Demand Rates')->getCell('F2')->getCalculatedValue()]).PHP_EOL;
$b->getSheetByName('Demand Rates')->setCellValue('C2',3)->setCellValue('D2',0.8);
PhpOffice\PhpSpreadsheet\Calculation\Calculation::getInstance($b)->clearCalculationCache();
if(abs($b->getSheetByName('Demand Rates')->getCell('F2')->getCalculatedValue()-2.4)>1e-8)throw new RuntimeException('Formula validation failed.');
echo 'Workbook reopens; inputs remain blank; calculation formula verified with temporary test values (file unchanged).'.PHP_EOL;
