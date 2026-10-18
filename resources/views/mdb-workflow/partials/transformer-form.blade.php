@php
    $saved = $transformer?->toArray() ?? [];
    $submitted = old('form_key') === $formKey ? old() : [];
    $value = fn ($key, $default = '') => data_get($submitted, $key, data_get($saved, $key, $default));
@endphp
<input type="hidden" name="form_key" value="{{ $formKey }}"><input type="hidden" name="revision" value="{{ $batch->revision }}">
<div class="mdb-fields">
    <label>Transformer code<input name="code" value="{{ $value('code') }}" maxlength="100" required></label>
    <label>Transformer capacity (KVA), verified value<input type="search" data-measurement-search placeholder="Search capacity options" aria-label="Search transformer capacities"><select data-capacity-choice data-saved-capacity="{{ $value('capacity_kva') }}" required><option value="">Choose capacity</option>@foreach([5,10,15,25,37.5,50,63,75,100,125,160,200,250,315,400,500] as $capacity)<option value="{{ $capacity }}" @selected((string) $value('capacity_kva') === (string) $capacity)>{{ $capacity }} KVA</option>@endforeach<option value="__custom__" @selected($value('capacity_kva') !== '' && !in_array((float) $value('capacity_kva'), [5,10,15,25,37.5,50,63,75,100,125,160,200,250,315,400,500])>Other / Custom Value</option></select><input name="capacity_kva" type="hidden" value="{{ $value('capacity_kva') }}" data-capacity-value><input type="number" min="0.001" max="1000000" step="any" value="{{ $value('capacity_kva') }}" placeholder="Enter capacity in KVA" aria-label="Custom transformer capacity in KVA" data-capacity-custom hidden><small>Positive values only. Saved in KVA.</small></label>
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
