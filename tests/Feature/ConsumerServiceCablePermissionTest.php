<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class ConsumerServiceCablePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_admin_can_grant_or_revoke_operator_access_in_the_user_form(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $operator = User::factory()->create(['role' => 'survey_data_entry_operator']);
        $this->assertFalse($operator->canEnterConsumerServiceCables());
        $this->actingAs($admin);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        foreach ([true, false] as $allowed) {
            Livewire::test(EditUser::class, ['record' => $operator->id])
                ->fillForm(['allow_consumer_service_cables' => $allowed])->call('save')->assertHasNoFormErrors();
            $this->assertSame($allowed, $operator->fresh()->canEnterConsumerServiceCables());
        }
        $manager = User::factory()->create(['role' => 'project_manager']);
        $this->actingAs($manager);
        foreach ([false, true] as $allowed) {
            $operator->update(['allow_consumer_service_cables' => $allowed]);
            Livewire::test(EditUser::class, ['record' => $operator->id])
                ->fillForm(['allow_consumer_service_cables' => ! $allowed])->call('save')->assertHasNoFormErrors();
            $this->assertSame($allowed, $operator->fresh()->allow_consumer_service_cables);
        }
    }

    public function test_flag_does_not_grant_entry_access_to_other_roles_or_inactive_users(): void
    {
        foreach (['project_manager', 'survey_data_verifier', 'mobile_survey_user'] as $role) {
            $user = User::factory()->create(['role' => $role, 'allow_consumer_service_cables' => true]);
            $this->assertFalse($user->canEnterConsumerServiceCables());
        }
        $user = User::factory()->create(['role' => 'survey_data_entry_operator', 'status' => 'inactive', 'allow_consumer_service_cables' => true]);
        $this->assertFalse($user->canEnterConsumerServiceCables());
    }
}
