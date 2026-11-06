<?php

namespace App\Http\Controllers;

use App\Models\FieldSurvey;
use App\Models\MobileGpxLink;
use App\Models\MobileGpxSource;
use App\Models\SurveyTeam;
use App\Services\AuditService;
use App\Services\FieldSurveyAccess;
use App\Services\MobileGpxWorkflow;
use App\Services\MobileSurveySnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MobileGpxController extends Controller
{
    public function index(Request $request, FieldSurveyAccess $access, MobileGpxWorkflow $workflow)
    {
        abort_unless($workflow->canLink($request->user()), 403);
        $filters = $request->validate(['team_id' => 'nullable|integer', 'date' => 'nullable|date_format:Y-m-d', 'q' => 'nullable|string|max:100']);
        $teams = SurveyTeam::with('project')->where('status', 'active')->whereHas('project', fn ($q) => $q->where('status', 'active'))
            ->unless($request->user()->hasRole('super_admin'), fn ($q) => $q->whereIn('project_id', $request->user()->mdbTeams()->where('status', 'active')->select('mdb_teams.project_id')))->get();
        $surveys = $access->visible($request->user())->with(['team', 'feeder', 'collector', 'gpxLinks'])->where('status', 'submitted')
            ->when($filters['team_id'] ?? null, fn ($q, $id) => $q->where('survey_team_id', $id))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('survey_date', $date))
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where('transformer_code', 'like', '%'.$search.'%'))
            ->latest('survey_date')->paginate(25)->withQueryString();
        $sources = MobileGpxSource::with('team')->whereIn('survey_team_id', $teams->modelKeys())->latest('id')->limit(50)->get();
        foreach ($sources as $source) {
            $source->setAttribute('linked_count', MobileGpxLink::whereJsonContains('source_ids', $source->id)
                ->whereIn('id', MobileGpxLink::selectRaw('MAX(id)')->groupBy('field_survey_id'))
                ->whereHas('survey', fn ($q) => $q->whereColumn('field_surveys.revision', 'mobile_gpx_links.survey_revision'))
                ->distinct()->count('field_survey_id'));
        }

        return view('mobile-gpx.index', compact('teams', 'surveys', 'sources', 'filters', 'workflow'));
    }

    public function upload(Request $request, MobileGpxWorkflow $workflow)
    {
        abort_unless($workflow->canLink($request->user()), 403);
        $data = $request->validate(['survey_team_id' => 'required|integer', 'gpx' => 'required|file|extensions:gpx|max:10240']);
        $source = $workflow->upload($request->user(), SurveyTeam::findOrFail($data['survey_team_id']), $request->file('gpx'));

        return to_route('mobile-gpx.index', ['team_id' => $source->survey_team_id, 'date' => $source->survey_date->format('Y-m-d')])
            ->with('success', 'Shared GPX retained once. Open each related transformer to preview and confirm its waypoint links.');
    }

    private function survey(Request $request, string $clientUuid, MobileGpxWorkflow $workflow): FieldSurvey
    {
        abort_unless($workflow->canLink($request->user()), 403);
        $survey = app(FieldSurveyAccess::class)->visible($request->user())->with(['team', 'feeder', 'collector'])->where('client_uuid', $clientUuid)->firstOrFail();
        $workflow->authorizeTeam($request->user(), $survey->team);

        return $survey;
    }

    public function show(Request $request, string $clientUuid, MobileGpxWorkflow $workflow)
    {
        $survey = $this->survey($request, $clientUuid, $workflow);

        return $this->screen($survey, $workflow);
    }

    private function screen(FieldSurvey $survey, MobileGpxWorkflow $workflow, ?array $report = null, ?array $selected = null)
    {
        $dates = array_unique(array_merge([$survey->survey_date->format('Y-m-d')], array_column($survey->rows, 'date')));
        $sources = MobileGpxSource::where('survey_team_id', $survey->survey_team_id)->whereIn('survey_date', $dates)->latest('id')->get();
        $current = $workflow->current($survey);
        $previous = $survey->gpxLinks()->latest('id')->first();
        $selected ??= $current?->source_ids ?? [];

        return view('mobile-gpx.show', compact('survey', 'sources', 'current', 'previous', 'selected', 'report'));
    }

    private function selection(Request $request): array
    {
        return $request->validate(['source_ids' => 'required|array|min:1|max:20', 'source_ids.*' => 'required|integer|distinct', 'survey_revision' => 'required|integer|min:1']);
    }

    public function preview(Request $request, string $clientUuid, MobileGpxWorkflow $workflow)
    {
        $survey = $this->survey($request, $clientUuid, $workflow);
        $data = $this->selection($request);
        abort_unless($survey->revision === (int) $data['survey_revision'], 409, 'The survey changed. Reload before previewing.');
        $report = $workflow->preview($request->user(), $survey, $data['source_ids']);

        return $this->screen($survey, $workflow, $report, array_map('intval', $data['source_ids']));
    }

    public function confirm(Request $request, string $clientUuid, MobileGpxWorkflow $workflow)
    {
        $survey = $this->survey($request, $clientUuid, $workflow);
        $data = $this->selection($request) + $request->validate(['preview_sha256' => 'required|regex:/^[a-f0-9]{64}$/D', 'confirmed' => 'accepted', 'confirmation_note' => 'nullable|string|max:4000']);
        $workflow->confirm($request->user(), $survey, $data['source_ids'], (int) $data['survey_revision'], $data['preview_sha256'], $data['confirmation_note'] ?? null);

        return to_route('mobile-gpx.show', $clientUuid)->with('success', 'GPX linkage confirmed for this survey revision. JSON export will also check model readiness.');
    }

    public function export(Request $request, string $clientUuid, MobileGpxWorkflow $workflow, MobileSurveySnapshot $transfer)
    {
        $survey = $this->survey($request, $clientUuid, $workflow);
        $data = $request->validate(['survey_revision' => 'required|integer|min:1', 'link_id' => 'required|integer|min:1']);
        $package = DB::transaction(function () use ($request, $survey, $workflow, $transfer, $data) {
            $locked = FieldSurvey::whereKey($survey->id)->lockForUpdate()->firstOrFail();
            $workflow->authorizeTeam($request->user(), $locked->team);
            $link = $workflow->current($locked);
            abort_unless($locked->revision === (int) $data['survey_revision'] && $link && $link->id === (int) $data['link_id'], 409, 'GPX confirmation is missing or outdated. Preview and confirm the current revision.');
            $report = $workflow->preview($request->user(), $locked, $link->source_ids);
            abort_unless(hash_equals($link->report['preview_sha256'], $report['preview_sha256']), 409, 'The source evidence changed. Preview and confirm again.');
            $package = $transfer->export($link);
            app(AuditService::class)->record($request->user(), 'mobile_gpx.json_exported', $link, [], ['package_sha256' => $package['sha256'], 'survey_uuid' => $locked->client_uuid]);

            return $package;
        });
        $json = json_encode($package, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return response()->streamDownload(fn () => print ($json), 'mobile_'.Str::slug($survey->survey_name, '_').'_r'.$survey->revision.'.json',
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
    }
}
