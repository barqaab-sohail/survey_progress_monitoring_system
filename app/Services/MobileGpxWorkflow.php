<?php

namespace App\Services;

use App\Models\FieldSurvey;
use App\Models\MobileGpxLink;
use App\Models\MobileGpxSource;
use App\Models\SurveyTeam;
use App\Models\User;
use App\Services\Mdb\GpxParser;
use App\Services\Mdb\SnapshotService;
use App\Services\Mdb\SurveyWaypoint;
use App\Services\Mdb\WorkflowAccess;
use App\Support\FieldSurveyPairs;
use App\Support\SurveyTeamGroup;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileGpxWorkflow
{
    public function canLink(User $user): bool
    {
        return $user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active')
            && ($user->hasRole('super_admin') || ($user->hasRole('mdb_generator') && app(WorkflowAccess::class)->allows($user, 'prepare')));
    }

    public function authorizeTeam(User $user, SurveyTeam $team): void
    {
        abort_unless($this->canLink($user), 403);
        abort_unless($user->hasRole('super_admin') || $user->mdbTeams()->where('status', 'active')->where('project_id', $team->project_id)->exists(), 403);
        abort_unless($team->status === 'active' && $team->project?->status->value === 'active', 422, 'This survey team or project is inactive.');
    }

    public function upload(User $actor, SurveyTeam $team, UploadedFile $file): MobileGpxSource
    {
        $this->authorizeTeam($actor, $team);
        $name = $file->getClientOriginalName();
        if (! preg_match('/^([A-Za-z0-9]{1,20})_([0-9]{6})_([0-9]{2,6})\.gpx$/iD', $name, $match)) {
            $this->fail('gpx', 'Use Group_DDMMYY_Count.gpx, for example A_061126_06.gpx.');
        }
        $group = SurveyTeamGroup::identifier($team);
        $filenameGroup = SurveyTeamGroup::filenameGroup($team);
        if (! $group || ! $filenameGroup || strtoupper($match[1]) !== $filenameGroup) {
            $this->fail('gpx', 'The filename group does not match this team. Group '.$group.' requires the GPX filename prefix '.$filenameGroup.'.');
        }
        $date = CarbonImmutable::createFromFormat('!dmy', $match[2], 'Asia/Karachi');
        if (! $date || $date->format('dmy') !== $match[2] || $date->year < 2000 || $date->isFuture() || (int) $match[3] < 1) {
            $this->fail('gpx', 'The filename must contain a valid nonfuture DDMMYY date and a positive transformer count.');
        }
        $xml = $file->get();
        $parsed = app(GpxParser::class)->parse($xml);
        $hash = hash('sha256', $xml);

        // Serialize duplicate uploads for this team; originals are retained once.
        return DB::transaction(function () use ($actor, $team, $name, $match, $group, $date, $xml, $parsed, $hash) {
            SurveyTeam::whereKey($team->id)->lockForUpdate()->firstOrFail();
            $existing = MobileGpxSource::where('survey_team_id', $team->id)->where('sha256', $hash)->first();
            if ($existing) {
                return $existing;
            }
            $path = 'mobile-gpx/'.Str::uuid().'.gpx';
            if (! Storage::disk('local')->put($path, $xml)) {
                $this->fail('gpx', 'The GPX original could not be saved.');
            }
            $source = MobileGpxSource::create(['survey_team_id' => $team->id, 'survey_date' => $date->format('Y-m-d'),
                'group_code' => $group, 'filename_group' => strtoupper($match[1]), 'expected_transformers' => (int) $match[3],
                'original_name' => $name, 'path' => $path, 'sha256' => $hash, 'points' => $parsed['waypoints'], 'uploaded_by' => $actor->id]);
            app(AuditService::class)->record($actor, 'mobile_gpx.uploaded', $source, [], $source->only(['sha256', 'original_name', 'survey_team_id']));

            return $source;
        });
    }

    public function surveyHash(FieldSurvey $survey): string
    {
        $data = $survey->only(['client_uuid', 'survey_team_id', 'feeder_id', 'transformer_id', 'transformer_code', 'survey_date', 'status', 'revision', 'header', 'rows', 'solar', 'reference_snapshot', 'remarks']);
        if ($survey->ht_network_update_id) {
            $data['ht_update'] = $survey->htUpdate?->only(['client_uuid', 'revision', 'status', 'transformer_id']);
        }

        return app(SnapshotService::class)->hash($data);
    }

    public function preview(User $actor, FieldSurvey $survey, array $sourceIds): array
    {
        app(HtNetworkWorkflow::class)->assertVerified($survey);
        $this->authorizeTeam($actor, $survey->team);
        abort_unless(app(FieldSurveyAccess::class)->visible($actor)->whereKey($survey->id)->exists(), 404);
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        $sources = MobileGpxSource::where('survey_team_id', $survey->survey_team_id)->whereIn('id', $sourceIds)->orderBy('id')->get();
        abort_unless(count($sourceIds) === $sources->count(), 404);
        $issues = FieldSurveyPairs::errors($survey->rows);
        if ($survey->status !== 'submitted') {
            $issues['status'] = 'The surveyor must submit this survey before GPX confirmation.';
        }
        if ($sources->isEmpty()) {
            $issues['sources'] = 'Select at least one GPX source.';
        }
        foreach ($survey->solar as $i => $pv) {
            if (empty($pv['row_id']) || ! in_array($pv['row_id'], array_column($survey->rows, 'row_id'), true)) {
                $issues['pv_'.$i] = 'PV record '.($i + 1).' must be linked to its original S/E row before export.';
            }
        }
        $points = [];
        foreach ($sources as $source) {
            if (! Storage::disk('local')->exists($source->path)) {
                $this->fail('gpx', 'An original GPX file is missing. Restore the original before proceeding.');
            }
            $xml = Storage::disk('local')->get($source->path);
            if (! is_string($xml) || ! hash_equals($source->sha256, hash('sha256', $xml))) {
                $this->fail('gpx', 'An original GPX file is missing or changed. Restore the original before proceeding.');
            }
            foreach (app(GpxParser::class)->parse($xml)['waypoints'] as $i => $point) {
                $points[] = $point + ['point_index' => $i + 1, 'source_id' => $source->id, 'source_date' => $source->survey_date->format('Y-m-d'),
                    'source_group' => $source->group_code, 'filename' => $source->original_name];
            }
        }
        $firstPair = array_values(FieldSurveyPairs::groups($survey->rows))[0] ?? [];
        $firstS = collect($firstPair)->first(fn ($i) => ($survey->rows[$i]['se'] ?? '') === 'S');
        $mapping = $warnings = [];
        foreach ($survey->rows as $i => $row) {
            $identity = null;
            try {
                $gps = (string) ($row['gps_waypoint'] ?? '');
                if (preg_match('/^0*[0-9]{1,3}$/D', $gps)) {
                    $identity = app(SurveyWaypoint::class)->compose($row['group'] ?? null, $row['date'] ?? null, str_pad((string) (int) $gps, 3, '0', STR_PAD_LEFT));
                }
            } catch (ValidationException) {
                // Report invalid observations alongside the rest of the matching rows.
            }
            if (empty($row['row_id'])) {
                $issues['row_id_'.$i] = 'Row '.($i + 1).' needs a stable mobile row ID. Resync from the updated app.';
            }
            $item = ['row_id' => $row['row_id'] ?? null, 'row_index' => $i, 'se' => $row['se'] ?? '', 'group' => $row['group'] ?? '',
                'date' => $row['date'] ?? '', 'waypoint' => $row['gps_waypoint'] ?? '', 'complete_waypoint' => $identity, 'point' => null];
            if (! $identity) {
                $issues['row_'.$i] = 'Row '.($i + 1).' needs a two-digit group, valid date, and waypoint from 000 to 999.';
            }
            if ($i === $firstS) {
                $item['status'] = 'Historical first S: starting-coordinate policy applies';
                $mapping[] = $item;

                continue;
            }
            // Exact text first, then explicit numeric equivalence for short GPS names only.
            // Never truncate a complete 11-digit GPX name into a short waypoint.
            $candidates = array_values(array_filter($points, fn ($p) => $p['name'] === ($row['gps_waypoint'] ?? '') || ($identity && $p['name'] === $identity)));
            if ($candidates === [] && preg_match('/^0*[0-9]{1,3}$/D', (string) ($row['gps_waypoint'] ?? ''))) {
                $candidates = array_values(array_filter($points, fn ($p) => preg_match('/^0*[0-9]{1,3}$/D', $p['name']) && (int) $p['name'] === (int) $row['gps_waypoint']));
            }
            $candidates = array_values(array_filter($candidates, fn ($p) => $p['source_group'] === ($row['group'] ?? '') && $p['source_date'] === ($row['date'] ?? '')));
            // A repeated name can be disambiguated by its actual recording date.
            if (count($candidates) > 1) {
                $dated = array_values(array_filter($candidates, fn ($p) => $p['recorded_at'] && CarbonImmutable::parse($p['recorded_at'], 'UTC')->timezone('Asia/Karachi')->format('Y-m-d') === ($row['date'] ?? '')));
                if (count($dated) === 1) {
                    $candidates = $dated;
                }
            }
            if (count($candidates) !== 1) {
                $item['status'] = count($candidates) ? 'Ambiguous: select a smaller set of GPX sources or correct the source' : 'Missing matching waypoint / group / date';
                $issues['row_'.$i] = 'Row '.($i + 1).': '.$item['status'].'.';
            } else {
                $point = $candidates[0];
                $item['point'] = $point;
                $item['status'] = 'Matched';
                if ($point['name'] !== $row['gps_waypoint'] && $point['name'] !== $identity) {
                    $warnings[] = 'Row '.($i + 1).': matched numeric-equivalent GPX name '.$point['name'].'; original survey reference '.$row['gps_waypoint'].' is retained.';
                }
                if (! $point['recorded_at']) {
                    $warnings[] = 'Row '.($i + 1).': GPX timestamp is missing. Confirm the session with the surveyor.';
                } elseif (CarbonImmutable::parse($point['recorded_at'], 'UTC')->timezone('Asia/Karachi')->format('Y-m-d') !== $row['date']) {
                    $warnings[] = 'Row '.($i + 1).': GPX recording date differs. Obtain surveyor clarification before confirmation.';
                }
            }
            $mapping[] = $item;
        }
        $usedSources = array_unique(array_column(array_filter(array_column($mapping, 'point')), 'source_id'));
        foreach ($sources as $source) {
            if (! in_array($source->id, $usedSources, true)) {
                $issues['unused_source_'.$source->id] = $source->original_name.' supplies no required matched points for this transformer. Deselect it.';
            }
        }
        $report = ['issues' => array_values($issues), 'warnings' => array_values(array_unique($warnings)), 'mapping' => $mapping,
            'sources' => $sources->map->only(['id', 'sha256', 'original_name', 'expected_transformers'])->all(),
            'survey_revision' => $survey->revision, 'survey_sha256' => $this->surveyHash($survey)];
        $report['preview_sha256'] = app(SnapshotService::class)->hash($report);

        return $report;
    }

    public function confirm(User $actor, FieldSurvey $survey, array $ids, int $revision, string $previewHash, ?string $note): MobileGpxLink
    {
        return DB::transaction(function () use ($actor, $survey, $ids, $revision, $previewHash, $note) {
            $survey = FieldSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            abort_unless($survey->revision === $revision, 409, 'The survey changed. Review the current revision.');
            $report = $this->preview($actor, $survey, $ids);
            abort_unless(hash_equals($report['preview_sha256'], $previewHash), 409, 'The GPX selection or survey changed. Preview again before confirming.');
            if ($report['issues']) {
                $this->fail('gpx', implode(' ', $report['issues']));
            }
            if ($report['warnings'] && blank($note)) {
                $this->fail('confirmation_note', 'Record the surveyor clarification and your reason for accepting the warnings.');
            }
            $snapshot = app(MobileSurveySnapshot::class)->build($survey, $report);
            $link = $survey->gpxLinks()->create(['survey_revision' => $survey->revision, 'survey_sha256' => $this->surveyHash($survey),
                'source_ids' => array_values(array_map('intval', $ids)), 'mapping' => $report['mapping'], 'report' => $report,
                'snapshot' => $snapshot, 'sha256' => app(SnapshotService::class)->hash($snapshot),
                'confirmation_note' => $note, 'confirmed_by' => $actor->id]);
            app(AuditService::class)->record($actor, 'mobile_gpx.link_confirmed', $link, [], ['survey_uuid' => $survey->client_uuid, 'revision' => $survey->revision, 'source_ids' => $ids, 'sha256' => $link->sha256]);

            return $link;
        });
    }

    public function current(FieldSurvey $survey): ?MobileGpxLink
    {
        app(HtNetworkWorkflow::class)->assertVerified($survey);
        $link = $survey->gpxLinks()->latest('id')->first();

        return $link && $link->survey_revision === $survey->revision && hash_equals($link->survey_sha256, $this->surveyHash($survey)) ? $link : null;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
