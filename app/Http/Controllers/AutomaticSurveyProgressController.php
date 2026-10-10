<?php

namespace App\Http\Controllers;

use App\Jobs\SurveyProgress\RunSurveyProgress;
use App\Models\Feeder;
use App\Models\SurveyProgress\Connection;
use App\Models\SurveyProgress\ProgressFeeder;
use App\Models\SurveyProgress\Run;
use App\Models\SurveyProgress\SourceFile;
use App\Models\SurveyProgress\Transcription;
use App\Services\SurveyProgress\DriveClient;
use App\Services\SurveyProgress\SurveyInventory;
use App\Services\SurveyProgress\Synchronizer;
use App\Services\SurveyProgress\TranscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AutomaticSurveyProgressController extends Controller
{
    public function index(Request $request)
    {
        $ready = Schema::hasTable('survey_progress_feeders');
        $feeders = app(Synchronizer::class)->feeders()->with(['circle', 'assignments'])->orderBy('feeder_code');
        if (! $request->user()->hasAnyRole(['super_admin', 'project_manager', 'management_viewer'])) {
            $feeders->whereHas('assignments', fn ($q) => $q->where('status', 'active')->whereIn('survey_team_id', $request->user()->surveyTeams()->select('survey_teams.id')));
        }
        $feeders = $feeders->get();
        $states = $ready ? ProgressFeeder::whereIn('feeder_id', $feeders->pluck('id'))->get()->keyBy('feeder_id') : collect();
        $eligible = $feeders->filter(fn ($f) => ! $f->baseline_pending && $f->total_transformers > 0);
        $baseline = (int) $eligible->sum('total_transformers');
        $surveyedEligible = (int) $eligible->sum(fn ($f) => $states->get($f->id)?->surveyed_count ?? 0);
        $current = $states->filter(fn ($s) => in_array($s->length_status, ['completed', 'needs_review'], true) && $s->synced_at && ! in_array($s->sync_status, ['mapping_required', 'failed'], true));
        $summary = ['surveyed' => $states->sum('surveyed_count'), 'baseline' => $baseline, 'remaining' => $eligible->sum(fn ($f) => max(0, $f->total_transformers - ($states->get($f->id)?->surveyed_count ?? 0))),
            'percentage' => $baseline ? round($surveyedEligible / $baseline * 100, 1) : null,
            'confirmed_km' => $current->sum('confirmed_km'), 'unverified_km' => $current->sum('unverified_horizontal_km'), 'calculated_feeders' => $current->whereNotNull('confirmed_km')->count()];
        $summary['by_group'] = [];
        $summary['by_date'] = [];
        foreach ($states as $state) {
            foreach (['by_group', 'by_date'] as $dimension) {
                foreach ($state->aggregates[$dimension] ?? [] as $key => $quantity) {
                    $summary[$dimension][$key] = ($summary[$dimension][$key] ?? 0) + $quantity;
                }
            }
        }
        ksort($summary['by_group']);
        ksort($summary['by_date']);
        $runs = $ready ? Run::with('requester')->where(fn ($q) => $q->whereIn('progress_feeder_id', $states->pluck('id'))->orWhereNull('progress_feeder_id'))->latest()->limit(20)->get() : collect();

        return view('survey-progress.index', ['feeders' => $feeders, 'states' => $states, 'summary' => $summary, 'runs' => $runs, 'ready' => $ready, 'connected' => $ready && Connection::whereKey(1)->exists(), 'configured' => (bool) (config('survey_progress.client_id') && config('survey_progress.client_secret'))]);
    }

    public function sync(Request $request)
    {
        return $this->enqueue($request, 'sync');
    }

    public function transcriptions(Request $request, Feeder $feeder, SurveyInventory $inventory)
    {
        abort_unless(app(Synchronizer::class)->feeders()->whereKey($feeder->id)->exists(), 404);
        $state = ProgressFeeder::where('feeder_id', $feeder->id)->firstOrFail();
        $analysis = $inventory->analyze($state->files()->where('active', true)->get()->pluck('metadata')->all());
        $history = Transcription::with(['operator', 'reviewer'])->where('progress_feeder_id', $state->id)->latest('version')->latest('id')->get();

        return view('survey-progress.transcriptions', ['feeder' => $feeder, 'state' => $state, 'analysis' => $analysis, 'history' => $history, 'sources' => $state->files()->where('active', true)->get()->keyBy('drive_id')]);
    }

    public function saveTranscription(Request $request, Feeder $feeder, TranscriptionService $service)
    {
        abort_unless(app(Synchronizer::class)->feeders()->whereKey($feeder->id)->exists(), 404);
        $data = $request->validate(['survey_key' => 'required|string|max:255', 'version' => 'required|integer|min:0', 'pdf_checksum' => 'required|string|size:32', 'gpx_checksum' => 'required|string|size:32', 'records' => 'required|string|max:500000', 'reviewed' => 'nullable|boolean', 'remarks' => 'nullable|string|max:4000']);
        $service->save(ProgressFeeder::where('feeder_id', $feeder->id)->firstOrFail(), $request->user(), $data);

        return back()->with('success', $request->boolean('reviewed') ? 'Reviewed transcription saved. Recalculate LT length to use it.' : 'Draft saved. Review all PDF S/E records before accepting this transcription.');
    }

    public function source(Request $request, Feeder $feeder, SourceFile $source, DriveClient $drive)
    {
        abort_unless(app(Synchronizer::class)->feeders()->whereKey($feeder->id)->exists(), 404);
        $state = ProgressFeeder::where('feeder_id', $feeder->id)->firstOrFail();
        abort_unless($source->progress_feeder_id === $state->id && $source->active && $source->kind === 'pdf', 404);
        $disk = Storage::disk('local');
        $directory = 'survey-progress/review/'.$source->id;
        $disk->makeDirectory($directory);
        abort_unless(preg_match('/^[a-f0-9]{32}$/D', $source->metadata['md5Checksum'] ?? ''), 422, 'Source checksum unavailable. Synchronize again.');
        $path = $disk->path($directory.'/'.$source->metadata['md5Checksum'].'.pdf');
        if (! is_file($path) || md5_file($path) !== $source->metadata['md5Checksum']) {
            $drive->download($source->metadata, $path);
        }
        abort_unless(file_get_contents($path, false, null, 0, 5) === '%PDF-', 422, 'Source is not a PDF.');

        return response()->file($path, ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function calculate(Request $request, Feeder $feeder)
    {
        abort_unless(app(Synchronizer::class)->feeders()->whereKey($feeder->id)->exists(), 404);

        return $this->enqueue($request, 'length', ProgressFeeder::where('feeder_id', $feeder->id)->firstOrFail());
    }

    private function enqueue(Request $request, string $type, ?ProgressFeeder $state = null)
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        abort_unless(Schema::hasTable('survey_progress_runs') && Connection::whereKey(1)->exists(), 422, 'Install the survey progress tables and connect its Drive account first.');
        $run = Cache::lock('survey-progress:enqueue', 15)->block(5, function () use ($request, $type, $state) {
            // A single progress worker keeps per-feeder inventories and calculation snapshots consistent.
            $existing = Run::whereIn('status', ['queued', 'running'])->first();
            abort_if($existing, 409, 'A survey progress task is already queued or running.');

            return Run::create(['type' => $type, 'progress_feeder_id' => $state?->id, 'requested_by' => $request->user()->id]);
        });
        RunSurveyProgress::dispatch($run->id)->onConnection(config('survey_progress.queue_connection'))->onQueue('survey-progress');

        return back()->with('success', 'Survey progress task queued. Refresh to see the result.');
    }

    public function mapping(Request $request, Feeder $feeder, DriveClient $drive)
    {
        abort_unless(app(Synchronizer::class)->feeders()->whereKey($feeder->id)->exists(), 404);
        $data = $request->validate(['folder_id' => 'required|string|max:200']);
        $drive->assertId($data['folder_id']);
        $folders = $drive->children(config('survey_progress.parent_folder'));
        abort_unless(collect($folders)->contains(fn ($f) => $f['id'] === $data['folder_id'] && $f['mimeType'] === 'application/vnd.google-apps.folder'), 422, 'The feeder folder must belong to the configured parent folder.');
        DB::transaction(function () use ($feeder, $data) {
            $state = ProgressFeeder::firstOrCreate(['feeder_id' => $feeder->id]);
            $state = ProgressFeeder::lockForUpdate()->findOrFail($state->id);
            abort_if(ProgressFeeder::where('folder_id', $data['folder_id'])->whereKeyNot($state->id)->exists(), 422, 'This folder is already mapped to another feeder.');
            $before = $state->toArray();
            $state->update(['folder_id' => $data['folder_id'], 'mapping_manual' => true, 'sync_status' => 'mapping_changed', 'synced_at' => null, 'manifest_hash' => null, 'surveyed_count' => 0, 'length_status' => 'stale']);
            Run::create(['type' => 'mapping', 'progress_feeder_id' => $state->id, 'requested_by' => auth()->id(), 'status' => 'completed', 'finished_at' => now(), 'result' => ['before' => $before, 'after' => $state->toArray()]]);
        });

        return back()->with('success', 'Folder mapping saved. Synchronize before calculating lengths.');
    }

    public function connect(Request $request)
    {
        abort_unless(config('survey_progress.client_id') && config('survey_progress.client_secret'), 503, 'Survey progress Google OAuth credentials are not configured.');
        $state = Str::random(64);
        $request->session()->put('survey_progress_oauth', ['state' => $state, 'user_id' => $request->user()->id, 'expires' => now()->addMinutes(10)->timestamp]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('survey_progress.client_id'), 'redirect_uri' => config('survey_progress.redirect_uri'), 'response_type' => 'code',
            'scope' => DriveClient::SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request)
    {
        $pending = $request->session()->pull('survey_progress_oauth');
        $state = $request->query('state');
        abort_unless(is_array($pending) && is_string($state) && hash_equals($pending['state'], $state) && $pending['user_id'] === $request->user()->id && $pending['expires'] > now()->timestamp, 403);
        if ($request->has('error')) {
            return to_route('survey-progress.index')->withErrors(['drive' => 'Google Drive authorization was declined.']);
        }
        $data = $request->validate(['code' => 'required|string|max:4096']);
        $response = Http::asForm()->timeout(30)->post('https://oauth2.googleapis.com/token', ['client_id' => config('survey_progress.client_id'), 'client_secret' => config('survey_progress.client_secret'), 'redirect_uri' => config('survey_progress.redirect_uri'), 'grant_type' => 'authorization_code', 'code' => $data['code']]);
        $token = $response->json();
        if (! $response->successful() || ! is_array($token) || empty($token['access_token']) || empty($token['refresh_token']) || ! in_array(DriveClient::SCOPE, explode(' ', $token['scope'] ?? ''), true)) {
            return to_route('survey-progress.index')->withErrors(['drive' => 'Read-only offline authorization was not granted. Reconnect and approve read access.']);
        }
        Connection::updateOrCreate(['id' => 1], ['token' => ['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'], 'scope' => $token['scope'], 'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600))->timestamp], 'connected_by' => $request->user()->id]);

        return to_route('survey-progress.index')->with('success', 'Survey progress Drive connected. Existing MDB Drive authorization is unchanged.');
    }
}
