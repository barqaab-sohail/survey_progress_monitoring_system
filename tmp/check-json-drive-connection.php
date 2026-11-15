<?php
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=App\Models\DriveStorageConnection::find(1);
try {$token=$c?->token;echo json_encode(['storage_connected'=>(bool)$c,'token_readable'=>!empty($token['access_token']),'automatic_storage_enabled'=>(bool)config('drive_storage.enabled')]);}
catch(Throwable $e){echo json_encode(['storage_connected'=>(bool)$c,'token_readable'=>false,'reason'=>get_class($e)]);}
