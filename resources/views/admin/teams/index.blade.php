@extends('layouts.app')
@section('title', 'Teams & Assignments')
@section('content')
@php
    $availableSurveyTeams = $surveyTeams->filter(fn ($team) => $team->status === 'active' && $team->project?->status === \App\Enums\RecordStatus::Active);
    $availableMdbTeams = $mdbTeams->filter(fn ($team) => $team->status === 'active' && $team->project?->status === \App\Enums\RecordStatus::Active);
@endphp

<div class="page-head">
    <div>
        <h1>Teams & Assignments</h1>
        <p>Maintain delivery teams, membership, and survey feeder allocation.</p>
    </div>
</div>

<section class="grid period-grid">
    @foreach(['Survey Teams' => $surveyTeams, 'MDB Teams' => $mdbTeams] as $label => $teams)
    <div class="card">
        <h2>{{ $label }}</h2>
        @forelse($teams as $team)
        <div style="padding:10px 0;border-top:1px solid var(--line)">
            <strong>{{ $team->code }} | {{ $team->name }}</strong>
            <div class="muted">{{ $team->project?->name }} | {{ ucfirst($team->status) }}</div>
            <div class="muted">{{ $team->members->pluck('name')->join(', ') ?: 'No members' }}</div>
        </div>
        @empty
        <p class="muted">None configured.</p>
        @endforelse
    </div>
    @endforeach
</section>

<div class="grid period-grid">
    <form class="card" method="POST" action="{{ route('admin.teams.store') }}">
        @csrf
        <h2>Create team</h2>
        <div class="field">
            <label for="new-team-type">Type</label>
            <select name="type" id="new-team-type">
                <option value="survey" @selected(old('type', 'survey') === 'survey')>Survey</option>
                <option value="mdb" @selected(old('type') === 'mdb')>MDB</option>
            </select>
        </div>
        <div class="field">
            <label for="new-team-project">Project</label>
            <select name="project_id" id="new-team-project" required>
                @foreach($projects as $project)
                <option value="{{ $project->id }}" @selected(old('project_id') == $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label for="new-team-code">Code</label><input id="new-team-code" name="code" value="{{ old('code') }}" required></div>
        <div class="field"><label for="new-team-name">Name</label><input id="new-team-name" name="name" value="{{ old('name') }}" required></div>
        <button class="btn btn-primary" style="margin-top:14px">Create team</button>
    </form>

    <form class="card" method="POST" action="{{ route('admin.teams.member') }}">
        @csrf
        <h2>Assign member</h2>
        <div class="field">
            <label for="member-type">Team type</label>
            <select name="type" id="member-type">
                <option value="survey" @selected(old('type', 'survey') === 'survey')>Survey</option>
                <option value="mdb" @selected(old('type') === 'mdb')>MDB</option>
            </select>
        </div>
        <div class="field">
            <label for="member-team">Team</label>
            <select name="team_id" id="member-team" required>
                <option value="">Select a team</option>
                @foreach(['survey' => $availableSurveyTeams, 'mdb' => $availableMdbTeams] as $type => $teams)
                <optgroup label="{{ $type === 'survey' ? 'Survey teams' : 'MDB teams' }}">
                    @foreach($teams as $team)
                    <option value="{{ $team->id }}" data-team-type="{{ $type }}" @selected(old('type', 'survey') === $type && old('team_id') == $team->id)>{{ $team->code }} | {{ $team->name }} | {{ $team->project->name }}</option>
                    @endforeach
                </optgroup>
                @endforeach
            </select>
            <small>Choose a team matching the selected type. Only active teams in active projects are available.</small>
        </div>
        <div class="field">
            <label for="member-user">User</label>
            <select name="user_id" id="member-user" required>
                <option value="">Select a user</option>
                @foreach($users as $user)
                <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} | {{ $user->role->label() }}</option>
                @endforeach
            </select>
        </div>
        <label style="display:flex;gap:8px;margin-top:12px">
            <input type="checkbox" id="member-leader" name="is_leader" value="1" @checked(old('is_leader', false))>
            Survey team leader
        </label>
        <button class="btn btn-primary" style="margin-top:14px">Assign member</button>
    </form>

    <form class="card" method="POST" action="{{ route('admin.teams.assign-feeder') }}">
        @csrf
        <h2>Assign survey feeders</h2>
        <div class="field">
            <label for="assignment-team">Survey team</label>
            <select name="survey_team_id" id="assignment-team" required>
                <option value="">Select a survey team</option>
                @foreach($availableSurveyTeams as $team)
                <option value="{{ $team->id }}" data-project-id="{{ $team->project_id }}" @selected(old('survey_team_id') == $team->id)>{{ $team->code }} | {{ $team->name }} | {{ $team->project->name }} | {{ $team->members->pluck('name')->join(', ') ?: 'No members' }}</option>
                @endforeach
            </select>
            <small>Assigned feeders are available to every member of this team. For one surveyor, choose a team containing only that surveyor.</small>
        </div>
        <div class="field">
            <label for="assignment-scope">Feeder selection</label>
            <select name="assignment_scope" id="assignment-scope">
                <option value="single" @selected(old('assignment_scope', 'single') === 'single')>One feeder</option>
                <option value="all" @selected(old('assignment_scope') === 'all')>All active feeders in this project</option>
            </select>
            <small>The all-feeders option assigns the current active feeders in the selected team's project. Run it again after importing new feeders.</small>
        </div>
        <div class="field" id="assignment-feeder-field">
            <label for="assignment-feeder">Feeder</label>
            <select name="feeder_id" id="assignment-feeder">
                <option value="">Select a feeder</option>
                @foreach($feeders as $feeder)
                <option value="{{ $feeder->id }}" data-project-id="{{ $feeder->project_id }}" @selected(old('feeder_id') == $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>
                @endforeach
            </select>
            <small>Required for one feeder. The all-feeders option uses the selected team and ignores this field.</small>
        </div>
        <div class="field"><label for="assignment-start">Start date</label><input id="assignment-start" type="date" name="start_date" value="{{ old('start_date', today()->toDateString()) }}" required></div>
        <div class="field"><label for="assignment-end">End date</label><input id="assignment-end" type="date" name="end_date" value="{{ old('end_date') }}"></div>
        <div class="field"><label for="assignment-remarks">Remarks</label><input id="assignment-remarks" name="remarks" value="{{ old('remarks') }}"></div>
        <button class="btn btn-primary" style="margin-top:14px">Assign feeders</button>
    </form>
</div>

<div class="section-title"><h2>Active feeder assignments</h2></div>
<div class="card table-wrap">
    <table class="table">
        <thead><tr><th>Feeder</th><th>Survey team</th><th>Start</th><th>End</th></tr></thead>
        <tbody>
        @forelse($assignments as $assignment)
        <tr>
            <td>{{ $assignment->feeder->feeder_code }}</td>
            <td>{{ $assignment->surveyTeam->name }}</td>
            <td>{{ $assignment->start_date->format('d M Y') }}</td>
            <td>{{ $assignment->end_date?->format('d M Y') ?: 'Open' }}</td>
        </tr>
        @empty
        <tr><td colspan="4" class="empty">No active feeder assignments.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const memberType = document.getElementById('member-type');
    const memberTeam = document.getElementById('member-team');
    const memberLeader = document.getElementById('member-leader');
    const teamOptions = Array.from(memberTeam.querySelectorAll('option[data-team-type]'));

    function updateMemberTeams(changedType = false) {
        const selected = changedType ? '' : memberTeam.value;
        const options = teamOptions.filter(option => option.dataset.teamType === memberType.value);
        memberTeam.replaceChildren(new Option('Select a team', ''));
        options.forEach(option => memberTeam.appendChild(option.cloneNode(true)));
        memberTeam.value = options.some(option => option.value === selected) ? selected : '';
        memberLeader.disabled = memberType.value !== 'survey';
    }

    memberType.addEventListener('change', () => updateMemberTeams(true));
    updateMemberTeams();

    const assignmentTeam = document.getElementById('assignment-team');
    const assignmentScope = document.getElementById('assignment-scope');
    const assignmentFeeder = document.getElementById('assignment-feeder');
    const feederField = document.getElementById('assignment-feeder-field');
    const feederOptions = Array.from(assignmentFeeder.querySelectorAll('option[data-project-id]'));

    function updateFeederOptions() {
        const selected = assignmentFeeder.value;
        const projectId = assignmentTeam.selectedOptions[0]?.dataset.projectId;
        const options = feederOptions.filter(option => !projectId || option.dataset.projectId === projectId);
        assignmentFeeder.replaceChildren(new Option('Select a feeder', ''));
        options.forEach(option => assignmentFeeder.appendChild(option.cloneNode(true)));
        assignmentFeeder.value = options.some(option => option.value === selected) ? selected : '';
    }

    function updateAssignmentScope() {
        const allFeeders = assignmentScope.value === 'all';
        assignmentFeeder.required = !allFeeders;
        assignmentFeeder.disabled = allFeeders;
        feederField.hidden = allFeeders;
    }

    assignmentTeam.addEventListener('change', updateFeederOptions);
    assignmentScope.addEventListener('change', updateAssignmentScope);
    updateFeederOptions();
    updateAssignmentScope();
})();
</script>
@endpush
