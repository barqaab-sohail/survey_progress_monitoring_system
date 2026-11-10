<?php

namespace App\Http\Controllers;

use App\Models\Transformer;
use App\Models\TransformerCapacityObservation;
use App\Services\TransformerRegistryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TransformerVerificationController extends Controller
{
    public function confirmCode(Request $request, Transformer $transformer, TransformerRegistryService $service)
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        $data = $request->validate(['verification_revision' => 'required|integer', 'transformer_code' => 'required|string|max:100',
            'reason' => 'required|string|min:5|max:4000', 'client_reference' => 'required|string|min:5|max:4000']);
        $service->confirmCode($request->user(), $transformer, $data);
        return back()->with('success', 'Client-confirmed code saved. The permanent transformer ID and linked surveys are retained. Linked PDF work requires verification again.');
    }

    public function observe(Request $request, Transformer $transformer, TransformerRegistryService $service)
    {
        abort_unless($service->canObserve($request->user(), $transformer), 403);
        $data = $request->validate(['verification_revision' => 'required|integer', 'capacity_kva' => 'required|numeric|gt:0|max:99999999',
            'evidence_note' => 'required|string|min:5|max:4000', 'evidence_photo' => 'required|image|mimes:jpeg,png,webp|max:8192']);
        $path = $request->file('evidence_photo')->store('transformer-capacity-evidence', 'local');
        abort_unless($path, 503, 'Evidence could not be stored. Retry with the original photo.');
        try {
            $service->observe($request->user(), $transformer, $data + ['evidence_path' => $path,
                'evidence_sha256' => hash_file('sha256', Storage::disk('local')->path($path))]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        return back()->with('success', 'Field capacity observation saved for independent verification. The reference capacity is unchanged until approval.');
    }

    public function review(Request $request, Transformer $transformer, TransformerCapacityObservation $observation, TransformerRegistryService $service)
    {
        abort_unless($observation->transformer_id === $transformer->id, 404);
        abort_unless($service->canReview($request->user(), $transformer), 403);
        $data = $request->validate(['verification_revision' => 'required|integer', 'decision' => 'required|in:approved,rejected', 'review_note' => 'required|string|min:5|max:4000']);
        $service->reviewCapacity($request->user(), $observation, $data);
        return back()->with('success', 'Capacity observation '.$data['decision'].'.');
    }

    public function evidence(Request $request, Transformer $transformer, TransformerCapacityObservation $observation, TransformerRegistryService $service)
    {
        abort_unless($observation->transformer_id === $transformer->id && $service->canView($request->user(), $transformer), 404);
        abort_unless($observation->evidence_path && Storage::disk('local')->exists($observation->evidence_path), 404);
        abort_unless(hash_equals($observation->evidence_sha256, hash_file('sha256', Storage::disk('local')->path($observation->evidence_path))), 409, 'Evidence integrity check failed.');
        return response()->file(Storage::disk('local')->path($observation->evidence_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function surveyEvidence(Request $request, Transformer $transformer, TransformerCapacityObservation $observation, int $attachment, TransformerRegistryService $service)
    {
        abort_unless($observation->transformer_id === $transformer->id && $service->canView($request->user(), $transformer), 404);
        $photo = $observation->survey?->attachments()->where('mime_type', 'like', 'image/%')->find($attachment);
        abort_unless($photo && Storage::disk('local')->exists($photo->path), 404);
        abort_unless(hash_equals($photo->sha256, hash_file('sha256', Storage::disk('local')->path($photo->path))), 409, 'Evidence integrity check failed.');
        return response()->file(Storage::disk('local')->path($photo->path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
