<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncHtNetworkUpdateRequest;
use App\Services\HtNetworkWorkflow;
use Illuminate\Http\Request;

class HtNetworkUpdateApiController extends Controller
{
    public function index(Request $request, HtNetworkWorkflow $workflow)
    {
        return response()->json(['ht_updates' => $workflow->visible($request->user())->get()->map(fn ($u) => $u->summary())->values()]);
    }

    public function sync(SyncHtNetworkUpdateRequest $request, HtNetworkWorkflow $workflow)
    {
        return response()->json($workflow->sync($request->user(), $request->validated())->summary());
    }
}
