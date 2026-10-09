@extends('layouts.app')
@section('title', 'Android Survey Web Test')
@push('styles')
<link rel="stylesheet" href="{{ asset('css/field-survey-test.css') }}?v=1">
@endpush
@section('content')
@php
    $testConfig = ['userId' => auth()->id(), 'userName' => auth()->user()->name, 'base' => url('field-survey-test'), 'today' => today()->format('Y-m-d')];
@endphp
<div id="field-test" data-config='@json($testConfig)'>
    <div class="page-head"><div><h1>Android Survey Web Test</h1><p>The Android survey workflow, available here for Super Admin testing.</p></div><button id="new-survey" class="btn btn-primary" disabled>New survey</button></div>
    <div class="test-banner"><strong>Test workspace</strong> — surveys and attachments saved here are separate from field submissions and progress totals.</div>
    <div class="card test-toolbar">
        <div><strong id="connection-status">Loading assignments…</strong><p id="sync-status" role="status" aria-live="polite">Drafts are saved in this browser. Submit and sync to save a test copy on the server.</p></div>
        <div class="actions"><label><input type="checkbox" id="simulate-offline"> Simulate offline</label><button id="refresh-reference" class="btn btn-light">Download assignments</button><button id="sync-all" class="btn btn-primary">Sync now</button></div>
    </div>
    <div id="test-notice" class="test-notice" role="status" aria-live="polite" hidden></div>
    <section id="notebook">
        <div class="field"><label for="survey-search">Find transformer, feeder or date</label><input id="survey-search" type="search" placeholder="Search test surveys"></div>
        <div id="survey-filters" class="test-tabs" aria-label="Survey filters"></div>
        <div id="survey-list"></div>
    </section>
    <section id="editor" hidden>
        <div class="test-editor-head"><button id="back-notebook" class="btn btn-light">← Field notebook</button><strong id="save-status" role="status" aria-live="polite"></strong><button id="export-payload" class="btn btn-light">Export Android JSON</button></div>
        <div id="editor-meta"></div>
        <div id="validation-errors" class="test-errors" role="alert" hidden></div>
        <div id="editor-tabs" class="test-tabs" role="tablist" aria-label="Survey sections"></div>
        <form id="survey-form" novalidate></form>
        <div class="card test-footer"><button id="save-draft" class="btn btn-light">Save draft</button><button id="submit-survey" class="btn btn-primary">Submit test survey</button><button id="sync-current" class="btn btn-light">Sync this survey</button><button id="load-server" class="btn btn-light" hidden>Review server copy</button></div>
    </section>
    <dialog id="start-dialog">
        <form id="start-form"><h2>Start a field survey</h2><div class="field"><label for="start-team">Assigned survey team</label><select id="start-team" required></select></div><div class="field"><label for="start-feeder">Assigned feeder</label><select id="start-feeder" required></select></div><div class="form-footer"><button type="button" id="cancel-start" class="btn btn-light">Cancel</button><button class="btn btn-primary">Create survey</button></div></form>
    </dialog>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/field-survey-test.js') }}?v=3" defer></script>
@endpush
