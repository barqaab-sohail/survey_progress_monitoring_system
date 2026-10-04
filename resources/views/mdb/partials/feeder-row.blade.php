<div class="row-card">
    <div class="row-card-head"><strong data-row-number>Feeder {{ $index + 1 }}</strong>@if(!isset($entry))<button type="button" class="btn btn-sm btn-light" data-remove-row>Remove</button>@endif</div>
    <div class="form-grid">
        <div class="field"><label>Feeder</label>
            @isset($entry)<input type="hidden" name="items[{{ $index }}][feeder_id]" value="{{ $item['feeder_id'] ?? '' }}">@endisset
            <select @if(!isset($entry)) name="items[{{ $index }}][feeder_id]" @else disabled @endif required>
                <option value="">Select feeder</option>
                @foreach($feeders as $feeder)
                <option value="{{ $feeder->id }}" @selected((string) ($item['feeder_id'] ?? '') === (string) $feeder->id)>{{ $feeder->feeder_code }} — {{ $feeder->feeder_name }}@if(!isset($entry)) — Available {{ $feeder->verified_quantity - $feeder->created_quantity }}@endif</option>
                @endforeach
            </select>
        </div>
        <div class="field"><label>MDB files created</label><input data-quantity inputmode="numeric" type="number" min="1" name="items[{{ $index }}][mdb_files_created]" value="{{ $item['mdb_files_created'] ?? '' }}" required></div>
        <div class="field"><label>MDB Google Drive URL <small>(required)</small></label><input type="url" name="items[{{ $index }}][drive_url]" value="{{ $item['drive_url'] ?? '' }}" placeholder="https://drive.google.com/drive/folders/..." maxlength="2000" required></div>
        <div class="field"><label>Remarks</label><input name="items[{{ $index }}][remarks]" value="{{ $item['remarks'] ?? '' }}" maxlength="1000"></div>
    </div>
</div>
