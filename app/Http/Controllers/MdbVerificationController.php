<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Http\Requests\ReturnMdbItemRequest;
use App\Models\MdbDailyEntryItem;
use App\Models\MdbVerificationHistory;
use App\Policies\MdbDailyEntryItemPolicy;
use App\Services\MdbCreationService;
use App\Support\ReviewAging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MdbVerificationController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(app(MdbDailyEntryItemPolicy::class)->verifyAny($request->user()), 403);
        $filters = $request->validate(['feeder_id' => ['nullable', 'integer', 'exists:feeders,id'], 'overdue' => ['nullable', 'boolean']]);
        $query = ReviewAging::query(MdbDailyEntryItem::where('status', SurveyItemStatus::Submitted->value), $filters);
        $aging = ReviewAging::summary($query);
        $items = $query->with(['entry.team', 'entry.enteredBy', 'feeder'])
            ->orderByRaw('COALESCE(resubmitted_at, created_at)')->orderBy('id')->paginate(30)->withQueryString();

        return view('mdb-verification.index', compact('items', 'aging'));
    }

    public function history(Request $request): View
    {
        abort_unless(app(MdbDailyEntryItemPolicy::class)->verifyAny($request->user()), 403);
        $histories = MdbVerificationHistory::with(['item.entry.enteredBy', 'item.feeder', 'actor'])
            ->latest('acted_at')->latest('id')->paginate(30);

        return view('mdb-verification.history', compact('histories'));
    }

    public function verify(Request $request, MdbDailyEntryItem $item, MdbCreationService $service): RedirectResponse
    {
        $service->verify($request->user(), $item);

        return back()->with('success', 'MDB files verified.');
    }

    public function return(ReturnMdbItemRequest $request, MdbDailyEntryItem $item, MdbCreationService $service): RedirectResponse
    {
        $service->returnForCorrection($request->user(), $item, $request->validated('reason'));

        return back()->with('success', 'MDB files returned for correction.');
    }
}
