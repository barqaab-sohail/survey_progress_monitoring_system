<?php

namespace App\Services\Mdb;

use App\Models\Mdb\SurveyBatch;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class TransformerTransfer
{
    public function __construct(private SnapshotService $snapshots, private DataEntryMdbExport $exporter, private DataEntryPayload $mapper) {}

    public function export(SurveyBatch $batch, int $transformerId): array
    {
        $prepared = $this->exporter->prepare($batch, $transformerId);
        $transformer = collect($batch->approvedRevision->snapshot['transformers'])->firstWhere('id', $transformerId);
        $content = ['format' => 'hazeco-transformer-data', 'version' => 1,
            'source' => ['batch_id' => $batch->id, 'revision_number' => $prepared['revision_number'],
                'revision_sha256' => $prepared['revision_sha256'], 'exported_at' => now()->utc()->toIso8601String()],
            'snapshot' => ['transformers' => [$transformer]]];

        return $content + ['sha256' => $this->snapshots->hash($content)];
    }

    public function read(string $json): array
    {
        try {
            $package = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalid('Choose a valid Transformer JSON file downloaded from the production application.');
        }
        if (! is_array($package)) {
            $this->invalid('The uploaded file is not a Transformer JSON package.');
        }
        $rules = [
            'format' => 'required|in:hazeco-transformer-data', 'version' => 'required|integer|in:1',
            'sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'source' => 'required|array',
            'source.batch_id' => 'required|integer|min:1', 'source.revision_number' => 'required|integer|min:1',
            'source.revision_sha256' => 'required|string|regex:/^[a-f0-9]{64}$/D', 'source.exported_at' => 'required|date',
            'snapshot' => 'required|array', 'snapshot.transformers' => 'required|array|list|size:1',
            'snapshot.transformers.0' => 'required|array', 'snapshot.transformers.0.id' => 'required|integer|min:1',
            'snapshot.transformers.0.code' => 'required|string|max:255', 'snapshot.transformers.0.capacity_kva' => 'nullable|numeric|gt:0',
            'snapshot.transformers.0.header' => 'required|array', 'snapshot.transformers.0.header.manually_verified' => 'accepted',
            'snapshot.transformers.0.entry_rows' => 'required|array|list|min:2|max:20000',
            'snapshot.transformers.0.entry_rows.*' => 'required|array',
            'snapshot.transformers.0.entry_rows.*.id' => 'required|integer|min:1',
            'snapshot.transformers.0.entry_rows.*.pair_number' => 'required|integer|min:1',
            'snapshot.transformers.0.entry_rows.*.designation' => 'required|in:S,E',
            'snapshot.transformers.0.entry_rows.*.manually_verified' => 'accepted',
            'snapshot.transformers.0.entry_rows.*.row_date' => 'nullable|date_format:Y-m-d',
            'snapshot.transformers.0.entry_rows.*.conductors' => 'nullable|array',
            'snapshot.transformers.0.entry_rows.*.conductors.*' => 'nullable|string|max:255',
            'snapshot.transformers.0.entry_rows.*.consumers' => 'nullable|array',
            'snapshot.transformers.0.entry_rows.*.consumers.*' => 'nullable|integer|min:0|max:1000000',
            'snapshot.transformers.0.entry_rows.*.pole_height' => 'nullable|numeric|gt:0',
            'snapshot.transformers.0.entry_rows.*.pv_details' => 'nullable|array|list',
            'snapshot.transformers.0.entry_rows.*.pv_details.*' => 'required|array',
            'snapshot.transformers.0.entry_rows.*.pv_details.*.installed_capacity_kw' => 'nullable|numeric|min:0',
            'snapshot.transformers.0.entry_rows.*.pv_details.*.service_load_kw' => 'nullable|numeric|min:0',
            'snapshot.transformers.0.pv_records' => 'nullable|array|list', 'snapshot.transformers.0.pv_records.*' => 'required|array',
            'snapshot.transformers.0.pv_records.*.original_entry' => 'nullable|array',
            'snapshot.transformers.0.sections' => 'nullable|array|list', 'snapshot.transformers.0.sections.*' => 'required|array',
            'snapshot.transformers.0.sections.*.original_entry' => 'required|array',
        ];
        foreach (['substation_name', 'feeder_name', 'feeder_identifier', 'make', 'location', 'mounting', 'service_category'] as $field) {
            $rules['snapshot.transformers.0.header.'.$field] = 'nullable|string|max:4000';
        }
        foreach (['group_number', 'waypoint_reference', 'composite_identifier', 'pole_height_unit', 'pole_class', 'equipment_type'] as $field) {
            $rules['snapshot.transformers.0.entry_rows.*.'.$field] = 'nullable|string|max:255';
        }
        foreach (['reference', 'meter_number', 'remarks'] as $field) {
            $rules['snapshot.transformers.0.entry_rows.*.pv_details.*.'.$field] = 'nullable|string|max:4000';
        }
        $validation = Validator::make($package, $rules);
        if ($validation->fails()) {
            $this->invalid('Invalid or unsupported Transformer JSON: '.$validation->errors()->first());
        }
        $content = $package;
        unset($content['sha256']);
        if (! hash_equals($package['sha256'], $this->snapshots->hash($content))) {
            $this->invalid('The Transformer JSON checksum does not match. Download the file again without editing it.');
        }
        // Validate original observations with the same mapper used by production.
        // No local survey records or approvals are created from a transfer file.
        try {
            $payload = $this->mapper->build($package['snapshot']);
        } catch (ValidationException $exception) {
            $this->invalid('The Transformer JSON cannot be converted: '.$exception->getMessage());
        }

        return ['package' => $package, 'payload' => $payload];
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
