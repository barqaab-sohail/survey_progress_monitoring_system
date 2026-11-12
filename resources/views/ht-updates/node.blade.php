<fieldset data-ht-node class="ht-node">
<legend data-ht-node-label>{{ $nodeLabel ?? 'Intermediate HT pole' }}</legend>
<div class="ht-node-primary">
<label>Waypoint name <span class="ht-required">Required</span><input name="nodes[{{ $i }}][waypoint]" value="{{ $node['waypoint'] ?? '' }}" maxlength="100" required placeholder="Enter GPX waypoint name"></label>
@foreach(['latitude'=>'Latitude','longitude'=>'Longitude'] as $key=>$label)<label>{{ $label }} <span class="ht-optional">Optional</span><input type="number" step="any" min="{{ $key === 'latitude' ? -90 : -180 }}" max="{{ $key === 'latitude' ? 90 : 180 }}" name="nodes[{{ $i }}][{{ $key }}]" value="{{ $node[$key] ?? '' }}" placeholder="Resolve from GPX if unknown"></label>@endforeach
</div>
<details class="ht-pole-details"><summary>Optional pole and HT conductor details</summary><div class="ht-fields">
<label>Pole height (ft)<input type="number" step="any" min="0" max="1000" name="nodes[{{ $i }}][pole_height]" value="{{ $node['pole_height'] ?? '' }}"></label>
<label>Pole type<input name="nodes[{{ $i }}][pole_type]" value="{{ $node['pole_type'] ?? '' }}" maxlength="100"></label>
@foreach(['r','y','b'] as $phase)<label>HT phase {{ strtoupper($phase) }} conductor<select name="nodes[{{ $i }}][conductor_{{ $phase }}]"><option value="">Unknown / absent</option>@foreach(App\Services\Mdb\ApprovedWarehouse::conductors() as $conductor)<option value="{{ $conductor }}" @selected(($node['conductor_'.$phase] ?? '') === $conductor)>{{ $conductor }}</option>@endforeach</select></label>@endforeach
</div></details>
<button type="button" data-ht-remove class="btn btn-light ht-remove" @if($endpoint ?? false) hidden @endif>Remove intermediate pole</button>
</fieldset>
