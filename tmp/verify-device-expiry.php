<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::where('status', 'active')->whereIn('role', ['super_admin', 'survey_team_leader'])->firstOrFail();
$plain = bin2hex(random_bytes(64));
$token = App\Models\MobileDeviceToken::create(['user_id' => $user->id, 'device_name' => 'Temporary expiry verification', 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);
try {
    $expiry = $token->expires_at->toDateTimeString();
    $url = 'http://192.168.1.70/survey_progress_monitoring_system/public/api/v1/field/bootstrap';
    for ($i = 0; $i < 2; $i++) {
        $response = Illuminate\Support\Facades\Http::acceptJson()->withToken($plain)->timeout(15)->get($url);
        if (!$response->successful()) throw new RuntimeException('Bootstrap verification failed: HTTP '.$response->status());
        $token->refresh();
        if (!$token->last_used_at || $token->expires_at->toDateTimeString() !== $expiry || !$token->expires_at->isFuture()) throw new RuntimeException('Session expiry changed after activity.');
        $token->update(['last_used_at' => now()->subMinutes(6)]);
    }
    echo "PASS: Apache/MySQL authentication succeeds on repeated requests and preserves the thirty-day expiry.\n";
} finally {
    $token->delete();
}
