<?php

namespace App\Services;

use App\Models\FieldSurvey;
use App\Models\FieldSurveyAttachment;
use App\Models\Transformer;
use App\Models\User;
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
                $survey = FieldSurvey::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
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
                $old = $survey?->toArray() ?? [];
                $isNew = $survey === null;
                $survey ??= new FieldSurvey(['client_uuid' => $data['client_uuid'], 'collected_by' => $actor->id]);
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

    public function attach(User $actor, FieldSurvey $survey, string $uuid, string $kind, UploadedFile $file): array
    {
        $path = null;
        try {
            return DB::transaction(function () use ($actor, $survey, $uuid, $kind, $file, &$path): array {
                $actor = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $survey = FieldSurvey::query()->lockForUpdate()->findOrFail($survey->id);
                $this->access->authorizeOwner($actor, $survey);
                $hash = hash_file('sha256', $file->getRealPath());
                $existing = FieldSurveyAttachment::where('client_uuid', $uuid)->first();
                if ($existing) {
                    abort_unless($existing->field_survey_id === $survey->id && $existing->kind === $kind && hash_equals($existing->sha256, $hash), 409, 'This attachment UUID already identifies another file.');

                    return [$existing, false];
                }
                $path = $file->store('field-surveys/'.$survey->client_uuid, 'local');
                if (! $path) {
                    abort(503, 'The attachment could not be stored. Keep the file on your device and retry.');
                }
                $attachment = $survey->attachments()->create([
                    'client_uuid' => $uuid, 'kind' => $kind, 'path' => $path,
                    'mime_type' => $file->getMimeType(), 'byte_length' => $file->getSize(), 'sha256' => $hash,
                ]);
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
