<?php

namespace Database\Seeders;

use App\Enums\OrganizationType;
use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\MdbTeam;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SurveyTeam;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (UserRole::cases() as $userRole) {
            Role::findOrCreate($userRole->value, 'web');
        }

        $accessPanel = Permission::findOrCreate('Access:AdminPanel', 'web');
        Role::findByName(UserRole::SuperAdmin->value)->givePermissionTo($accessPanel);
        Role::findByName(UserRole::ProjectManager->value)->givePermissionTo($accessPanel);

        $internal = Organization::updateOrCreate(['name' => 'BARQAAB Consulting Services'], [
            'type' => OrganizationType::Internal,
            'contact_person' => 'Project Director',
            'email' => 'projects@barqaab.example',
            'status' => RecordStatus::Active,
        ]);
        $thirdParty = Organization::updateOrCreate(['name' => 'Third Party MDB Processor A'], [
            'type' => OrganizationType::ThirdParty,
            'contact_person' => 'Operations Lead',
            'email' => 'operations@processor-a.example',
            'status' => RecordStatus::Active,
        ]);

        $password = Hash::make('Password123!');
        $admin = $this->user($internal, 'Super Admin', 'admin@hazeco.test', UserRole::SuperAdmin, $password, '1');
        $this->user($internal, 'Project Manager', 'manager@hazeco.test', UserRole::ProjectManager, $password, '2');
        $surveyLead = $this->user($internal, 'Survey Team Leader', 'survey@hazeco.test', UserRole::SurveyTeamLeader, $password, '3');
        $mdbUser = $this->user($internal, 'MDB User', 'mdb@hazeco.test', UserRole::MdbTeamUser, $password, '4');
        $externalProcessor = $this->user($thirdParty, 'Third-Party Processor', 'thirdparty@hazeco.test', UserRole::MdbProcessingUser, $password, '6');
        $this->user($internal, 'Management Viewer', 'viewer@hazeco.test', UserRole::ManagementViewer, $password, '7');
        $entryOperator = $this->user($internal, 'PDF Data Entry Operator', 'dataentry@hazeco.test', UserRole::SurveyDataEntryOperator, $password, '8');
        $dataVerifier = $this->user($internal, 'Data Verifier', 'dataverifier@hazeco.test', UserRole::SurveyDataVerifier, $password, '9');
        $mdbCreator = $this->user($internal, 'MDB Creator', 'mdbcreator@hazeco.test', UserRole::MdbGenerator, $password, '10');
        $mdbVerifier = $this->user($internal, 'MDB Verifier', 'mdbverifier@hazeco.test', UserRole::MdbVerifier, $password, '11');

        $project = Project::updateOrCreate(['code' => 'HAZECO-TDL'], [
            'name' => 'HAZECO Transmission and Distribution Losses Calculation Project',
            'timezone' => 'Asia/Karachi',
            'processing_required' => true,
            'status' => RecordStatus::Active,
        ]);

        $surveyTeam = SurveyTeam::updateOrCreate(['project_id' => $project->id, 'code' => 'ST-01'], ['name' => 'Survey Team 01', 'status' => RecordStatus::Active]);
        $surveyTeam->members()->syncWithoutDetaching([$surveyLead->id => ['is_leader' => true], $entryOperator->id => ['is_leader' => false], $dataVerifier->id => ['is_leader' => false]]);
        $mdbTeam = MdbTeam::updateOrCreate(['project_id' => $project->id, 'code' => 'MDB-01'], ['name' => 'MDB Team 01', 'status' => RecordStatus::Active]);
        $mdbTeam->members()->syncWithoutDetaching([$mdbUser->id, $mdbCreator->id, $mdbVerifier->id]);

        $admin->syncRoles([UserRole::SuperAdmin->value]);
        $this->call(ShieldPermissionSeeder::class);
        $this->call(MdbWorkflowPermissionSeeder::class);
    }

    private function user(Organization $organization, string $name, string $email, UserRole $role, string $password, string $suffix): User
    {
        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'phone' => '0300-000000'.$suffix,
            'role' => $role,
            'status' => RecordStatus::Active,
        ]);
        if (! $user->exists) {
            $user->password = $password;
        }
        $user->save();
        // Repair primary roles on reruns, retaining separately configured custom roles.
        $primaryRoles = array_map(fn (UserRole $item) => $item->value, UserRole::cases());
        $additionalRoles = $user->roles()->whereNotIn('name', $primaryRoles)->pluck('name')->all();
        $user->syncRoles([$role->value, ...$additionalRoles]);

        return $user;
    }
}
