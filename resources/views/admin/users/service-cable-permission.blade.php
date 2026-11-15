@if(auth()->user()->hasRole('super_admin'))
<div class="field"><input type="hidden" name="allow_consumer_service_cables" value="0"><label><input type="checkbox" name="allow_consumer_service_cables" value="1" @checked(old('allow_consumer_service_cables', $serviceCableUser?->allow_consumer_service_cables ?? false))> Allow consumer service-cable entry (PDF / mobile)</label><small>Applies to Data Entry Operators and Mobile Survey Users. Allows optional meter waypoint entry beneath E poles.</small></div>
@endif
