<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\MdbTeam;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\WorkflowAccess;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_workflow_users_have_separate_roles_permissions_and_team_access(): void
    {
        $this->seed(DatabaseSeeder::class);
        $access = app(WorkflowAccess::class);
        $accounts = [
            'dataentry@hazeco.test' => [UserRole::SurveyDataEntryOperator, 'entry', ['edit', 'upload', 'submit'], ['surveyVerify', 'generate']],
            'dataverifier@hazeco.test' => [UserRole::SurveyDataVerifier, 'survey_verification', ['surveyVerify'], ['edit', 'generate']],
            'mdbcreator@hazeco.test' => [UserRole::MdbGenerator, 'mdb_creation', ['generate', 'prepare', 'export'], ['edit', 'surveyVerify']],
            'mdbverifier@hazeco.test' => [UserRole::MdbVerifier, 'mdb_verification', ['analyze', 'export'], ['edit', 'surveyVerify', 'generate']],
        ];
        foreach ($accounts as $email => [$role, $queue, $allowed, $denied]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertTrue($user->hasRole($role->value));
            $this->assertTrue(Hash::check('Password123!', $user->password));
            $this->assertTrue($user->surveyTeams()->exists() || $user->mdbTeams()->exists());
            foreach ($allowed as $ability) {
                $this->assertTrue($access->allows($user, $ability), "$email: $ability");
            }
            foreach ($denied as $ability) {
                $this->assertFalse($access->allows($user, $ability), "$email: $ability");
            }
            $this->actingAs($user)->get(route('mdb-workflow.index', ['queue' => $queue]))->assertOk();
        }
        $this->assertDatabaseHas('users', ['email' => 'survey@hazeco.test', 'role' => UserRole::SurveyTeamLeader->value]);
        $this->assertDatabaseHas('users', ['email' => 'mdb@hazeco.test', 'role' => UserRole::MdbTeamUser->value]);
    }

    public function test_reseeding_updates_accounts_without_duplicates_password_resets_or_removed_members(): void
    {
        $this->seed(DatabaseSeeder::class);
        $operator = User::where('email', 'dataentry@hazeco.test')->firstOrFail();
        $operator->update(['name' => 'Old name', 'role' => UserRole::ManagementViewer, 'password' => 'ChangedPassword123!']);
        $password = $operator->fresh()->password;
        $verifier = User::where('email', 'dataverifier@hazeco.test')->firstOrFail();
        $verifier->assignRole(UserRole::MdbGenerator->value);
        $extraMember = User::factory()->create(['role' => UserRole::ManagementViewer]);
        $team = SurveyTeam::where('code', 'ST-01')->firstOrFail();
        $team->members()->attach($extraMember->id);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 11);
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('survey_teams', 1);
        $this->assertDatabaseCount('mdb_teams', 1);
        $this->assertDatabaseCount('survey_team_members', 4);
        $this->assertDatabaseCount('mdb_team_members', 3);
        $operator->refresh();
        $this->assertSame(UserRole::SurveyDataEntryOperator, $operator->role);
        $this->assertSame('PDF Data Entry Operator', $operator->name);
        $this->assertSame($password, $operator->password);
        $this->assertTrue($operator->hasRole('survey_data_entry_operator'));
        $this->assertFalse($operator->hasRole('management_viewer'));
        $verifier->refresh();
        $this->assertTrue($verifier->hasRole(UserRole::SurveyDataVerifier->value));
        $this->assertFalse($verifier->hasRole(UserRole::MdbGenerator->value));
        $this->assertTrue($team->members()->whereKey($extraMember->id)->exists());
        $this->assertSame(3, MdbTeam::where('code', 'MDB-01')->firstOrFail()->members()->count());
    }
}
