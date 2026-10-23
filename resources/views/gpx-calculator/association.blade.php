<div class="gpx-fields">
    <label>Project (optional)
        <select name="project_id" data-gpx-project>
            <option value="">Unlinked calculation</option>
            @foreach($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id', $calculation->project_id ?? '') === (string) $project->id)>{{ $project->code }} | {{ $project->name }}</option>@endforeach
        </select>
    </label>
    <label>Feeder (optional)
        <select name="feeder_id" data-gpx-feeder>
            <option value="">No feeder selected</option>
            @foreach($feeders as $feeder)<option value="{{ $feeder->id }}" data-project="{{ $feeder->project_id }}" @selected((string) old('feeder_id', $calculation->feeder_id ?? '') === (string) $feeder->id)>{{ $feeder->feeder_code }} | {{ $feeder->feeder_name }}</option>@endforeach
        </select>
    </label>
</div>
<p class="muted">Only accessible active projects and currently assigned feeders are listed. Linking a calculation does not change feeder progress or official feeder lengths.</p>
