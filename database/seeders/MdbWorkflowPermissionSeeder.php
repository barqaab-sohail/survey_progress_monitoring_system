<?php

namespace Database\Seeders;

use App\Services\Mdb\WorkflowAccess;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class MdbWorkflowPermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (WorkflowAccess::ABILITIES as $ability) {
            Permission::findOrCreate('MdbWorkflow:'.ucfirst($ability), 'web');
        }
        $grants = [
            'super_admin' => WorkflowAccess::ABILITIES,
            'project_manager' => ['view'],
            'survey_team_leader' => ['view', 'upload'],
            'mdb_team_user' => ['view', 'edit', 'export'],
            'mdb_processing_user' => ['view', 'verify'],
            'mdb_creation_team' => ['view', 'edit', 'export'],
            'mdb_verifier' => ['view', 'verify'],
            'analysis_team' => ['view', 'analyze', 'export'],
        ];
        foreach ($grants as $role => $abilities) {
            Role::findOrCreate($role, 'web')->givePermissionTo(array_map(fn ($ability) => 'MdbWorkflow:'.ucfirst($ability), $abilities));
        }
    }
}
