@extends('layouts.app')
@section('title', 'User Assignments')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/user-assignments.css') }}?v=1">
@endpush
@section('content')
<div class="page-head assignment-page-head"><div><h1>User Assignments</h1><p>Select a user to see their work and assign tasks in one place.</p></div>
    <a class="btn btn-secondary" href="{{ route('admin.teams.index') }}">Manage teams</a>
</div>
<div class="assignment-hub" data-assignment-hub>
<section class="card">
    <h2>1. Select a user</h2>
    <form method="get" action="{{ route('admin.assignments.index') }}" class="assignment-fields">
        <div class="field"><label for="user-search">Find user</label><input id="user-search" type="search" placeholder="Search by name, email or role" data-filter-select="assignment-user" autocomplete="off"></div>
        <div class="field assignment-grow"><label for="assignment-user">User</label><select id="assignment-user" name="user_id" required>
            <option value="">Choose a user</option>
            @foreach($users as $user)
                <option value="{{ $user->id }}" @selected($selected?->id === $user->id)>{{ $user->name }} · {{ $user->email }} · {{ $user->role->label() }} · {{ $user->roles->pluck('name')->map(fn ($role) => str_replace('_', ' ', $role))->implode(', ') }}{{ $user->isActive() ? '' : ' · Inactive' }}</option>
            @endforeach
        </select></div>
        <button class="btn" type="submit">View assignments</button>
    </form>
    <p class="muted" data-user-search-status role="status"></p>
    @if($selected)
        <div class="assignment-user-summary"><strong>{{ $selected->name }}</strong><span>{{ $selected->email }}</span>
            <span class="status">{{ $selected->isActive() ? 'Active' : 'Inactive — history only' }}</span>
            <span>{{ $selected->roles->pluck('name')->map(fn ($role) => str_replace('_', ' ', $role))->implode(' · ') }}</span>
        </div>
    @endif
</section>
@if($selected)
<section class="card" id="assignment-history">
    <div class="assignment-section-head"><div><h2>All assignments <span class="assignment-count">{{ $counts->sum() }}</span></h2>
        <p class="muted">Direct tasks and shared allocations, including completed and historical records. Batch statuses show the current workflow stage.</p></div>
        @if(count($eligible))<a class="btn" href="#assign-tasks">Assign tasks</a>@endif
    </div>
    <div class="assignment-types">
        @foreach($counts as $type => $count)<a class="assignment-chip" href="{{ route('admin.assignments.index', ['user_id' => $selected->id, 'type' => $type, 'task_type' => $taskType]) }}#assignment-history">{{ \App\Services\AssignmentOverview::TYPES[$type] }} <strong>{{ $count }}</strong></a>@endforeach
    </div>
    <form method="get" action="{{ route('admin.assignments.index') }}#assignment-history" class="assignment-fields assignment-filters">
        <input type="hidden" name="user_id" value="{{ $selected->id }}"><input type="hidden" name="task_type" value="{{ $taskType }}">
        <div class="field"><label for="history-search">Search assignments</label><input id="history-search" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" placeholder="Feeder, team or batch number"></div>
        <div class="field"><label for="history-type">Type</label><select id="history-type" name="type"><option value="">All types</option>@foreach(\App\Services\AssignmentOverview::TYPES as $type => $label)<option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="history-status">Status</label><select id="history-status" name="status"><option value="">All statuses</option>@foreach($statuses as $status)<option @selected(($filters['status'] ?? '') === $status) value="{{ $status }}">{{ ucfirst(str_replace('_', ' ', $status ?? 'Unknown')) }}</option>@endforeach</select></div>
        <div class="field"><label for="history-project">Project</label><select id="history-project" name="project_id"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int)($filters['project_id'] ?? 0) === $project->id)>{{ $project->name }}</option>@endforeach</select></div>
        <button class="btn btn-secondary" type="submit">Apply filters</button><a href="{{ route('admin.assignments.index', ['user_id' => $selected->id, 'task_type' => $taskType]) }}#assignment-history">Clear</a>
    </form>
    <div class="assignment-table-wrap"><table class="table assignment-table"><thead><tr><th>Assignment</th><th>Type / allocation</th><th>Status</th><th>Record date / deadline</th><th>Action</th></tr></thead><tbody>
    @forelse($records as $record)
        <tr><td><strong>{{ $record->title ?: 'Record #'.$record->resource_id }}</strong>
            @if(in_array($record->source, ['batch', 'workflow_history']))<div>Batch #{{ $record->resource_id }}</div>@endif
            <div class="muted">{{ $record->description }}</div><div class="muted">{{ $record->project_name ?: 'Project unavailable' }}</div></td>
            <td>{{ \App\Services\AssignmentOverview::TYPES[$record->type] }}<div class="muted">{{ $record->allocation }}</div></td>
            <td><span class="status">{{ ucfirst(str_replace('_', ' ', $record->status ?? 'Unknown')) }}</span></td>
            <td>{{ $record->assigned_at ? substr($record->assigned_at, 0, 10) : 'Not recorded' }}<div class="muted">Due: {{ $record->deadline ? substr($record->deadline, 0, 10) : 'Not set' }}</div></td>
            <td>@if($record->source === 'mobile' && $record->status === 'assigned')
                <button type="button" class="btn btn-secondary" data-reassign data-id="{{ $record->record_id }}" data-label="{{ $record->title }}" data-owner="{{ $selected->id }}" data-url="{{ route('admin.assignments.candidates', $record->record_id) }}">Reassign</button>
            @elseif(in_array($record->source, ['batch', 'workflow_history']))<a href="{{ route('team-work.workflow') }}">Workflow</a>
            @elseif($record->source === 'processing')<span class="muted">Historical allocation</span>
            @else<span class="muted">{{ $record->source === 'mobile' ? 'Owner retained' : '—' }}</span>@endif</td></tr>
    @empty<tr><td colspan="5"><div class="assignment-empty">{{ $counts->sum() ? 'No assignments match these filters. Clear filters to see all work.' : 'This user has no recorded assignments yet.' }}</div></td></tr>@endforelse
    </tbody></table></div>
    <div class="assignment-pagination">{{ $records->onEachSide(1)->links() }}</div>
    <p class="muted">Workflow record dates refer to batch creation; previous-owner dates refer to audit events. Shared work belongs to a team or organization. Previous workflow owners appear where an audit record exists.</p>
</section>
@if(count($eligible))
<section class="card" id="assign-tasks">
    <h2>2. Assign tasks to {{ $selected->name }}</h2>
    <form method="get" action="{{ route('admin.assignments.index') }}#assign-tasks" class="assignment-fields" id="task-kind-form">
        <input type="hidden" name="user_id" value="{{ $selected->id }}">
        <div class="field assignment-grow"><label for="task-kind">Assignment type</label><select id="task-kind" name="task_type">@foreach(\App\Services\UserAssignmentManager::TASKS as $type => $label)<option value="{{ $type }}" @selected($taskType === $type) @disabled(!isset($eligible[$type]))>{{ $label }}{{ isset($eligible[$type]) ? '' : ' — existing role or membership required' }}</option>@endforeach</select></div>
        <button class="btn btn-secondary" type="submit">Choose type</button>
    </form>
    @if(isset($eligible[$taskType]))
    <form method="get" action="{{ route('admin.assignments.index') }}#assign-tasks" id="task-finder" class="assignment-fields assignment-filters" data-options-url="{{ route('admin.assignments.options') }}">
        <input type="hidden" name="user_id" value="{{ $selected->id }}"><input type="hidden" name="task_type" value="{{ $taskType }}">
        @if(in_array($taskType, ['mobile', 'feeder_pool']))
            <div class="field"><label for="survey-team">Survey team</label><select id="survey-team" name="survey_team_id" required><option value="">Select team</option>@foreach($teams as $team)<option value="{{ $team->id }}" @selected((int)($filters['survey_team_id'] ?? 0) === $team->id)>{{ $team->name }} · {{ $team->project?->name }}</option>@endforeach</select></div>
        @endif
        <div class="field assignment-grow"><label for="task-search">Find tasks</label><input id="task-search" type="search" name="task_search" maxlength="100" value="{{ $filters['task_search'] ?? '' }}" placeholder="Search feeder, team or batch number"></div>
        <div class="field"><label for="task-project">Project</label><select id="task-project" name="task_project"><option value="">All projects</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected((int)($filters['task_project'] ?? 0) === $project->id)>{{ $project->name }}</option>@endforeach</select></div>
        <button class="btn btn-secondary" type="submit">Find tasks</button>
    </form>
    @if(in_array($taskType, ['mobile', 'feeder_pool']) && $teams->isEmpty())<p class="alert alert-warning">This user needs survey team membership before feeders can be assigned. Choose Survey Team Membership above.</p>@endif
    @if($taskType === 'feeder_pool')<p class="assignment-notice">This allocates feeders to the selected survey team. All team members share the allocation.</p>@endif
    @if(in_array($taskType, ['entry', 'verification', 'mdb']))<p class="assignment-notice">Only batches ready for this stage appear. Select an existing owner's batch to reassign it with a reason.</p>@endif
    <form method="post" action="{{ route('admin.assignments.store') }}" id="assign-form" data-user-name="{{ $selected->name }}" data-task-label="{{ $eligible[$taskType] }}" data-task-type="{{ $taskType }}">
        @csrf<input type="hidden" name="user_id" value="{{ $selected->id }}"><input type="hidden" name="task_type" value="{{ $taskType }}">
        <input type="hidden" name="survey_team_id" value="{{ $filters['survey_team_id'] ?? '' }}" id="assign-team">
        <div class="assignment-selection-head"><strong id="selected-count" role="status" aria-live="polite">Select tasks below (maximum 50)</strong>
            <button class="btn btn-secondary" type="button" id="select-visible">Select available on this page</button>
            <button class="btn btn-secondary" type="button" id="clear-selected">Clear selection</button>
        </div>
        <div id="selection-summary" class="muted" aria-live="polite"></div><div id="selected-hidden"></div>
        <div id="task-results" class="assignment-choices" aria-busy="false">
            @forelse($options['items'] as $option)
            <label class="assignment-choice {{ $option['disabled'] ? 'is-disabled' : '' }}"><input type="checkbox" name="task_ids[]" value="{{ $option['id'] }}" data-choice data-label="{{ $option['label'] }}" data-owner="{{ $option['owner'] }}" @disabled($option['disabled'])>
                <span><strong>{{ $option['label'] }}</strong><small>{{ $option['detail'] }}</small><small>{{ $option['note'] }}</small></span>
                @if(isset(\App\Services\AssignmentOverview::FIELDS[$taskType]))<input type="hidden" name="expected_assignees[{{ $option['id'] }}]" value="{{ $option['owner'] }}">@endif
            </label>
            @empty<p class="assignment-empty">No available tasks here. Try another search or project; check team membership and workflow status.</p>@endforelse
        </div>
        <p id="task-feedback" role="status" aria-live="polite"></p>
        <div class="assignment-choice-pages">
            <button type="button" class="btn btn-secondary" id="choices-prev" disabled>Previous tasks</button>
            <span id="choices-page">Page {{ $filters['choice_page'] ?? 1 }}</span>
            <button type="button" class="btn btn-secondary" id="choices-next" @disabled(!$options['more'])>Next tasks</button>
            <noscript><p>Enable JavaScript to search across pages and review your selection. Use the existing assignment screens if JavaScript is unavailable.</p></noscript>
        </div>
        <div class="assignment-fields">
            @if(in_array($taskType, ['mobile', 'feeder_pool']))
                <div class="field"><label for="assigned-at">Start date</label><input id="assigned-at" type="date" name="assigned_at" required value="{{ old('assigned_at', today()->toDateString()) }}"></div>
                <div class="field"><label for="deadline">Deadline (optional)</label><input id="deadline" type="date" name="deadline" value="{{ old('deadline') }}"></div>
                @if($taskType === 'mobile')<div class="field"><label for="priority">Priority</label><select id="priority" name="priority">@foreach(['low', 'normal', 'high', 'urgent'] as $priority)<option @selected(old('priority', 'normal') === $priority)>{{ $priority }}</option>@endforeach</select></div>@endif
            @endif
        </div>
        <div class="field"><label for="assignment-reason">{{ in_array($taskType, ['entry', 'verification', 'mdb']) ? 'Reason (required when replacing an owner)' : 'Instructions / remarks (optional)' }}</label><textarea id="assignment-reason" name="reason" rows="2" maxlength="{{ $taskType === 'mobile' ? 2000 : 1000 }}">{{ old('reason') }}</textarea></div>
        @if(in_array($taskType, ['entry', 'verification', 'mdb']))<label class="assignment-check"><input type="checkbox" name="replace_existing" value="1"> I authorize replacing the current owners of selected batches.</label>@endif
        @if($taskType === 'mobile')<label class="assignment-check"><input type="checkbox" name="authorize_overlap" value="1"> Allow overlapping work where another surveyor already has this feeder. To transfer a task, use Reassign in the assignment list.</label>@endif
        <label class="assignment-check"><input type="checkbox" name="confirmed" value="1" required> I have checked the user, assignment type and selected tasks.</label>
        <button type="submit" class="btn" id="review-assignments">Review and assign</button>
    </form>
    @else<p class="assignment-empty">Choose an available assignment type for this user's existing roles.</p>@endif
</section>
@else<p class="card muted">{{ $selected->isActive() ? 'This user has no eligible assignment types under their existing roles. Manage roles through the existing Users screen.' : 'Inactive users retain their assignment history. New work requires an active user.' }}</p>@endif
@else<div class="card assignment-empty">Choose a user above to view all their assignments.</div>@endif
<dialog id="assignment-review" class="assignment-dialog"><h2>Confirm assignment</h2><p id="review-summary"></p><p id="review-details" class="muted"></p><div class="assignment-fields"><button type="button" class="btn btn-secondary" data-close-dialog>Go back</button><button type="button" class="btn" id="confirm-assignment">Confirm and save</button></div></dialog>
<dialog id="reassign-dialog" class="assignment-dialog"><h2>Reassign pending survey task</h2><p id="reassign-label"></p><p class="muted">The existing assignment remains in history. Started or completed work retains its owner.</p>
    <form method="post" action="{{ route('admin.assignments.store') }}" id="reassign-form">@csrf
        <input type="hidden" name="task_type" value="mobile_reassign"><input type="hidden" name="assignment_id"><input type="hidden" name="expected_owner">
        <div class="field"><label for="reassign-user-search">Find replacement user</label><input id="reassign-user-search" type="search" data-filter-select="reassign-user"></div>
        <div class="field"><label for="reassign-user">New owner (same team)</label><select id="reassign-user" name="user_id" required><option value="">Loading eligible users…</option></select></div>
        <div class="field"><label for="reassign-reason">Reason</label><textarea id="reassign-reason" name="reason" required minlength="3" maxlength="1000"></textarea></div>
        <label class="assignment-check"><input type="checkbox" name="confirmed" value="1" required> Confirm transfer to the selected user.</label>
        <p id="reassign-feedback" role="status"></p><div class="assignment-fields"><button type="button" class="btn btn-secondary" data-close-dialog>Cancel</button><button type="submit" class="btn">Confirm reassignment</button></div>
    </form>
</dialog>
</div>
@endsection
@push('scripts')<script type="module" src="{{ asset('js/user-assignments.js') }}?v=1"></script>@endpush
