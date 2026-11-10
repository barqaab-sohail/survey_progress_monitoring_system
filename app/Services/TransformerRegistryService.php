<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\Mdb\NetworkTransformer;
use App\Models\Mdb\SurveyBatch;
use App\Models\Transformer;
use App\Models\TransformerCapacityObservation;
use App\Models\TransformerRegistryChange;
use App\Models\User;
use App\Services\Mdb\SnapshotService;
use App\Support\FieldTransformerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class TransformerRegistryService
{
    public function canView(User $actor, Transformer $record): bool
    {
        if (! $actor->isActive() || ($actor->organization_id && $actor->organization?->status->value !== 'active')) return false;
        if ($actor->hasAnyRole(['super_admin', 'project_manager', 'mdb_team_user'])) return true;
        if ($actor->hasRole('survey_data_verifier')) {
            return $actor->surveyTeams()->where('project_id', $record->feeder->project_id)->exists()
                || SurveyBatch::where('feeder_id', $record->feeder_id)->where('verification_assigned_to', $actor->id)->exists();
        }
        return $actor->hasAnyRole(['survey_team_leader', 'surveyor', 'mobile_survey_user'])
            && app(FieldSurveyAccess::class)->feeders($actor)->whereKey($record->feeder_id)->exists();
    }

    public function canObserve(User $actor, Transformer $record): bool
    {
        return $this->canView($actor, $record) && ($actor->hasRole('super_admin')
            || ($actor->hasAnyRole(['survey_team_leader', 'surveyor', 'mobile_survey_user'])
                && app(FieldSurveyAccess::class)->feeders($actor)->whereKey($record->feeder_id)->exists()));
    }

    public function canReview(User $actor, Transformer $record): bool
    {
        return $this->canView($actor, $record) && ($actor->hasRole('super_admin')
            || ($actor->hasRole('survey_data_verifier') && ($actor->surveyTeams()->where('project_id', $record->feeder->project_id)->exists()
                || SurveyBatch::where('feeder_id', $record->feeder_id)->where('verification_assigned_to', $actor->id)->exists())));
    }

    public function candidates(int $feederId, string $code)
    {
        $key = FieldTransformerIdentity::key($code);
        if (! $key) return collect();
        return Transformer::where('feeder_id', $feederId)->get()->filter(fn ($t) => in_array($key, array_map(FieldTransformerIdentity::key(...),
            array_merge([$t->transformer_code, $t->temporary_reference], $t->code_aliases ?? [])), true));
    }

    public function observe(User $actor, Transformer $record, array $data): TransformerCapacityObservation
    {
        abort_unless($this->canObserve($actor, $record), 403);
        return DB::transaction(function () use ($actor, $record, $data) {
            Feeder::whereKey($record->feeder_id)->lockForUpdate()->firstOrFail();
            $record = Transformer::lockForUpdate()->findOrFail($record->id);
            $this->assertRevision($record, (int) $data['verification_revision']);
            $observation = $record->capacityObservations()->create([
                'observed_by' => $actor->id, 'baseline_revision' => $record->verification_revision,
                'baseline_capacity_revision' => $record->capacity_revision,
                'capacity_kva' => $data['capacity_kva'], 'evidence_note' => $data['evidence_note'],
                'evidence_path' => $data['evidence_path'] ?? null, 'evidence_sha256' => $data['evidence_sha256'] ?? null,
            ]);
            app(AuditService::class)->record($actor, 'transformer.capacity_observed', $observation, [], $observation->toArray());
            return $observation;
        });
    }

    /** Existing phones already send the observed positive capacity in their survey header. */
    public function observeSurvey(FieldSurvey $survey): void
    {
        if ($survey->status !== 'submitted' || ! $survey->transformer_id || ! is_numeric($survey->header['capacity_kva'] ?? null)
            || $survey->header['capacity_kva'] <= 0) return;
        $record = Transformer::find($survey->transformer_id);
        if (! $record || $record->capacity_verification_status !== 'pending_field') return;
        $observation = $record->capacityObservations()->firstOrCreate(['field_survey_id' => $survey->id, 'source_survey_revision' => $survey->revision], [
            'observed_by' => $survey->collected_by, 'baseline_revision' => $record->verification_revision,
            'baseline_capacity_revision' => $record->capacity_revision,
            'capacity_kva' => $survey->header['capacity_kva'],
            'evidence_note' => 'Observed in field survey '.$survey->client_uuid.', revision '.$survey->revision.'. Review its photographs before approval.',
        ]);
        if ($observation->wasRecentlyCreated) app(AuditService::class)->record($survey->collector, 'transformer.capacity_observed', $observation, [], $observation->toArray());
    }

    public function reviewCapacity(User $actor, TransformerCapacityObservation $observation, array $data): void
    {
        abort_unless($this->canReview($actor, $observation->transformer), 403);
        abort_if($observation->observed_by === $actor->id, 403, 'Another verifier must review your capacity observation.');
        DB::transaction(function () use ($actor, $observation, $data) {
            Feeder::whereKey($observation->transformer->feeder_id)->lockForUpdate()->firstOrFail();
            $record = Transformer::lockForUpdate()->findOrFail($observation->transformer_id);
            $observation = TransformerCapacityObservation::lockForUpdate()->findOrFail($observation->id);
            abort_unless($observation->status === 'pending', 409, 'This observation has already been reviewed.');
            $this->assertRevision($record, (int) $data['verification_revision']);
            if ($data['decision'] === 'approved') {
                abort_unless($observation->baseline_capacity_revision === $record->capacity_revision, 409, 'The reference capacity changed after this observation. Record a new observation against the current rating.');
                if ($observation->field_survey_id) {
                    $survey = $observation->survey;
                    abort_unless($survey && $survey->status === 'submitted' && $survey->revision === $observation->source_survey_revision, 409, 'The source survey changed. Use its latest capacity observation.');
                    $hasPhoto = $survey->attachments()->where('mime_type', 'like', 'image/%')->get()->contains(fn ($photo) => Storage::disk('local')->exists($photo->path)
                        && hash_equals($photo->sha256, hash_file('sha256', Storage::disk('local')->path($photo->path))));
                    abort_unless($hasPhoto, 422, 'Upload the field evidence photograph before approving this capacity.');
                } else {
                    abort_unless($observation->evidence_path && Storage::disk('local')->exists($observation->evidence_path)
                        && hash_equals($observation->evidence_sha256, hash_file('sha256', Storage::disk('local')->path($observation->evidence_path))),
                        422, 'The capacity evidence photograph is missing or changed.');
                }
                $before = $record->only(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status']);
                $record->update(['capacity_kva' => $observation->capacity_kva, 'capacity_verification_status' => 'confirmed',
                    'capacity_verified_by' => $actor->id, 'capacity_verified_at' => now(), 'verification_revision' => $record->verification_revision + 1,
                    'capacity_revision' => $record->capacity_revision + 1]);
                $this->history($actor, $record, 'capacity_verification', $before, $data['review_note'], 'Capacity observation #'.$observation->id.': '.$observation->evidence_note);
                $this->refreshLinkedWork($record, $before);
            }
            $observation->update(['status' => $data['decision'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['review_note']]);
            app(AuditService::class)->record($actor, 'transformer.capacity_'.$data['decision'], $observation, [], $observation->fresh()->toArray(), $data['review_note']);
        });
    }

    public function confirmCode(User $actor, Transformer $record, array $data): void
    {
        abort_unless($this->canView($actor, $record) && $actor->hasRole('super_admin'), 403);
        DB::transaction(function () use ($actor, $record, $data) {
            Feeder::whereKey($record->feeder_id)->lockForUpdate()->firstOrFail();
            $record = Transformer::lockForUpdate()->findOrFail($record->id);
            $this->assertRevision($record, (int) $data['verification_revision']);
            $code = trim($data['transformer_code']);
            $key = FieldTransformerIdentity::key($code);
            if (! $key || str_contains(strtoupper($code), '~PENDING-') || str_starts_with(strtoupper($code), 'KMZ-')) {
                throw ValidationException::withMessages(['transformer_code' => 'Enter the official code confirmed by the client, not a temporary reference.']);
            }
            $other = Transformer::where('feeder_id', $record->feeder_id)->whereKeyNot($record->id)->get()->contains(fn ($t) =>
                in_array($key, array_map(FieldTransformerIdentity::key(...), array_merge([$t->transformer_code], $t->code_aliases ?? [])), true));
            $surveys = FieldSurvey::withTrashed()->where('feeder_id', $record->feeder_id)->where('transformer_key', $key)->whereNull('transformer_id')->exists();
            $network = NetworkTransformer::whereHas('batch', fn ($q) => $q->where('feeder_id', $record->feeder_id))->get()
                ->contains(fn ($t) => FieldTransformerIdentity::key($t->code) === $key && ($t->header['registered_transformer_id'] ?? null) != $record->id
                    && FieldTransformerIdentity::key($t->code) !== FieldTransformerIdentity::key($record->transformer_code));
            if ($other || $surveys || $network) throw ValidationException::withMessages(['transformer_code' => 'This code or historical reference already belongs to another record on this feeder. Reconcile the records before confirming it.']);
            $before = $record->only(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status']);
            $aliases = $record->code_aliases ?? [];
            if ($record->transformer_code !== $code) $aliases[] = $record->transformer_code;
            $record->update(['transformer_code' => $code, 'code_aliases' => array_values(array_unique($aliases)),
                'code_verification_status' => 'confirmed', 'code_confirmed_by' => $actor->id, 'code_confirmed_at' => now(),
                'verification_revision' => $record->verification_revision + 1]);
            $this->history($actor, $record, 'client_code_confirmation', $before, $data['reason'], $data['client_reference']);
            $this->refreshLinkedWork($record, $before);
        });
    }

    private function history(User $actor, Transformer $record, string $kind, array $before, string $reason, string $evidence): void
    {
        $after = $record->only(['transformer_code', 'capacity_kva', 'code_verification_status', 'capacity_verification_status', 'verification_revision']);
        TransformerRegistryChange::create(['transformer_id' => $record->id, 'changed_by' => $actor->id, 'kind' => $kind,
            'old_values' => $before, 'new_values' => $after, 'reason' => $reason, 'evidence_reference' => $evidence, 'created_at' => now()]);
        app(AuditService::class)->record($actor, 'transformer.'.$kind, $record, $before, $after, $reason.' | '.$evidence);
    }

    public function refreshLinkedWork(Transformer $record, array $before): void
    {
        $batches = [];
        foreach (NetworkTransformer::whereHas('batch', fn ($q) => $q->where('feeder_id', $record->feeder_id))->get() as $work) {
            $linked = ($work->header['registered_transformer_id'] ?? null) == $record->id;
            if (! $linked && ! empty($work->header['registered_transformer_id'])) continue;
            if (! $linked && FieldTransformerIdentity::key($work->code) !== FieldTransformerIdentity::key($before['transformer_code'] ?? $record->transformer_code)) continue;
            $header = $work->header ?? [];
            $header['registered_transformer_id'] = $record->id;
            $header['manually_verified'] = false;
            $header['registry_reconciliation'] = ['revision' => $record->verification_revision, 'recorded_code' => $work->code,
                'recorded_capacity_kva' => $work->capacity_kva, 'at' => now()->utc()->toIso8601String()];
            $work->update(['code' => $record->transformer_code, 'capacity_kva' => $record->capacity_kva ?? $work->capacity_kva, 'header' => $header]);
            DB::table('mdb_review_checks')->where('transformer_id', $work->id)->delete();
            $batches[$work->batch_id] = true;
        }
        foreach (array_keys($batches) as $id) {
            $batch = SurveyBatch::lockForUpdate()->findOrFail($id);
            app(SnapshotService::class)->invalidate($batch);
            if ($batch->staged_workflow && $batch->survey_status === 'awaiting_verification') $batch->update(['survey_status' => 'returned', 'status' => 'returned']);
            $batch->increment('revision');
        }
    }

    public function freeze(array $transformer, int $feederId): array
    {
        $id = $transformer['header']['registered_transformer_id'] ?? null;
        $candidates = $id ? collect() : $this->candidates($feederId, $transformer['code'] ?? '');
        $record = $id ? Transformer::where('feeder_id', $feederId)->find($id) : ($candidates->count() === 1 ? $candidates->first() : null);
        if (! $id && ! $record) {
            $pending = Transformer::where('feeder_id', $feederId)->where('code_verification_status', 'pending_client')->get()
                ->contains(fn ($t) => FieldTransformerIdentity::key($t->source_transformer_code) === FieldTransformerIdentity::key($transformer['code'] ?? ''));
            if ($pending) {
                $transformer['registry_verification'] = ['code_status' => 'pending_client', 'capacity_status' => 'pending_field'];
                return $transformer;
            }
        }
        if (! $record || (! $record->source_identity_key && ! $record->hasPendingVerification() && ! $record->code_confirmed_at && ! $record->capacity_verified_at)) return $transformer;
        $transformer['registry_verification'] = ['registration_id' => $record->id, 'revision' => $record->verification_revision,
            'code_status' => $record->code_verification_status, 'capacity_status' => $record->capacity_verification_status,
            'confirmed_code' => $record->transformer_code, 'confirmed_capacity_kva' => $record->capacity_kva,
            'requires_capacity_match' => $record->capacity_verified_at !== null || ($record->source_identity_key && ! ((float) $record->source_capacity_kva > 0)),
            'original_source_code' => $record->source_transformer_code, 'source_feature_id' => $record->source_feature_id];
        return $transformer;
    }

    public static function issues(array $transformer): array
    {
        $v = $transformer['registry_verification'] ?? null;
        if (! $v) return [];
        $messages = [];
        if (($v['code_status'] ?? '') !== 'confirmed') $messages[] = 'Client confirmation of the transformer code is pending.';
        if (($v['capacity_status'] ?? '') !== 'confirmed' || ! is_numeric($v['confirmed_capacity_kva'] ?? null) || $v['confirmed_capacity_kva'] <= 0) $messages[] = 'Field verification of a positive transformer capacity is pending.';
        if (($v['code_status'] ?? '') === 'confirmed' && FieldTransformerIdentity::key($transformer['code'] ?? '') !== FieldTransformerIdentity::key($v['confirmed_code'] ?? '')) $messages[] = 'The survey still uses a previous transformer code. Refresh and reverify this transformer.';
        if (($v['capacity_status'] ?? '') === 'confirmed' && ! empty($v['requires_capacity_match'])
            && (! is_numeric($transformer['capacity_kva'] ?? null) || abs((float) $transformer['capacity_kva'] - (float) $v['confirmed_capacity_kva']) > 0.001)) {
            $messages[] = 'Use the field-verified capacity of '.$v['confirmed_capacity_kva'].' kVA and reverify the changed model.';
        }
        return array_map(fn ($message) => ['code' => 'unresolved_transformer_reference', 'message' => 'Transformer '.($transformer['code'] ?? '?').': '.$message,
            'transformer_id' => $transformer['id']], $messages);
    }

    public function assertForBatch(SurveyBatch $batch, ?int $transformerId = null): void
    {
        foreach ($batch->transformers()->when($transformerId, fn ($q) => $q->whereKey($transformerId))->get() as $record) {
            $issues = self::issues($this->freeze($record->toArray(), $batch->feeder_id));
            if ($issues) throw ValidationException::withMessages(['transformer' => array_column($issues, 'message')]);
        }
    }

    private function assertRevision(Transformer $record, int $revision): void
    {
        abort_unless($record->verification_revision === $revision, 409, 'The transformer reference changed. Reload before saving.');
    }
}
