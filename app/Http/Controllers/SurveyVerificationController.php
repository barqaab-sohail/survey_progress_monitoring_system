<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Http\Requests\ReturnSurveyItemRequest;
use App\Models\SurveyDailyEntryItem;
use App\Services\SurveyProgressService;
use App\Support\ReviewAging;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SurveyVerificationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['feeder_id' => ['nullable', 'integer', 'exists:feeders,id'], 'overdue' => ['nullable', 'boolean']]);
        $query = ReviewAging::query(SurveyDailyEntryItem::where('status', SurveyItemStatus::Submitted->value), $filters);
        $aging = ReviewAging::summary($query);
        $items = $query->with(['entry.team', 'entry.enteredBy', 'feeder'])
            ->orderByRaw('COALESCE(resubmitted_at, created_at)')->orderBy('id')->paginate(30)->withQueryString();

        return view('verification.index', compact('items', 'aging'));
    }

    public function verify(SurveyDailyEntryItem $item, SurveyProgressService $service): RedirectResponse
    {
        $service->verify(request()->user(), $item);

        return back()->with('success', 'Survey quantity verified.');
    }

    public function return(ReturnSurveyItemRequest $request, SurveyDailyEntryItem $item, SurveyProgressService $service): RedirectResponse
    {
        $service->returnForCorrection($request->user(), $item, $request->validated('reason'));

        return back()->with('success', 'Survey item returned for correction.');
    }
}
