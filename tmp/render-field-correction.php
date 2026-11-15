<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Auth::login(App\Models\User::where('role','super_admin')->firstOrFail());
$survey=App\Models\FieldSurvey::with(['feeder','attachments'])->where('client_uuid','35340390-cb21-47c9-94db-a19adffeb85a')->firstOrFail();
Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag());
$html=view('field-surveys.edit',compact('survey'))->render();
file_put_contents(__DIR__.'/field-correction-preview.html',$html);
echo 'Rendered correction page successfully';
