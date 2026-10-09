@php
    $saved = $pv?->toArray() ?? [];
    $submitted = old('form_key') === $formKey ? old() : [];
    $value = fn ($key, $default = '') => data_get($submitted, $key, data_get($saved, $key, $default));
@endphp
<input type="hidden" name="revision" value="{{ $batch->revision }}"><input type="hidden" name="form_key" value="{{ $formKey }}">
<div class="mdb-fields">
    <label>Consumer reference<input name="reference" value="{{ $value('reference') }}" required maxlength="200"></label>
    <label>Installed capacity (kW)<input name="installed_capacity_kw" value="{{ $value('installed_capacity_kw') }}" type="number" min="0" step="any"></label>
    <label>Consumer reference as written<input name="original_entry[reference]" value="{{ $value('original_entry.reference') }}" maxlength="200"></label>
    <label>Capacity as written<input name="original_entry[installed_capacity]" value="{{ $value('original_entry.installed_capacity') }}" maxlength="200"></label>
    <label>Associated section<select name="section_id"><option value="">Transformer level / unresolved section</option>@foreach($selectedTransformer->sections as $section)<option value="{{ $section->id }}" @selected((string) $value('section_id') === (string) $section->id)>{{ $section->start_reference }} &rarr; {{ $section->end_reference }}</option>@endforeach</select></label>
    <label>PDF page<input name="original_entry[source_page]" value="{{ $value('original_entry.source_page') }}" type="number" min="1"></label>
    <label>PDF source<select name="original_entry[source_pdf_id]"><option value="">Choose PDF</option>@foreach($pdfs as $source)<option value="{{ $source->id }}" @selected((string) $value('original_entry.source_pdf_id') === (string) $source->id)>{{ $source->original_name }} v{{ $source->version }}</option>@endforeach</select></label>
    <label>Survey row reference<input name="original_entry[source_row]" value="{{ $value('original_entry.source_row') }}" maxlength="100"></label>
    <label class="mdb-span-all">Remarks<textarea name="remarks" maxlength="4000" rows="2">{{ $value('remarks') }}</textarea></label>
    <label class="mdb-span-all">Remarks as written<textarea name="original_entry[remarks]" maxlength="4000" rows="2">{{ $value('original_entry.remarks') }}</textarea></label>
    <label class="mdb-check mdb-span-all"><input name="original_entry[manually_verified]" type="checkbox" value="1" @checked($value('original_entry.manually_verified', false)) required>I checked this PV observation against its original PDF row.</label>
</div>
<div class="mdb-form-actions"><button class="btn btn-primary">{{ $pv ? 'Save corrected PV observation' : 'Add PV observation' }}</button></div>
