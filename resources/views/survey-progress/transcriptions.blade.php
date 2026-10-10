@extends('layouts.app')
@section('title', 'Reviewed PDF S/E Transcriptions')
@section('content')
<div class="page-head"><div><h1>Reviewed PDF S/E Transcriptions</h1><p>{{ $feeder->feeder_code }} · {{ $feeder->feeder_name }}. These records are used only for survey-progress LT calculations.</p></div><a class="btn btn-light" href="{{ route('survey-progress.index') }}">Survey progress</a></div>
<section class="card"><p>Read the original PDF and enter each explicit Start/End span. Enter one CSV row per span:</p><pre>Transformer GPS_No,Start GPS_No,End GPS_No,PDF page,PDF row
11131222104,11131222104,01101026001,1,1
11131222104,01101026001,01101026003,1,2</pre><p>Do not include the heading line. Use all 11 digits, preserving leading zeros. Repeat the historical transformer GPS_No for every span in its network. Enter branches as their own explicit spans; GPX order never defines connections.</p><p>Drafts can contain incomplete references. Approval requires complete references and a network for every transformer reported by the filename. Approval records who reviewed the PDF and preserves every correction as a new version.</p></section>
@forelse($analysis['pairs'] as $key=>$pair)
@php($latest = $history->firstWhere('survey_key', $key))
@php($restore = old('survey_key') === $key)
@php($pdfSource = $sources->get($pair['pdf']['id']))
<section class="card"><h2>{{ $pair['pdf']['name'] }}</h2><p><a target="_blank" rel="noopener" href="{{ route('survey-progress.source', [$feeder, $pdfSource]) }}">Open original PDF</a> · GPX: {{ $pair['gpx']['name'] }} · Latest transcription version: {{ $latest?->version ?? 0 }} · {{ $latest?->reviewed_at ? 'Reviewed' : 'Draft / not entered' }}</p>
@if($latest && ($latest->pdf_checksum !== ($pair['pdf']['md5Checksum'] ?? '') || $latest->gpx_checksum !== ($pair['gpx']['md5Checksum'] ?? '')))<div class="alert alert-danger">PDF or GPX changed. Review the new source documents before approving this transcription again.</div>@endif
<form method="POST" action="{{ route('survey-progress.transcriptions.store', $feeder) }}">@csrf
<input type="hidden" name="survey_key" value="{{ $key }}"><input type="hidden" name="version" value="{{ $latest?->version ?? 0 }}"><input type="hidden" name="pdf_checksum" value="{{ $pair['pdf']['md5Checksum'] ?? '' }}"><input type="hidden" name="gpx_checksum" value="{{ $pair['gpx']['md5Checksum'] ?? '' }}">
<label>S/E records<textarea name="records" rows="12" style="width:100%;font-family:monospace" required>{{ $restore ? old('records') : ($latest?->data['records'] ?? '') }}</textarea></label><label>Review / correction remarks<textarea name="remarks" rows="2" maxlength="4000">{{ $restore ? old('remarks') : '' }}</textarea></label>
<label><input type="checkbox" name="reviewed" value="1" @checked($restore && old('reviewed'))> I reviewed every span and transformer reference against the original PDF. This transcription is complete and may be used for confirmed length calculations after coordinate and elevation validation.</label><p><button class="btn btn-primary">Save new transcription version</button></p></form>
<details><summary>Transcription history</summary><ul>@foreach($history->where('survey_key', $key) as $record)<li>Version {{ $record->version }} · {{ $record->created_at->timezone('Asia/Karachi')->format('d M Y H:i') }} · {{ $record->operator?->name }} · {{ $record->reviewed_at ? 'Reviewed by '.$record->reviewer?->name : 'Draft' }} · {{ $record->remarks }}<details><summary>Saved records</summary><pre>{{ $record->data['records'] }}</pre></details></li>@endforeach</ul></details></section>
@empty<div class="card">No unambiguous matching PDF/GPX submissions are available. Synchronize or correct conflicting filenames first.</div>@endforelse
@endsection
