<?php

namespace App\Http\Controllers;

use App\Services\Mdb\DataEntryMdbExport;
use App\Services\Mdb\TransformerTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfflineMdbController extends Controller
{
    public function __construct(private TransformerTransfer $transfer, private DataEntryMdbExport $exporter) {}

    private function authorizeConversion(): void
    {
        Gate::authorize('mdb-workflow.export');
        abort_unless(PHP_OS_FAMILY === 'Windows' && config('data_entry_mdb.offline_conversion_enabled'), 404);
    }

    public function index()
    {
        $this->authorizeConversion();

        return view('mdb-workflow.offline-mdb');
    }

    public function preview(Request $request)
    {
        $this->authorizeConversion();
        try {
            $request->validate(['file' => 'required|file|extensions:json|max:10240']);
            $json = $request->file('file')->get();
            $parsed = $this->transfer->read($json);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('mdb-workflow.offline-mdb.index'));
        }

        return view('mdb-workflow.offline-mdb', $parsed + ['packageJson' => $json]);
    }

    public function download(Request $request)
    {
        $this->authorizeConversion();
        try {
            $request->validate(['package' => 'required|string|max:10485760']);
            $parsed = $this->transfer->read($request->string('package')->toString());
            $path = $this->exporter->generate($parsed['payload'], 'windows');
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('mdb-workflow.offline-mdb.index'));
        }
        $package = $parsed['package'];
        $code = Str::slug($package['snapshot']['transformers'][0]['code']) ?: 'transformer';
        $filename = 'data_entry_'.$code.'_batch-'.$package['source']['batch_id'].'_r'.$package['source']['revision_number'].'.mdb';

        return response()->download($path, $filename, ['Content-Type' => 'application/x-msaccess', 'Cache-Control' => 'private, no-store',
            'X-Data-Entry-Observations' => (string) count($parsed['payload']['rows'])])->deleteFileAfterSend(true);
    }
}
