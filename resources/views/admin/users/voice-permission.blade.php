<div class="field">
    <label>Mobile voice form-filling</label>
    <select name="allow_voice_form_filling">
        <option value="0" @selected(!($voiceUser?->allow_voice_form_filling ?? false))>Disabled</option>
        <option value="1" @selected($voiceUser?->allow_voice_form_filling ?? false)>Enabled</option>
    </select>
    <small>Allows microphone entry with review before applying each value. Requires an internet connection to confirm permission.</small>
</div>
