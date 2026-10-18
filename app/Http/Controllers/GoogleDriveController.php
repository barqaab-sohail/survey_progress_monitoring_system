<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use App\Services\DriveStorage\DuplicateFiles;

class GoogleDriveController extends Controller
{
    public function index(Request $request)
    {
        return view('google-drive.index', [
            'configured' => $this->configured(),
            'connected' => (bool) $request->user()->google_drive_token,
            'storageConnection' => $request->user()->hasRole('super_admin') ? \App\Models\DriveStorageConnection::find(1) : null,
            'storagePending' => $request->user()->hasRole('super_admin') ? \App\Models\DriveStoredFile::where('status', '!=', 'uploaded')->count() : 0,
            'duplicateGroups' => $request->user()->hasRole('super_admin') ? app(DuplicateFiles::class)->groups() : [],
        ]);
    }

    public function cleanupDuplicates(Request $request, string $sha256, DuplicateFiles $duplicates)
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        $request->validate(['confirm' => ['required', 'accepted']]);
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $sha256), 404);
        try {
            $plan = $duplicates->cleanup($sha256, $request->user());
            $duplicates->finishCleanup($plan);
        } catch (\Throwable $exception) {
            report($exception);
            return to_route('google-drive.index')->withErrors(['duplicates' => 'Cleanup could not be completed safely: '.$exception->getMessage()]);
        }
        return to_route('google-drive.index')->with('success', 'Confirmed duplicate copies were removed. Valid records now use the retained original.');
    }

    public function connect(Request $request)
    {
        abort_unless($this->configured(), 503, 'Google Drive credentials are not configured.');
        $storage = $request->boolean('application_storage');
        if ($storage) abort_unless($request->user()->hasRole('super_admin'), 403);
        $state = Str::random(64);
        $verifier = Str::random(64);
        $request->session()->put('google_drive_oauth', [
            'application_storage' => $storage,
            'code_verifier' => $verifier,
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect_uri'),
            'response_type' => 'code',
            'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function callback(Request $request)
    {
        abort_unless($this->configured(), 503, 'Google Drive credentials are not configured.');
        $pending = $request->session()->pull('google_drive_oauth');
        $state = $request->query('state');
        abort_unless(is_array($pending) && is_string($state)
            && hash_equals($pending['state'], $state)
            && $pending['user_id'] === $request->user()->id
            && $pending['expires_at'] > now()->timestamp, 403, 'Invalid or expired Google authorization request.');

        $storage = $pending['application_storage'] ?? false;
        if ($storage) abort_unless($request->user()->hasRole('super_admin'), 403);

        if ($request->has('error')) {
            return to_route('google-drive.index')->withErrors(['google_drive' => 'Google Drive authorization was declined.']);
        }

        $request->validate(['code' => ['required', 'string', 'max:4096']]);

        try {
            $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('services.google_drive.client_id'),
                'client_secret' => config('services.google_drive.client_secret'),
                'redirect_uri' => config('services.google_drive.redirect_uri'),
                'grant_type' => 'authorization_code',
                'code' => $request->query('code'),
                'code_verifier' => $pending['code_verifier'] ?? null,
            ]);
        } catch (ConnectionException) {
            return to_route('google-drive.index')->withErrors(['google_drive' => 'Google could not be reached. Please connect again.']);
        }

        $token = $response->json();
        if (! $response->successful() || ! is_array($token) || empty($token['access_token'])
            || ! in_array('https://www.googleapis.com/auth/drive.file', explode(' ', $token['scope'] ?? ''), true)) {
            return to_route('google-drive.index')->withErrors(['google_drive' => 'Google Drive authorization failed. Please connect again and allow Drive access.']);
        }

        $storedToken = [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600))->timestamp,
            'scope' => $token['scope'],
        ];
        if ($storage) {
            if (empty($storedToken['refresh_token'])) {
                return to_route('google-drive.index')->withErrors(['google_drive' => 'Allow offline access to enable automatic storage. Reconnect with consent.']);
            }
            try {
                $account = Http::withToken($token['access_token'])->timeout(20)->get('https://www.googleapis.com/drive/v3/about', ['fields' => 'user(permissionId,emailAddress)']);
            } catch (ConnectionException) {
                return to_route('google-drive.index')->withErrors(['google_drive' => 'Could not verify the storage account. Please reconnect.']);
            }
            if (! $account->successful() || ! $account->json('user.permissionId') || ! $account->json('user.emailAddress')) {
                return to_route('google-drive.index')->withErrors(['google_drive' => 'Could not verify the storage account. Please reconnect.']);
            }
            $connection = \App\Models\DriveStorageConnection::find(1);
            if ($connection && $connection->account_id !== $account->json('user.permissionId')) {
                return to_route('google-drive.index')->withErrors(['google_drive' => 'Reconnect the original storage account so existing files remain accessible.']);
            }
            \Illuminate\Support\Facades\Cache::lock('drive-storage:oauth', 60)->block(10, function () use ($account, $storedToken, $request) {
                $current = \App\Models\DriveStorageConnection::find(1);
                if ($current && $current->account_id !== $account->json('user.permissionId')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['google_drive' => 'Reconnect the original storage account.']);
                }
                \App\Models\DriveStorageConnection::updateOrCreate(['id' => 1], [
                    'token' => $storedToken, 'account_id' => $account->json('user.permissionId'),
                    'account_email' => $account->json('user.emailAddress'), 'connected_by' => $request->user()->id,
                ]);
            });
            return to_route('google-drive.index')->with('success', 'Application storage connected. Uploads will transfer automatically when storage is enabled and the worker is running.');
        }
        $request->user()->google_drive_token = $storedToken;
        $request->user()->save();

        return to_route('google-drive.index')->with('success', 'Google Drive connected successfully.');
    }

    public function disconnect(Request $request)
    {
        $request->user()->google_drive_token = null;
        $request->user()->save();
        $request->session()->forget('google_drive_oauth');

        return to_route('google-drive.index')->with('success', 'Google Drive disconnected from this application.');
    }

    private function configured(): bool
    {
        return (bool) config('services.google_drive.enabled')
            && filled(config('services.google_drive.client_id'))
            && filled(config('services.google_drive.client_secret'))
            && filled(config('services.google_drive.redirect_uri'));
    }
}
