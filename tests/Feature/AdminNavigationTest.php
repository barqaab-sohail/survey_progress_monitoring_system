<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_find_administration_links_in_filament(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'status' => RecordStatus::Active,
        ]);

        $response = $this->actingAs($user)->get('/admin')->assertOk();

        $response->assertSee('Administration')
            ->assertSee('Master Data &amp; Import', false)
            ->assertSee('Teams &amp; Assignments', false)
            ->assertSee('Audit Log');

        foreach (['master', 'teams', 'users', 'organizations', 'audit'] as $section) {
            $response->assertSee(route("admin.{$section}.index"), false);
        }
    }

    public function test_project_manager_cannot_see_or_access_super_admin_links(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::ProjectManager,
            'status' => RecordStatus::Active,
        ]);

        $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee('Administration');

        foreach (['master', 'teams', 'users', 'organizations', 'audit'] as $section) {
            $this->get(route("admin.{$section}.index"))->assertForbidden();
        }
    }
}
