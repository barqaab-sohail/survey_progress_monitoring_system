<?php

namespace App\Http\Controllers;

use App\Enums\SurveyItemStatus;
use App\Http\Requests\StoreMdbEntryRequest;
use App\Models\Feeder;
use App\Models\MdbDailyEntry;
use App\Services\MdbCreationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class MdbEntryController extends Controller
{
    public function index(): View
    {
        $entries = MdbDailyEntry::with(['team', 'enteredBy', 'items.feeder'])->latest('entry_date')->paginate(20);

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

        return redirect()->route('mdb.index')->with('success', 'MDB creation progress saved.');
    }
}
