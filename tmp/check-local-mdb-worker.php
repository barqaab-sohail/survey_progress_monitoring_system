<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('local')) throw new RuntimeException('Local environment expected.');
$runtime = json_decode(file_get_contents(storage_path('app/private/mdb-local-worker/runtime.json')), true, 512, JSON_THROW_ON_ERROR);
$payload = app(App\Services\Mdb\DataEntryPayload::class)->build(Tests\Fixtures\DataEntrySnapshot::make());
$body = json_encode(['version' => 1, 'rows' => $payload['rows'], 'schema_sha256' => hash('sha256', str_replace("\r\n", "\n", file_get_contents(resource_path('mdb/data-entry/schema.json'))))], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
$stamp = (string)time();
$signature = hash_hmac('sha256', $stamp."\n".hash('sha256', $body), $runtime['secret']);
$response = Illuminate\Support\Facades\Http::timeout(180)->withOptions(['allow_redirects' => false])
    ->withHeaders(['Authorization' => 'MDB-HMAC '.$stamp.':'.$signature])->withBody($body, 'application/json')
    ->post('http://127.0.0.1:8766/v1/data-entry-exports');
if (!hash_equals(hash_hmac('sha256', $response->body(), $runtime['secret']), (string)$response->header('X-MDB-Signature'))) throw new RuntimeException('Invalid worker signature.');
$result = $response->json();
if (!$response->successful()) throw new RuntimeException('Worker failed: '.($result['error'] ?? 'unknown error'));
$bytes = base64_decode($result['mdb_base64'], true);
if (($result['validated'] ?? false) !== true || $result['observations'] !== count($payload['rows']) || $result['gps_points'] !== 0 || $result['payload_sha256'] !== hash('sha256', $body) || hash('sha256', $bytes) !== $result['sha256'] || substr($bytes, 0, 20) !== "\x00\x01\x00\x00Standard Jet DB\x00") throw new RuntimeException('Worker output validation failed.');
$file = storage_path('app/private/mdb-local-worker/synthetic-local-check.mdb');
file_put_contents($file, $bytes);
echo json_encode(['http_status' => $response->status(), 'validated' => true, 'synthetic_sample' => true,
    'observations' => $result['observations'], 'bytes' => strlen($bytes), 'file' => $file], JSON_UNESCAPED_SLASHES).PHP_EOL;
