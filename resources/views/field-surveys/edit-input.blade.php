<label>{{ $label }}
    <input data-field="{{ $path }}" type="{{ $type ?? 'text' }}" value="{{ data_get($payload, $path) ?? '' }}"
        @if(($type ?? 'text') === 'number') step="any" min="{{ str_ends_with($path, 'pole_height_ft') ? 30 : 0 }}" @if(str_ends_with($path, 'pole_height_ft')) max="10000" @endif @endif
        @if(($type ?? 'text') === 'date') max="{{ today()->toDateString() }}" @endif>
</label>
