<?php
require __DIR__.'/../vendor/autoload.php';
$b=PhpOffice\PhpSpreadsheet\IOFactory::load(__DIR__.'/../public/templates/Simple-Synergee-Inputs.xlsx');
$s=$b->getActiveSheet();
echo json_encode(['worksheets'=>$b->getSheetCount(),'reference_header'=>$s->getCell('E9')->getValue(),'reference_kva'=>$s->getCell('E6')->getValue(),'reference_pf'=>$s->getCell('E7')->getValue(),'reference_transformer'=>$s->getCell('E22')->getValue(),'confirmed_kva'=>$s->getCell('C6')->getValue(),'confirmed_pf'=>$s->getCell('C7')->getValue(),'confirmed_transformer'=>$s->getCell('C22')->getValue(),'print_area'=>$s->getPageSetup()->getPrintArea(),'single_page'=>$s->getPageSetup()->getFitToWidth()===1 && $s->getPageSetup()->getFitToHeight()===1],JSON_PRETTY_PRINT).PHP_EOL;
