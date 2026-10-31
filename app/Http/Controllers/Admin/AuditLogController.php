<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\Payload;
use App\Services\Audit\Storage;
use App\Services\Audit\WebCleanup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function __invoke(Request $request): View
    {
        $storage = new Storage;
        $logs = AuditLog::select(['id', 'user_id', 'organization_id', 'action', 'auditable_type', 'auditable_id', 'created_at'])
            ->selectRaw($storage->boundedJson('old_values'))->selectRaw($storage->boundedJson('new_values'))
            ->selectRaw('SUBSTR(reason, 1, 2049) AS reason')
            ->with(['user', 'organization'])->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->action.'%'))->latest()->paginate(50)->withQueryString();
        foreach ($logs as $log) {
            $notice = ['_audit' => ['details_omitted' => true, 'reason' => 'Non-object legacy JSON retained in database.']];
            [$old, $new] = (new Payload)->prepare($log->old_values === null ? [] : (is_array($log->old_values) ? $log->old_values : $notice),
                $log->new_values === null ? [] : (is_array($log->new_values) ? $log->new_values : $notice), false);
            $log->old_values = $old;
            $log->new_values = $new;
            $log->reason = (new Payload)->text($log->reason);
        }

        $preview = null;
        if ($token = $request->session()->get('audit_clear_token')) {
            try {
                $preview = app(WebCleanup::class)->load($request->user(), $token);
            } catch (ValidationException) {
                $request->session()->forget('audit_clear_token');
            }
        }

        return view('admin.audit.index', ['logs' => $logs, 'statistics' => $storage->statistics(), 'cleanupPreview' => $preview]);
    }

    public function preview(Request $request, WebCleanup $cleanup): RedirectResponse
    {
        $data = $request->validate(['scope' => ['required', Rule::in(['all', 'expired'])]]);
        if ($token = $request->session()->get('audit_clear_token')) {
            $cleanup->discard($request->user(), $token);
        }
        $preview = $cleanup->preview($request->user(), $data['scope']);
        $request->session()->put('audit_clear_token', $preview['token']);

        return to_route('admin.audit.index')->with('success', 'Cleanup preview prepared. No logs have been deleted.');
    }

    public function records(Request $request, WebCleanup $cleanup)
    {
        $token = $request->session()->get('audit_clear_token');
        abort_unless($token, 404);
        $preview = $cleanup->load($request->user(), $token);

        return response()->streamDownload(function () use ($preview) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Audit ID', 'Action', 'Record type', 'Record ID', 'Recorded at']);
            foreach ($preview['records'] as $record) {
                fputcsv($stream, [$record['id'], $record['action'], $record['auditable_type'], $record['auditable_id'], $record['created_at']]);
            }
            fclose($stream);
        }, 'audit-cleanup-records.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'no-store']);
    }

    public function clear(Request $request, WebCleanup $cleanup)
    {
        $data = $request->validate(['plan_token' => ['required', 'regex:/^[a-f0-9]{32}$/D'],
            'confirmation' => ['required', Rule::in(['CLEAR LOGS'])], 'backup_confirmed' => ['accepted'],
            'password' => ['required', 'current_password']]);
        abort_unless(hash_equals((string) $request->session()->get('audit_clear_token'), $data['plan_token']), 422, 'Preview the logs again before clearing them.');
        try {
            $result = $cleanup->execute($request->user(), $data['plan_token']);
        } catch (ValidationException $error) {
            throw $error;
        } catch (\Throwable $error) {
            Log::warning('Audit web cleanup stopped.', ['user_id' => $request->user()->id, 'exception_class' => $error::class]);
            throw ValidationException::withMessages(['cleanup' => 'Cleanup stopped. Previous completed batches remain cleared. Refresh the page and continue, or prepare a new preview.']);
        }
        $completed = $result['status'] === 'completed';
        if ($completed) {
            $cleanup->discard($request->user(), $data['plan_token']);
            $request->session()->forget('audit_clear_token');
        }
        $message = number_format($result['cleared']).' audit records cleared. '
            .($completed ? 'Cleanup complete.' : 'Continue clearing to finish this preview.')
            .' Assignment history, cleanup summaries and records created after the preview are retained.';
        if ($request->expectsJson()) {
            if ($completed) {
                $request->session()->flash('success', $message);
            }

            return response()->json($result + ['completed' => $completed, 'message' => $message]);
        }

        return to_route('admin.audit.index')->with('success', $message);
    }
}
