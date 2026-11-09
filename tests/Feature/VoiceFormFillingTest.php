<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\MobileDeviceToken;
use App\Models\Project;
use App\Models\SurveyTeam;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class VoiceFormFillingTest extends TestCase
{
    use RefreshDatabase;

    private function device(User $user): void
    {
        $token = bin2hex(random_bytes(64));
        MobileDeviceToken::create(['user_id' => $user->id, 'device_name' => 'Voice test',
            'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_voice_is_disabled_by_default_including_for_super_admin(): void
    {
        foreach (['mobile_survey_user', 'super_admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertFalse($user->fresh()->allow_voice_form_filling);
            $this->device($user);
            $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('capabilities.voice_form_filling', false);
            $this->postJson('/api/v1/field/voice/authorize')->assertForbidden();
        }
    }

    public function test_enabled_mobile_user_is_authorized_and_revocation_takes_effect_without_new_login(): void
    {
        $user = User::factory()->create(['role' => 'mobile_survey_user', 'allow_voice_form_filling' => true]);
        $this->device($user);
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('capabilities.voice_form_filling', true);
        $this->postJson('/api/v1/field/voice/authorize')->assertOk()->assertJsonPath('voice_form_filling', true)
            ->assertHeader('Cache-Control', 'no-store, private');
        $user->update(['allow_voice_form_filling' => false]);
        $this->postJson('/api/v1/field/voice/authorize')->assertForbidden();
        $this->getJson('/api/v1/field/bootstrap')->assertOk()->assertJsonPath('capabilities.voice_form_filling', false);
    }

    public function test_voice_requires_a_valid_active_device_account(): void
    {
        $this->postJson('/api/v1/field/voice/authorize')->assertUnauthorized();
        $user = User::factory()->create(['role' => 'mobile_survey_user', 'allow_voice_form_filling' => true, 'status' => 'inactive']);
        $this->device($user);
        $this->postJson('/api/v1/field/voice/authorize')->assertForbidden();
    }

    public function test_super_admin_can_enable_and_disable_voice_in_user_form(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'mobile_survey_user']);
        $project = Project::create(['code' => 'VOICE', 'name' => 'Voice project', 'timezone' => 'Asia/Karachi', 'status' => 'active']);
        $team = SurveyTeam::create(['project_id' => $project->id, 'code' => 'V01', 'name' => 'Voice team', 'group_code' => '01', 'status' => 'active']);
        $target->surveyTeams()->attach($team->id);
        $this->actingAs($admin);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([true, false] as $allowed) {
            Livewire::test(EditUser::class, ['record' => $target->id])
                ->fillForm(['allow_voice_form_filling' => $allowed])->call('save')->assertHasNoFormErrors();
            $this->assertSame($allowed, $target->fresh()->allow_voice_form_filling);
        }
    }

    public function test_non_super_admin_with_user_edit_access_cannot_grant_or_revoke_voice(): void
    {
        $manager = User::factory()->create(['role' => 'project_manager']);
        $target = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($manager);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([false, true] as $existing) {
            $target->update(['allow_voice_form_filling' => $existing]);
            Livewire::test(EditUser::class, ['record' => $target->id])
                ->set('data.allow_voice_form_filling', ! $existing)->call('save')->assertHasNoFormErrors();
            $this->assertSame($existing, $target->fresh()->allow_voice_form_filling);
        }
    }

    public function test_profile_payload_cannot_enable_voice(): void
    {
        $user = User::factory()->create(['role' => 'mobile_survey_user']);
        $this->actingAs($user)->put(route('profile.update'), ['allow_voice_form_filling' => true])->assertRedirect();
        $this->assertFalse($user->fresh()->allow_voice_form_filling);
    }

    public function test_legacy_super_admin_user_form_can_toggle_voice(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $target = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin);
        foreach ([1, 0] as $allowed) {
            $this->put(route('admin.users.update', $target), [
                'name' => $target->name, 'email' => $target->email, 'role' => 'super_admin',
                'status' => 'active', 'allow_voice_form_filling' => $allowed,
            ])->assertSessionHasNoErrors();
            $this->assertSame((bool) $allowed, $target->fresh()->allow_voice_form_filling);
        }
        $this->actingAs(User::factory()->create(['role' => 'mobile_survey_user']))
            ->put(route('admin.users.update', $target), ['allow_voice_form_filling' => true])->assertForbidden();
        $this->assertFalse($target->fresh()->allow_voice_form_filling);
    }
}
