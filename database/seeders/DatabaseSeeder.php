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

        $internal = Organization::create([
            'name' => 'BARQAAB Consulting Services',
            'type' => OrganizationType::Internal,
            'contact_person' => 'Project Director',
            'email' => 'projects@barqaab.example',
            'status' => RecordStatus::Active,
        ]);
        $thirdParty = Organization::create([
            'name' => 'Third Party MDB Processor A',
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

        $project = Project::create([
            'code' => 'HAZECO-TDL',
            'name' => 'HAZECO T&D Losses Project',
            'timezone' => 'Asia/Karachi',
            'processing_required' => true,
            'status' => RecordStatus::Active,
        ]);

        $surveyTeam = SurveyTeam::create(['project_id' => $project->id, 'code' => 'ST-01', 'name' => 'Survey Team 01', 'status' => RecordStatus::Active]);
        $surveyTeam->members()->attach($surveyLead->id, ['is_leader' => true]);
        $mdbTeam = MdbTeam::create(['project_id' => $project->id, 'code' => 'MDB-01', 'name' => 'MDB Team 01', 'status' => RecordStatus::Active]);
        $mdbTeam->members()->attach($mdbUser->id);

        $admin->syncRoles([UserRole::SuperAdmin->value]);
        $this->call(ShieldPermissionSeeder::class);
    }

    private function user(Organization $organization, string $name, string $email, UserRole $role, string $password, string $suffix): User
    {
        return User::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'email' => $email,
            'phone' => '0300-000000'.$suffix,
            'role' => $role,
            'status' => RecordStatus::Active,
            'password' => $password,
        ]);
    }
}
