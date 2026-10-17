<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Template;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Registers a schema-only system asset, without claiming engineering approval. */
class SchemaTemplate
{
    public const CODE = 'HAZECO-REFERENCE-SCHEMA';

    public function ensure(User $actor): Template
    {
        abort_unless($actor->isActive() && $actor->hasRole('super_admin'), 403);
        $source = resource_path('mdb/synergee-empty.mdb');
        $hash = hash_file('sha256', $source);
        $template = Template::where('code', self::CODE)->where('version', '1')->first();
        if ($template && ! hash_equals($hash, $template->sha256)) {
            throw ValidationException::withMessages(['template' => 'The system schema template changed. Restore or register the matching reference schema.']);
        }
        $path = 'mdb-workflow/templates/reference-schema-'.$hash.'.mdb';
        if (! Storage::disk('local')->exists($path) && ! Storage::disk('local')->put($path, file_get_contents($source))) {
            throw new \RuntimeException('The reference MDB schema template could not be retained.');
        }
        if ($template) {
            return $template;
        }

        return Template::create(['code' => self::CODE, 'version' => '1', 'disk' => 'local', 'path' => $path,
            'sha256' => $hash, 'synergee_version' => 'SynerGEE Electric 5.0.0.324', 'active' => true,
            'approved_by' => null, 'approved_at' => null, 'metadata' => ['system_reference_schema' => true,
                'permitted_static_tables' => [], 'equipment_references' => [], 'required_base_files' => []]]);
    }

    public function current(): ?Template
    {
        return Template::where('code', self::CODE)->where('version', '1')->where('active', true)
            ->where('sha256', hash_file('sha256', resource_path('mdb/synergee-empty.mdb')))->first();
    }
}
