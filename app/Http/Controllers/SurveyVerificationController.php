<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Http\Requests\ReturnSurveyItemRequest;
use App\Models\SurveyDailyEntryItem;
use App\Services\SurveyProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SurveyVerificationController extends Controller
{
    public function index(): View
    {
        $items = SurveyDailyEntryItem::where('status', SurveyItemStatus::Submitted->value)->with(['entry.team', 'entry.enteredBy', 'feeder'])->oldest()->paginate(30);

        return view('verification.index', compact('items'));
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
