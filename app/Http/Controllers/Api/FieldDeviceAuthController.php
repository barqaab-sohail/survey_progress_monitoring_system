<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MobileDeviceToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class FieldDeviceAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');
        abort_if(strlen($request->getContent()) > 8192, 413, 'Login payload is too large.');
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1000'], 'device_name' => ['required', 'string', 'max:120']]);
        $key = 'field-login:'.hash('sha256', strtolower(trim($data['email'])).'|'.$request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many sign-in attempts. Try again in one minute.');
        RateLimiter::hit($key, 60);
        $user = User::whereRaw('LOWER(email) = ?', [strtolower(trim($data['email']))])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }
        abort_unless($user->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active'), 403, 'Your account or organization is inactive.');
        abort_unless($user->hasAnyRole([UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value]), 403, 'Use an assigned survey-team-leader account to collect field surveys.');
        RateLimiter::clear($key);
        $plain = bin2hex(random_bytes(64));
        $token = MobileDeviceToken::create(['user_id' => $user->id, 'device_name' => $data['device_name'], 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);

        return response()->json(['token' => $plain, 'user' => $user->only(['id', 'name']), 'expires_at' => $token->expires_at->toIso8601String()]);
    }

    public function logout(Request $request): Response
    {
        $request->attributes->get('field_device_token')->delete();

        return response()->noContent();
    }
}
