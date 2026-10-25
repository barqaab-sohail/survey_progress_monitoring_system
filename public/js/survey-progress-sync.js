export function syncMessage(run) {
    if (!run) return 'Ready to synchronize.';
    const prefix = `Task #${run.id}: `;
    if (run.status === 'queued') return prefix + 'queued; waiting for the background runner.';
    if (run.status === 'running') return prefix + (run.phase || 'Synchronization is running.');
    if (run.status === 'completed') return prefix + 'synchronization completed successfully.';
    if (run.status === 'partial') return prefix + 'finished with warnings or incomplete folders. Review the details below; successful feeder updates were saved.';
    if (run.status === 'failed') return prefix + (run.error || 'synchronization failed. See the private worker log.');
    return prefix + (run.error || run.status.replaceAll('_', ' '));
}

export function initializeSync(root = document, onComplete = () => window.location.reload()) {
    const panel = root.querySelector('[data-survey-sync]');
    if (!panel) return;
    const form = root.querySelector('[data-survey-sync-form]'), button = root.querySelector('[data-sync-button]');
    const message = panel.querySelector('[data-sync-message]'), spinner = panel.querySelector('[data-sync-spinner]');
    const progress = panel.querySelector('[data-sync-progress]'), health = panel.querySelector('[data-sync-health]');
    let current = JSON.parse(root.getElementById('survey-sync-boot').textContent), timer, sending = false, stopped = false;
    let lastStatus = current.run?.status, runId = current.run?.id;
    const active = () => sending || current.run?.active;
    const updateButtons = () => {
        panel.setAttribute('aria-busy', String(Boolean(active()))); spinner.hidden = !active();
        if (button) { button.disabled = Boolean(active()) || !current.health.connected; button.textContent = active() ? 'Synchronization in progress…' : 'Synchronize now'; }
    };
    const render = () => {
        message.textContent = syncMessage(current.run);
        message.className = current.run?.status === 'failed' ? 'survey-sync-error' : current.run?.status === 'completed' ? 'survey-sync-success' : '';
        const metrics = current.run?.metrics || {};
        panel.querySelectorAll('[data-sync-metric]').forEach(element => { element.textContent = String(metrics[element.dataset.syncMetric] ?? 0); });
        if (current.run?.active && !metrics.folders_total) progress.removeAttribute('value');
        else progress.value = metrics.folders_total ? Math.min(100, 100 * metrics.folders_processed / metrics.folders_total) : 0;
        const h = current.health;
        health.textContent = !h.ready ? 'Survey progress tables are missing; check deployment.' : h.token_read_error ? 'Stored Drive authorization cannot be read. Verify the original application encryption key and reconnect only if needed.'
            : !h.configured ? 'Google OAuth configuration is missing on this server.' : !h.connected ? 'Connect the separate survey Drive account first.'
            : !h.scope_ok || !h.refresh_available ? 'Reconnect Drive and grant read-only offline access for unattended synchronization.'
            : !h.runner_recent ? 'No recent background-runner heartbeat. Check the cPanel cron and survey-progress worker log; queued tasks need the runner.'
            : current.run?.waiting_too_long ? 'No recent progress on this task. Check the private worker log and queue; a long-running task may still be active.'
            : !h.automatic ? 'Automatic synchronization is off. Manual synchronization is available through the background runner.'
            : `Background runner checked in. Daily synchronization: ${h.daily_time} (${h.timezone}).`;
        const issues = panel.querySelector('[data-sync-issues]'); issues.replaceChildren();
        if (current.run?.error && current.run.status !== 'failed') { const item = root.createElement('li'); item.textContent = current.run.error; issues.append(item); }
        for (const issue of current.run?.issues || []) {
            const item = root.createElement('li'); item.textContent = [issue.code?.replaceAll('_', ' '), issue.feeder_id ? `Feeder #${issue.feeder_id}` : '', issue.file || issue.survey || issue.name || '', issue.message || ''].filter(Boolean).join(' · '); issues.append(item);
        }
        if ((current.run?.issue_count || 0) > 100) { const item = root.createElement('li'); item.textContent = 'More details are available in synchronization history.'; issues.append(item); }
        if (current.last_successful_sync) {
            const value = current.last_successful_sync.includes('T') ? current.last_successful_sync : current.last_successful_sync.replace(' ', 'T');
            // Server emits an ISO timestamp with its timezone; never guess a naive timestamp's order/zone.
            const date = new Date(value);
            if (!Number.isNaN(date.getTime())) root.querySelector('[data-sync-last-success]').textContent = new Intl.DateTimeFormat('en-GB', {timeZone:'Asia/Karachi', dateStyle:'medium', timeStyle:'medium'}).format(date);
        }
        updateButtons();
    };
    const schedule = (delay = 2500) => { clearTimeout(timer); if (!stopped && current.run?.active) timer = setTimeout(poll, delay); };
    const poll = async () => {
        if (stopped) return;
        try {
            const url = new URL(panel.dataset.statusUrl, root.baseURI); if (runId) url.searchParams.set('run_id', runId);
            const response = await fetch(url, {headers:{Accept:'application/json'}, credentials:'same-origin', cache:'no-store'});
            if (!response.ok) throw new Error('Could not refresh task status. Your task may still be running; check your session or refresh the dashboard.');
            current = await response.json(); render();
            if (['queued','running'].includes(lastStatus) && current.run && !current.run.active) {
                lastStatus = current.run.status; if (current.run.type === 'sync') timer = setTimeout(onComplete, 1200);
            } else { lastStatus = current.run?.status; schedule(); }
        } catch (error) { message.textContent = error.message; schedule(10000); }
    };
    form?.addEventListener('submit', async event => {
        event.preventDefault(); if (active()) return;
        sending = true; message.textContent = 'Requesting synchronization…'; updateButtons();
        try {
            const response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{Accept:'application/json'}, credentials:'same-origin'});
            let result;
            try { result = await response.json(); } catch { throw new Error('Could not read the response. Your session may have expired; refresh and sign in again.'); }
            if (!response.ok) throw new Error([result.message, ...Object.values(result.errors || {}).flat()].filter(Boolean).join(' '));
            current = result; runId = result.run?.id; lastStatus = result.run?.status; render(); schedule();
        } catch (error) { message.textContent = error.message; message.className = 'survey-sync-error'; }
        finally { sending = false; updateButtons(); }
    });
    render(); schedule();
    const stop = () => { stopped = true; clearTimeout(timer); };
    window.addEventListener('pagehide', stop, {once:true});
    return {stop, poll};
}

if (typeof document !== 'undefined') initializeSync();
