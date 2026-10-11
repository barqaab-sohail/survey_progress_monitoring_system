<?php

namespace App\Services\Mdb;

use App\Models\Mdb\GenerationInput;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use App\Models\User;
use App\Services\AuditService;
use DOMDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class GenerationInputService
{
    public function store(SurveyBatch $batch, User $actor, UploadedFile $file, string $kind): GenerationInput
    {
        app(WorkflowAccess::class)->authorize($actor, $batch, 'prepare');
        abort_unless($batch->staged_workflow, 422, 'Generation inputs belong to the staged survey workflow.');
        if (! in_array($kind, ['kmz', 'base'], true) || ! $file->getSize() || $file->getSize() > 100 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Select a nonempty KMZ or base file of at most 100 MB.']);
        }
        if ($kind === 'kmz') {
            $this->validateKmz($file);
        }

        return DB::transaction(function () use ($batch, $actor, $file, $kind) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            app(WorkflowAccess::class)->authorize($actor, $locked, 'prepare');
            app(ExportService::class)->assertApproved($locked);
            $hash = hash_file('sha256', $file->getRealPath());
            $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255);
            $existing = $locked->generationInputs()->where('kind', $kind)->where('original_name', $name)->where('sha256', $hash)->first();
            if ($existing) {
                return $existing;
            }
            $path = 'mdb-workflow/generation-inputs/'.$locked->id.'/'.Str::uuid().'.bin';
            $stream = fopen($file->getRealPath(), 'rb');
            try {
                if (! Storage::disk('local')->put($path, $stream, ['visibility' => 'private'])) {
                    throw new \RuntimeException('Could not preserve the generation input.');
                }
            } finally {
                fclose($stream);
            }
            $input = $locked->generationInputs()->create(['kind' => $kind, 'disk' => 'local', 'path' => $path,
                'original_name' => $name, 'sha256' => $hash, 'bytes' => $file->getSize(), 'uploaded_by' => $actor->id]);
            app(AuditService::class)->record($actor, 'mdb.generation_input_uploaded', $input, [], $input->toArray());

            return $input;
        });
    }

    public function manifest(SurveyBatch $batch): array
    {
        $revision = $batch->approvedRevision;
        $template = Template::find($revision->snapshot['template']['id'] ?? null);
        if (! $template) {
            throw ValidationException::withMessages(['generation_inputs' => 'An approved MDB base template is required. Ask the administrator to configure it and obtain approval of the updated survey revision.']);
        }
        $inputs = $batch->generationInputs()->latest('id')->get()->unique(fn ($input) => $input->kind.':'.($input->kind === 'kmz' ? 'map' : strtolower($input->original_name)))->values();
        if (! $inputs->contains('kind', 'kmz')) {
            throw ValidationException::withMessages(['generation_inputs' => 'The MDB Creator must add a KMZ file before generation.']);
        }
        $required = $template->metadata['required_base_files'] ?? [];
        if (! is_array($required)) {
            throw ValidationException::withMessages(['generation_inputs' => 'The approved template required_base_files setting must be a list of filenames.']);
        }
        foreach ($required as $name) {
            if (! is_string($name) || ! $inputs->contains(fn ($input) => $input->kind === 'base' && strcasecmp($input->original_name, $name) === 0)) {
                throw ValidationException::withMessages(['generation_inputs' => 'Add all base files required by the approved template: '.implode(', ', array_filter($required, 'is_string')).'.']);
            }
        }
        $manifest = ['approved_revision_id' => $revision->id,
            'survey_sources' => $revision->snapshot['sources'],
            'supporting_files' => $inputs->map->only(['id', 'kind', 'disk', 'path', 'original_name', 'sha256', 'bytes', 'uploaded_by'])->all(),
            'base_template' => $template->only(['id', 'disk', 'path', 'sha256', 'version'])];
        $this->assertUnchanged($manifest);

        return $manifest;
    }

    public function assertUnchanged(array $manifest): void
    {
        foreach ([...($manifest['survey_sources'] ?? []), ...($manifest['supporting_files'] ?? []), ...(! empty($manifest['base_template']) ? [$manifest['base_template']] : [])] as $file) {
            $disk = Storage::disk($file['disk']);
            if (! $disk->exists($file['path']) || ! hash_equals($file['sha256'], hash_file('sha256', $disk->path($file['path'])))) {
                throw ValidationException::withMessages(['generation_inputs' => 'A frozen generation input is missing or changed. Restore the original file before retrying.']);
            }
        }
    }

    private function validateKmz(UploadedFile $file): void
    {
        $archive = new ZipArchive;
        if (strtolower($file->getClientOriginalExtension()) !== 'kmz' || $archive->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['file' => 'Upload a valid KMZ archive containing KML map data.']);
        }
        try {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $info = $archive->statIndex($index);
                if (strtolower(pathinfo($info['name'], PATHINFO_EXTENSION)) !== 'kml' || $info['size'] > 10 * 1024 * 1024) {
                    continue;
                }
                $xml = $archive->getFromIndex($index);
                if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) {
                    continue;
                }
                $previous = libxml_use_internal_errors(true);
                try {
                    $document = new DOMDocument;
                    if ($document->loadXML($xml, LIBXML_NONET) && $document->documentElement?->localName === 'kml') {
                        return;
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
            }
        } finally {
            $archive->close();
        }
        throw ValidationException::withMessages(['file' => 'The KMZ must contain valid KML map data of at most 10 MB.']);
    }
}
