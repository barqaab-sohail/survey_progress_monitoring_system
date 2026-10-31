<?php

namespace App\Http\Controllers;

use App\Models\GpxCalculation;
use App\Services\AuditService;
use App\Services\Gpx\Access;
use App\Services\Gpx\Calculator;
use App\Services\Gpx\KmzExporter;
use App\Services\Gpx\Parser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GpxCalculatorController extends Controller
{
    public function __construct(private Access $access, private Calculator $calculator, private AuditService $audit) {}

    public function index(Request $request)
    {
        return view('gpx-calculator.index', ['verifiedBatches' => $this->access->verifiedBatches($request->user())->with(['project', 'feeder', 'approvedRevision'])->get(), 'calculations' => $this->access->visible($request->user())->with(['project', 'feeder'])->latest('updated_at')->paginate(20)] + $this->references($request));
    }

    public function verified(Request $request, \App\Services\Gpx\VerifiedSurvey $surveys)
    {
        $data = $request->validate(['batch_id' => 'required|integer', 'transformer_id' => 'required|integer']);
        $record = DB::transaction(function () use ($request, $surveys, $data) {
            $batch = $this->access->verifiedBatches($request->user())->lockForUpdate()->findOrFail($data['batch_id']);
            $source = $surveys->source($batch, (int) $data['transformer_id']);
            $settings = array_replace($this->calculator->defaults(), ['span_sequences' => $source['survey']['span_sequences'], 'connections_confirmed' => true, 'supports_confirmed' => true]);
            $result = $this->calculator->calculate($source, $settings);
            if (! $result['complete']) {
                throw ValidationException::withMessages(['transformer_id' => 'The verified network has blocked spans. Correct and reverify the survey.']);
            }
            $record = GpxCalculation::create(['project_id' => $batch->project_id, 'feeder_id' => $batch->feeder_id,
                'created_by' => $request->user()->id, 'file_name' => 'Transformer '.$source['survey']['transformer_code'].' revision '.$source['survey']['revision'],
                'source_sha256' => $source['survey']['revision_sha256'], 'source_data' => $source, 'settings' => $settings, 'result' => $result]);
            $this->audit->record($request->user(), 'gpx.verified_survey_loaded', $record, new: array_intersect_key($source['survey'], array_flip(['batch_id', 'transformer_id', 'transformer_code', 'revision', 'revision_sha256'])));
            return $record;
        });
        return redirect()->route('gpx-calculator.show', $record)->with('success', 'Verified transformer loaded and horizontal length calculated. Elevation and sag are optional.');
    }

    private function verifiedSettings(GpxCalculation $record, array $settings): array
    {
        if (! empty($record->source_data['survey']['verified'])) {
            $settings['span_sequences'] = $record->source_data['survey']['span_sequences'];
            $settings['connections_confirmed'] = $settings['supports_confirmed'] = true;
        }
        return $settings;
    }

    public function store(Request $request, Parser $parser)
    {
        Gate::authorize('gpx-calculator.use');
        $request->validate(['gpx' => ['required', 'file', 'extensions:gpx', 'max:10240'],
            'survey_json' => ['nullable', 'file', 'extensions:json', 'max:30720'], 'transformer_code' => ['nullable', 'string', 'max:100']]);
        $association = $this->association($request);
        $file = $request->file('gpx');
        $xml = file_get_contents($file->getRealPath());
        $source = $parser->parse($xml);
        $settings = $this->calculator->defaults();
        if ($request->hasFile('survey_json')) {
            $import = app(\App\Services\Gpx\SurveyConnections::class)->apply($source,
                file_get_contents($request->file('survey_json')->getRealPath()), $request->input('transformer_code'));
            $source = $import['source'];
            $settings['span_sequences'] = $import['span_sequences'];
        }
        $result = $this->calculator->calculate($source, $settings);
        $calculation = DB::transaction(function () use ($request, $file, $xml, $source, $settings, $result, $association) {
            $calculation = GpxCalculation::create($association + ['created_by' => $request->user()->id,
                'file_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 255),
                'source_sha256' => hash('sha256', $xml), 'source_data' => $source, 'settings' => $settings, 'result' => $result]);
            $this->audit->record($request->user(), 'gpx.uploaded', $calculation, new: ['file_name' => $calculation->file_name, 'source_sha256' => $calculation->source_sha256] + $association);

            return $calculation;
        });

        return redirect()->route('gpx-calculator.show', $calculation)->with('success', 'GPX imported. Review points, connectivity and calculation options.');
    }

    public function show(Request $request, int $calculation)
    {
        return view('gpx-calculator.show', ['calculation' => $this->access->visible($request->user())->findOrFail($calculation)] + $this->references($request));
    }

    public function preview(Request $request, int $calculation)
    {
        $record = $this->access->visible($request->user())->findOrFail($calculation);
        $settings = $this->verifiedSettings($record, $this->settings($request));

        return response()->json($this->calculator->calculate($record->source_data, $settings));
    }

    public function update(Request $request, int $calculation, KmzExporter $exporter)
    {
        $record = $this->access->visible($request->user())->findOrFail($calculation);
        $settings = $this->verifiedSettings($record, $this->settings($request));
        $association = $this->association($request);
        $result = $this->calculator->calculate($record->source_data, $settings);
        $export = $request->input('action') === 'export';
        if ($export && ! $result['complete']) {
            throw ValidationException::withMessages(['span_sequences' => 'Resolve all blocked spans and confirm connectivity before exporting. At least one included span is required.']);
        }
        DB::transaction(function () use ($record, $request, $association, $settings, $result) {
            $record->update($association + ['settings' => $settings, 'result' => $result]);
            $this->audit->record($request->user(), 'gpx.calculated', $record, new: $association + ['settings' => $settings, 'final_m' => $result['final_m'], 'version' => $result['version']]);
        });
        if ($export) {
            $path = $exporter->write($record->file_name, $record->source_data, $settings, $result);
            $this->audit->record($request->user(), 'gpx.kmz_exported', $record, new: ['final_m' => $result['final_m'], 'settings' => $settings]);

            return response()->download($path, (Str::slug(pathinfo($record->file_name, PATHINFO_FILENAME)) ?: 'gpx-calculation').'.kmz',
                ['Content-Type' => 'application/vnd.google-earth.kmz', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
        }

        return back()->with('success', 'Calculation settings, associations and full-precision results saved.');
    }

    private function settings(Request $request): array
    {
        $data = $request->validate([
            'include_elevation' => ['sometimes', 'boolean'], 'include_sag' => ['sometimes', 'boolean'],
            'connections_confirmed' => ['sometimes', 'boolean'], 'supports_confirmed' => ['sometimes', 'boolean'],
            'span_sequences' => ['nullable', 'string', 'max:150000'],
            'default_sag' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'sag_overrides' => ['sometimes', 'array', 'max:10000'], 'sag_overrides.*' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'missing_elevation' => ['nullable', Rule::in(['exclude', 'horizontal'])],
            'long_span_m' => ['required', 'numeric', 'min:1', 'max:100000'],
        ]);
        foreach (['include_elevation', 'include_sag', 'connections_confirmed', 'supports_confirmed'] as $field) {
            $data[$field] = $request->boolean($field);
        }
        $data['span_sequences'] = $data['span_sequences'] ?? '';
        $data['sag_overrides'] = array_filter($data['sag_overrides'] ?? [], fn ($value) => $value !== null && $value !== '');

        return array_replace($this->calculator->defaults(), $data);
    }

    private function references(Request $request): array
    {
        return ['projects' => $this->access->projects($request->user())->orderBy('name')->get(),
            'feeders' => $this->access->feeders($request->user())->orderBy('feeder_code')->get()];
    }

    private function association(Request $request): array
    {
        $data = $request->validate(['project_id' => ['nullable', 'integer'], 'feeder_id' => ['nullable', 'integer']]);
        $project = empty($data['project_id']) ? null : $this->access->projects($request->user())->find($data['project_id']);
        $feeder = empty($data['feeder_id']) ? null : $this->access->feeders($request->user())->find($data['feeder_id']);
        if ((! empty($data['project_id']) && ! $project) || (! empty($data['feeder_id']) && (! $feeder || ! $project || $feeder->project_id !== $project->id))) {
            throw ValidationException::withMessages(['project_id' => 'Select an accessible active project and an assigned feeder in that project, or leave both unlinked.']);
        }

        return ['project_id' => $project?->id, 'feeder_id' => $feeder?->id];
    }
}
