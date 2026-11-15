@if(session('drive_json'))
<section class="card" style="margin-bottom:18px">
    <p><strong>{{ session('drive_json.filename') }}</strong> is saved in its feeder folder.</p>
    <div class="actions">
        <a class="btn btn-primary" href="{{ session('drive_json.file_url') }}" target="_blank" rel="noopener">Open JSON in Google Drive</a>
        <a class="btn btn-light" href="{{ session('drive_json.folder_url') }}" target="_blank" rel="noopener">Open feeder folder</a>
    </div>
</section>
@endif
