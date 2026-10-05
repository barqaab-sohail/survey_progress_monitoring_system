<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDriveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_drive' => [
            'enabled' => true,
            'client_id' => 'test-client',
            'client_secret' => 'test-secret',
            'redirect_uri' => 'https://hazeco.barqaab.pk/auth/google/callback',
        ]]);
        Http::preventStrayRequests();
    }

    public function test_connection_requires_login_and_credentials(): void
    {
        $this->post('/auth/google')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        config(['services.google_drive.client_secret' => null]);
        $this->post('/auth/google')->assertStatus(503);
    }

    public function test_authorization_uses_exact_callback_and_encrypted_hidden_tokens(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/auth/google');
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('https://hazeco.barqaab.pk/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('offline', $query['access_type']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response([
            'access_token' => 'private-access', 'refresh_token' => 'private-refresh',
            'expires_in' => 3600, 'scope' => 'https://www.googleapis.com/auth/drive.file',
        ])]);
        $callback = '/auth/google/callback?'.http_build_query(['state' => $query['state'], 'code' => 'test-code']);
        $this->get($callback)->assertRedirect(route('google-drive.index'))->assertSessionHas('success');
        Http::assertSent(fn ($request) => $request['redirect_uri'] === $query['redirect_uri']);
        $this->assertSame('private-refresh', $user->fresh()->google_drive_token['refresh_token']);
        $this->assertStringNotContainsString('private-access', DB::table('users')->where('id', $user->id)->value('google_drive_token'));
        $this->assertArrayNotHasKey('google_drive_token', $user->fresh()->toArray());
        $this->get($callback)->assertForbidden();
        $this->delete('/google-drive')->assertRedirect(route('google-drive.index'));
        $this->assertNull($user->fresh()->google_drive_token);
    }

    public function test_invalid_state_and_expired_state_never_exchange_tokens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/auth/google');
        $this->get('/auth/google/callback?state=invalid&code=test')->assertForbidden();
        $this->withSession(['google_drive_oauth' => [
            'state' => 'expired', 'user_id' => $user->id, 'expires_at' => now()->subMinute()->timestamp,
        ]])->get('/auth/google/callback?state=expired&code=test')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_denied_authorization_and_failed_exchange_do_not_store_tokens(): void
    {
        $user = User::factory()->create();
        $pending = ['state' => 'valid', 'user_id' => $user->id, 'expires_at' => now()->addMinute()->timestamp];
        $this->actingAs($user)->withSession(['google_drive_oauth' => $pending])
            ->get('/auth/google/callback?state=valid&error=access_denied')
            ->assertSessionHasErrors('google_drive');
        Http::assertNothingSent();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->withSession(['google_drive_oauth' => $pending])
            ->get('/auth/google/callback?state=valid&code=test')->assertSessionHasErrors('google_drive');
        $this->assertNull($user->fresh()->google_drive_token);
    }
}
