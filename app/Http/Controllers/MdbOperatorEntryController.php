<?php

namespace App\Http\Controllers;

use App\Models\Mdb\EntryRow;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\PdfRow;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\EntryLookups;
use App\Services\Mdb\EntryService;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\StartingPoleCoordinates;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\TransformerIdentity;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

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
        $this->authorizeEntry($request, $batch);
        abort_unless($transformer->batch_id === $batch->id && (! $row || $row->transformer_id === $transformer->id), 404);
        $rules = ['revision' => 'required|integer', 'client_uuid' => 'required|uuid', 'save_uuid' => 'required|uuid',
            'pair_number' => 'required|integer|min:1|max:100000', 'designation' => 'required|in:S,E', 'group_number' => 'nullable|string|max:40', 'row_date' => 'nullable|date_format:Y-m-d',
            'waypoint_reference' => 'nullable|string|max:255', 'gpx_source_id' => 'nullable|integer', 'source_pdf_id' => 'nullable|integer',
            'source_page' => 'nullable|integer|min:1|max:10000', 'source_row' => 'nullable|string|max:40', 'conductors' => 'required|array:R,Y,B,N',
            'equipment_type' => 'required|string|max:255', 'pole_class' => 'required|string|max:255', 'pole_height' => 'required|numeric|gt:0|max:10000', 'pole_height_unit' => 'nullable|in:m,ft',
            'consumers' => 'required|array:rs,rl,sc,lc,si,li,pb,ag,st,pv', 'intersection' => 'required|boolean',
            'consumer_phases' => 'sometimes|array:rs,sc,st',
            'pv_details' => 'nullable|array|max:100', 'pv_details.*' => 'array:reference,service_load_kw,installed_capacity_kw,meter_number,remarks',
            'pv_details.*.reference' => 'nullable|string|max:255', 'pv_details.*.service_load_kw' => 'nullable|numeric|min:0|max:1000000',
            'pv_details.*.installed_capacity_kw' => 'nullable|numeric|min:0|max:1000000', 'pv_details.*.meter_number' => 'nullable|string|max:255', 'pv_details.*.remarks' => 'nullable|string|max:4000',
            'inheritance' => 'nullable|array:conductors,equipment,group_date', 'inheritance.*' => 'nullable|integer', 'manually_verified' => 'nullable|boolean',
            'identity_version' => 'nullable|in:2', 'composite_identifier' => 'nullable|string|regex:/^\d{11}$/D', 'pdf_progress_row_id' => 'nullable|integer'];
        foreach (['R', 'Y', 'B', 'N'] as $phase) {
            $codes = array_merge(array_keys(app(EntryLookups::class)->options($batch->project_id)['conductors']), ['__none__'], array_values($row?->conductors ?? []));
            $rules['conductors.'.$phase] = ['present', 'nullable', 'string', 'max:255', Rule::in(array_filter($codes, fn ($value) => $value !== null && $value !== ''))];
        }
        foreach (array_keys(EntryLookups::CONSUMERS) as $category) {
            $rules['consumers.'.$category] = 'nullable|integer|min:0|max:1000000';
        }
        foreach (EntryLookups::SINGLE_PHASE_CONSUMERS as $category) {
            $rules['consumer_phases.'.$category] = 'sometimes|array:R,Y,B|required_array_keys:R,Y,B';
            foreach (['R', 'Y', 'B'] as $phase) {
                $rules['consumer_phases.'.$category.'.'.$phase] = 'nullable|integer|min:0|max:1000000';
            }
        }
        if ((string) $request->input('identity_version') === '2') {
            $rules['pole_height_unit'] = 'nullable|in:ft';
        }
        $validated = $request->validate($rules, [
            'row_date.date_format' => 'Enter a valid calendar date in DD/MM/YYYY.',
            'conductors.*.in' => 'Select a listed conductor code or None / Not Present.',
            'consumers.*.integer' => ':attribute must be a whole number; use 0 for none or leave it blank.',
            'consumers.*.min' => ':attribute cannot be negative; use 0 for none or leave it blank.',
            'consumers.*.max' => ':attribute cannot exceed 1,000,000.',
            'pole_height.numeric' => 'Pole/tower height must be a number in the displayed unit.',
            'pole_height.min' => 'Pole/tower height cannot be negative.',
            'pole_height.max' => 'Pole/tower height cannot exceed 200 in the displayed unit.',
            'pv_details.*.service_load_kw.numeric' => 'PV service load must be a number in kW.',
            'pv_details.*.installed_capacity_kw.numeric' => 'PV solar capacity must be a number in kW.',
        ], ['row_date' => 'Row date', 'group_number' => 'Group', 'waypoint_reference' => 'GPS number',
            'equipment_type' => 'Type', 'pole_class' => 'Pole class', 'pole_height' => 'Pole height', 'pair_number' => 'Section pair',
            ...collect(EntryLookups::CONSUMERS)->mapWithKeys(fn ($description, $key) => ['consumers.'.$key => strtoupper($key).' consumer count'])->all()]);
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
        validator($validated, ['group_number' => 'required', 'waypoint_reference' => 'required'], [],
            ['group_number' => 'Group', 'waypoint_reference' => 'GPS number'])->validate();
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
        $copiedEquipment = ! empty($validated['inheritance']['equipment']) ? $transformer->entryRows()->findOrFail($validated['inheritance']['equipment']) : null;
        $unit = $validated['pole_height_unit'] ?? $row?->pole_height_unit ?? $copiedEquipment?->pole_height_unit ?? 'ft';
        if ($unit === 'm') {
            $validated['pole_height'] = round((float) $validated['pole_height'] * 3.280839895, 4);
        }
        $validated['pole_height_unit'] = 'ft';
        if ($strict) {
            unset($validated['source_row']);
        }
        if (empty($validated['source_pdf_id'])) {
            $validated['source_pdf_id'] = $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->count() === 1 ? $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->value('id') : null;
        }
        $firstPair = min((int) $validated['pair_number'], (int) ($transformer->entryRows()->min('pair_number') ?? $validated['pair_number']));
        $firstStart = $validated['designation'] === 'S' && (int) $validated['pair_number'] === $firstPair;
        if (empty($validated['gpx_source_id']) && ! $firstStart) {
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
        if ($request->attributes->get('mdb_verifier_correction')) {
            $validated['manually_verified'] = false;
        }
        // Older clients can edit a row without sending its phase metadata.
        $savedPhases = $row?->original_entry['consumer_phases'] ?? [];
        if ($savedPhases || array_key_exists('consumer_phases', $validated)) {
            $validated['consumer_phases'] = array_replace($savedPhases, $validated['consumer_phases'] ?? []);
        }
        $data = $this->entries->normalized($validated);
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 256 * 1024) {
            throw ValidationException::withMessages(['pv_details' => 'This row exceeds the 256 KB entry limit.']);
        }
        $hash = $this->snapshots->hash($data + (! empty($validated['pdf_progress_row_id']) ? ['pdf_progress_row_id' => $validated['pdf_progress_row_id']] : []));
        DB::transaction(function () use ($request, $batch, $transformer, $row, $validated, $data, $hash) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $existing = $row ? $transformer->entryRows()->findOrFail($row->id) : $transformer->entryRows()->where('client_uuid', $validated['client_uuid'])->first();
            if ($existing && $existing->save_uuid === $validated['save_uuid'] && hash_equals($existing->save_hash, $hash)) {
                return; // Repeated click/network retry, including a stale revision, is a no-op.
            }
            if (! $row && $existing) {
                throw ValidationException::withMessages(['client_uuid' => 'This row already exists. Reload it before making changes.']);
            }
            $this->authorizeEntry($request, $locked);
            if (! $request->attributes->get('mdb_verifier_correction')) {
                $this->access->recordEntry($locked, $request->user());
            }
            $this->assertRevision($locked, (int) $validated['revision']);
            if ($row && ($row->pair_number !== (int) $data['pair_number'] || $row->designation !== $data['designation'] || $row->client_uuid !== $validated['client_uuid'])) {
                throw ValidationException::withMessages(['designation' => 'Keep the saved pair and S/E designation. Delete and add a corrected row to move it.']);
            }
            if ($transformer->entryRows()->where('pair_number', $data['pair_number'])->where('designation', $data['designation'])->when($row, fn ($q) => $q->whereKeyNot($row->id))->exists()) {
                throw ValidationException::withMessages(['designation' => 'This pair already contains that S/E row. Edit the saved row.']);
            }
            $progress = ! empty($validated['pdf_progress_row_id'])
                ? PdfRow::whereIn('source_file_id', $locked->sources()->where('kind', 'pdf')->pluck('id'))->findOrFail($validated['pdf_progress_row_id'])
                : ($existing ? PdfRow::where('entry_row_id', $existing->id)->first() : null);
            if (! $progress && PdfRow::where('source_file_id', $data['source_pdf_id'])->where('page', $data['source_page'])->exists()) {
                throw ValidationException::withMessages(['pdf_progress_row_id' => 'Select the corresponding PDF row before saving this record.']);
            }
            if ($progress) {
                if ($progress->source_file_id !== ($data['source_pdf_id'] ?? null) || $progress->page !== ($data['source_page'] ?? null)) {
                    throw ValidationException::withMessages(['source_page' => 'The selected PDF row must match this record\'s PDF and page.']);
                }
                if ($progress->entry_row_id && $progress->entry_row_id !== $existing?->id) {
                    throw ValidationException::withMessages(['pdf_progress_row_id' => 'This PDF row already has a saved record. Select it to edit that record.']);
                }
                if ($existing && PdfRow::where('entry_row_id', $existing->id)->whereKeyNot($progress->id)->exists()) {
                    throw ValidationException::withMessages(['pdf_progress_row_id' => 'This record already belongs to another PDF row.']);
                }
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
                        // Copying is a starting point, not a restriction. Keep source provenance only
                        // while the copied scope still matches; manual corrections are valid values.
                        unset($data['inheritance'][$scope]);
                        break;
                    }
                }
            }
            $old = $this->snapshots->snapshot($locked);
            $this->invalidateEntry($request, $locked);
            $values = $data + ['client_uuid' => $validated['client_uuid'], 'save_uuid' => $validated['save_uuid'], 'save_hash' => $hash];
            if (($data['identity_version'] ?? null) == 2) {
                $values['entry_sequence'] = $existing?->entry_sequence ?? ((int) $locked->transformers()->withMax('entryRows', 'entry_sequence')->get()->max('entry_rows_max_entry_sequence') + 1);
                $values['source_row'] = $progress ? 'PDF row '.$progress->row_number : 'Entry '.$values['entry_sequence'];
            }
            if ($progress) {
                $values['source_row'] = 'PDF row '.$progress->row_number;
            }
            $values['original_entry'] = $existing?->original_entry ?? ['first_entry' => $data];
            if (array_key_exists('consumer_phases', $data)) {
                $values['original_entry']['consumer_phases'] = $data['consumer_phases'];
            }
            unset($values['consumer_phases']);
            $record = $existing ? tap($existing)->update($values) : $transformer->entryRows()->create($values);
            if ($progress) {
                $progress->update(['entry_row_id' => $record->id, 'entered' => true]);
            }
            $this->entries->synchronizePair($transformer, $record->pair_number);
            $this->entries->synchronizeTransformerLocation($transformer);
            $this->entries->synchronizePv($record->fresh());
            $locked->increment('revision');
            $this->audit->record($request->user(), $request->attributes->get('mdb_verifier_correction') ? 'mdb.verifier_row_corrected' : 'mdb.entry_row_saved', $record, $old, $this->snapshots->snapshot($locked), $request->input('correction_reason'));
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
            $this->access->authorize($request->user(), $locked, 'edit');
            $this->access->recordEntry($locked, $request->user());
            $this->assertRevision($locked, (int) $request->input('revision'));
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $row->pvRecords()->delete();
            PdfRow::where('entry_row_id', $row->id)->update(['entry_row_id' => null, 'entered' => false]);
            $row->delete();
            $this->entries->synchronizePair($transformer, $row->pair_number);
            $this->entries->synchronizeTransformerLocation($transformer);
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.entry_row_deleted', $locked, $old, $this->snapshots->snapshot($locked), 'Operator confirmed row deletion.');
        });

        return response()->json($this->entries->editorData($batch->fresh()));
    }

    public function header(Request $request, SurveyBatch $batch, ?NetworkTransformer $transformer = null)
    {
        $reference = (string) Str::uuid();
        try {
            return $this->persistHeader($request, $batch, $transformer);
        } catch (Throwable $exception) {
            $status = 500;
            $errors = [];
            $message = 'The server could not confirm saving the survey header. Refresh saved records to check whether it was saved before retrying. If this continues, contact the administrator with the reference below.';
            if ($exception instanceof ValidationException) {
                $status = 422;
                $errors = $exception->errors();
                $message = 'Correct the invalid or missing header fields listed below and save again.';
            } elseif ($exception instanceof ModelNotFoundException) {
                $status = 404;
                $message = 'The selected transformer, PDF or GPS waypoint is no longer available in this survey. Refresh saved records and select it again.';
            } elseif ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                $status = $exception->getStatusCode();
                $message = $exception->getMessage() ?: match ($status) {
                    403 => 'You do not have permission to save this survey header, or survey entry is locked.',
                    404 => 'The selected transformer or survey could not be found. Refresh saved records and try again.',
                    default => 'The survey header could not be saved. Refresh saved records and try again.',
                };
            }
            $context = ['reference' => $reference, 'batch_id' => $batch->id, 'transformer_id' => $transformer?->id,
                'user_id' => $request->user()?->id, 'revision' => $request->input('revision'), 'http_status' => $status,
                'validation_errors' => $errors, 'exception' => $exception];
            if ($status >= 500) {
                Log::error('Survey header save failed', $context);
            } else {
                Log::warning('Survey header save rejected', $context);
            }

            return response()->json(['message' => $message, 'errors' => $errors, 'reference' => $reference], $status);
        }
    }

    private function persistHeader(Request $request, SurveyBatch $batch, ?NetworkTransformer $transformer)
    {
        $this->authorizeEntry($request, $batch);
        abort_unless(! $transformer || $transformer->batch_id === $batch->id, 404);
        if ($transformer) {
            $request->merge(['code' => $transformer->code]);
        } elseif (! $request->boolean('manual_transformer')) {
            $selection = $request->validate(['registered_transformer_id' => ['required', 'integer', Rule::exists('transformers', 'id')->where('feeder_id', $batch->feeder_id)]], [
                'registered_transformer_id.required' => 'Choose a registered transformer from this feeder in Active transformer.',
                'registered_transformer_id.exists' => 'Choose a registered transformer belonging to this survey feeder.',
            ]);
            $registered = $batch->feeder->transformers()->findOrFail($selection['registered_transformer_id']);
            $request->merge(['code' => $registered->transformer_code]);
        }
        if (! $transformer && is_string($request->input('code'))) {
            $request->merge(['code' => trim($request->input('code'))]);
        }
        $rules = ['manual_transformer' => 'sometimes|boolean', 'revision' => 'required|integer', 'code' => ['required', 'string', 'max:255', Rule::unique('mdb_workflow_transformers', 'code')->where('batch_id', $batch->id)->ignore($transformer?->id)],
            'capacity_kva' => 'required|numeric|gt:0|max:1000000', 'source_waypoint_id' => 'nullable|integer', 'header' => 'required|array',
            'header.survey_date' => 'nullable|date_format:Y-m-d', 'header.mounting' => ['nullable', Rule::in(array_filter(['Single Pole', 'Double Pole', 'Pad', $transformer?->header['mounting'] ?? null]))],
            'header.inspector' => 'required|string|max:500', 'header.location' => 'required|string|max:500',
            'header.service_category' => 'required|in:General Duty,Dedicated', 'header.source_survey_identifier' => 'nullable|string|regex:/^\d{11}$/D', 'source_pdf_id' => 'required|integer', 'source_page' => 'required|integer|min:1'];
        $fields = ['substation_name', 'substation_identifier', 'feeder_identifier', 'feeder_name', 'division', 'subdivision', 'subdivision_code',
            'make', 'inspector', 'location', 'survey_date', 'mounting', 'service_category', 'team_group', 'source_survey_identifier', 'client_transformer_information'];
        foreach ($fields as $field) {
            $rules['header.'.$field] ??= 'nullable|string|max:500';
        }
        $attributes = ['code' => 'transformer code', 'capacity_kva' => 'transformer capacity (kVA)', 'source_waypoint_id' => 'transformer GPS waypoint',
            'source_pdf_id' => 'source PDF', 'source_page' => 'PDF page'];
        foreach ($fields as $field) {
            $attributes['header.'.$field] = str_replace('_', ' ', $field);
        }
        $data = $request->validate($rules, [
            'header.inspector.required' => 'Enter the inspector name.',
            'header.location.required' => 'Enter the transformer location.',
            'header.service_category.required' => 'Choose a service category.',
            'source_pdf_id.required' => 'Choose the PDF containing this transformer.',
            'source_page.required' => 'Enter the starting PDF page number for this transformer.',
            'code.required' => 'Enter a transformer code or choose a registered transformer.',
            'code.unique' => 'This transformer is already added to this survey. Select its saved header in Active transformer to edit it.',
            'header.source_survey_identifier.regex' => 'Enter an 11-digit complete transformer waypoint (GGDDMMYYWWW), or leave it blank.',
            'header.survey_date.date_format' => 'Enter a valid survey date in YYYY-MM-DD format.',
        ], $attributes);
        if (! empty($data['source_waypoint_id'])) {
            if (! $batch->waypoints()->whereKey($data['source_waypoint_id'])->exists()) {
                throw ValidationException::withMessages(['source_waypoint_id' => 'Choose a transformer GPS waypoint from this survey\'s GPX sources.']);
            }
        }
        $page = null;
        if (! empty($data['source_pdf_id']) && ! empty($data['source_page'])) {
            $pdf = $batch->sources()->where('kind', 'pdf')->where('status', 'ready')->find($data['source_pdf_id']);
            if (! $pdf) {
                throw ValidationException::withMessages(['source_pdf_id' => 'Choose a PDF from this survey that has finished processing.']);
            }
            if ($data['source_page'] > ($pdf->metadata['page_count'] ?? 0)) {
                throw ValidationException::withMessages(['source_page' => 'The selected page is outside this PDF. Choose a valid PDF page.']);
            }
            $page = ['source_pdf_id' => (int) $pdf->id, 'page' => (int) $data['source_page'], 'role' => 'header', 'confirmed' => true];
        }
        $record = null;
        DB::transaction(function () use ($request, $batch, $transformer, $data, $fields, $page, &$record) {
            $locked = SurveyBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorizeEntry($request, $locked);
            if (! $request->attributes->get('mdb_verifier_correction')) {
                $this->access->recordEntry($locked, $request->user());
            }
            $this->assertRevision($locked, (int) $data['revision']);
            $old = $this->snapshots->snapshot($locked);
            $this->invalidateEntry($request, $locked);
            $header = array_replace($transformer?->header ?? [], array_intersect_key($data['header'], array_flip($fields)), ['manually_verified' => false, 'operator_entry' => true]);
            app(TransformerIdentity::class)->assertAvailable($locked, $data['code'], $transformer);
            $registered = null;
            if (! $transformer && $request->boolean('manual_transformer')) {
                if ($locked->feeder->transformers()->whereRaw('LOWER(TRIM(transformer_code)) = ?', [mb_strtolower($data['code'])])->exists()) {
                    throw ValidationException::withMessages(['code' => 'This transformer is registered with this feeder. Choose it in Active transformer to add its survey header.']);
                }
                $header['manual_transformer'] = true;
            } elseif (! $transformer) {
                $registered = $locked->feeder->transformers()->lockForUpdate()->find($request->input('registered_transformer_id'));
                if (! $registered || $registered->transformer_code !== $data['code']) {
                    throw ValidationException::withMessages(['registered_transformer_id' => 'The selected feeder registration changed. Refresh saved records and select the transformer again.']);
                }
                $header['registered_transformer_id'] = $registered->id;
            }
            if (! $transformer) {
                // New selections opt in. Existing records and approved snapshots are never backfilled.
                $header['starting_pole_coordinates'] = app(StartingPoleCoordinates::class)->capture($registered);
            }
            $header['pdf_source_id'] = (int) $data['source_pdf_id'];
            $header['pdf_start_page'] = (int) $data['source_page'];
            if ($page && ! collect($header['pages'] ?? [])->contains(fn ($existing) => $existing['source_pdf_id'] === $page['source_pdf_id'] && $existing['page'] === $page['page'])) {
                $header['pages'][] = $page;
            }
            $values = ['code' => $data['code'], 'capacity_kva' => $data['capacity_kva'] ?? null, 'source_waypoint_id' => $transformer?->source_waypoint_id,
                'header' => $header, 'original_header' => $transformer?->original_header ?? ($transformer ? ['code' => $transformer->code, 'capacity_kva' => $transformer->capacity_kva] + ($transformer->header ?? []) : ['code' => $data['code'], 'capacity_kva' => $data['capacity_kva'] ?? null] + $data['header'])];
            $record = $transformer ? tap($transformer)->update($values) : $locked->transformers()->create($values);
            $this->entries->synchronizeTransformerLocation($record);
            $locked->increment('revision');
            $this->audit->record($request->user(), $request->attributes->get('mdb_verifier_correction') ? 'mdb.verifier_header_corrected' : 'mdb.operator_header_saved', $record, $old, $this->snapshots->snapshot($locked), $request->input('correction_reason'));
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
            $this->access->authorize($request->user(), $locked, 'edit');
            $this->access->recordEntry($locked, $request->user());
            $this->assertRevision($locked, (int) $data['revision']);
            $issues = collect($this->entries->issues($locked))->where('transformer_id', $transformer->id);
            if ($issues->isNotEmpty()) {
                throw ValidationException::withMessages(['rows' => $issues->pluck('message')->all()]);
            }
            $old = $this->snapshots->snapshot($locked);
            $this->snapshots->invalidate($locked);
            $this->entries->synchronizeTransformerLocation($transformer);
            $transformer->update(['header' => array_replace($transformer->header ?? [], ['manually_verified' => true])]);
            $transformer->entryRows()->update(['manually_verified' => true]);
            foreach ($transformer->entryRows()->pluck('pair_number')->unique() as $number) {
                $this->entries->synchronizePair($transformer, $number);
            }
            $transformer->pvRecords()->whereNotNull('entry_row_id')->get()->each(fn ($pv) => $pv->update(['original_entry' => array_replace($pv->original_entry ?? [], ['manually_verified' => true])]));
            $locked->increment('revision');
            $this->audit->record($request->user(), 'mdb.operator_review_saved', $transformer, $old, $this->snapshots->snapshot($locked));
        });

        if ($request->expectsJson()) {
            return response()->json($this->entries->editorData($batch->fresh()));
        }

        return back()->with('success', 'Header and S/E rows checked and saved. Engineering verification is still required before export.');
    }

    private function authorizeEntry(Request $request, SurveyBatch $batch): void
    {
        if ($request->attributes->get('mdb_verifier_correction')) {
            $this->access->authorizeCorrection($request->user(), $batch);
        } else {
            $this->access->authorize($request->user(), $batch, 'edit');
        }
    }

    private function invalidateEntry(Request $request, SurveyBatch $batch): void
    {
        $status = $batch->status;
        $this->snapshots->invalidate($batch);
        if ($request->attributes->get('mdb_verifier_correction')) {
            $batch->update(['status' => $status]);
        }
    }

    private function assertRevision(SurveyBatch $batch, int $revision): void
    {
        if ($batch->revision !== $revision) {
            throw ValidationException::withMessages(['revision' => 'Another save or source job changed this batch. Your draft is retained; reload current records before saving.']);
        }
    }
}
