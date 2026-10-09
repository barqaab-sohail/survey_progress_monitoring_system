@php
    $saved = $transformer?->toArray() ?? [];
    $submitted = old('form_key') === $formKey ? old() : [];
    $value = fn ($key, $default = '') => data_get($submitted, $key, data_get($saved, $key, $default));
@endphp
<input type="hidden" name="form_key" value="{{ $formKey }}"><input type="hidden" name="revision" value="{{ $batch->revision }}">
<div class="mdb-fields">
    <label>Transformer code<input name="code" value="{{ $value('code') }}" maxlength="100" required></label>
    <label>Capacity (kVA), verified value<input name="capacity_kva" value="{{ $value('capacity_kva') }}" type="number" min="0.001" step="any"><small>Leave unreadable or unrecorded values blank.</small></label>
    <label>Transformer code as written<input name="original_header[code]" value="{{ $value('original_header.code') }}" maxlength="200"></label>
    <label>Capacity as written<input name="original_header[capacity_kva]" value="{{ $value('original_header.capacity_kva') }}" maxlength="200"></label>
    <label class="mdb-span-all">Transformer connection waypoint<select name="source_waypoint_id"><option value="">Unresolved / not recorded</option>@foreach($batch->sources->where('kind', 'gpx') as $source)@foreach($source->waypoints as $waypoint)<option value="{{ $waypoint->id }}" @selected((string) $value('source_waypoint_id') === (string) $waypoint->id)>{{ $waypoint->name }} | {{ $source->original_name }} v{{ $source->version }}</option>@endforeach @endforeach</select><small>Select the surveyed transformer connection. Waypoint names belong to their specific GPX file.</small></label>
    @foreach(['feeder_identifier'=>'Feeder identifier','feeder_name'=>'Feeder name','substation_identifier'=>'Substation identifier','substation_name'=>'Substation name','make'=>'Transformer make','location'=>'Transformer location','mounting'=>'Mounting details','survey_date'=>'Survey date','team_group'=>'Survey team / group','inspector'=>'Inspector'] as $key=>$label)
    <label>{{ $label }}<input name="header[{{ $key }}]" value="{{ $value('header.'.$key) }}" maxlength="500" @if($key === 'survey_date') type="date" @endif></label>
    <label>{{ $label }} as written<input name="original_header[{{ $key }}]" value="{{ $value('original_header.'.$key) }}" maxlength="500"></label>
    @endforeach
    <label class="mdb-span-all">Header remarks<textarea name="header[remarks]" maxlength="4000" rows="2">{{ $value('header.remarks') }}</textarea></label>
    <label class="mdb-span-all">Header remarks as written<textarea name="original_header[remarks]" maxlength="4000" rows="2">{{ $value('original_header.remarks') }}</textarea></label>
    <label class="mdb-check mdb-span-all"><input name="header[manually_verified]" type="checkbox" value="1" @checked($value('header.manually_verified', false))><span>I checked the transformer header against the source PDF and confirmed its associated continuation pages.<small>Leave unchecked while creating the draft header; confirm after associating its source pages.</small></span></label>
</div>
<div class="mdb-form-actions"><button class="btn btn-primary">{{ $transformer ? 'Save transformer header' : 'Add transformer network' }}</button><small>Saving changes creates a new revision and invalidates any earlier approval.</small></div>
