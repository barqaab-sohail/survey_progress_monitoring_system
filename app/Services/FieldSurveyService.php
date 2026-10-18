<?php

namespace App\Services;

use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\Transformer;
use App\Models\User;
use App\Models\SurveyorAssignment;
use App\Services\DriveStorage\FileStorage;
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
                $survey = FieldSurvey::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
                abort_if($survey && $survey->collected_by !== $actor->id, 409, 'This survey UUID belongs to another account.');
                $this->validateRowOwnershipAndGroups($data, $survey, SurveyTeamGroup::identifier($team));
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
                $transformer = empty($data['transformer_id']) ? null : Transformer::where('feeder_id', $feeder->id)->find($data['transformer_id']);
                if (! empty($data['transformer_id']) && ! $transformer) {
                    throw ValidationException::withMessages(['transformer_id' => 'The reference transformer is unavailable or belongs to another feeder. Download reference data again or select an unlinked transformer.']);
                }
                $key = FieldTransformerIdentity::key($data['transformer_code'] ?? null);
                $identityChanged = ! $survey || $survey->feeder_id !== $feeder->id
                    || $survey->transformer_id !== $transformer?->id || FieldTransformerIdentity::key($survey->transformer_code) !== $key;
                if ($identityChanged && ($key !== null || $transformer)) {
                    $duplicate = FieldSurvey::where('feeder_id', $feeder->id)->where('client_uuid', '!=', $data['client_uuid'])
                        ->where(function ($query) use ($key, $transformer): void {
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
                if ($isNew || $survey->feeder_id !== $feeder->id) {
                    $survey->reference_snapshot = [
                        'project_id' => $team->project_id,
                        'feeder_code' => $feeder->feeder_code,
                        'feeder_name' => $feeder->feeder_name,
                        'team_name' => $team->name,
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
                $task = SurveyorAssignment::where('surveyor_id', $actor->id)->where('survey_team_id', $team->id)->where('feeder_id', $feeder->id)
                    ->whereIn('status', ['assigned', 'in_progress', 'draft', 'returned'])
                    ->first();
                if ($task) {
                    $taskOld = $task->toArray();
                    $task->update(['status' => $data['status'] === 'submitted' ? 'submitted' : 'in_progress',
                        'started_at' => $task->started_at ?? now(), 'completed_at' => $data['status'] === 'submitted' ? now() : null]);
                    if ($task->wasChanged('status')) $this->audit->record($actor, 'survey.task_status_changed', $task, $taskOld, $task->fresh()->toArray());
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
