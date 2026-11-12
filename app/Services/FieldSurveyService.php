<?php

namespace App\Services;

use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\HtNetworkUpdate;
use App\Models\SurveyorAssignment;
use App\Models\Transformer;
use App\Models\User;
use App\Services\DriveStorage\FileStorage;
use App\Services\Mdb\StartingPoleCoordinates;
use App\Services\Mdb\SurveyPhaseConsistency;
use App\Support\FieldTransformerIdentity;
use App\Support\SurveyTeamGroup;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FieldSurveyService
{
    public function __construct(private readonly FieldSurveyAccess $access, private readonly AuditService $audit) {}

    public function sync(User $actor, array $data): array
    {
        try {
            return DB::transaction(function () use ($actor, $data): array {
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                [$team, $feeder] = $this->access->authorizeCollection($actor, $data['survey_team_id'], $data['feeder_id']);
                DB::table('feeders')->where('id', $feeder->id)->lockForUpdate()->first();
                $survey = FieldSurvey::withTrashed()->where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
                abort_if($survey?->trashed(), 409, 'This survey was deleted on the server. Your phone copy cannot recreate it.');
                abort_if($survey && $survey->collected_by !== $actor->id, 409, 'This survey UUID belongs to another account.');
                $this->validateRowOwnershipAndGroups($data, $survey, SurveyTeamGroup::identifier($team));
                if ($actor->hasRole('mobile_survey_user')) {
                    $group = SurveyTeamGroup::identifier($team);
                    if (! $group) {
                        throw ValidationException::withMessages(['survey_team_id' => 'Your assigned team needs a two-digit group code. Contact the administrator.']);
                    }
                    $firstStartIndex = collect($data['rows'])->search(fn (array $row): bool => ($row['se'] ?? null) === 'S');
                    foreach ($data['rows'] as $i => $row) {
                        $oldRows = $survey?->rows ?? [];
                        $old = ! empty($row['row_id']) ? collect($oldRows)->firstWhere('row_id', $row['row_id']) : null;
                        $legacyIndex = $row['legacy_row_index'] ?? null;
                        if (! $old && $legacyIndex !== null && isset($oldRows[$legacyIndex]) && empty($oldRows[$legacyIndex]['row_id'])) {
                            $old = $oldRows[$legacyIndex];
                        }
                        if ($i !== $firstStartIndex && ! empty($row['group']) && $row['group'] !== $group && ($old['group'] ?? null) !== $row['group']) {
                            throw ValidationException::withMessages(["rows.$i.group" => 'New observations use your assigned group '.$group.'. Existing historical values remain unchanged.']);
                        }
                    }
                }
                $hashData = $data;
                unset($hashData['base_revision']);
                $hash = hash('sha256', json_encode($this->canonical($hashData), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                if ($survey) {
                    abort_unless($survey->collected_by === $actor->id, 409, 'This survey UUID belongs to another account.');
                    $this->access->authorizeOwner($actor, $survey);
                    if (hash_equals($survey->payload_hash, $hash)) {
                        return [$survey, false];
                    }
                    abort_unless((int) $data['base_revision'] === $survey->revision, 409, 'The server has a newer revision. Your local data has been preserved; resolve the conflict before syncing.');
                } else {
                    abort_unless((int) $data['base_revision'] === 0, 409, 'This survey was not found. A new survey must start at revision zero.');
                }
                $phaseErrors = app(SurveyPhaseConsistency::class)->fieldErrors($data['rows'], $data['transformer_code'] ?? '(unrecorded)', $data['status'] === 'submitted', $survey?->rows ?? []);
                if ($phaseErrors) {
                    throw ValidationException::withMessages($phaseErrors);
                }
                $transformer = empty($data['transformer_id']) ? null : Transformer::where('feeder_id', $feeder->id)->find($data['transformer_id']);
                $htUpdate = null;
                if (! empty($data['ht_update_uuid'])) {
                    $htUpdate = HtNetworkUpdate::where('client_uuid', $data['ht_update_uuid'])->first();
                    if (! $htUpdate || $htUpdate->collected_by !== $actor->id || $htUpdate->feeder_id !== $feeder->id
                        || $htUpdate->survey_team_id !== $team->id || $htUpdate->transformer_key !== FieldTransformerIdentity::key($data['transformer_code'] ?? null)) {
                        throw ValidationException::withMessages(['ht_update_uuid' => 'Synchronize the matching HT Network Update first. Keep its feeder, team and transformer code.']);
                    }
                    $transformer = $htUpdate->transformer;
                }
                abort_if($survey?->ht_network_update_id && $survey->ht_network_update_id !== $htUpdate?->id, 409, 'The original HT/LT connection cannot be removed or changed.');
                if (! empty($data['transformer_id']) && ! $transformer) {
                    throw ValidationException::withMessages(['transformer_id' => 'The reference transformer is unavailable or belongs to another feeder. Download reference data again or select an unlinked transformer.']);
                }
                if (! $transformer && ! empty($data['transformer_code'])) {
                    $references = app(TransformerRegistryService::class)->candidates($feeder->id, $data['transformer_code'])->filter(fn ($t) => $t->source_identity_key || $t->temporary_reference);
                    if ($references->count() === 1) $transformer = $references->first();
                    $ambiguous = ! $transformer && Transformer::where('feeder_id', $feeder->id)->where('code_verification_status', 'pending_client')->get()
                        ->contains(fn ($t) => FieldTransformerIdentity::key($t->source_transformer_code) === FieldTransformerIdentity::key($data['transformer_code']));
                    if ($ambiguous) throw ValidationException::withMessages(['transformer_code' => 'This client code identifies multiple KMZ points. Select the exact registered transformer using its temporary reference.']);
                }
                if (! $transformer || ($htUpdate && $htUpdate->status !== 'verified') || ($transformer && HtNetworkUpdate::where('feeder_id', $feeder->id)->where('transformer_id', $transformer->id)->where('status', '!=', 'verified')->exists())) {
                    throw ValidationException::withMessages(['transformer_id' => 'Update and verify HT data first, then select its registered transformer before entering LT survey data.']);
                }
                $key = FieldTransformerIdentity::key($data['transformer_code'] ?? null);
                if (! $htUpdate && HtNetworkUpdate::where('feeder_id', $feeder->id)->where('transformer_key', $key)->whereIn('status', ['pending', 'returned'])->exists()) {
                    throw ValidationException::withMessages(['ht_update_uuid' => 'This transformer has an HT Network Update awaiting verification. Open its linked LT survey or synchronize its HT reference first.']);
                }
                $identityChanged = ! $survey || $survey->feeder_id !== $feeder->id
                    || $survey->transformer_id !== $transformer?->id || FieldTransformerIdentity::key($survey->transformer_code) !== $key;
                if ($identityChanged && ($key !== null || $transformer)) {
                    $duplicate = FieldSurvey::where('feeder_id', $feeder->id)->where('client_uuid', '!=', $data['client_uuid'])
                        ->where(function ($query) use ($key, $transformer): void {
                            if ($transformer && $transformer->source_identity_key) {
                                $query->where('transformer_id', $transformer->id)->orWhere(fn ($unlinked) => $unlinked->whereNull('transformer_id')->where('transformer_key', $key));
                                return;
                            }
                            if ($key !== null) {
                                $query->where('transformer_key', $key);
                            }
                            if ($transformer) {
                                $query->orWhere('transformer_id', $transformer->id);
                            }
                        })->exists();
                    if ($duplicate) {
                        throw ValidationException::withMessages(['transformer_code' => 'This transformer already has a saved survey. Open the saved survey to edit it.']);
                    }
                }
                $old = $survey?->toArray() ?? [];
                $isNew = $survey === null;
                $survey ??= new FieldSurvey(['client_uuid' => $data['client_uuid'], 'collected_by' => $actor->id]);
                $survey->forceFill(['transformer_key' => $key]);
                $survey->forceFill(['ht_network_update_id' => $htUpdate?->id]);
                if ($isNew || $survey->feeder_id !== $feeder->id) {
                    $survey->reference_snapshot = [
                        'project_id' => $team->project_id,
                        'feeder_code' => $feeder->feeder_code,
                        'feeder_name' => $feeder->feeder_name,
                        'team_name' => $team->name,
                        'group_code' => SurveyTeamGroup::identifier($team),
                        'gpx_group_code' => SurveyTeamGroup::filenameGroup($team),
                        'starting_pole_coordinates' => app(StartingPoleCoordinates::class)->capture($transformer),
                        'transformer' => $transformer?->only(['id', 'transformer_code', 'capacity_kva', 'equipment_make', 'equipment_location']),
                    ];
                }
                $survey->fill([
                    'survey_team_id' => $team->id,
                    'feeder_id' => $feeder->id,
                    'transformer_id' => $transformer?->id,
                    'transformer_code' => $data['transformer_code'] ?? null,
                    'survey_date' => $data['survey_date'],
                    'status' => $data['status'],
                    'revision' => $isNew ? 1 : $survey->revision + 1,
                    'payload_hash' => $hash,
                    'header' => $data['header'],
                    'rows' => array_values($data['rows']),
                    'solar' => array_values($data['solar']),
                    'remarks' => $data['remarks'] ?? null,
                    'submitted_at' => $data['status'] === 'submitted' ? ($survey->submitted_at ?? now()) : null,
                ])->save();
                app(TransformerRegistryService::class)->observeSurvey($survey);
                $task = SurveyorAssignment::where('surveyor_id', $actor->id)->where('survey_team_id', $team->id)->where('feeder_id', $feeder->id)
                    ->whereIn('status', ['assigned', 'in_progress', 'draft', 'returned'])
                    ->first();
                if ($task) {
                    $taskOld = $task->toArray();
                    $task->update(['status' => $data['status'] === 'submitted' ? 'submitted' : 'in_progress',
                        'started_at' => $task->started_at ?? now(), 'completed_at' => $data['status'] === 'submitted' ? now() : null,
                        'return_comments' => $data['status'] === 'submitted' ? null : $task->return_comments]);
                    if ($task->wasChanged('status')) {
                        $this->audit->record($actor, 'survey.task_status_changed', $task, $taskOld, $task->fresh()->toArray());
                    }
                }
                $this->audit->record($actor, $isNew ? 'field_survey.created' : 'field_survey.updated', $survey, $old, $survey->toArray());

                return [$survey, $isNew];
            }, 3);
        } catch (QueryException $exception) {
            if (FieldSurvey::where('client_uuid', $data['client_uuid'])->exists()
                && in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                abort(409, 'This survey UUID was synchronized concurrently. Retry with the same record.');
            }
            throw $exception;
        }
    }

    public function updateOnServer(User $actor, FieldSurvey $survey, array $data): FieldSurvey
    {
        return DB::transaction(function () use ($actor, $survey, $data): FieldSurvey {
            // Use the same feeder-before-survey lock order as mobile sync.
            DB::table('feeders')->where('id', $survey->feeder_id)->lockForUpdate()->first();
            $locked = FieldSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $this->access->authorizeManagement($actor, $locked);
            $this->assertWebRevision($locked, (int) $data['base_revision']);
            if (count($data['rows']) !== count($locked->rows)) {
                throw ValidationException::withMessages(['rows' => 'Keep the original S/E rows and their photo links when correcting this survey.']);
            }
            foreach ($locked->rows as $index => $row) {
                foreach (['row_id', 'pair_id', 'entry_version', 'se'] as $key) {
                    if (($row[$key] ?? null) !== ($data['rows'][$index][$key] ?? null)) {
                        throw ValidationException::withMessages(['rows' => 'The original S/E row identities cannot be changed. Reload the survey.']);
                    }
                }
            }
            $this->validateRowOwnershipAndGroups($data, $locked, SurveyTeamGroup::identifier($locked->team));
            $phaseErrors = app(SurveyPhaseConsistency::class)->fieldErrors($data['rows'], $data['transformer_code'] ?? '(unrecorded)', $locked->status === 'submitted', $locked->rows);
            if ($phaseErrors) {
                throw ValidationException::withMessages($phaseErrors);
            }
            $key = FieldTransformerIdentity::key($data['transformer_code'] ?? null);
            if ($locked->ht_network_update_id && $key !== $locked->htUpdate?->transformer_key) {
                throw ValidationException::withMessages(['transformer_code' => 'Keep the transformer identity linked to the HT Network Update. Reconcile HT and LT together before changing its code.']);
            }
            if ($key !== null && FieldSurvey::where('feeder_id', $locked->feeder_id)->where('id', '!=', $locked->id)->where('transformer_key', $key)->exists()) {
                throw ValidationException::withMessages(['transformer_code' => 'This transformer already has another saved survey on this feeder.']);
            }
            $before = $locked->toArray();
            $hashData = array_intersect_key($data, array_flip(['client_uuid', 'survey_team_id', 'feeder_id', 'transformer_id', 'transformer_code', 'survey_date', 'status', 'header', 'rows', 'solar', 'remarks']));
            $locked->fill(array_intersect_key($data, array_flip(['transformer_code', 'survey_date', 'header', 'rows', 'solar', 'remarks'])));
            $locked->forceFill(['transformer_key' => $key, 'revision' => $locked->revision + 1,
                'payload_hash' => hash('sha256', json_encode($this->canonical($hashData), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))])->save();
            $this->audit->record($actor, 'field_survey.corrected_on_server', $locked, $before, $locked->toArray(), $data['correction_reason'] ?? null);
            app(TransformerRegistryService::class)->observeSurvey($locked);

            return $locked;
        }, 3);
    }

    public function deleteOnServer(User $actor, FieldSurvey $survey, int $revision): void
    {
        DB::transaction(function () use ($actor, $survey, $revision): void {
            $locked = FieldSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $this->access->authorizeManagement($actor, $locked);
            $this->assertWebRevision($locked, $revision);
            $before = $locked->toArray();
            // Retain a tombstone and evidence so an old phone retry cannot recreate it.
            $locked->delete();
            $this->audit->record($actor, 'field_survey.deleted_on_server', $locked, $before, $locked->toArray());
        }, 3);
    }

    private function assertWebRevision(FieldSurvey $survey, int $revision): void
    {
        if ($survey->revision !== $revision) {
            throw ValidationException::withMessages(['base_revision' => 'This survey changed after you opened it. Reload the latest survey before saving or deleting.']);
        }
    }

    public function attach(User $actor, FieldSurvey $survey, string $uuid, string $kind, UploadedFile $file, ?string $rowId = null): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $survey, $uuid, $kind, $file, $rowId, &$path): array {
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $survey = FieldSurvey::query()->lockForUpdate()->findOrFail($survey->id);
                $this->access->authorizeOwner($actor, $survey);
                $hash = hash_file('sha256', $file->getRealPath());
                $existing = FieldSurveyAttachment::where('client_uuid', $uuid)->first();
                if ($existing) {
                    abort_unless($existing->field_survey_id === $survey->id && $existing->kind === $kind && $existing->row_id === $rowId && hash_equals($existing->sha256, $hash), 409, 'This attachment UUID already identifies another file or row.');

                    return [$existing, false];
                }
                if (! $rowId || ! collect($survey->rows)->contains(fn ($row) => ($row['row_id'] ?? null) === $rowId)) {
                    throw ValidationException::withMessages(['row_id' => 'Select an S/E row belonging to this saved survey before uploading the photo.']);
                }
                $path = $file->store('field-surveys/'.$survey->client_uuid, 'local');
                if (! $path) {
                    abort(503, 'The attachment could not be stored. Keep the file on your device and retry.');
                }
                $attachment = $survey->attachments()->create([
                    'client_uuid' => $uuid, 'row_id' => $rowId, 'kind' => $kind, 'path' => $path,
                    'mime_type' => $file->getMimeType(), 'byte_length' => $file->getSize(), 'sha256' => $hash,
                ]);
                if (config('drive_storage.enabled') && $attachment->mime_type === 'application/pdf') {
                    $archive = app(FileStorage::class)->register('local', $path, $file->getClientOriginalName(), $attachment->mime_type, [
                        'project_id' => $survey->feeder->project_id, 'feeder_id' => $survey->feeder_id,
                        'field_survey_id' => $survey->id, 'transformer_id' => $survey->transformer_id, 'transformer_code' => $survey->transformer_code,
                        'uploaded_by' => $actor->id,
                    ]);
                    $attachment->update(['drive_stored_file_id' => $archive->id]);
                }
                $this->audit->record($actor, 'field_survey.attachment_added', $attachment, new: $attachment->toArray());

                return [$attachment, true];
            }, 3);
        } catch (Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            if ($exception instanceof QueryException && FieldSurveyAttachment::where('client_uuid', $uuid)->exists()) {
                abort(409, 'This attachment UUID was uploaded concurrently. Retry with the same file.');
            }
            throw $exception;
        }
    }

    private function validateRowOwnershipAndGroups(array &$data, ?FieldSurvey $survey, ?string $group): void
    {
        $oldRows = $survey?->rows ?? [];
        $claimedLegacy = [];
        foreach ($data['rows'] as $index => &$row) {
            $old = null;
            if (! empty($row['row_id'])) {
                $old = collect($oldRows)->first(fn ($saved) => ($saved['row_id'] ?? null) === $row['row_id']);
                $legacyIndex = $row['legacy_row_index'] ?? null;
                if (! $old && $legacyIndex !== null && isset($oldRows[$legacyIndex]) && empty($oldRows[$legacyIndex]['row_id'])
                    && ! isset($claimedLegacy[$legacyIndex]) && ($oldRows[$legacyIndex]['se'] ?? null) === ($row['se'] ?? null)) {
                    $old = $oldRows[$legacyIndex];
                    $claimedLegacy[$legacyIndex] = true;
                }
            } elseif (isset($oldRows[$index])) {
                $old = $oldRows[$index];
                if (! empty($old['row_id'])) {
                    $row['row_id'] = $old['row_id'];
                }
            }
            if ($old !== null) {
                if (($row['group'] ?? null) === null && ! empty($old['group'])) {
                    $row['group'] = $old['group'];
                }
            } else {
                if (! isset($row['group']) || $row['group'] === '') {
                    $row['group'] = $group;
                }
            }
        }
        unset($row);
        $ids = collect($data['rows'])->pluck('row_id')->filter()->all();
        foreach ($data['solar'] as $index => $pv) {
            $oldPv = ! empty($pv['pv_id']) ? collect($survey?->solar ?? [])->firstWhere('pv_id', $pv['pv_id'])
                : (($survey?->solar ?? [])[$index] ?? null);
            if (! empty($pv['row_id']) && ! in_array($pv['row_id'], $ids, true)) {
                throw ValidationException::withMessages(["solar.$index.row_id" => 'PV must belong to an S/E row in this survey.']);
            }
            if (empty($pv['row_id']) && (! $oldPv || ! empty($oldPv['row_id']))) {
                throw ValidationException::withMessages(["solar.$index.row_id" => 'Select the S/E row for this PV observation. Existing unlinked records remain unlinked.']);
            }
        }
        if ($survey && $survey->attachments()->whereNotNull('row_id')->whereNotIn('row_id', $ids)->exists()) {
            throw ValidationException::withMessages(['rows' => 'A row with uploaded photos cannot be removed. Keep its stable row identifier.']);
        }
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
