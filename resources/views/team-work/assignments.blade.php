@extends('layouts.app')
@section('title', 'Survey Team Leader | Task Assignments')
@push('styles')
<style>
.assignment-summary{grid-template-columns:repeat(5,minmax(120px,1fr));margin-bottom:18px}.assignment-summary .card{margin:0;padding:14px 18px}.assignment-summary strong{display:block;font-size:24px;color:#102a4c}.assignment-summary span{font-size:12px;color:#667085}.feeder-picker{border:1px solid #cfd7e1;border-radius:10px;background:#fff;padding:10px}.feeder-picker input[type=search]{width:100%;min-height:42px;border:1px solid #cfd7e1;border-radius:8px;padding:9px 11px}.feeder-picker-actions{display:flex;gap:8px;margin:9px 0}.feeder-picker-list{max-height:230px;overflow:auto;border-top:1px solid #e8edf2;padding-top:6px}.feeder-option{display:flex;gap:9px;align-items:flex-start;padding:8px;border-radius:7px;font-weight:500!important}.feeder-option:hover{background:#f4f8fc}.feeder-option input{margin-top:4px}.feeder-option strong{display:block}.feeder-option small{display:block;color:#667085}.task-feeder{font-weight:850;color:#114c89;font-size:16px}.task-meta{display:block;color:#667085;font-size:12px;margin-top:4px}.assignment-form-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.task-table td{min-width:115px}.task-table td:last-child{min-width:230px}@media(max-width:900px){.assignment-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.assignment-form-grid{grid-template-columns:1fr}}
</style>
@endpush
@section('content')
@if(auth()->user()->hasRole('super_admin'))<p><a class="btn btn-light" href="{{ route('admin.assignments.index') }}">User Assignments</a></p>@endif
<div class="page-head"><div><h1>Survey Team Leader - Task Assignments</h1><p>Assign feeders to surveyors in teams you lead. The mobile app receives these tasks after the surveyor signs in or synchronizes.</p></div></div>

<section class="grid assignment-summary" aria-label="Survey task progress">
    @foreach(['assigned'=>'Assigned','in_progress'=>'In progress','draft'=>'Draft','submitted'=>'Submitted','returned'=>'Returned'] as $key=>$label)
        <article class="card"><span>{{ $label }}</span><strong>{{ $progressCounts[$key] ?? 0 }}</strong></article>
    @endforeach
</section>

<form class="card" method="POST" action="{{ route('team-work.survey.assign') }}" id="survey-task-form">@csrf
    <h2>Assign survey work</h2>
    <div class="grid assignment-form-grid">
        <div class="field"><label for="survey-team">Survey team</label><select id="survey-team" name="survey_team_id" required><option value="">Choose a team you lead</option>@foreach($teams as $team)<option value="{{ $team->id }}">{{ $team->code }} | {{ $team->name }} - {{ $team->project?->name }}</option>@endforeach</select></div>
        <div class="field"><label for="surveyor">Surveyor</label><select id="surveyor" name="surveyor_id" required disabled><option value="">Choose a team first</option></select><small id="surveyor-workload">Only active surveyors assigned to the selected team are listed.</small></div>
        <div class="field"><label>Feeders</label><div class="feeder-picker" id="feeder-picker"><input type="search" id="feeder-search" placeholder="Choose a team first" aria-label="Search feeder code or name" disabled><div class="feeder-picker-actions"><button type="button" class="btn btn-sm btn-light" id="select-visible" disabled>Select visible</button><button type="button" class="btn btn-sm btn-light" id="clear-feeders" disabled>Clear</button><span id="feeder-count" class="task-meta">0 selected</span></div><div class="feeder-picker-list" id="feeder-list" aria-live="polite"><small class="task-meta">Select a survey team to load its project feeders.</small></div><div id="selected-feeder-inputs"></div></div></div>
        <div class="field"><label for="assigned-at">Assignment date</label><input id="assigned-at" type="date" name="assigned_at" value="{{ today()->toDateString() }}" required></div>
        <div class="field"><label for="deadline">Deadline</label><input id="deadline" type="date" name="deadline"></div>
        <div class="field"><label for="priority">Priority</label><select id="priority" name="priority" required><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select></div>
        <div class="field"><label for="instructions">Instructions / remarks</label><textarea id="instructions" name="instructions" maxlength="2000" rows="3" placeholder="Optional field instructions for the surveyor"></textarea></div>
    </div>
    <label style="display:flex;gap:8px;align-items:flex-start;margin:14px 0"><input type="checkbox" name="authorize_overlap" value="1"> I deliberately authorize overlap on a feeder already assigned to another surveyor.</label>
    <button class="btn btn-primary">Assign selected survey tasks</button>
</form>

<div class="section-title"><h2>Assigned tasks and live status</h2><span class="muted">Status changes when mobile sync confirms progress; refresh this page to view the latest.</span></div>
<div class="card table-wrap"><table class="table task-table"><thead><tr><th>Task ID / assigned</th><th>Feeder</th><th>Surveyor</th><th>Priority / deadline</th><th>Status</th><th>Instructions / corrections</th><th>Actions</th></tr></thead><tbody>
@forelse($assignments as $assignment)
<tr>
    <td>#{{ substr($assignment->assignment_uuid, 0, 8) }}<small class="task-meta">{{ $assignment->assigned_at?->format('d M Y') }} by {{ $assignment->assigner?->name }}</small></td>
    <td><span class="task-feeder">{{ $assignment->feeder?->feeder_code }}</span><small class="task-meta">{{ $assignment->feeder?->feeder_name }}</small><small class="task-meta">{{ $assignment->team?->name }}</small></td>
    <td>{{ $assignment->surveyor?->name }}<small class="task-meta">{{ $assignment->surveyor?->role?->label() }}</small></td>
    <td>{{ ucfirst($assignment->priority) }}<small class="task-meta">Due {{ $assignment->deadline?->format('d M Y') ?? 'No deadline' }}</small></td>
    <td><span class="status status-{{ $assignment->status === 'submitted' ? 'completed' : ($assignment->status === 'returned' ? 'returned' : (in_array($assignment->status, ['assigned','draft'], true) ? 'pending' : '')) }}">{{ str($assignment->status)->replace('_',' ')->title() }}</span><small class="task-meta">{{ $assignment->updated_at?->timezone('Asia/Karachi')->format('d M Y H:i') }}</small></td>
    <td>{{ $assignment->instructions ?: '—' }}@if($assignment->return_comments)<small class="task-meta"><strong>Correction:</strong> {{ $assignment->return_comments }}</small>@endif</td>
    <td>
        @if($assignment->status === 'assigned')<form method="POST" action="{{ route('team-work.survey.reassign', $assignment) }}">@csrf<select name="surveyor_id" required><option value="">Choose new owner</option>@foreach($assignment->team->members as $member)@if($member->isActive() && in_array($member->role?->value, ['surveyor','survey_team_leader'], true) && $member->id !== $assignment->surveyor_id)<option value="{{ $member->id }}">{{ $member->name }}</option>@endif @endforeach</select><input name="reason" placeholder="Reason for reassignment" required minlength="3" maxlength="1000"><button class="btn btn-sm btn-light">Reassign</button></form>
        @elseif($assignment->status === 'submitted')<form method="POST" action="{{ route('team-work.survey.return', $assignment) }}">@csrf<textarea name="comments" placeholder="Correction comments for the surveyor" required minlength="3" maxlength="2000"></textarea><button class="btn btn-sm btn-light">Return for correction</button></form>
        @else <span class="muted">No action</span> @endif
    </td>
</tr>
@empty<tr><td colspan="7" class="empty">No survey tasks assigned yet. Choose a team to get started.</td></tr>@endforelse
</tbody></table>{{ $assignments->links() }}</div>
<script>
const surveyTeamOptions = {{ Illuminate\Support\Js::from($teamOptions) }};
const teamPicker = document.getElementById('survey-team');
const surveyorPicker = document.getElementById('surveyor');
const searchInput = document.getElementById('feeder-search');
const feederList = document.getElementById('feeder-list');
const feederCount = document.getElementById('feeder-count');
const selectedFeederInputs = document.getElementById('selected-feeder-inputs');
const selectVisible = document.getElementById('select-visible');
const clearFeeders = document.getElementById('clear-feeders');
let visibleFeeders = [];
const selectedFeederIds = new Set();
function updateFeederCount(){
    feederCount.textContent = `${selectedFeederIds.size} selected`;
    selectedFeederInputs.replaceChildren();
    for(const id of selectedFeederIds){const input=document.createElement('input');input.type='hidden';input.name='feeder_ids[]';input.value=id;selectedFeederInputs.append(input)}
}
function renderFeeders(items){
    visibleFeeders = items;
    feederList.replaceChildren();
    if(!items.length){const empty=document.createElement('small');empty.className='task-meta';empty.textContent='No active feeders are available in this project.';feederList.append(empty);updateFeederCount();return}
    for(const feeder of items){
        const label=document.createElement('label');label.className='feeder-option';
        const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.value=feeder.id;checkbox.checked=selectedFeederIds.has(String(feeder.id));checkbox.addEventListener('change',()=>{checkbox.checked?selectedFeederIds.add(String(feeder.id)):selectedFeederIds.delete(String(feeder.id));updateFeederCount()});
        const text=document.createElement('span');const code=document.createElement('strong');code.textContent=feeder.code;const name=document.createElement('small');name.textContent=feeder.name;
        text.append(code,name);label.append(checkbox,text);feederList.append(label);
    }
    updateFeederCount();
}
function filterFeeders(){const q=searchInput.value.trim().toLocaleLowerCase();renderFeeders((surveyTeamOptions[teamPicker.value]?.feeders||[]).filter(f=>`${f.code} ${f.name}`.toLocaleLowerCase().includes(q)))}
teamPicker.addEventListener('change',()=>{
    const option=surveyTeamOptions[teamPicker.value];
    selectedFeederIds.clear();
    surveyorPicker.replaceChildren();
    const placeholder=document.createElement('option');placeholder.value='';placeholder.textContent=option?'Choose a surveyor in this team':'Choose a team first';surveyorPicker.append(placeholder);surveyorPicker.disabled=!option;
    for(const member of option?.members||[]){const item=document.createElement('option');item.value=member.id;item.textContent=`${member.name} | ${member.role} | ${member.open_tasks} open task(s)`;surveyorPicker.append(item)}
    searchInput.disabled=!option;searchInput.placeholder=option?'Search feeder code or name':'Choose a team first';selectVisible.disabled=!option;clearFeeders.disabled=!option;searchInput.value='';renderFeeders(option?.feeders||[]);
});
searchInput.addEventListener('input',filterFeeders);
selectVisible.addEventListener('click',()=>{for(const checkbox of feederList.querySelectorAll('input[type=checkbox]')){checkbox.checked=true;selectedFeederIds.add(checkbox.value)}updateFeederCount()});
clearFeeders.addEventListener('click',()=>{selectedFeederIds.clear();feederList.querySelectorAll('input[type=checkbox]').forEach(checkbox=>checkbox.checked=false);updateFeederCount()});
</script>
@endsection
