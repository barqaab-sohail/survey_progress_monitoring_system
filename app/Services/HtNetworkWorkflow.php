<?php

namespace App\Services;

use App\Models\Feeder;
use App\Models\FieldSurvey;
use App\Models\HtNetworkUpdate;
use App\Models\Transformer;
use App\Models\User;
use App\Services\Mdb\SnapshotService;
use App\Support\FieldTransformerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HtNetworkWorkflow
{
    public function visible(User $actor)
    {
        $access = app(FieldSurveyAccess::class);
        abort_unless($actor->isActive() && (! $actor->organization_id || $actor->organization?->status->value === 'active'), 403);
        abort_unless($actor->hasAnyRole(['super_admin', 'project_manager', 'management_viewer', 'survey_team_leader', 'surveyor', 'mobile_survey_user', 'mdb_generator']), 403);
        if ($actor->hasAnyRole(['super_admin', 'project_manager', 'management_viewer'])) {
            return HtNetworkUpdate::query();
        }
        if ($actor->hasRole('mdb_generator')) {
            return HtNetworkUpdate::whereHas('feeder', fn ($q) => $q->whereIn('project_id', $actor->mdbTeams()->where('status', 'active')->select('mdb_teams.project_id')));
        }

        return HtNetworkUpdate::whereIn('feeder_id', $access->feeders($actor)->select('feeders.id'))
            ->when(! $actor->hasRole('survey_team_leader'), fn ($q) => $q->where('collected_by', $actor->id));
    }

    public function canCreateWeb(User $actor): bool
    {
        return $actor->isActive() && $actor->canUseHtNetworkUpdates()
            && $actor->hasAnyRole(['super_admin', 'project_manager', 'survey_team_leader', 'surveyor', 'mobile_survey_user']);
    }

    public function syncWeb(User $actor, array $data): HtNetworkUpdate
    {
        abort_unless($this->canCreateWeb($actor), 403);
        return $this->sync($actor, $data, true);
    }

    public function sync(User $actor, array $data, bool $web = false): HtNetworkUpdate
    {
        abort_unless($actor->canUseHtNetworkUpdates(), 403, 'HT Network Updates are not enabled for this account.');

        return DB::transaction(function () use ($actor, $data, $web) {
            if ($web && $actor->hasAnyRole(['super_admin', 'project_manager'])) {
                abort_unless(! $actor->organization_id || $actor->organization?->status->value === 'active', 403);
                $team = \App\Models\SurveyTeam::where('status', 'active')->findOrFail($data['survey_team_id']);
                $feeder = Feeder::active()->whereHas('project', fn ($q) => $q->where('status', 'active'))->findOrFail($data['feeder_id']);
                abort_unless($team->project_id === $feeder->project_id, 422, 'Choose a team belonging to this feeder project.');
            } else {
                app(FieldSurveyAccess::class)->authorizeCollection($actor, $data['survey_team_id'], $data['feeder_id']);
            }
            Feeder::whereKey($data['feeder_id'])->lockForUpdate()->firstOrFail();
            $existing = HtNetworkUpdate::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            abort_if($existing && $existing->collected_by !== $actor->id, 409, 'HT update UUID belongs to another account.');
            $revision = $data['base_revision'];
            unset($data['base_revision']);
            $hash = app(SnapshotService::class)->hash($data);
            if ($existing && hash_equals($existing->payload_hash, $hash)) {
                return $existing;
            }
            abort_unless((int) $revision === (int) ($existing?->revision ?? 0), 409, 'HT update changed. Download feeder data and review the current version.');
            abort_if($existing?->status === 'verified', 409, 'Verified HT records cannot be overwritten.');
            $key = FieldTransformerIdentity::key($data['transformer_code']);
            if (! $key) {
                throw ValidationException::withMessages(['transformer_code' => 'Enter a transformer code or a unique temporary reference.']);
            }
            $duplicate = Transformer::where('feeder_id', $data['feeder_id'])->get(['transformer_code'])->contains(fn ($t) => FieldTransformerIdentity::key($t->transformer_code) === $key);
            $other = HtNetworkUpdate::where('feeder_id', $data['feeder_id'])->where('transformer_key', $key)->where('client_uuid', '!=', $data['client_uuid'])->exists();
            if ($duplicate || $other) {
                throw ValidationException::withMessages(['transformer_code' => 'This transformer already exists or has another HT update. Ask the administrator to reconcile the records; your LT work is retained.']);
            }
            if ($existing && ((int) $existing->feeder_id !== (int) $data['feeder_id'] || $existing->transformer_key !== $key || (int) $existing->survey_team_id !== (int) $data['survey_team_id'])) {
                abort(409, 'Keep the original HT feeder, team and transformer identity when correcting this update.');
            }
            $network = app(HtKmzService::class)->network($data['feeder_id']);
            if ($web && (! $network['baseline_sha256'] || $network['baseline_sha256'] !== ($data['baseline_sha256'] ?? null)
                || ! collect($network['features'])->contains('id', $data['upstream_feature_id'] ?? ''))) {
                throw ValidationException::withMessages(['upstream_feature_id' => 'Select a connection from the current HT network of this feeder. Reload the form if HT data changed.']);
            }
            if ($web) {
                foreach ($data['nodes'] as $node) {
                    foreach (['conductor_r', 'conductor_y', 'conductor_b'] as $phase) {
                        if (! empty($node[$phase]) && ! in_array($node[$phase], \App\Services\Mdb\ApprovedWarehouse::conductors(), true)) {
                            throw ValidationException::withMessages(['nodes' => 'Choose conductors from the approved warehouse.']);
                        }
                    }
                }
            }
            foreach ($data['photos'] as $photo) {
                $bytes = base64_decode($photo, true);
                $info = $bytes === false ? false : @getimagesizefromstring($bytes);
                if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png'], true) || strlen($bytes) > 1024 * 1024) {
                    throw ValidationException::withMessages(['photos' => 'HT evidence must be JPEG/PNG images, each no larger than 1 MB.']);
                }
            }
            if ($data['kind'] === 'missing_transformer' && count($data['nodes']) !== 2) {
                throw ValidationException::withMessages(['nodes' => 'A missing transformer without an HT extension needs exactly the existing connection and transformer nodes.']);
            }
            if (! $web && $data['kind'] === 'ht_extension' && collect(array_slice($data['nodes'], 1))->contains(fn ($n) => empty($n['conductor_r']) && empty($n['conductor_y']) && empty($n['conductor_b']))) {
                throw ValidationException::withMessages(['nodes' => 'Record at least one phase conductor for every new HT span.']);
            }
            $before = $existing?->toArray() ?? [];
            $update = $existing ?? new HtNetworkUpdate;
            $update->fill(['client_uuid' => $data['client_uuid'], 'feeder_id' => $data['feeder_id'], 'survey_team_id' => $data['survey_team_id'],
                'collected_by' => $actor->id, 'transformer_code' => $data['transformer_code'], 'transformer_key' => $key,
                'revision' => ($existing?->revision ?? 0) + 1, 'payload' => $data, 'payload_hash' => $hash, 'status' => 'pending',
                'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null])->save();
            app(AuditService::class)->record($actor, 'ht_update.submitted', $update, array_diff_key($before, ['payload' => 1]), $update->only(['client_uuid', 'revision', 'status', 'transformer_code']));

            return $update;
        });
    }

    public function review(User $actor, HtNetworkUpdate $update, array $data): HtNetworkUpdate
    {
        abort_unless($actor->hasAnyRole(['super_admin', 'project_manager']), 403);
        abort_if($update->collected_by === $actor->id, 403, 'The collector cannot verify their own HT update.');

        return DB::transaction(function () use ($actor, $update, $data) {
            $feeder = Feeder::whereKey($update->feeder_id)->lockForUpdate()->firstOrFail();
            $update = HtNetworkUpdate::whereKey($update->id)->lockForUpdate()->firstOrFail();
            abort_unless($update->revision === (int) $data['revision'] && $update->status === 'pending', 409, 'The HT update changed. Reload before reviewing.');
            $before = $update->only(['revision', 'status', 'transformer_id']) + ['nodes' => $update->payload['nodes']];
            if ($data['decision'] === 'verified') {
                $p = $update->payload;
                $network = app(HtKmzService::class)->network($update->feeder_id);
                $feature = collect($network['features'])->firstWhere('id', $p['upstream_feature_id'] ?? '');
                if (! $feature || $network['baseline_sha256'] !== ($p['baseline_sha256'] ?? null)) {
                    throw ValidationException::withMessages(['decision' => 'The upstream feature must be selected from the current approved feeder HT network. Return this update for correction if the baseline changed.']);
                }
                if (count($data['nodes']) !== count($p['nodes'])) {
                    abort(422, 'Keep the original route nodes.');
                }
                foreach ($p['nodes'] as $i => &$node) {
                    $node['latitude'] = (float) $data['nodes'][$i]['latitude'];
                    $node['longitude'] = (float) $data['nodes'][$i]['longitude'];
                    abort_if($node['latitude'] == 0 && $node['longitude'] == 0, 422, 'Resolve every waypoint coordinate before verification.');
                }
                unset($node);
                $first = $p['nodes'][0];
                $distance = $this->distanceToFeature($first, $feature);
                if ($distance > 30) {
                    throw ValidationException::withMessages(['nodes' => 'The upstream coordinate is more than 30 metres from the selected HT feature. Check the connection.']);
                }
                if ($p['kind'] === 'missing_transformer' && $this->distanceToFeature($p['nodes'][count($p['nodes']) - 1], $feature) > 30) {
                    throw ValidationException::withMessages(['nodes' => 'A transformer on existing HT must meet the selected HT feature. If a new span is needed, return it for an HT extension update.']);
                }
                $key = $update->transformer_key;
                $matches = Transformer::where('feeder_id', $update->feeder_id)->get()->filter(fn ($t) => FieldTransformerIdentity::key($t->transformer_code) === $key);
                $transformer = null;
                if (! empty($data['existing_transformer_id'])) {
                    $transformer = Transformer::where('feeder_id', $update->feeder_id)->findOrFail($data['existing_transformer_id']);
                    abort_unless(FieldTransformerIdentity::key($transformer->transformer_code) === $key, 422, 'Reconcile only the same transformer identity on this feeder.');
                    abort_if(FieldSurvey::where('feeder_id', $feeder->id)->where(fn ($q) => $q->whereNull('ht_network_update_id')->orWhere('ht_network_update_id', '!=', $update->id))->where('transformer_id', $transformer->id)->exists(), 422, 'This transformer already has an LT survey. Reconcile that survey before verification.');
                } elseif ($matches->isNotEmpty()) {
                    throw ValidationException::withMessages(['existing_transformer_id' => 'An imported transformer now has this code. Enter its ID to explicitly reconcile it instead of creating a duplicate.']);
                }
                $last = $p['nodes'][count($p['nodes']) - 1];
                $transformer ??= new Transformer(['feeder_id' => $update->feeder_id, 'transformer_code' => $update->transformer_code]);
                $isNew = ! $transformer->exists;
                $transformer->fill(['capacity_kva' => $p['capacity_kva'], 'equipment_make' => $p['make'], 'equipment_location' => $p['location'],
                    'capacity_verification_status' => empty($p['capacity_kva']) ? 'pending_field' : 'confirmed',
                    'equipment_mounting' => $p['mounting'], 'gps_waypoint_number' => $last['waypoint'], 'pole_number' => $last['waypoint'],
                    'latitude' => $last['latitude'], 'longitude' => $last['longitude'], 'line_voltage' => '11kV', 'equipment_phase' => $p['phase'], 'equipment_status' => 'Connected'])->save();
                if ($isNew) {
                    $feeder->increment('total_transformers');
                }
                $update->transformer_id = $transformer->id;
                $update->payload = $p;
            }
            $update->fill(['status' => $data['decision'], 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['review_note'], 'revision' => $update->revision + 1])->save();
            app(AuditService::class)->record($actor, 'ht_update.'.$data['decision'], $update, $before, $update->only(['revision', 'status', 'transformer_id']) + ['nodes' => $update->payload['nodes']], $data['review_note']);

            return $update;
        });
    }

    private function distanceToFeature(array $point, array $feature): float
    {
        $scaleX = 111320 * cos(deg2rad($point['latitude']));
        $coords = array_map(fn ($c) => [($c[0] - $point['longitude']) * $scaleX, ($c[1] - $point['latitude']) * 111320], $feature['coordinates']);
        $distance = INF;
        foreach ($coords as $i => [$x, $y]) {
            $distance = min($distance, hypot($x, $y));
            if ($i === 0 || $feature['type'] !== 'line') {
                continue;
            }
            [$a, $b] = $coords[$i - 1];
            $dx = $x - $a;
            $dy = $y - $b;
            $t = ($dx * $dx + $dy * $dy) > 0 ? max(0, min(1, -($a * $dx + $b * $dy) / ($dx * $dx + $dy * $dy))) : 0;
            $distance = min($distance, hypot($a + $t * $dx, $b + $t * $dy));
        }

        return $distance;
    }

    public function assertVerified(FieldSurvey $survey): void
    {
        $update = $survey->ht_network_update_id ? $survey->htUpdate : HtNetworkUpdate::where('feeder_id', $survey->feeder_id)
            ->where('transformer_key', FieldTransformerIdentity::key($survey->transformer_code))->first();
        if (($survey->ht_network_update_id && ! $update) || ($update && $update->status !== 'verified')) {
            throw ValidationException::withMessages(['ht_update' => 'HT Network Update is pending verification or returned. LT collection is retained; final GPX/JSON/MDB release requires verified HT.']);
        }
    }
}
