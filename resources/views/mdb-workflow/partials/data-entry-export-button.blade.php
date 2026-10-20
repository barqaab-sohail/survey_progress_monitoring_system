@if($batch->status === 'approved' && $batch->approved_revision_id && app(\App\Services\Mdb\WorkflowAccess::class)->allowsForBatch(auth()->user(), $batch, 'export'))
<a class="btn btn-light" data-entry-leave href="{{ route('mdb-workflow.data-entry-export.show', $batch) }}">Export Data Entry MDB</a>
@endif
