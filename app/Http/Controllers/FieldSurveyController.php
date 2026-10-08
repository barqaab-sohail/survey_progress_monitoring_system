<?php

namespace App\Http\Controllers;

use App\Models\FieldSurveyAttachment;
use App\Services\FieldSurveyAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FieldSurveyController extends Controller
{
    public function index(Request $request, FieldSurveyAccess $access): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['draft', 'submitted'])]]);
        $query = $access->visible($request->user())->with(['feeder', 'team', 'collector'])->withCount('attachments');
        if (! empty($filters['q'])) {
            $search = $filters['q'];
            $query->where(fn ($query) => $query->where('transformer_code', 'like', '%'.$search.'%')
                ->orWhereHas('feeder', fn ($feeder) => $feeder->where('feeder_code', 'like', '%'.$search.'%')->orWhere('feeder_name', 'like', '%'.$search.'%')));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $surveys = $query->latest('survey_date')->latest('id')->paginate(20)->withQueryString();

        return view('field-surveys.index', compact('surveys', 'filters'));
    }

    public function show(Request $request, string $clientUuid, FieldSurveyAccess $access): View
    {
        $survey = $access->visible($request->user())->with(['feeder', 'team', 'collector', 'attachments'])->where('client_uuid', $clientUuid)->firstOrFail();

        return view('field-surveys.show', compact('survey'));
    }

    public function download(Request $request, string $clientUuid, FieldSurveyAccess $access): BinaryFileResponse
    {
        $attachment = FieldSurveyAttachment::where('client_uuid', $clientUuid)->firstOrFail();
        abort_unless($access->visible($request->user())->whereKey($attachment->field_survey_id)->exists(), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);
        $extension = match ($attachment->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', default => 'pdf'
        };

        return response()->file(Storage::disk('local')->path($attachment->path), [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->client_uuid.'.'.$extension.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
