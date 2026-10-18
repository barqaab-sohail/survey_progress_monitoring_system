@extends('layouts.app')
@section('title', 'Survey task assignments')
@section('content')
<div class="page-head"><div><h1>Survey task assignments</h1><p>Assign feeder tasks to surveyors in teams you lead. New assignments appear in the mobile app after the next synchronization.</p></div></div>
<form class="card" method="POST" action="{{ route('team-work.survey.assign') }}">@csrf
    <h2>Assign survey work</h2>
    <div class="grid period-grid">
        <label>Survey team<select name="survey_team_id" required><option value="">Choose team</option>@foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->code }} | {{ $team->name }} | {{ $team->project?->name }}</option>@endforeach</select></label>
        <label>Surveyor<select name="surveyor_id" required><option value="">Choose team member</option>@foreach($users as $member)<option value="{{ $member->id }}">{{ $member->name }} | {{ $member->role->label() }} | {{ $workload[$member->id] ?? 0 }} active task(s)</option>@endforeach</select><small>Workload count helps balance assignments.</small></label>
        <label>Feeders (select one or more)<select name="feeder_ids[]" multiple required size="6">@foreach($feeders as $feeder)<option value="{{ $feeder->id }}">{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach</select></label>
        <label>Assignment date<input type="date" name="assigned_at" value="{{ today()->toDateString() }}" required></label>
        <label>Deadline<input type="date" name="deadline"></label>
        <label>Priority<select name="priority" required><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></label>
        <label>Instructions / remarks<textarea name="instructions" maxlength="2000" rows="3"></textarea></label>
    </div>
    <label style="display:flex;gap:8px;margin:12px 0"><input type="checkbox" name="authorize_overlap" value="1"> I deliberately authorize overlapping work on a feeder already assigned to another surveyor.</label>
    <button class="btn btn-primary">Assign survey tasks</button>
</form>
<div class="section-title"><h2>Assignments and progress</h2></div>
<div class="card table-wrap"><table class="table"><thead><tr><th>Task</th><th>Team / Feeder</th><th>Surveyor</th><th>Priority / Deadline</th><th>Status</th><th>Instructions</th><th>Reassign pending task</th></tr></thead><tbody>
@forelse($assignments as $assignment)<tr><td>{{ substr($assignment->assignment_uuid, 0, 8) }}<small>Assigned {{ $assignment->assigned_at?->format('d M Y') }} by {{ $assignment->assigner?->name }}</small></td><td>{{ $assignment->team?->name }}<small>{{ $assignment->feeder?->feeder_code }} | {{ $assignment->feeder?->feeder_name }}</small></td><td>{{ $assignment->surveyor?->name }}</td><td>{{ ucfirst($assignment->priority) }}<small>{{ $assignment->deadline?->format('d M Y') ?? 'No deadline' }}</small></td><td>{{ str($assignment->status)->replace('_',' ')->title() }}</td><td>{{ $assignment->instructions ?: '—' }}</td><td>@if($assignment->status === 'assigned')<form method="POST" action="{{ route('team-work.survey.reassign', $assignment) }}">@csrf<select name="surveyor_id" required><option value="">Choose new owner</option>@foreach($assignment->team->members as $member)@if(in_array($member->role?->value, ['surveyor','survey_team_leader'], true) && $member->id !== $assignment->surveyor_id)<option value="{{ $member->id }}">{{ $member->name }}</option>@endif @endforeach</select><input name="reason" placeholder="Reason for reassignment" required minlength="3" maxlength="1000"><button class="btn btn-sm">Reassign</button></form>@else — @endif</td></tr>
@empty<tr><td colspan="7" class="empty">No survey tasks assigned yet.</td></tr>@endforelse
</tbody></table>{{ $assignments->links() }}</div>
@endsection
