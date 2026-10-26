<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo json_encode(['database'=>config('database.default'),'revisions'=>App\Models\Mdb\Revision::where('batch_id',17)->get(['id','batch_id','number'])->toArray(),'exports'=>App\Models\Mdb\ExportJob::where('batch_id',17)->get()->map(fn($e)=>$e->only(['id','revision_id','status','output_path','transformer_id']))->toArray()],JSON_PRETTY_PRINT);
$r=App\Models\Mdb\Revision::find(79);
if (!$r) exit;
$s=$r->snapshot;
$out=['revision_id'=>$r->id,'batch_id'=>$r->batch_id,'number'=>$r->number,'configuration'=>$s['configuration']??null,'template'=>$s['template']??null,'transformers'=>array_map(fn($t)=>array_diff_key($t,array_flip(['sections','entry_rows','pv_records'])), $s['transformers']??[])];
file_put_contents(__DIR__.'/revision-summary.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
