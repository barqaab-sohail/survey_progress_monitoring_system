@if($canManage)
<a class="btn btn-primary" href="{{ route('field-surveys.edit', $survey->client_uuid) }}">Edit survey</a>
<form method="POST" action="{{ route('field-surveys.destroy', $survey->client_uuid) }}" style="display:inline" onsubmit="return confirm('Delete this survey from the active list? Its history and uploaded evidence will be retained.');">
    @csrf @method('DELETE')
    <input type="hidden" name="base_revision" value="{{ $survey->revision }}">
    <button class="btn btn-light" type="submit">Delete survey</button>
</form>
@endif
