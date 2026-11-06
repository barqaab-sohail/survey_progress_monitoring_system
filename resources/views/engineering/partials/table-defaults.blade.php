@php
    $tableService = app(\App\Services\Mdb\MdbTableDefaults::class);
    $tableCatalog = $tableService->catalog();
    $tableProfile = $tableService->forSettings($tableSettings ?? []);
    $tableInput = fn ($path, $fallback) => old('mdb_table_defaults.'.$path, $fallback);
@endphp
<section class="card mdb-defaults" id="mdb-table-defaults" data-mdb-defaults>
    <div class="mdb-defaults-heading"><div><span class="eyebrow">MDB Creation</span><h2>Static values by table</h2></div><span class="mdb-defaults-badge">{{ count($tableCatalog) }} tables</span></div>
    <p>Choose the MDB table below, then enter the values to use {{ ($tableLibrary ?? false) ? 'as defaults for new project configurations' : 'for this project' }}. The friendly label explains each setting; the column name matches Microsoft Access exactly.</p>
    <div class="mdb-defaults-guide"><strong>Example: InstMymSwDevPos</strong> stores the switch status for each transformer. SectionId is filled from the transformer automatically. Device code <code>M</code>, position <strong>Closed (0)</strong> and year indexes <strong>0–10</strong> create 11 records per transformer.</div>
    <p class="mdb-defaults-note">Year 0 means the base year in SAI_Control, not calendar year 0. Blank means NULL where permitted; 0 is a value. @if($tableLibrary ?? false)Saving updates the library for future project configurations. Existing projects retain their settings.@else Saving creates a configuration revision and requires fresh survey approval and JSON export.@endif The settings are included once in the JSON used for local MDB generation.</p>
    <label class="mdb-table-picker" for="mdb-table-picker">Find a table<select id="mdb-table-picker" data-table-picker><option value="">Show all tables</option>@foreach($tableCatalog as $table => $definition)<option value="{{ $table }}">{{ $table }} — {{ $definition['title'] }}</option>@endforeach</select></label>
    <input type="hidden" name="mdb_table_defaults[version]" value="1">
    @foreach($tableCatalog as $table => $definition)
    @php
        $tablePath = 'tables.'.$table;
        $profile = $tableProfile['tables'][$table];
        $hasTableError = $errors->has('mdb_table_defaults.'.$tablePath.'.*');
    @endphp
    <details class="mdb-table-card" data-table="{{ $table }}" @if($loop->first || $hasTableError) open @endif>
        <summary><span><strong>{{ $table }}</strong><span class="mdb-table-title">{{ $definition['title'] }}</span></span>@if($hasTableError)<span class="mdb-table-error">Check values</span>@endif</summary>
        <div class="mdb-table-body"><p>{{ $definition['description'] }}</p>
            <div class="mdb-automatic"><strong>Filled automatically</strong><ul>@foreach($definition['generated'] as $explanation)<li>{{ $explanation }}</li>@endforeach</ul></div>
            @if(isset($profile['first_year']))
            <fieldset class="mdb-year-range"><legend>Years to create</legend><div class="mdb-fields">
                @foreach(['first_year'=>'First year index','last_year'=>'Last year index'] as $key=>$label)
                @php $path = $tablePath.'.'.$key; @endphp
                <label for="mdb-{{ $table }}-{{ $key }}">{{ $label }}<input id="mdb-{{ $table }}-{{ $key }}" name="mdb_table_defaults[tables][{{ $table }}][{{ $key }}]" type="number" min="0" max="10" step="1" value="{{ $tableInput($path, $profile[$key]) }}" data-year="{{ $key }}" required><small>0 = base year; 1–10 = forecast years.</small>@error('mdb_table_defaults.'.$path)<span class="mdb-table-error">{{ $message }}</span>@enderror</label>
                @endforeach
            </div><p data-year-summary aria-live="polite"></p></fieldset>
            @endif
            <div class="mdb-fields">
            @foreach(['values'=>'fields', 'options'=>'option_fields'] as $part=>$fieldsKey)
                @foreach($definition[$fieldsKey] ?? [] as $field=>$meta)
                @php
                    $path = $tablePath.'.'.$part.'.'.$field;
                    $id = 'mdb-'.$table.'-'.$field;
                    $current = $tableInput($path, $profile[$part][$field]);
                    $managed = isset($meta['managed_by']);
                    $locked = !empty($meta['locked']);
                @endphp
                <div class="mdb-field @if($managed || $locked) mdb-field-managed @endif">
                    <label for="{{ $id }}">{{ $meta['label'] }}<code>{{ $field }}</code></label>
                    @if($managed || $locked)
                        <div class="mdb-managed-value" id="{{ $id }}">{{ $current === null || $current === '' ? 'Unspecified' : $current }}</div>
                        <small>@if($managed)@if($tableLibrary ?? false)Managed in the general configuration JSON: <code>{{ $meta['managed_by'] }}</code>.@else<a href="#engineering-general" data-managed-field="{{ $meta['managed_by'] }}">Edit {{ str_replace('_', ' ', $meta['managed_by']) }} above</a>.@endif @else Fixed to match the generated network.@endif</small>
                    @elseif($meta['type'] === 'choice')
                        <select id="{{ $id }}" name="mdb_table_defaults[tables][{{ $table }}][{{ $part }}][{{ $field }}]" @required(!$meta['nullable']) aria-describedby="{{ $id }}-help">
                            @if($meta['nullable'])<option value="" @selected($current === null || $current === '')>Unspecified (NULL)</option>@endif
                            @foreach($meta['choices'] as $choice=>$label)<option value="{{ $choice }}" @selected($current !== null && $current !== '' && (string)$current === (string)$choice)>{{ $label }} ({{ $choice }})</option>@endforeach
                        </select>
                    @else
                        <input id="{{ $id }}" name="mdb_table_defaults[tables][{{ $table }}][{{ $part }}][{{ $field }}]" type="{{ $meta['type'] === 'text' ? 'text' : 'number' }}" value="{{ $current }}" @required(!$meta['nullable']) @if($meta['type'] === 'text') maxlength="{{ $meta['max'] }}" @else step="{{ $meta['type'] === 'integer' ? '1' : 'any' }}" min="{{ $meta['min'] }}" max="{{ $meta['max'] }}" @endif aria-describedby="{{ $id }}-help">
                    @endif
                    <small id="{{ $id }}-help">{{ $meta['help'] }}</small>
                    @error('mdb_table_defaults.'.$path)<span class="mdb-table-error" role="alert">{{ $message }}</span>@enderror
                </div>
                @endforeach
            @endforeach
            </div>
            @if($table === 'SAI_Control')<p><button type="button" class="btn btn-light" data-fill-years>Fill forecast labels from the base-year label</button><span data-year-label-message role="status"></span></p>@endif
            @if(isset($profile['seasons']))
                <div class="mdb-selections">
                @foreach(['seasons'=>'Months to create','day_types'=>'Day types to create'] as $part=>$label)
                <fieldset><legend>{{ $label }}</legend><input type="hidden" name="mdb_table_defaults[tables][{{ $table }}][{{ $part }}]" value=""><div class="mdb-checks">
                    @foreach($definition['defaults'][$part] as $choice)<label><input type="checkbox" name="mdb_table_defaults[tables][{{ $table }}][{{ $part }}][]" value="{{ $choice }}" @checked(in_array($choice, (array)$tableInput($tablePath.'.'.$part, $profile[$part]), true))>{{ $choice }}</label>@endforeach
                </div><small>Select at least one. One record is created for each selected month and day type, per meter.</small>@error('mdb_table_defaults.'.$tablePath.'.'.$part)<span class="mdb-table-error">{{ $message }}</span>@enderror</fieldset>
                @endforeach
                </div>
                <details class="mdb-hourly" @if($errors->has('mdb_table_defaults.tables.InstMeterDmd.hourly.*')) open @endif><summary>Optional hourly percentages</summary><p>Leave an hour blank to use its hourly level above. Enter 0 to set that hour to zero. These percentages apply to every selected month and day type.</p><div class="table-wrap"><table class="table"><thead><tr><th>Hour</th>@foreach(['PctKwLoad','PctKvarLoad','PctKwDmd','PctKvarDmd'] as $group)<th>{{ $definition['fields'][$group]['label'] }}</th>@endforeach</tr></thead><tbody>
                @for($hour=1; $hour<=24; $hour++)@php $hourKey = sprintf('%02d', $hour); @endphp<tr><th scope="row">{{ $hourKey }}</th>@foreach(['PctKwLoad','PctKvarLoad','PctKwDmd','PctKvarDmd'] as $group)<td><input aria-label="{{ $group }} hour {{ $hourKey }}" name="mdb_table_defaults[tables][InstMeterDmd][hourly][{{ $group }}][{{ $hourKey }}]" type="number" min="0" max="1000" step="any" value="{{ $tableInput($tablePath.'.hourly.'.$group.'.'.$hourKey, $profile['hourly'][$group][$hourKey] ?? '') }}" placeholder="Use level above">@error('mdb_table_defaults.'.$tablePath.'.hourly.'.$group.'.'.$hourKey)<span class="mdb-table-error">{{ $message }}</span>@enderror</td>@endforeach</tr>@endfor
                </tbody></table></div></details>
            @endif
        </div>
    </details>
    @endforeach
</section>
@once
@push('styles')<link rel="stylesheet" href="{{ asset('css/mdb-table-defaults.css') }}">@endpush
@push('scripts')<script src="{{ asset('js/mdb-table-defaults.js') }}" defer></script>@endpush
@endonce
