<?php

namespace App\Services\Mdb;

use App\Models\Mdb\Revision;
use App\Models\Mdb\SurveyBatch;
use App\Models\Mdb\Template;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Portable frozen engineering inputs; never imports surveys or approvals into the local database. */
class SynergeeTransfer
{
    public const MAX_BYTES = 30 * 1024 * 1024;

    public function __construct(private SnapshotService $snapshots, private ExportPayloadBuilder $builder) {}

    public function export(SurveyBatch $batch, ?int $transformerId): array
    {
        $revision = app(ExportService::class)->assertApproved($batch);
        $this->require($revision->number === $batch->revision, 'The approved revision is not the current survey revision. Reverify before export.');
        // Independently enforce integrity even if another export path has relaxed checks.
        $this->require(hash_equals($revision->sha256, $this->snapshots->hash($revision->snapshot)), 'The frozen revision checksum differs. Reverify the survey before export.');
        $selected = $this->selected($revision->snapshot, $transformerId);
        foreach ($selected as $transformer) {
            $this->require(! empty($transformer['header']['manually_verified']), 'Verify the transformer header before exporting '.$transformer['code'].'.');
            foreach ($transformer['entry_rows'] ?? [] as $row) {
                $this->require(! empty($row['manually_verified']), 'Verify every PDF observation before exporting '.$transformer['code'].'.');
            }
        }
        $templateData = $revision->snapshot['template'] ?? [];
        $template = Template::find($templateData['id'] ?? null);
        $this->require($template && $template->active && $template->approved_by && $template->approved_at,
            'Select an engineering-approved clean SynerGEE template and reapprove this survey.');
        $this->require($template->sha256 === ($templateData['sha256'] ?? null) && $template->version === ($templateData['version'] ?? null), 'The template differs from the approved revision. Reapproval is required.');
        $bytes = Storage::disk($template->disk)->get($template->path);
        $this->require(is_string($bytes) && strlen($bytes) <= 15 * 1024 * 1024 && hash_equals($template->sha256, hash('sha256', $bytes)),
            'The approved clean template is missing, changed or larger than 15 MB.');
        $this->require(empty($templateData['metadata']['required_base_files']),
            'This template requires external base files. This offline converter supports a self-contained clean template with reviewed library references; retain those external files in the target SynerGEE installation or configure a suitable template and reapprove.');
        $content = [
            'format' => 'hazeco-synergee-transfer', 'version' => 1, 'schema_sha256' => ApprovedSchema::fingerprint(),
            'source' => ['batch_id' => $batch->id, 'project_id' => $batch->project_id, 'feeder_id' => $batch->feeder_id,
                'feeder_code' => $batch->feeder?->feeder_code, 'feeder_name' => $batch->feeder?->feeder_name,
                'revision_id' => $revision->id, 'revision_number' => $revision->number, 'revision_sha256' => $revision->sha256,
                'exported_at' => now()->utc()->toIso8601String()],
            'scope' => ['transformer_id' => $transformerId, 'transformer_codes' => array_column($selected, 'code')],
            'snapshot' => $revision->snapshot,
            'template_base64' => base64_encode($bytes),
        ];

        return $content + ['sha256' => $this->transferHash($content)];
    }

    public function read(string $json): array
    {
        $this->require(strlen($json) <= self::MAX_BYTES, 'SynerGEE JSON exceeds 30 MB. Export a smaller scope.');
        try {
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->require(false, 'Choose a valid SynerGEE JSON downloaded from an approved production survey.');
        }
        $this->require(is_array($package), 'The file is not a SynerGEE transfer package.');
        $rules = [
            'format' => 'required|in:hazeco-synergee-transfer', 'version' => 'required|integer|in:1',
            'sha256' => 'required|regex:/^[a-f0-9]{64}$/D', 'schema_sha256' => 'required|string',
            'source' => 'required|array', 'source.batch_id' => 'required|integer|min:1',
            'source.revision_id' => 'required|integer|min:1', 'source.revision_number' => 'required|integer|min:1',
            'source.revision_sha256' => 'required|regex:/^[a-f0-9]{64}$/D', 'source.exported_at' => 'required|date',
            'scope' => 'required|array', 'scope.transformer_id' => 'present|nullable|integer|min:1',
            'scope.transformer_codes' => 'required|array|list|min:1', 'scope.transformer_codes.*' => 'required|string',
            'snapshot' => 'required|array', 'snapshot.batch' => 'required|array', 'snapshot.batch.id' => 'required|integer|min:1',
            'snapshot.configuration' => 'present|nullable|array', 'snapshot.configuration.settings' => 'sometimes|array',
            'snapshot.template' => 'required|array', 'snapshot.template.metadata' => 'required|array',
            'snapshot.template.sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'snapshot.template.version' => 'required|string', 'snapshot.template.code' => 'required|string',
            'snapshot.template.synergee_version' => 'required|string',
            'snapshot.sources' => 'present|array|list', 'snapshot.sources.*' => 'required|array',
            'snapshot.sources.*.id' => 'required|integer|min:1', 'snapshot.sources.*.sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'snapshot.sources.*.original_name' => 'required|string', 'snapshot.sources.*.kind' => 'required|string',
            'snapshot.waypoints' => 'present|array|list|max:10000', 'snapshot.waypoints.*' => 'required|array',
            'snapshot.waypoints.*.id' => 'required|integer|min:1|distinct', 'snapshot.waypoints.*.name' => 'required|string',
            'snapshot.waypoints.*.latitude' => 'nullable|numeric|between:-90,90',
            'snapshot.waypoints.*.longitude' => 'nullable|numeric|between:-180,180',
            'snapshot.waypoints.*.elevation' => 'nullable|numeric', 'snapshot.waypoints.*.correction' => 'nullable|array',
            'snapshot.waypoints.*.recorded_at' => 'nullable|date',
            'snapshot.transformers' => 'required|array|list|min:1|max:1000', 'snapshot.transformers.*' => 'required|array',
            'snapshot.transformers.*.id' => 'required|integer|min:1|distinct', 'snapshot.transformers.*.code' => 'required|string',
            'snapshot.transformers.*.header' => 'required|array', 'snapshot.transformers.*.sections' => 'present|array|list',
            'snapshot.transformers.*.sections.*' => 'required|array', 'snapshot.transformers.*.sections.*.id' => 'required|integer|min:1',
            'snapshot.transformers.*.sections.*.phases' => 'present|array',
            'snapshot.transformers.*.sections.*.conductors' => 'present|array',
            'snapshot.transformers.*.sections.*.consumers' => 'present|array|list',
            'snapshot.transformers.*.sections.*.consumers.*' => 'required|array',
            'snapshot.transformers.*.sections.*.consumers.*.demand' => 'nullable|array',
            'snapshot.transformers.*.sections.*.original_entry' => 'sometimes|array',
            'snapshot.transformers.*.sections.*.geometry' => 'nullable|array|list',
            'snapshot.transformers.*.entry_rows' => 'sometimes|array|list', 'snapshot.transformers.*.entry_rows.*' => 'required|array',
            'snapshot.transformers.*.entry_rows.*.id' => 'required|integer|min:1',
            'snapshot.transformers.*.entry_rows.*.pair_number' => 'required|integer|min:1',
            'snapshot.transformers.*.entry_rows.*.section_id' => 'nullable|integer|min:1',
            'snapshot.transformers.*.entry_rows.*.designation' => 'required|in:S,E',
            'snapshot.transformers.*.entry_rows.*.pv_details' => 'nullable|array|list',
            'snapshot.transformers.*.pv_records' => 'sometimes|array|list', 'snapshot.transformers.*.pv_records.*' => 'required|array',
            'template_base64' => 'required|string',
        ];
        $validation = Validator::make($package, $rules);
        $this->require(! $validation->fails(), 'Invalid SynerGEE package: '.$validation->errors()->first());
        $content = $package;
        unset($content['sha256']);
        $this->require($this->matchesTransferHash($package['sha256'], $content), 'Package checksum failed. Download the original JSON again without editing it.');
        $this->require($this->matchesTransferHash($package['source']['revision_sha256'], $package['snapshot']), 'Frozen survey revision checksum failed. Re-export from production.');
        $this->require($package['source']['batch_id'] === $package['snapshot']['batch']['id'], 'The package batch identity differs from its frozen survey.');
        $this->require($package['source']['revision_number'] === ($package['snapshot']['batch']['revision'] ?? null), 'The package revision number differs from its frozen survey.');
        $this->require(hash_equals(ApprovedSchema::fingerprint(), $package['schema_sha256']), 'The package uses a different MDB schema. Update the local application from Git.');
        $this->require(empty($package['snapshot']['template']['metadata']['required_base_files']), 'This template requires external base files that this offline package does not carry. Configure a supported self-contained clean template and reapprove in production.');
        $this->require($package['scope']['transformer_codes'] === array_column($this->selected($package['snapshot'], $package['scope']['transformer_id']), 'code'), 'The selected transformers differ from the package scope.');
        $template = base64_decode($package['template_base64'], true);
        $this->require(is_string($template) && strlen($template) >= 4096 && strlen($template) <= 15 * 1024 * 1024
            && hash_equals($package['snapshot']['template']['sha256'], hash('sha256', $template)), 'The embedded clean MDB template is missing or its checksum differs.');

        return $package;
    }

    public function inspect(array $package, ?string $gpx = null): array
    {
        if ($gpx !== null) {
            $this->verifyGpx($package, $gpx);
        }
        $snapshot = $package['snapshot'];
        $snapshot['transformers'] = $this->selected($snapshot, $package['scope']['transformer_id']);
        $errors = [];
        foreach ($snapshot['transformers'] as $transformer) {
            $mapped = [];
            foreach (collect($transformer['entry_rows'] ?? [])->groupBy('pair_number') as $number => $rows) {
                $ids = $rows->pluck('section_id')->unique()->values();
                $id = $ids->first();
                if ($ids->count() !== 1 || ! $id || ! in_array($id, array_column($transformer['sections'], 'id'), true) || isset($mapped[$id])) {
                    $errors[] = ['code' => 'unmapped_pdf_pair', 'transformer_id' => $transformer['id'],
                        'message' => 'PDF pair '.$number.' is not linked to its own verified network section. Re-save and verify the pair in production before export.'];
                }
                $mapped[$id ?? 'unlinked'] = true;
                foreach ($rows as $row) {
                    $pvCount = collect($transformer['pv_records'] ?? [])->filter(fn ($pv) => ($pv['entry_row_id'] ?? $pv['original_entry']['entry_row_id'] ?? null) === ($row['id'] ?? null))->count();
                    if ($pvCount !== count($row['pv_details'] ?? [])) {
                        $errors[] = ['code' => 'unmapped_pdf_pv', 'transformer_id' => $transformer['id'],
                            'message' => 'PV observations in PDF pair '.$number.' differ from its saved engineering PV records. Re-save and verify the PV observations in production.'];
                    }
                }
            }
        }
        if (GenerationMode::simple($snapshot['configuration'] ?? null)) {
            $errors[] = ['code' => 'engineering_mode', 'message' => 'Simple MDB generation permits missing engineering values. Configure and approve full engineering settings, disable Simple MDB generation, and reapprove/export this survey.'];
            // Show all missing engineering inputs, without changing the frozen package or approving anything.
            $snapshot['configuration']['settings']['simple_mdb_generation'] = false;
        }
        try {
            $validation = app(NetworkValidator::class)->validate($snapshot);
            $errors = [...$errors, ...$validation['errors']];
            $payload = null;
            if ($errors === []) {
                $revision = new Revision(['batch_id' => $package['source']['batch_id'], 'number' => $package['source']['revision_number'],
                    'snapshot' => $snapshot, 'sha256' => $package['source']['revision_sha256']]);
                $revision->id = $package['source']['revision_id'];
                $template = new Template($snapshot['template'] + ['active' => true]);
                $payload = $this->builder->build($revision, $template, $package['scope']['transformer_id'], $package['sha256']);
                $this->require(count($payload['tables']['InstPrimaryTransformers']) === count($snapshot['transformers']), 'The mapper omitted a selected transformer.');
                $expectedSections = array_sum(array_map(fn ($t) => count($t['sections']), $snapshot['transformers']));
                $this->require(count($payload['provenance']) === $expectedSections, 'The mapper omitted a verified survey section.');
            }
        } catch (ValidationException $exception) {
            $errors[] = ['code' => 'mapping', 'message' => implode(' ', array_merge(...array_values($exception->errors())))];
        } catch (Throwable $exception) {
            $errors[] = ['code' => 'mapping', 'message' => 'The frozen engineering inputs cannot be mapped: '.$exception->getMessage()];
        }

        $warnings = [...($validation['warnings'] ?? []), ...array_map(fn ($message) => ['message' => $message], $payload['engineering_omissions'] ?? [])];
        if (($snapshot['configuration']['settings']['mapping']['version'] ?? '') === ExportPayloadBuilder::VERSION
            && collect($snapshot['transformers'])->contains(fn ($t) => collect($t['pv_records'] ?? [])->contains(fn ($pv) => ($pv['installed_capacity_kw'] ?? 0) > 0))) {
            $reason = $snapshot['configuration']['settings']['mapping']['pv_exclusion']['reason'] ?? null;
            if ($reason) {
                $warnings[] = ['message' => 'PV is excluded from this model by the documented engineering decision: '.$reason];
            }
        }

        return ['package' => $package, 'payload' => $payload ?? null, 'issues' => $errors, 'warnings' => $warnings];
    }

    public function generate(array $package): string
    {
        $this->require(PHP_OS_FAMILY === 'Windows', 'Local SynerGEE MDB generation requires Windows with Access DAO/ACE.');
        $package = $this->read(json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $inspection = $this->inspect($package);
        $this->require($inspection['issues'] === [], 'Correct these inputs in production, obtain verification and download new JSON: '.implode(' ', array_column($inspection['issues'], 'message')));
        $payload = $inspection['payload'];
        $directory = 'synergee-offline/'.Str::uuid();
        $disk = Storage::disk('local');
        $disk->makeDirectory($directory);
        $output = $disk->path($directory.'/synergee.mdb');
        try {
            $this->require($disk->put($directory.'/template.mdb', base64_decode($package['template_base64'], true)), 'The local template could not be written. Check storage permissions.');
            $template = new Template($package['snapshot']['template']);
            $template->disk = 'local';
            $template->path = $directory.'/template.mdb';
            $result = app(WindowsExporter::class)->export($payload, $template, $output);
            app(ReadbackVerifier::class)->verify($output, $payload, $result);

            return $output;
        } catch (Throwable $exception) {
            $disk->delete($directory.'/synergee.mdb');
            throw ValidationException::withMessages(['file' => 'SynerGEE MDB generation failed: '.$exception->getMessage()]);
        } finally {
            $disk->delete([$directory.'/template.mdb', $directory.'/payload.json']);
        }
    }

    private function selected(array $snapshot, ?int $id): array
    {
        $selected = array_values(array_filter($snapshot['transformers'] ?? [], fn ($t) => $id === null || (int) $t['id'] === $id));
        $this->require($selected !== [] && ($id === null || count($selected) === 1), 'Choose a transformer from this verified survey.');

        return $selected;
    }

    private function verifyGpx(array $package, string $xml): void
    {
        $hash = hash('sha256', $xml);
        $source = collect($package['snapshot']['sources'])->first(fn ($s) => $s['kind'] === 'gpx' && hash_equals($s['sha256'], $hash));
        $this->require($source !== null, 'This GPX is not an original source of the approved survey (SHA-256 differs). Select the matching GPX or omit it; verified coordinates are embedded in the JSON.');
        $parsed = app(GpxParser::class)->parse($xml);
        foreach ($package['snapshot']['waypoints'] as $point) {
            if (($point['source_file_id'] ?? null) !== $source['id']) {
                continue;
            }
            $matches = array_filter($parsed['waypoints'], fn ($p) => $p['name'] === $point['name']
                && (empty($point['recorded_at']) || $p['recorded_at'] === CarbonImmutable::parse($point['recorded_at'])->utc()->format('Y-m-d H:i:s'))
                && abs($p['latitude'] - (float) $point['latitude']) < 0.00000001
                && abs($p['longitude'] - (float) $point['longitude']) < 0.00000001
                && (($p['elevation'] === null && ($point['elevation'] ?? null) === null)
                    || (is_numeric($p['elevation']) && is_numeric($point['elevation'] ?? null) && abs($p['elevation'] - (float) $point['elevation']) < 0.000001)));
            $this->require($matches !== [], 'GPX waypoint '.$point['name'].' differs from the frozen source coordinates/elevation. Resolve the discrepancy in production and obtain new verification.');
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['file' => $message]);
        }
    }

    /**
     * PHP serializes floating point JSON numbers according to serialize_precision,
     * which can differ between cPanel and the local Windows PHP installation.
     * Pin transfer-package hashes to one value and still accept packages created
     * by the earlier runtime-dependent implementation.
     */
    private function matchesTransferHash(string $expected, array $value): bool
    {
        return hash_equals($expected, $this->transferHash($value))
            || hash_equals($expected, $this->snapshots->hash($value));
    }

    private function transferHash(array $value): string
    {
        $previousPrecision = ini_get('serialize_precision');
        ini_set('serialize_precision', '100');
        try {
            return $this->snapshots->hash($value);
        } finally {
            if ($previousPrecision !== false) {
                ini_set('serialize_precision', $previousPrecision);
            }
        }
    }
}
