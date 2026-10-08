<div class="row-card">
    <div class="row-card-head"><strong data-row-number>Feeder {{ $index + 1 }}</strong>@if(!isset($entry))<button type="button" class="btn btn-sm btn-light" data-remove-row>Remove</button>@endif</div>
    <div class="form-grid">
        <div class="field"><label>Feeder</label>
            @if(!isset($entry))<input type="search" data-feeder-search aria-label="Search feeders" placeholder="Search feeder code or name">@endif
            @isset($entry)<input type="hidden" name="items[{{ $index }}][feeder_id]" value="{{ $item['feeder_id'] ?? '' }}">@endisset
            <select data-feeder @if(!isset($entry)) name="items[{{ $index }}][feeder_id]" @else disabled @endif required>
                <option value="">Select feeder</option>
                @foreach($feeders as $feeder)
                <option data-capacity="{{ max(0, (int) $feeder->verified_quantity - (int) $feeder->created_quantity) }}" value="{{ $feeder->id }}" @selected((string) ($item['feeder_id'] ?? '') === (string) $feeder->id)>{{ $feeder->feeder_code }} — {{ $feeder->feeder_name }}@if(!isset($entry)) — Available {{ $feeder->verified_quantity - $feeder->created_quantity }}@endif</option>
                @endforeach
            </select><small data-capacity-hint role="status"></small>@error("items.$index.feeder_id")<small class="field-error" role="alert">{{ $message }}</small>@enderror
        </div>
        <div class="field"><label>MDB files created</label><input data-quantity inputmode="numeric" type="number" min="1" name="items[{{ $index }}][mdb_files_created]" value="{{ $item['mdb_files_created'] ?? '' }}" required>@error("items.$index.mdb_files_created")<small class="field-error" role="alert">{{ $message }}</small>@enderror</div>
        <div class="field"><label>MDB Google Drive URL <small>(required)</small></label><input type="url" pattern="https?://.+" name="items[{{ $index }}][drive_url]" value="{{ $item['drive_url'] ?? '' }}" placeholder="https://drive.google.com/drive/folders/..." maxlength="2000" required>@error("items.$index.drive_url")<small class="field-error" role="alert">{{ $message }}</small>@enderror</div>
        <div class="field"><label>Remarks</label><input name="items[{{ $index }}][remarks]" value="{{ $item['remarks'] ?? '' }}" maxlength="1000">@error("items.$index.remarks")<small class="field-error" role="alert">{{ $message }}</small>@enderror</div>
    </div>
</div>
