<div class="mdb-fields">
    @foreach(['survey_pdf' => ['Survey PDF', 'pdf'], 'gps_gpx' => ['GPS GPX', 'gpx']] as $field => [$label, $extension])
    <div data-source-field="{{ $field }}">
        <label>{{ $label }}<input name="{{ $field }}" type="file" accept=".{{ $extension }}" data-source-local required></label>
        @if(config('services.google_drive.enabled'))
        <label>Or paste a Google Drive file link or ID<input type="text" data-drive-link value="{{ old($field.'_drive_id') }}" placeholder="https://drive.google.com/file/d/.../view" @disabled(!auth()->user()->google_drive_token)></label>
        <input type="hidden" name="{{ $field }}_drive_id" value="{{ old($field.'_drive_id') }}" data-drive-id>
        @if($extension === 'pdf')<button type="button" class="btn btn-light" data-preview-drive @disabled(!auth()->user()->google_drive_token)>View Drive PDF</button>@endif
        @endif
        @error($field)<small class="mdb-inline-error">{{ $message }}</small>@enderror
        @error($field.'_drive_id')<small class="mdb-inline-error">{{ $message }}</small>@enderror
    </div>
    @endforeach
</div>
@if(config('services.google_drive.enabled'))
<p class="mdb-note"><a href="{{ route('google-drive.index') }}" target="_blank" rel="noopener">{{ auth()->user()->google_drive_token ? 'Manage Google Drive connection' : 'Connect Google Drive, then reload this form' }}</a>. Use files accessible to your connected account and authorized for this app.</p>
@else
<p class="mdb-note">Google Drive import needs administrator configuration. Local PDF and GPX uploads are available.</p>
@endif
<noscript><p>Enable JavaScript to preview PDFs and import Drive links. Local uploads still work.</p></noscript>
