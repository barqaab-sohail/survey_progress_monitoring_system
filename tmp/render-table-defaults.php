<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['session.driver'=>'array']);
$request = Illuminate\Http\Request::create('http://localhost/engineering');
$request->setLaravelSession(app('session')->driver());
$app->instance('request', $request);
view()->share('errors', new Illuminate\Support\ViewErrorBag);
$html = view('engineering.partials.table-defaults', ['tableSettings'=>[]])->render();
$css = file_get_contents(public_path('css/app.css')).file_get_contents(public_path('css/mdb-table-defaults.css'));
$js = file_get_contents(public_path('js/mdb-table-defaults.js'));
file_put_contents(__DIR__.'/mdb-table-defaults-preview.html', '<!doctype html><html lang="en"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MDB table settings preview</title><style>'.$css.' body{padding:20px;background:#f3f6fa} main{max-width:1250px;margin:auto}</style><main><form>'.$html.'</form></main><script>'.$js.'</script></html>');
echo 'Rendered table settings preview'.PHP_EOL;
