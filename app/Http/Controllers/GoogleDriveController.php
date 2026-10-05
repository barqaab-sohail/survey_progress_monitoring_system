<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GoogleDriveController extends Controller
{
    public function index(Request $request)
    {
        return view('google-drive.index', [
            'configured' => $this->configured(),
            'connected' => (bool) $request->user()->google_drive_token,
        ]);
    }

    public function connect(Request $request)
    {
        abort_unless($this->configured(), 503, 'Google Drive credentials are not configured.');
        $state = Str::random(64);
        $request->session()->put('google_drive_oauth', [
            'state' => $state,
            'user_id' => $request->user()->id,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => config('services.google_drive.redirect_uri'),
            'response_type' => 'code',
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
            ]);
        } catch (ConnectionException) {
            return to_route('google-drive.index')->withErrors(['google_drive' => 'Google could not be reached. Please connect again.']);
        }

        $token = $response->json();
        if (! $response->successful() || ! is_array($token) || empty($token['access_token'])
            || ! in_array('https://www.googleapis.com/auth/drive.file', explode(' ', $token['scope'] ?? ''), true)) {
            return to_route('google-drive.index')->withErrors(['google_drive' => 'Google Drive authorization failed. Please connect again and allow Drive access.']);
        }

        $request->user()->google_drive_token = [
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'] ?? null,
            'expires_at' => now()->addSeconds((int) ($token['expires_in'] ?? 3600))->timestamp,
            'scope' => $token['scope'],
        ];
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
