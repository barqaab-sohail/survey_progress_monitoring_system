@extends('layouts.app')
@section('title','Audit Log')
@section('content')
<div class="page-head"><div><h1>Audit Log</h1><p>Operational and administrative history, subject to the configured retention policy. Omitted details are marked in the JSON.</p></div><form><div class="field"><input name="action" value="{{ request('action') }}" placeholder="Filter action"></div></form></div>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))" aria-label="Database storage statistics">
    <div class="card kpi"><div class="kpi-label">Total database size</div><div class="kpi-value">{{ \App\Services\Audit\Storage::formatBytes($statistics['database_bytes']) }}</div><small>Allocated data and indexes across this application's database.</small></div>
    <div class="card kpi"><div class="kpi-label">Audit log table size</div><div class="kpi-value">{{ \App\Services\Audit\Storage::formatBytes($statistics['audit_bytes']) }}</div><small>Data: {{ \App\Services\Audit\Storage::formatBytes($statistics['audit_data_bytes']) }} · Indexes: {{ \App\Services\Audit\Storage::formatBytes($statistics['audit_index_bytes']) }}</small></div>
    <div class="card kpi"><div class="kpi-label">Audit records{{ request()->filled('action') ? ' matching filter' : '' }}</div><div class="kpi-value">{{ number_format($logs->total()) }}</div><small>Exact record count{{ request()->filled('action') ? ' for the current action filter' : '' }}.</small></div>
</div>
<p class="muted">@if($statistics['available'])Storage sizes are database allocation estimates. Clearing records makes space reusable; the displayed allocation may not immediately decrease.@else Storage sizes are unavailable for this database connection. Log browsing remains available.@endif</p>
<section class="card audit-cleanup" aria-labelledby="audit-cleanup-heading">
    <h2 id="audit-cleanup-heading">Clear audit records</h2>
    <p>Keep the table and logging working. Assignment history, Drive-cleanup history and cleanup summaries are retained.</p>
    <p>Cleanup applies to the whole audit table, regardless of the action filter above.</p>
    <form method="POST" action="{{ route('admin.audit.preview') }}">
        @csrf
        <div class="field"><label for="audit-cleanup-scope">Records to clear</label>
            <select id="audit-cleanup-scope" name="scope">
                <option value="all" @selected(($cleanupPreview['scope'] ?? old('scope', 'all')) === 'all')>All removable audit records, including recent records</option>
                <option value="expired" @selected(($cleanupPreview['scope'] ?? old('scope')) === 'expired')>Expired records only, according to retention settings</option>
            </select>
        </div>
        <button class="btn btn-light" type="submit">Preview cleanup</button>
    </form>
    @if($cleanupPreview)
        <hr>
        <h3>Review this cleanup</h3>
        <p><strong>{{ number_format(count($cleanupPreview['records'])) }} records selected.</strong>
            {{ number_format($cleanupPreview['protected_count']) }} records protected.
            Preview expires 30 minutes after it was prepared.</p>
        @if($cleanupPreview['eligible_total'] > count($cleanupPreview['records']))
            <p class="alert alert-warning">This pass covers {{ number_format(count($cleanupPreview['records'])) }} of {{ number_format($cleanupPreview['eligible_total']) }} removable records. Prepare another preview after this pass to clear more.</p>
        @endif
        @if(count($cleanupPreview['records']))
            <p class="alert alert-warning">Clearing is permanent. {{ $cleanupPreview['scope'] === 'all' ? 'This selection includes recent business and security audit history.' : 'Only records expired under the retention policy are selected.' }} Verify that your daily application backup can be recovered before continuing.</p>
            <p><a class="btn btn-light btn-sm" href="{{ route('admin.audit.records') }}">Download exact record list (CSV)</a></p>
            <details><summary>Show first {{ min(10, count($cleanupPreview['records'])) }} selected records</summary>
                <div class="table-wrap"><table class="table"><thead><tr><th>Audit ID</th><th>Action</th><th>Recorded at</th></tr></thead><tbody>
                    @foreach(array_slice($cleanupPreview['records'], 0, 10) as $record)
                        <tr><td>{{ $record['id'] }}</td><td>{{ $record['action'] }}</td><td>{{ $record['created_at'] ?: 'Not recorded' }}</td></tr>
                    @endforeach
                </tbody></table></div>
            </details>
            <form method="POST" action="{{ route('admin.audit.clear') }}" id="audit-clear-form">
                @csrf
                <input type="hidden" name="plan_token" value="{{ $cleanupPreview['token'] }}">
                <div class="field"><label for="audit-clear-confirmation">Type CLEAR LOGS to confirm</label><input id="audit-clear-confirmation" name="confirmation" value="{{ old('confirmation') }}" required autocomplete="off" spellcheck="false"></div>
                <div class="field"><label for="audit-clear-password">Your current Super Admin password</label><input id="audit-clear-password" name="password" type="password" autocomplete="current-password" required></div>
                <div class="field"><label class="audit-backup-confirm"><input type="checkbox" name="backup_confirmed" value="1" required @checked(old('backup_confirmed'))> <span>I verified that the daily application backup can be recovered.</span></label></div>
                <p class="muted">A verified application backup from the last 48 hours must cover these records. New records created after this preview are retained.</p>
                <button class="btn btn-danger" type="submit" id="audit-clear-button">Clear listed logs</button>
                <p id="audit-clear-status" role="status" aria-live="polite">Cleanup runs in small batches. You can continue if it is interrupted.</p>
                <progress id="audit-clear-progress" max="{{ count($cleanupPreview['records']) }}" value="0" hidden style="width:100%"></progress>
            </form>
        @else
            <p>No removable records were found for this selection.</p>
        @endif
    @endif
</section>
<div class="card table-wrap"><table class="table"><thead><tr><th>Date & time</th><th>User</th><th>Organization</th><th>Action</th><th>Record</th><th>Reason</th><th>Change</th></tr></thead><tbody>@foreach($logs as $log)<tr><td>{{ $log->created_at?->format('d M Y h:i A') ?? 'Not recorded' }}</td><td>{{ $log->user?->name ?: 'System' }}</td><td>{{ $log->organization?->name ?: '—' }}</td><td><strong>{{ $log->action }}</strong></td><td>{{ class_basename($log->auditable_type) }} #{{ $log->auditable_id }}</td><td>{{ $log->reason ?: '—' }}</td><td><details><summary>View JSON</summary><pre style="max-width:480px;white-space:pre-wrap">{{ json_encode(['old'=>$log->old_values,'new'=>$log->new_values], JSON_PRETTY_PRINT) }}</pre></details></td></tr>@endforeach</tbody></table></div>{{ $logs->links() }}
@endsection
@push('styles')
<style>
.audit-cleanup .field{margin-bottom:14px}
.audit-cleanup hr{margin:22px 0;border:0;border-top:1px solid #e5eaf0}
.audit-cleanup .alert-warning{background:#fffaeb;color:#7a2e0e;border:1px solid #fedf89}
.audit-cleanup .audit-backup-confirm{display:flex;align-items:flex-start;gap:10px}
.audit-cleanup .audit-backup-confirm input[type=checkbox]{width:18px;height:18px;min-height:18px;flex:0 0 18px;margin:2px 0 0;padding:0}
</style>
@endpush
@push('scripts')
<script>
(() => {
    const form = document.getElementById('audit-clear-form');
    if (!form || !window.fetch) return;
    const button = document.getElementById('audit-clear-button');
    const previewControls = form.closest('section').querySelector('form').querySelectorAll('button, select');
    const status = document.getElementById('audit-clear-status');
    const progress = document.getElementById('audit-clear-progress');
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        previewControls.forEach(control => control.disabled = true);
        button.textContent = 'Clearing…';
        progress.hidden = false;
        status.textContent = 'Verifying the daily backup and clearing the first batch…';
        try {
            while (true) {
                const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, body: new FormData(form)});
                if (response.status === 419 || response.status === 401) throw new Error('Your session expired. Sign in again and prepare a new preview.');
                let result;
                try { result = await response.json(); } catch (_) { throw new Error('Cleanup response was interrupted. Refresh the page to check progress and continue.'); }
                if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : (result.message || 'Cleanup could not continue. Refresh the page and try again.'));
                progress.value = result.processed;
                status.textContent = `${result.cleared.toLocaleString()} cleared; ${result.processed.toLocaleString()} of ${result.planned.toLocaleString()} checked. ${result.skipped ? result.skipped.toLocaleString() + ' skipped or already removed.' : ''}`;
                if (result.completed) {
                    status.textContent = result.message;
                    window.location.assign(@json(route('admin.audit.index')));
                    break;
                }
            }
        } catch (error) {
            status.textContent = error.message || 'Cleanup stopped. Refresh the page and continue.';
            button.disabled = false;
            previewControls.forEach(control => control.disabled = false);
            button.textContent = 'Continue clearing';
        } finally {
            document.getElementById('audit-clear-password').value = '';
        }
    });
})();
</script>
@endpush
