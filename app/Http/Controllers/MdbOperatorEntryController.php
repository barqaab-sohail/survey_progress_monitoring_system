<?php

namespace App\Http\Controllers;

use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\EntryLookups;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MdbOperatorEntryController extends Controller
{
    public function __construct(private WorkflowAccess $access, private EntryService $entries, private SnapshotService $snapshots, private AuditService $audit) {}

    public function data(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch);

        return response()->json($this->entries->editorData($batch));
    }

    public function save(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, ?EntryRow $row = null)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && (! $row || $row->transformer_id === $transformer->id), 404);
        $rules = ['revision' => 'required|integer', 'client_uuid' => 'required|uuid', 'save_uuid' => 'required|uuid',
            'pair_number' => 'required|integer|min:1|max:100000', 'designation' => 'required|in:S,E', 'group_number' => 'nullable|string|max:40', 'row_date' => 'nullable|date_format:Y-m-d',
            'waypoint_reference' => 'nullable|string|max:255', 'gpx_source_id' => 'nullable|integer', 'source_pdf_id' => 'nullable|integer',
            'source_page' => 'nullable|integer|min:1|max:10000', 'source_row' => 'nullable|string|max:40', 'conductors' => 'required|array:R,Y,B,N',
            'equipment_type' => 'nullable|string|max:255', 'pole_class' => 'nullable|string|max:255', 'pole_height' => 'nullable|numeric|min:0|max:200', 'pole_height_unit' => 'nullable|in:m,ft',
            'consumers' => 'required|array:rs,rl,sc,lc,si,li,pb,ag,st,pv', 'intersection' => 'required|boolean',
            'pv_details' => 'nullable|array|max:100', 'pv_details.*' => 'array:reference,service_load_kw,installed_capacity_kw,remarks',
            'pv_details.*.reference' => 'nullable|string|max:255', 'pv_details.*.service_load_kw' => 'nullable|numeric|min:0|max:1000000',
            'pv_details.*.installed_capacity_kw' => 'nullable|numeric|min:0|max:1000000', 'pv_details.*.remarks' => 'nullable|string|max:4000',
            'inheritance' => 'nullable|array:conductors,equipment,group_date', 'inheritance.*' => 'nullable|integer', 'manually_verified' => 'nullable|boolean',
            'identity_version' => 'nullable|in:2', 'composite_identifier' => 'nullable|string|regex:/^\d{11}$/D'];
        foreach (['R', 'Y', 'B', 'N'] as $phase) {
            $codes = array_merge(array_keys(app(EntryLookups::class)->options()['conductors']), ['__none__'], array_values($row?->conductors ?? []));
            $rules['conductors.'.$phase] = ['present', 'nullable', 'string', 'max:255', Rule::in(array_filter($codes, fn ($value) => $value !== null && $value !== ''))];
        }
        foreach (array_keys(EntryLookups::CONSUMERS) as $category) {
            $rules['consumers.'.$category] = 'nullable|integer|min:0|max:1000000';
        }
        $validated = $request->validate($rules);
        $identity = app(SurveyWaypoint::class);
        $settings = $identity->settings($batch->project_id);
        if (! empty($validated['inheritance']['group_date'])) {
            $previous = $transformer->entryRows()->findOrFail($validated['inheritance']['group_date']);
            $validated['group_number'] = $validated['group_number'] ?? $previous->group_number;
            $validated['row_date'] = $validated['row_date'] ?? $previous->row_date?->format('Y-m-d');
        }
        if (! empty($validated['composite_identifier']) && (empty($validated['group_number']) || empty($validated['row_date']) || empty($validated['waypoint_reference']))) {
            $parsed = $identity->parse($validated['composite_identifier'], $settings['two_digit_year_start']);
            foreach ($parsed as $key => $value) {
                if (! empty($validated[$key]) && $validated[$key] !== $value) {
                    throw ValidationException::withMessages([$key => 'Component differs from the pasted complete waypoint.']);
                }
                $validated[$key] = $value;
            }
        }
        $strict = ! empty($validated['identity_version']);
        if ($strict) {
            foreach (['group_number' => '/^\d{2}$/D', 'waypoint_reference' => '/^\d{3}$/D'] as $key => $pattern) {
                if (! empty($validated[$key]) && ! preg_match($pattern, $validated[$key])) {
                    throw ValidationException::withMessages([$key => $key === 'group_number' ? 'Enter exactly two group digits.' : 'Enter exactly three GPS digits.']);
                }
            }
        }
        $complete = null;
        if ($strict || (preg_match('/^\d{2}$/D', $validated['group_number'] ?? '') && preg_match('/^\d{3}$/D', $validated['waypoint_reference'] ?? ''))) {
            $complete = $identity->compose($validated['group_number'] ?? null, $validated['row_date'] ?? null, $validated['waypoint_reference'] ?? null, $settings['two_digit_year_start']);
        }
        if (! empty($validated['composite_identifier']) && $validated['composite_identifier'] !== $complete) {
            throw ValidationException::withMessages(['composite_identifier' => 'Complete waypoint differs from the row components.']);
        }
        $validated['composite_identifier'] = $complete;
        if ($strict) {
            $copiedEquipment = ! empty($validated['inheritance']['equipment']) ? $transformer->entryRows()->findOrFail($validated['inheritance']['equipment']) : null;
            $unit = $row?->pole_height_unit ?? $copiedEquipment?->pole_height_unit ?? $settings['pole_height_unit'];
            if (! empty($validated['pole_height_unit']) && $validated['pole_height_unit'] !== $unit) {
                throw ValidationException::withMessages(['pole_height_unit' => 'Project unit differs from this draft. Refresh saved records and check the pole height before saving.']);
            }
            $validated['pole_height_unit'] = $unit;
            unset($validated['source_row']);
        }
        if (empty($validated['source_pdf_id'])) {
            $validated['source_pdf_id'] = $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->count() === 1 ? $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->value('id') : null;
        }
        if (empty($validated['gpx_source_id'])) {
            $matches = $identity->matches($validated, $batch->waypoints->toArray());
            if (count($matches) === 1) {
                $validated['gpx_source_id'] = $matches[0]['source_file_id'];
            }
        }
        foreach (['source_pdf_id' => 'pdf', 'gpx_source_id' => 'gpx'] as $field => $kind) {
            if (! empty($validated[$field])) {
                $source = $batch->sources()->where('kind', $kind)->findOrFail($validated[$field]);
                if ($kind === 'pdf' && ! empty($validated['source_page']) && isset($source->metadata['page_count']) && $validated['source_page'] > $source->metadata['page_count']) {
                    throw ValidationException::withMessages(['source_page' => 'Page is outside the selected PDF.']);
                }
            }
        }
        $data = $this->entries->normalized($validated);
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 256 * 1024) {
            throw ValidationException::withMessages(['pv_details' => 'This row exceeds the 256 KB entry limit.']);
        }
        $hash = $this->snapshots->hash($data);
        DB::transaction(function () use ($request, $batch, $transformer, $row, $validated, $data, $hash) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $existing = $row ? $transformer->entryRows()->findOrFail($row->id) : $transformer->entryRows()->where('client_uuid', $validated['client_uuid'])->first();
            if ($existing && $existing->save_uuid === $validated['save_uuid'] && hash_equals($existing->save_hash, $hash)) {
                return; // Repeated click/network retry, including a stale revision, is a no-op.
            }
            if (! $row && $existing) {
                throw ValidationException::withMessages(['client_uuid' => 'This row already exists. Reload it before making changes.']);
            }
            $this->assertRevision($locked, (int) $validated['revision']);
            if ($row && ($row->pair_number !== (int) $data['pair_number'] || $row->designation !== $data['designation'] || $row->client_uuid !== $validated['client_uuid'])) {
                throw ValidationException::withMessages(['designation' => 'Keep the saved pair and S/E designation. Delete and add a corrected row to move it.']);
            }
            if ($transformer->entryRows()->where('pair_number', $data['pair_number'])->where('designation', $data['designation'])->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                throw ValidationException::withMessages(['designation' => 'This pair already contains that S/E row. Edit the saved row.']);
            }
            foreach ($data['inheritance'] as $scope => $sourceId) {
                if (! $sourceId) {
                    continue;
                }
                $source = $transformer->entryRows()->findOrFail($sourceId);
                $keys = match ($scope) {
                    'conductors' => ['conductors'], 'group_date' => ['group_number', 'row_date'], default => ['equipment_type', 'pole_class', 'pole_height', 'pole_height_unit']
                };
                foreach ($keys as $key) {
                    $sourceValue = $key === 'row_date' ? $source->row_date?->format('Y-m-d') : $source->$key;
                    if ($sourceValue != ($data[$key] ?? null)) {
                        throw ValidationException::withMessages(['inheritance' => 'Copied values changed; clear the copy marker or copy them again.']);
                    }
                }
            }
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $values = $data + ['client_uuid' => $validated['client_uuid'], 'save_uuid' => $validated['save_uuid'], 'save_hash' => $hash];
            if (($data['identity_version'] ?? null) == 2) {
                $values['entry_sequence'] = $existing?->entry_sequence ?? ((int) $locked->transformers()->withMax('entryRows', 'entry_sequence')->get()->max('entry_rows_max_entry_sequence') + 1);
                $values['source_row'] = 'Entry '.$values['entry_sequence'];
            }
            $values['original_entry'] = $existing?->original_entry ?? ['first_entry' => $data];
            $record = $existing ? tap($existing)->update($values) : $transformer->entryRows()->create($values);
            $this->entries->synchronizePair($transformer, $record->pair_number);
            $this->entries->synchronizePv($record->fresh());
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.entry_row_saved', $record, $old, $this->snapshots->snapshot($locked));
        });

        return response()->json($this->entries->editorData($batch->fresh()));
    }

    public function destroy(Request $request, SurveyBatch $batch, NetworkTransformer $transformer, EntryRow $row)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless($transformer->batch_id === $batch->id && $row->transformer_id === $transformer->id, 404);
        $request->validate(['revision' => 'required|integer', 'confirmed' => 'accepted']);
        DB::transaction(function () use ($request, $batch, $transformer, $row) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertRevision($locked, (int) $request->input('revision'));
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $row->pvRecords()->delete();
            $row->delete();
            $this->entries->synchronizePair($transformer, $row->pair_number);
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.entry_row_deleted', $locked, $old, $this->snapshots->snapshot($locked), 'Operator confirmed row deletion.');
        });

        return response()->json($this->entries->editorData($batch->fresh()));
    }

    public function header(Request $request, SurveyBatch $batch, ?NetworkTransformer $transformer = null)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        abort_unless(! $transformer || $transformer->batch_id === $batch->id, 404);
        $data = $request->validate(['revision' => 'required|integer', 'code' => ['required', 'string', 'max:255', Rule::unique('mdb_workflow_transformers', 'code')->where('batch_id', $batch->id)->ignore($transformer?->id)],
            'capacity_kva' => 'nullable|numeric|min:0.001|max:1000000', 'source_waypoint_id' => 'nullable|integer', 'header' => 'required|array',
            'header.survey_date' => 'nullable|date_format:Y-m-d', 'header.mounting' => ['nullable', Rule::in(array_filter(['Single Pole', 'Double Pole', 'Pad', $transformer?->header['mounting'] ?? null]))],
            'header.service_category' => 'nullable|in:General Duty,Dedicated', 'header.source_survey_identifier' => 'nullable|string|regex:/^\d{11}$/D', 'source_pdf_id' => 'nullable|integer', 'source_page' => 'nullable|integer|min:1']);
        $fields = ['substation_name', 'substation_identifier', 'feeder_identifier', 'feeder_name', 'division', 'subdivision', 'subdivision_code',
            'make', 'inspector', 'location', 'survey_date', 'mounting', 'service_category', 'team_group', 'source_survey_identifier'];
        foreach ($fields as $field) {
            $request->validate(['header.'.$field => 'nullable|string|max:500']);
        }
        if (! empty($data['source_waypoint_id'])) {
            $batch->waypoints()->findOrFail($data['source_waypoint_id']);
        }
        $page = null;
        if (! empty($data['source_pdf_id']) && ! empty($data['source_page'])) {
            $pdf = $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->findOrFail($data['source_pdf_id']);
            abort_unless($data['source_page'] <= ($pdf->metadata['page_count'] ?? 0), 422, 'Page is outside the PDF.');
            $page = ['source_pdf_id' => (int) $pdf->id, 'page' => (int) $data['source_page'], 'role' => 'header', 'confirmed' => true];
        }
        $record = null;
        DB::transaction(function () use ($request, $batch, $transformer, $data, $fields, $page, &$record) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertRevision($locked, (int) $data['revision']);
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $header = array_replace($transformer?->header ?? [], array_intersect_key($data['header'], array_flip($fields)), ['manually_verified' => false, 'operator_entry' => true]);
            if ($page && ! collect($header['pages'] ?? [])->contains(fn ($existing) => $existing['source_pdf_id'] === $page['source_pdf_id'] && $existing['page'] === $page['page'])) {
                $header['pages'][] = $page;
            }
            $values = ['code' => $data['code'], 'capacity_kva' => $data['capacity_kva'] ?? null, 'source_waypoint_id' => $data['source_waypoint_id'] ?? null,
                'header' => $header, 'original_header' => $transformer?->original_header ?? ($transformer ? ['code' => $transformer->code, 'capacity_kva' => $transformer->capacity_kva] + ($transformer->header ?? []) : ['code' => $data['code'], 'capacity_kva' => $data['capacity_kva'] ?? null] + $data['header'])];
            $record = $transformer ? tap($transformer)->update($values) : $locked->transformers()->create($values);
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.operator_header_saved', $record, $old, $this->snapshots->snapshot($locked));
        });

        return response()->json($this->entries->editorData($batch->fresh()) + ['active_transformer_id' => $record->id]);
    }

    public function confirmReview(Request $request, SurveyBatch $batch)
    {
        $this->access->authorize($request->user(), $batch, 'edit');
        $data = $request->validate(['revision' => 'required|integer', 'transformer_id' => 'required|integer', 'confirmed' => 'accepted']);
        $transformer = $batch->transformers()->findOrFail($data['transformer_id']);
        DB::transaction(function () use ($request, $batch, $data, $transformer) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->assertRevision($locked, (int) $data['revision']);
            $issues = collect($this->entries->issues($locked))->where('transformer_id', $transformer->id);
            if ($issues->isNotEmpty()) {
                throw ValidationException::withMessages(['rows' => $issues->pluck('message')->all()]);
            }
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $transformer->update(['header' => array_replace($transformer->header ?? [], ['manually_verified' => true])]);
            $transformer->entryRows()->update(['manually_verified' => true]);
            foreach ($transformer->entryRows()->pluck('pair_number')->unique() as $number) {
                $this->entries->synchronizePair($transformer, $number);
            }
            $transformer->pvRecords()->whereNotNull('entry_row_id')->get()->each(fn ($pv) => $pv->update(['original_entry' => array_replace($pv->original_entry ?? [], ['manually_verified' => true])]));
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.operator_review_saved', $transformer, $old, $this->snapshots->snapshot($locked));
        });

        return back()->with('success', 'Header and S/E rows checked and saved. Engineering verification is still required before export.');
    }

    private function assertRevision(SurveyBatch $batch, int $revision): void
    {
        if ($batch->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Another save or source job changed this batch. Your draft is retained; reload current records before saving.']);
        }
    }
}
