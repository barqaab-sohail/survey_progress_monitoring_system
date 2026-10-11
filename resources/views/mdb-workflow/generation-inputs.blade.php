@if($batch->staged_workflow)
@can('mdb-workflow.prepare')
<div class="mdb-generation-inputs"><h3>MDB generation files</h3>
    <p class="mdb-note">Generation uses the approved survey revision, its original PDF and GPX, a KMZ map, and the approved MDB base template. Each generation request freezes its input files and checks their hashes. KMZ and reference files do not replace verified survey coordinates or values.</p>
    @foreach($batch->sources as $source)<p>{{ strtoupper($source->kind) }}: {{ $source->original_name }} — {{ $source->status }}</p>@endforeach
    <p>MDB base template: {{ data_get($batch->approvedRevision?->snapshot, 'template.code', 'Approval required') }} {{ data_get($batch->approvedRevision?->snapshot, 'template.version', '') }}</p>
    @php($requiredBaseFiles = data_get($batch->approvedRevision?->snapshot, 'template.metadata.required_base_files', []))
    @if(is_array($requiredBaseFiles) && count($requiredBaseFiles))<p>Required base files: {{ implode(', ', array_filter($requiredBaseFiles, 'is_string')) }}</p>@elseif(!is_array($requiredBaseFiles))<p>Required base file setup needs correction by the administrator.</p>@endif
    @if($batch->survey_status === 'approved' && $batch->approved_revision_id)
        <form method="POST" action="{{ route('mdb-workflow.generation.inputs.store', $batch) }}" enctype="multipart/form-data">@csrf<div class="mdb-fields"><label>File purpose<select name="kind"><option value="kmz">KMZ map</option><option value="base">Base / reference file</option></select></label><label>Generation file<input type="file" name="file" required accept=".kmz,.mdb,.csv,.xlsx,.json,.ini,.cfg,.xml,.prj,.dat,.kml,.zip,.txt"></label></div><div class="mdb-form-actions"><button class="btn btn-light">Add generation file</button></div></form>
        <ul>@foreach($batch->generationInputs->sortByDesc('id') as $input)<li><a href="{{ route('mdb-workflow.generation.inputs.download', [$batch, $input]) }}">{{ $input->original_name }}</a> · {{ strtoupper($input->kind) }} · {{ $input->uploader?->name ?? 'Deleted user' }} · Added {{ $input->created_at->timezone('Asia/Karachi')->format('d M Y H:i:s') }} PKT</li>@endforeach</ul>
    @else<p class="mdb-note">Data Verifier approval is required before preparing generation files.</p>@endif
</div>
@endcan
@endif
