<?php

namespace Database\Seeders;

use App\Models\Mdb\EngineeringPreset;
use Illuminate\Database\Seeder;

class EngineeringDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $manifest = json_decode(file_get_contents(resource_path('mdb/engineering-defaults.json')), true, 512, JSON_THROW_ON_ERROR);
        // A deployment or reseed must never replace administrator edits or re-enable a disabled library.
        EngineeringPreset::firstOrCreate(['code' => $manifest['code']], [
            'name' => $manifest['name'], 'enabled' => true, 'revision' => 1,
            'configuration' => $manifest['configuration'], 'evidence' => $manifest['evidence'],
        ]);
    }
}
