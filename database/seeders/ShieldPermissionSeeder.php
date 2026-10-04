<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ShieldPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $subjects = [
            'Circle', 'Division', 'Feeder', 'GridStation', 'HtDataImport',
            'Organization', 'Project', 'Role', 'SubDivision', 'Transformer',
            'TransformerKmzImport', 'User',
        ];
        $abilities = [
            'ViewAny', 'View', 'Create', 'Update', 'Delete', 'DeleteAny',
            'Restore', 'RestoreAny', 'ForceDelete', 'ForceDeleteAny', 'Replicate', 'Reorder',
        ];

        foreach ($subjects as $subject) {
            foreach ($abilities as $ability) {
                Permission::findOrCreate("{$ability}:{$subject}", 'web');
            }
        }

        Permission::findOrCreate('Access:AdminPanel', 'web');

        $superAdmin = Role::findOrCreate(UserRole::SuperAdmin->value, 'web');
        $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->get());

        Role::findOrCreate(UserRole::ProjectManager->value, 'web')
            ->givePermissionTo(Permission::findOrCreate('Access:AdminPanel', 'web'));
    }
}
