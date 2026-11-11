<section class="card feeder-preview" data-feeder-map data-url="{{ $previewUrl }}">
    <h2>{{ $previewTitle ?? 'Current map preview' }}</h2>
    <p>HT is purple; approved LT is green. Click a pole or line for details. Layer switches affect the preview only; every export includes all eligible networks.</p>
    <div class="actions"><button type="button" class="btn btn-primary" data-map-load>Load / refresh map</button><button type="button" class="btn btn-light" data-map-fit disabled>Fit visible networks</button></div>
    <p data-map-status role="status" aria-live="polite">Load the map to review this feeder.</p>
    <div class="feeder-map-layout"><fieldset data-map-layers><legend>Visible networks</legend><p>Layers appear after loading.</p></fieldset><div data-map-canvas role="region" aria-label="Feeder HT and LT map"></div></div>
    <p class="mdb-note">Street map tiles need an internet connection. Survey geometry remains viewable when tiles are unavailable. A preview does not create an export history entry.</p>
</section>
@once
@push('styles')
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<style>
.feeder-map-layout{display:flex;gap:16px;align-items:stretch}.feeder-map-layout fieldset{flex:0 0 240px;min-width:0;max-height:540px;overflow:auto;border:1px solid #d9e2ec;border-radius:8px;padding:12px}.feeder-map-layout fieldset label{display:flex;gap:8px;align-items:flex-start;margin:12px 0;overflow-wrap:anywhere}.feeder-map-layout fieldset input{width:auto;margin-top:4px}.feeder-map-layout [data-map-canvas]{height:540px;flex:1;min-width:0;z-index:0;border:1px solid #d9e2ec;border-radius:8px;background:#edf2f7}.feeder-map-popup{max-height:350px;overflow:auto}.feeder-map-popup th,.feeder-map-popup td{padding:4px 6px;text-align:left;vertical-align:top;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere}.feeder-map-popup table{width:100%}@media(max-width:800px){.feeder-map-layout{flex-direction:column}.feeder-map-layout fieldset{flex:auto;max-height:180px}.feeder-map-layout [data-map-canvas]{flex:auto;height:440px}}
@media(max-width:820px){body:has(.feeder-preview) .brand{min-width:0;flex:1}body:has(.feeder-preview) .brand-copy{min-width:0}body:has(.feeder-preview) .brand-copy strong{max-width:100%}body:has(.feeder-preview).local-environment .topbar:after{display:none}}
</style>
@endpush
@push('scripts')
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<script type="module" src="{{ asset('js/feeder-kmz-preview.js').'?v='.filemtime(public_path('js/feeder-kmz-preview.js')) }}"></script>
@endpush
@endonce
