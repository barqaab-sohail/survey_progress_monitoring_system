<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const SUCCESS_CONFIRMATION = 'A password reset link has been sent to your registered email address. Check your inbox.';

    private const INCORRECT_EMAIL = 'This email address is not registered to an active account. Please enter the correct email address.';

    private const COOLDOWN_ERROR = 'A reset link was recently requested. Please wait one minute before trying again.';

    private const DELIVERY_UNCONFIGURED = 'Your email address is registered, but password reset email delivery is not configured. Please contact an administrator.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->user = User::factory()->create([
            'role' => UserRole::MdbTeamUser,
            'status' => RecordStatus::Active,
            'password' => Hash::make('ExistingPassword123!'),
        ]);
    }

    public function test_login_links_to_the_reset_request_and_both_reset_forms_render(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get('/admin/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get(route('password.request'))->assertOk()->assertSee('name="email"', false)
            ->assertSee('name="_token"', false)->assertSee(route('password.email'), false);
        $token = $this->requestToken($this->user);
        $this->get(route('password.reset', ['token' => $token, 'email' => $this->user->email]))
            ->assertOk()->assertSee('name="password_confirmation"', false)
            ->assertSee('name="_token"', false)->assertSee($this->user->email)->assertSee(route('password.update'), false);
    }

    public function test_only_existing_active_users_receive_reset_links_and_incorrect_emails_are_rejected(): void
    {
        $inactive = User::factory()->create(['status' => RecordStatus::Inactive]);
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->user->email])
            ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::SUCCESS_CONFIRMATION);
        foreach ([$inactive->email, 'unregistered@example.test'] as $email) {
            $this->from(route('password.request'))->post(route('password.email'), ['email' => $email])
                ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::INCORRECT_EMAIL])
                ->assertSessionHasInput('email', $email);
            $this->get(route('password.request'))->assertOk()->assertSee(self::INCORRECT_EMAIL)
                ->assertDontSee(self::SUCCESS_CONFIRMATION)->assertDontSee('If this email belongs to an active registered account');
        }
        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        Notification::assertCount(1);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $this->user->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $inactive->email]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'unregistered@example.test']);
    }

    public function test_reset_request_requires_a_valid_email_address(): void
    {
        foreach ([[], ['email' => 'not-an-email']] as $data) {
            $this->from(route('password.request'))->post(route('password.email'), $data)
                ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_tokens_are_hashed_and_repeated_requests_explain_the_cooldown(): void
    {
        $token = $this->requestToken($this->user);
        $storedToken = DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token');
        $this->assertNotSame($token, $storedToken);
        $this->assertTrue(Password::broker()->tokenExists($this->user, $token));
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->user->email])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::COOLDOWN_ERROR]);
        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
        $this->assertSame($storedToken, DB::table('password_reset_tokens')->where('email', $this->user->email)->value('token'));
    }

    public function test_successful_reset_changes_only_password_rotates_remember_token_and_requires_login(): void
    {
        $token = $this->requestToken($this->user);
        $originalRememberToken = $this->user->remember_token;
        $originalAttributes = $this->user->only(['id', 'name', 'email', 'organization_id', 'role', 'status']);
        $originalVerifiedAt = $this->user->email_verified_at?->toIso8601String();
        Event::fake([PasswordReset::class]);

        $this->post(route('password.update'), $this->resetData($token))
            ->assertRedirect(route('login'))->assertSessionHasNoErrors()->assertSessionHas('status');
        $updated = $this->user->fresh();
        $this->assertTrue(Hash::check('NewVerifiedPassword123!', $updated->password));
        $this->assertFalse(Hash::check('ExistingPassword123!', $updated->password));
        $this->assertNotSame($originalRememberToken, $updated->remember_token);
        $this->assertSame($originalAttributes, $updated->only(array_keys($originalAttributes)));
        $this->assertSame($originalVerifiedAt, $updated->email_verified_at?->toIso8601String());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $this->user->email]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'auditable_id' => $this->user->id, 'old_values' => null, 'new_values' => null]);
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($this->user));
        $this->assertGuest();
        $this->post('/login', ['email' => $this->user->email, 'password' => 'NewVerifiedPassword123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_reset_token_is_bound_to_its_owner_email(): void
    {
        $otherUser = User::factory()->create(['password' => Hash::make('OtherPassword123!')]);
        $token = $this->requestToken($this->user);
        $data = $this->resetData($token);
        $data['email'] = $otherUser->email;
        $this->from(route('password.reset', ['token' => $token]))->post(route('password.update'), $data)
            ->assertRedirect()->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('OtherPassword123!', $otherUser->fresh()->password));
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($this->user, $token));
        $this->assertGuest();
    }

    public function test_invalid_and_expired_reset_tokens_cannot_change_password(): void
    {
        $this->from('/reset-password/unknown-token')->post(route('password.update'), $this->resetData('unknown-token'))
            ->assertRedirect()->assertSessionHasErrors('email');
        $token = $this->requestToken($this->user);
        DB::table('password_reset_tokens')->where('email', $this->user->email)
            ->update(['created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1)]);
        $this->post(route('password.update'), $this->resetData($token))->assertRedirect()->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertGuest();
    }

    public function test_reset_token_is_one_use_and_cannot_change_password_again(): void
    {
        $token = $this->requestToken($this->user);
        $this->post(route('password.update'), $this->resetData($token))->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $data = $this->resetData($token);
        $data['password'] = $data['password_confirmation'] = 'ReplayedPassword123!';
        $this->from('/reset-password/'.$token)->post(route('password.update'), $data)->assertRedirect()->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('NewVerifiedPassword123!', $this->user->fresh()->password));
        $this->assertFalse(Hash::check('ReplayedPassword123!', $this->user->fresh()->password));
        $this->assertGuest();
    }

    public function test_deactivating_an_account_after_link_creation_prevents_reset(): void
    {
        $token = $this->requestToken($this->user);
        $this->user->update(['status' => RecordStatus::Inactive]);
        $this->from('/reset-password/'.$token)->post(route('password.update'), $this->resetData($token))
            ->assertRedirect()->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertSame(RecordStatus::Inactive, $this->user->fresh()->status);
        $this->assertGuest();
    }

    public function test_password_confirmation_and_minimum_length_are_required_without_consuming_token(): void
    {
        $token = $this->requestToken($this->user);
        $data = $this->resetData($token);
        $data['password_confirmation'] = 'MismatchedPassword123!';
        $this->from('/reset-password/'.$token)->post(route('password.update'), $data)->assertRedirect()->assertSessionHasErrors('password');
        $data['password'] = $data['password_confirmation'] = 'short123';
        $this->post(route('password.update'), $data)->assertRedirect()->assertSessionHasErrors('password');
        $data['password'] = $data['password_confirmation'] = str_repeat('P', 73);
        $this->post(route('password.update'), $data)->assertRedirect()->assertSessionHasErrors('password');
        $this->assertArrayNotHasKey('password', session()->get('_old_input', []));
        $this->assertArrayNotHasKey('password_confirmation', session()->get('_old_input', []));
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($this->user, $token));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertGuest();
    }

    public function test_successful_reset_revokes_only_the_owners_database_sessions(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        app('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $otherUser = User::factory()->create();
        foreach ([['owner-session-1', $this->user->id], ['owner-session-2', $this->user->id], ['other-session', $otherUser->id]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => now()->timestamp]);
        }
        $token = $this->requestToken($this->user);
        $this->post(route('password.update'), $this->resetData($token))->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('sessions', ['user_id' => $this->user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $otherUser->id]);
        $this->assertGuest();
    }

    public function test_authenticated_users_are_redirected_away_from_all_reset_endpoints(): void
    {
        $this->actingAs($this->user)->get(route('password.request'))->assertRedirect('/');
        $this->post(route('password.email'), ['email' => $this->user->email])->assertRedirect('/');
        $this->get(route('password.reset', ['token' => 'unused-token']))->assertRedirect('/');
        $this->post(route('password.update'), $this->resetData('unused-token'))->assertRedirect('/');
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_email_uses_the_configured_application_url_despite_a_hostile_request_host(): void
    {
        config(['app.url' => 'https://survey.example.test']);
        $this->withServerVariables(['HTTP_HOST' => 'attacker.example.test']);
        $token = $this->requestToken($this->user);
        $mail = Notification::sent($this->user, ResetPassword::class)->last()->toMail($this->user);
        $this->assertSame('https', parse_url($mail->actionUrl, PHP_URL_SCHEME));
        $this->assertSame('survey.example.test', parse_url($mail->actionUrl, PHP_URL_HOST));
        $this->assertSame('/reset-password/'.$token, parse_url($mail->actionUrl, PHP_URL_PATH));
        parse_str(parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
        $this->assertSame($this->user->email, $query['email']);
    }

    public function test_email_rate_limit_applies_across_different_request_ips(): void
    {
        $this->requestToken($this->user);
        foreach (['198.51.100.11', '198.51.100.12'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->from(route('password.request'))
                ->post(route('password.email'), ['email' => $this->user->email])
                ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::COOLDOWN_ERROR]);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.13'])
            ->post(route('password.email'), ['email' => $this->user->email])->assertTooManyRequests();
        Notification::assertSentToTimes($this->user, ResetPassword::class, 1);
    }

    public function test_ip_rate_limit_blocks_requests_for_many_different_unregistered_emails(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21']);
        for ($index = 1; $index <= 10; $index++) {
            $this->from(route('password.request'))->post(route('password.email'), ['email' => 'unknown'.$index.'@example.test'])
                ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::INCORRECT_EMAIL]);
        }
        $this->post(route('password.email'), ['email' => 'unknown11@example.test'])->assertTooManyRequests();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_reset_submission_rate_limit_blocks_repeated_invalid_tokens(): void
    {
        for ($index = 1; $index <= 10; $index++) {
            $this->from('/reset-password/invalid-token')->post(route('password.update'), $this->resetData('invalid-token-'.$index))
                ->assertRedirect()->assertSessionHasErrors('email');
        }
        $this->post(route('password.update'), $this->resetData('invalid-token-11'))->assertTooManyRequests();
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertGuest();
    }

    public function test_malformed_email_arrays_are_rejected_and_do_not_break_reset_forms(): void
    {
        $this->get(route('password.reset', ['token' => 'unused-token', 'email' => ['not', 'an', 'email']]))
            ->assertOk()->assertSee('name="email" value=""', false);
        $this->from(route('password.request'))->post(route('password.email'), ['email' => ['not', 'an', 'email']])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors('email');
        $this->get(route('password.request'))->assertOk()->assertSee('name="email" value=""', false);
        $this->get(route('password.reset', ['token' => 'unused-token']))->assertOk();
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_oversized_unicode_null_bytes_and_password_arrays_do_not_consume_reset_tokens(): void
    {
        $token = $this->requestToken($this->user);
        foreach ([str_repeat('é', 37), "NewPassword123!\0UnexpectedSuffix", ['invalid', 'password']] as $password) {
            $data = $this->resetData($token);
            $data['password'] = $data['password_confirmation'] = $password;
            $this->from('/reset-password/'.$token)->post(route('password.update'), $data)
                ->assertRedirect()->assertSessionHasErrors('password');
            $this->assertArrayNotHasKey('password', session()->get('_old_input', []));
            $this->assertArrayNotHasKey('password_confirmation', session()->get('_old_input', []));
        }
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
        $this->assertTrue(Password::broker()->tokenExists($this->user, $token));
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'user.password_reset']);
        $this->assertGuest();
    }

    public function test_registered_email_reports_missing_delivery_configuration_without_creating_a_reset_token(): void
    {
        config(['mail.default' => 'log']);
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->user->email])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::DELIVERY_UNCONFIGURED])
            ->assertSessionHasInput('email', $this->user->email);
        $this->get(route('password.request'))->assertOk()->assertSee(self::DELIVERY_UNCONFIGURED)
            ->assertDontSee(self::SUCCESS_CONFIRMATION);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertTrue(Hash::check('ExistingPassword123!', $this->user->fresh()->password));
    }

    public function test_wrong_email_reports_incorrect_address_before_checking_delivery_configuration(): void
    {
        config(['mail.default' => 'log']);
        $this->from(route('password.request'))->post(route('password.email'), ['email' => 'unregistered@example.test'])
            ->assertRedirect(route('password.request'))->assertSessionHasErrors(['email' => self::INCORRECT_EMAIL]);
        $this->get(route('password.request'))->assertOk()->assertSee(self::INCORRECT_EMAIL)
            ->assertDontSee(self::DELIVERY_UNCONFIGURED)->assertDontSee(self::SUCCESS_CONFIRMATION);
        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    private function requestToken(User $user): string
    {
        $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email])
            ->assertRedirect(route('password.request'))->assertSessionHasNoErrors()
            ->assertSessionHas('status', self::SUCCESS_CONFIRMATION);
        Notification::assertSentTo($user, ResetPassword::class);

        return Notification::sent($user, ResetPassword::class)->last()->token;
    }

    private function resetData(string $token): array
    {
        return ['token' => $token, 'email' => $this->user->email, 'password' => 'NewVerifiedPassword123!', 'password_confirmation' => 'NewVerifiedPassword123!'];
    }
}
