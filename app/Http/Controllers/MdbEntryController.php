<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Http\Requests\StoreMdbEntryRequest;
use App\Http\Requests\ResubmitMdbItemRequest;
use App\Models\Feeder;
use App\Models\MdbDailyEntry;
use App\Models\MdbDailyEntryItem;
use App\Services\MdbCreationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Enums\UserRole;
use Illuminate\View\View;

class MdbEntryController extends Controller
{
    public function index(Request $request): View
    {
        $entries = MdbDailyEntry::query()
            ->unless($request->user()->hasRole(UserRole::SuperAdmin->value), fn ($query) => $query->where('entered_by', $request->user()->id))
            ->with(['team', 'enteredBy', 'items.feeder'])->latest('entry_date')->paginate(20);

        return view('mdb.index', compact('entries'));
    }

    public function create(): View
    {
        $feeders = Feeder::active()->withSum(['surveyItems as verified_quantity' => fn ($query) => $query->where('status', SurveyItemStatus::Verified->value)], 'transformers_surveyed')->withSum('mdbItems as created_quantity', 'mdb_files_created')->orderBy('feeder_code')->get()->filter(fn ($feeder) => $feeder->verified_quantity > $feeder->created_quantity);

        return view('mdb.create', compact('feeders'));
    }

    public function store(StoreMdbEntryRequest $request, MdbCreationService $service): RedirectResponse
    {
        $team = $request->user()->mdbTeams()->first();
        $service->create($request->user(), $team, $request->validated());

        return redirect()->route('mdb.index')->with('success', 'MDB creation saved and submitted for third-party verification.');
    }

    public function edit(MdbDailyEntry $entry): View
    {
        $this->authorize('update', $entry);
        abort_unless($entry->canBeEdited(), 403, 'MDB editing is locked after review. Returned items must be corrected and resubmitted.');
        $entry->load('items.feeder');
        $feeders = $entry->items->pluck('feeder');

        return view('mdb.create', compact('feeders', 'entry'));
    }

    public function update(StoreMdbEntryRequest $request, MdbDailyEntry $entry, MdbCreationService $service): RedirectResponse
    {
        $this->authorize('update', $entry);
        $service->update($request->user(), $entry, $request->validated());

        return redirect()->route('mdb.index')->with('success', 'MDB creation entry updated.');
    }

    public function returned(Request $request): View
    {
        $items = MdbDailyEntryItem::where('status', SurveyItemStatus::Returned->value)
            ->unless($request->user()->hasRole(UserRole::SuperAdmin->value), fn ($query) => $query->whereHas('entry', fn ($entry) => $entry->where('entered_by', $request->user()->id)))
            ->with(['entry', 'feeder'])->latest()->paginate(20);

        return view('mdb.returned', compact('items'));
    }

    public function resubmit(ResubmitMdbItemRequest $request, MdbDailyEntryItem $item, MdbCreationService $service): RedirectResponse
    {
        $service->resubmit($request->user(), $item, $request->validated());

        return redirect()->route('mdb.returned')->with('success', 'MDB item corrected and resubmitted for third-party verification.');
    }
}
