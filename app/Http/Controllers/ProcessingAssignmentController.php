<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Http\Requests\StoreProcessingAssignmentRequest;
use App\Models\Feeder;
use App\Models\MdbProcessingAssignment;
use App\Models\Organization;
use App\Models\ProcessingTeam;
use App\Services\MdbProcessingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProcessingAssignmentController extends Controller
{
    public function index(): View
    {
        $assignments = MdbProcessingAssignment::with(['feeder', 'organization', 'processingTeam', 'progressItems'])->latest('assignment_date')->paginate(30);

        return view('processing.assignments.index', compact('assignments'));
    }

    public function create(): View
    {
        $feeders = Feeder::active()->withSum('mdbItems as created_quantity', 'mdb_files_created')->withSum(['processingAssignments as assigned_quantity' => fn ($query) => $query->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value])], 'assigned_quantity')->orderBy('feeder_code')->get()->filter(fn ($feeder) => $feeder->created_quantity > $feeder->assigned_quantity);
        $organizations = Organization::where('status', 'active')->orderBy('name')->get();
        $teams = ProcessingTeam::where('status', 'active')->orderBy('name')->get();

        return view('processing.assignments.create', compact('feeders', 'organizations', 'teams'));
    }

    public function store(StoreProcessingAssignmentRequest $request, MdbProcessingService $service): RedirectResponse
    {
        $data = $request->validated();
        $organization = Organization::findOrFail($data['organization_id']);
        unset($data['organization_id']);
        $service->assign($request->user(), $organization, $data);

        return redirect()->route('processing.assignments.index')->with('success', 'MDB processing work assigned.');
    }
}
