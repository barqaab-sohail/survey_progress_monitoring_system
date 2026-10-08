<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\MobileDeviceToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFieldDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $bearer = $request->bearerToken();
        abort_unless(is_string($bearer) && strlen($bearer) === 128, 401, 'Sign in again to synchronize.');
        $token = MobileDeviceToken::with('user')->where('token_hash', hash('sha256', $bearer))->first();
        abort_unless($token && $token->expires_at->isFuture(), 401, 'This device session has expired or was revoked.');
        $user = $token->user;
        abort_unless($user?->isActive() && (! $user->organization_id || $user->organization?->status->value === 'active'), 403, 'Your account or organization is inactive.');
        abort_unless($user->hasAnyRole([UserRole::SurveyTeamLeader->value, UserRole::SuperAdmin->value]), 403, 'This account cannot collect field surveys.');
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('field_device_token', $token);
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinutes(5))) {
            $token->update(['last_used_at' => now()]);
        }

        return $next($request);
    }
}
