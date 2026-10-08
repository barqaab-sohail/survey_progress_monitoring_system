<div class="card">
<p><strong>{{ $aging['pending'] }}</strong> pending rows &middot; <strong>{{ $aging['overdue'] }}</strong> overdue &middot; Oldest: <strong>{{ $aging['oldest_days'] }} days</strong></p>
<p class="muted">Overdue means waiting at least 7 days since submission or latest resubmission. Entry dates do not determine review age.</p>
<div class="actions"><a class="btn btn-light" href="{{ request()->fullUrlWithQuery(['overdue' => 1, 'page' => null]) }}">Show overdue</a><a class="btn btn-light" href="{{ request()->url() }}">Clear filters</a></div>
@if(request('feeder_id'))<p>Filtered to feeder ID {{ request('feeder_id') }}.</p>@endif
</div>
