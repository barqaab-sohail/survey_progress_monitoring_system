@can('mdb-workflow.export')
@if($batch->approved_revision_id && $batch->approvedRevision)
<section class="card"><h3>Generate SynerGEE MDB on Windows</h3>
    <p>Export this verified revision with its engineering settings, coordinates, original survey observations and clean MDB template. On your local Windows application, open <strong>JSON to SynerGEE</strong> and upload this file.</p>
    <form method="GET" action="{{ route('mdb-workflow.synergee-json.download', $batch) }}">
        <input type="hidden" name="revision_sha256" value="{{ $batch->approvedRevision->sha256 }}">
        <label>Export scope<select name="transformer_id"><option value="">Whole feeder (requires an approved consolidated mapping)</option>
        @foreach($batch->approvedRevision->snapshot['transformers'] ?? [] as $t)<option value="{{ $t['id'] }}" @selected((isset($active) && $active?->id === $t['id']) || count($batch->approvedRevision->snapshot['transformers']) === 1)>{{ $t['code'] }}</option>@endforeach
        </select></label>
        <button class="btn btn-primary" type="submit">Download SynerGEE JSON</button>
    </form>
    <p>The local generator reports missing engineering inputs before creating the MDB. The data-entry JSON is a separate format.</p>
</section>
@endif
@endcan
