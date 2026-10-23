<label>{{ $label }}
    <input data-field="{{ $path }}" type="{{ $type ?? 'text' }}" value="{{ data_get($payload, $path) ?? '' }}"
        @if(($type ?? 'text') === 'number') step="any" min="0" @endif
        @if(($type ?? 'text') === 'date') max="{{ today()->toDateString() }}" @endif>
</label>
