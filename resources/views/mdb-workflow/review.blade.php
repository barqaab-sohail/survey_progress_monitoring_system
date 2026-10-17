@extends('layouts.app')
@section('title', 'Review MDB survey')
@section('content')
@php
    $active = $batch->transformers->firstWhere('id', (int) request('transformer_id')) ?? $batch->transformers->first();
    $firstPair = $active?->entryRows->min('pair_number');
    $firstEnd = $active?->entryRows->first(fn ($row) => $row->pair_number == $firstPair && $row->designation === 'E');
    $firstStart = $active?->entryRows->first(fn ($row) => $row->pair_number == $firstPair && $row->designation === 'S');
    $firstStartIsKmz = $firstStart && empty($firstStart->gpx_source_id);
    $transformerLocation = $active ? app(\App\Services\Mdb\SurveyWaypoint::class)->transformerLocation([
        'header' => $active->header, 'source_waypoint_id' => $active->source_waypoint_id,
        'entry_rows' => $active->entryRows->map->surveyData()->all(),
    ], $batch->waypoints->toArray()) : [];
    $transformerPoint = $batch->waypoints->firstWhere('id', $transformerLocation['source_waypoint_id'] ?? null);

    $pdfSources = $batch->sources->where('kind', 'pdf');
    $locations = collect();
    if ($active) {
        $locations->push(['source_pdf_id' => $active->header['pdf_source_id'] ?? null, 'page' => $active->header['pdf_start_page'] ?? null]);
        $locations = $locations->concat(collect($active->header['pages'] ?? [])->where('role', 'header'))
            ->concat($active->header['pages'] ?? [])
            ->concat($active->entryRows->sortBy('source_page')->map(fn ($row) => ['source_pdf_id' => $row->source_pdf_id, 'page' => $row->source_page]));
    }
    $pdfLocation = $locations->first(function ($location) use ($pdfSources) {
        $source = $pdfSources->firstWhere('id', $location['source_pdf_id'] ?? null);
        $page = filter_var($location['page'] ?? null, FILTER_VALIDATE_INT);
        return $source && $page > 0 && $page <= ($source->metadata['page_count'] ?? PHP_INT_MAX);
    });
    $pdf = $pdfSources->firstWhere('id', $pdfLocation['source_pdf_id'] ?? null) ?? $pdfSources->first();
    $pdfStartPage = $pdfLocation['page'] ?? 1;
@endphp
<div class="mdb-workflow mdb-entry">
    <div class="page-head"><div><h1>Review survey data</h1><p>{{ $batch->feeder?->feeder_name }} · Batch #{{ $batch->id }} · Revision {{ $batch->revision }}</p></div><a class="btn btn-light" href="{{ route('mdb-workflow.show', [$batch, 'transformer_id'=>$active?->id]) }}">View survey PDF and rows</a></div>
    <nav class="mdb-tabs">@foreach($batch->transformers as $transformer)<a href="{{ route('mdb-workflow.review', [$batch, 'transformer_id'=>$transformer->id]) }}" @if($active?->id === $transformer->id) aria-current="page" @endif>{{ $transformer->code }}</a>@endforeach</nav>
    <div class="mdb-review-comparison" data-review-layout="paper"><section class="card mdb-review-source"><h2>Source PDF and GPX</h2><div class="actions">@foreach($batch->sources as $source)<a class="btn btn-light" href="{{ route('mdb-workflow.source', [$batch, $source]) }}" @if($source->kind === 'pdf') data-review-pdf-source @endif target="_blank" rel="noopener">{{ strtoupper($source->kind) }}: {{ $source->original_name }}</a>@endforeach</div>
        @if($pdf) @include('mdb-workflow.partials.review-pdf') @endif
        <details><summary>GPX waypoint comparison ({{ $batch->waypoints->count() }})</summary><div class="table-wrap"><table class="table"><thead><tr><th>Waypoint</th><th>GPX source</th><th>Latitude</th><th>Longitude</th></tr></thead><tbody>@foreach($batch->waypoints as $point)<tr><td>{{ $point->name }}</td><td>{{ $point->source_file_id }}</td><td>{{ $point->latitude }}</td><td>{{ $point->longitude }}</td></tr>@endforeach</tbody></table></div></details>
    </section><div class="mdb-review-entries">
    @if($active)
    @include('mdb-workflow.partials.review-workspace')
    <div id="verification-legacy-data">
    <section class="card"><h2>{{ $active->code }} — transformer header</h2><dl class="entry-review-header"><div><dt>Capacity</dt><dd>{{ $active->capacity_kva ?? 'Not entered' }} kVA</dd></div>
        @foreach(['substation_name'=>'Substation','feeder_name'=>'Feeder','feeder_identifier'=>'Feeder code','division'=>'Division','subdivision'=>'Subdivision','subdivision_code'=>'Subdivision code','make'=>'Make','inspector'=>'Inspector','location'=>'Location','survey_date'=>'Survey date','mounting'=>'Mounting','service_category'=>'Service category'] as $key=>$label)<div><dt>{{ $label }}</dt><dd>{{ $active->header[$key] ?? 'Not entered' }}</dd></div>@endforeach
        <div><dt>Transformer location (first E)</dt><dd>{{ $firstEnd && $transformerPoint ? 'GPS '.$transformerPoint->name.' from GPX' : 'Awaiting a unique GPX match for the first E row' }}</dd></div>
        @if($pdfLocation)<div><dt>PDF / starting page</dt><dd>{{ $pdf->original_name }} / {{ $pdfStartPage }}</dd></div>@endif
    </dl></section>
    <section class="card"><h2>All S/E rows for {{ $active->code }}</h2><div class="table-wrap"><table class="table entry-review-table"><thead><tr><th>Pair / S/E</th><th>Page / entry sequence</th><th>Group / date</th><th>GPS waypoint</th><th>R / Y / B / N</th><th>Phase</th><th>Type / pole</th><th>Consumers</th><th>Int</th><th>PV details</th></tr></thead><tbody>
        @forelse($active->entryRows as $row)<tr><td>{{ $row->pair_number }} / <strong>{{ $row->designation }}</strong></td><td>{{ $row->source_page ?? '—' }} / {{ $row->source_row ?? '—' }}</td><td>{{ $row->group_number ?? '—' }}<small>{{ $row->row_date?->format('d/m/Y') ?? '—' }}</small></td><td>{{ $row->composite_identifier ?? 'Legacy identity' }}<small>GPS: {{ $row->waypoint_reference ?? 'Not entered' }} &middot; @if($firstStartIsKmz && $row->id === $firstStart->id) KMZ reference (first S) @else GPX source #{{ $row->gpx_source_id ?? 'unresolved' }} @endif</small></td><td>@foreach(['R','Y','B','N'] as $phase)<small>{{ $phase }}: {{ ($row->conductors[$phase] ?? null) === null ? 'Not entered' : ($row->conductors[$phase] === '' ? 'None' : $row->conductors[$phase]) }}</small>@endforeach</td><td>{{ \App\Services\Mdb\EntryLookups::phase($row->conductors)['display'] ?: '—' }}</td><td>{{ $row->equipment_type ?? '—' }} / {{ $row->pole_class ?? '—' }}<small>{{ $row->pole_height ?? '—' }} {{ $row->pole_height_unit ?? '(unit unconfirmed)' }}</small></td><td>@foreach(\App\Services\Mdb\EntryLookups::CONSUMERS as $category=>$label)<small>{{ strtoupper($category) }}: {{ $row->consumers[$category] ?? 'blank' }}</small>@endforeach</td><td>{{ $row->intersection ? '✓' : '—' }}</td><td>@foreach($row->pv_details ?? [] as $pv)<small>{{ $pv['reference'] ?? '—' }} · service {{ $pv['service_load_kw'] ?? '—' }} kW · capacity {{ $pv['installed_capacity_kw'] ?? '—' }} kW · Meter: {{ $pv['meter_number'] ?? '—' }} · {{ $pv['remarks'] ?? '' }}</small>@endforeach</td></tr>@empty<tr><td colspan="10">No new S/E rows. Existing section records remain in the saved network; use the advanced editor to review them.</td></tr>@endforelse
    </tbody></table></div>
    @php($activeIssues = collect($entryIssues)->where('transformer_id', $active->id))
    @if($activeIssues->isNotEmpty())<div class="entry-error-summary entry-review-issues"><strong>Resolve these entries before final submission</strong><ul>@foreach($activeIssues as $issue)<li>Page {{ $issue['source_page'] ?? '—' }}, row {{ $issue['source_row'] ?? '—' }}: {{ $issue['message'] }}</li>@endforeach</ul></div>@endif

    </section></div>
    @if($canEdit)<section class="card" id="operator-review-confirmation"><h2>Save your review</h2><p class="mdb-note">After checking the header and S/E rows, save your review before submitting the survey for verification.</p>
<form method="POST" action="{{ route('mdb-workflow.entry.review', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}"><input type="hidden" name="transformer_id" value="{{ $active->id }}"><label class="mdb-check"><input name="confirmed" type="checkbox" value="1" required><span>I checked this transformer header and all its S/E rows against the PDF.</span></label><div class="mdb-form-actions"><button class="btn btn-primary" @disabled($activeIssues->isNotEmpty())>Review and save {{ $active->code }}</button><a class="btn btn-light" href="{{ route('mdb-workflow.show', [$batch, 'transformer_id'=>$active->id]) }}">Correct entries</a></div></form>
    </section>@endif
    @endif
    </div></div>
    @include('mdb-workflow.workflow-actions')
    @if($canCreateMdb)<section class="card"><h2>MDB creation</h2><p class="mdb-note">{{ str($batch->status)->replace('_',' ')->title() }}. Saved draft rows remain available when you return to entry.</p>
        @if(!$workerConfigured)<p class="mdb-worker-note">MDB export worker not configured.</p>@endif
        @include('mdb-workflow.generation-inputs')
        @can($batch->staged_workflow ? 'mdb-workflow.generate' : 'mdb-workflow.export')<form method="POST" action="{{ route('mdb-workflow.exports.store', $batch) }}">@csrf<input type="hidden" name="revision" value="{{ $batch->revision }}"><input type="hidden" name="transformer_id" value="{{ $active?->id }}"><div class="mdb-form-actions"><button class="btn btn-primary" @disabled(!$batch->approved_revision_id)>Generate MDB</button>@if(!$batch->approved_revision_id)<small>Verifier approval is required.</small>@endif</div></form>@endcan
        <details><summary>MDB generation requirements ({{ count($validation['errors']) }})</summary><ul class="mdb-issue-list">@foreach($validation['errors'] as $issue)<li>{{ $issue['message'] }} @if(isset($issue['source_page']))<small>Page {{ $issue['source_page'] }}, row {{ $issue['source_row'] ?? '—' }}</small>@endif</li>@endforeach</ul></details>
        <div class="mdb-form-actions"><a class="btn btn-light" href="{{ route('mdb-workflow.advanced', [$batch, 'transformer_id'=>$active?->id]) }}">MDB generation details</a>@can('mdb-workflow.configure')<a class="btn btn-light" href="{{ route('mdb-workflow.config', $batch->project_id) }}">Administrator settings</a>@endcan</div>
    </section>@endif
    @if($batch->exports->isNotEmpty())<section class="card"><h2>MDB generation history</h2>
        @foreach($batch->exports->sortByDesc('id') as $export)
        <p>Export #{{ $export->id }} — {{ $export->transformer?->code ?? 'Feeder' }} — {{ $export->superseded_at ? 'Outdated' : str($export->status)->replace('_',' ') }} &middot; {{ $export->generated_at ? 'Generated by' : 'Requested by' }} {{ $export->generator?->name ?? 'Unknown user' }} &middot; {{ $export->generated_at ? $export->generated_at->timezone('Asia/Karachi')->format('d M Y H:i:s').' PKT' : 'Not generated' }}
            @if($export->status === 'generated' && !$export->superseded_at)
                @can('mdb-workflow.export')<a href="{{ route('mdb-workflow.exports.download', $export) }}">Download MDB</a>@endcan
            @endif
        </p>
        @endforeach
    </section>@endif
    <section class="card"><h2>Action history</h2><ul>@forelse($workflowHistory as $event)<li>{{ $event->created_at->timezone('Asia/Karachi')->format('d M Y H:i:s') }} PKT · {{ $event->user?->name ?? 'Deleted user' }} · {{ str($event->action)->after('mdb.')->after('mdb_workflow.')->replace('_', ' ')->title() }} @if($event->reason) · {{ $event->reason }} @endif</li>@empty<li>No workflow actions recorded.</li>@endforelse</ul></section>
</div>
@endsection
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-workflow.css').'?v='.filemtime(public_path('css/mdb-workflow.css')) }}"><link rel="stylesheet" href="{{ asset('css/mdb-entry.css').'?v='.filemtime(public_path('css/mdb-entry.css')) }}">@endpush
