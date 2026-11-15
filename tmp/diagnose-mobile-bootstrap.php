<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$u=App\Models\User::where('name','like','%Shafiq%')->firstOrFail();
echo json_encode(['user'=>$u->only(['id','name']), 'mobile_teams'=>app(App\Services\FieldSurveyAccess::class)->mobileTeams($u)->get(['survey_teams.id','name','group_code'])->toArray()]).PHP_EOL;
$plain=bin2hex(random_bytes(64));
$t=App\Models\MobileDeviceToken::create(['user_id'=>$u->id,'device_name'=>'Temporary local diagnostic','token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addMinutes(2)]);
try {
$r=Illuminate\Support\Facades\Http::acceptJson()->withToken($plain)->timeout(45)->get('http://127.0.0.1:8000/api/v1/field/bootstrap?compact=1');
$d=$r->json();
$raw=Illuminate\Support\Facades\Http::acceptJson()->withToken($plain)->withHeaders(['Accept-Encoding'=>'gzip'])->withOptions(['decode_content'=>false])->get('http://127.0.0.1:8000/api/v1/field/bootstrap?compact=1');
file_put_contents(__DIR__.'/expected-bootstrap.gz',$raw->body());
file_put_contents(__DIR__.'/mobile-bootstrap-response.json', $r->body());
file_put_contents(__DIR__.'/mobile-diagnostic-token.json', json_encode(['token'=>$plain]));
exec('python ' . escapeshellarg(__DIR__.'/probe-emulator-api.py'), $probe); echo implode(PHP_EOL,$probe).PHP_EOL;
unlink(__DIR__.'/mobile-diagnostic-token.json');
echo json_encode(['status'=>$r->status(),'content_type'=>$r->header('Content-Type'),'bytes'=>strlen($r->body()),'valid_json'=>is_array($d),'message'=>$d['message']??null,'body_prefix'=>is_array($d)?null:substr($r->body(),0,900)]);
} finally {$t->delete();}
