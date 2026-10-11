<?php

namespace App\Http\Controllers;

use App\Models\Mdb\GenerationInput;
use App\Models\Mdb\SurveyBatch;
use App\Services\AuditService;
use App\Services\Mdb\GenerationInputService;
use App\Services\Mdb\WorkflowAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MdbGenerationInputController extends Controller
{
    public function store(Request $request, SurveyBatch $batch, WorkflowAccess $access, GenerationInputService $inputs)
    {
        $access->authorize($request->user(), $batch, 'prepare');
        $data = $request->validate(['kind' => 'required|in:kmz,base', 'file' => 'required|file|max:102400|extensions:kmz,mdb,csv,xlsx,json,ini,cfg,xml,prj,dat,kml,zip,txt']);
        $inputs->store($batch, $request->user(), $request->file('file'), $data['kind']);

        return back()->with('success', 'Generation input preserved. Approved survey data remains unchanged.');
    }

    public function download(Request $request, SurveyBatch $batch, GenerationInput $input, WorkflowAccess $access)
    {
        $access->authorize($request->user(), $batch, 'prepare');
        abort_unless($input->batch_id === $batch->id, 404);
        $disk = Storage::disk($input->disk);
        abort_unless($disk->exists($input->path) && hash_equals($input->sha256, hash_file('sha256', $disk->path($input->path))), 409, 'Original generation input is missing or changed.');
        app(AuditService::class)->record($request->user(), 'mdb.generation_input_downloaded', $input);

        return $disk->download($input->path, $input->original_name, ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
