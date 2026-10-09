<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class HttpExporter implements Exporter
{
    public function configured(): bool
    {
        $url = (string) config('mdb_workflow.export.http_url');

        return str_starts_with($url, 'https://') && strlen((string) config('mdb_workflow.export.secret')) >= 32;
    }

    public function export(array $payload, Template $template, string $outputPath): array
    {
        if (! $this->configured()) {
            throw new WorkerNotConfigured('MDB export worker not configured: an HTTPS worker URL and a secret of at least 32 characters are required.');
        }
        // Only versioned model data and approved template identity leave Laravel. No file paths are transmitted.
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        $stamp = (string) time();
        $secret = (string) config('mdb_workflow.export.secret');
        $signature = hash_hmac('sha256', $stamp."\n".hash('sha256', $body), $secret);
        $response = Http::timeout((int) config('mdb_workflow.export.timeout', 180))
            ->withHeaders(['Authorization' => 'MDB-HMAC '.$stamp.':'.$signature])
            ->withBody($body, 'application/json')->post(rtrim(config('mdb_workflow.export.http_url'), '/').'/v1/exports');
        $response->throw();
        $responseBody = $response->body();
        if (strlen($responseBody) > (int) config('mdb_workflow.export.max_output_bytes') * 1.4 + 1048576) {
            throw new RuntimeException('MDB worker response exceeds the configured size limit.');
        }
        if (! hash_equals(hash_hmac('sha256', $responseBody, $secret), (string) $response->header('X-MDB-Signature'))) {
            throw new RuntimeException('MDB worker response signature is invalid.');
        }
        $result = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        $bytes = base64_decode($result['mdb_base64'] ?? '', true);
        if ($bytes === false || strlen($bytes) > (int) config('mdb_workflow.export.max_output_bytes')) {
            throw new RuntimeException('MDB worker returned an invalid or oversized artifact.');
        }
        file_put_contents($outputPath, $bytes);
        unset($result['mdb_base64']);

        return $result;
    }
}
