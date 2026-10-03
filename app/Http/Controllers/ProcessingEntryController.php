<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Http\Requests\StoreProcessingEntryRequest;
use App\Models\MdbProcessingAssignment;
use App\Models\MdbProcessingDailyEntry;
use App\Services\MdbProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProcessingEntryController extends Controller
{
    public function index(Request $request): View
    {
        $entries = MdbProcessingDailyEntry::where('organization_id', $request->user()->organization_id)->with(['enteredBy', 'items.assignment.feeder'])->latest('entry_date')->paginate(20);

        return view('processing.entries.index', compact('entries'));
    }

    public function create(Request $request): View
    {
        $assignments = MdbProcessingAssignment::where('organization_id', $request->user()->organization_id)->where('status', AssignmentStatus::Active->value)->with(['feeder', 'progressItems'])->orderBy('assignment_date')->get();

        return view('processing.entries.create', compact('assignments'));
    }

    public function store(StoreProcessingEntryRequest $request, MdbProcessingService $service): RedirectResponse
    {
        $service->recordProgress($request->user(), $request->validated());

        return redirect()->route('processing.entries.index')->with('success', 'MDB processing progress saved.');
    }
}
