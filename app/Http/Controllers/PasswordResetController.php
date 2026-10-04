<?php

namespace App\Http\Controllers;

use App\Enums\RecordStatus;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $incorrectEmail = 'This email address is not registered to an active account. Please enter the correct email address.';
        $data = $request->validate([
            'email' => ['bail', 'required', 'email', 'max:255', Rule::exists('users', 'email')->where('status', RecordStatus::Active->value)],
        ], ['email.exists' => $incorrectEmail]);

        $mailer = config('mail.default');
        if ($mailer === 'log' || ($mailer === 'array' && ! app()->runningUnitTests())) {
            throw ValidationException::withMessages(['email' => 'Your email address is registered, but password reset email delivery is not configured. Please contact an administrator.']);
        }

        $status = Password::broker('users')->sendResetLink([
            'email' => $data['email'],
            'status' => RecordStatus::Active->value,
        ]);

        if ($status === Password::INVALID_USER) {
            throw ValidationException::withMessages(['email' => $incorrectEmail]);
        }
        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages(['email' => 'A reset link was recently requested. Please wait one minute before trying again.']);
        }

        return back()->withInput($request->only('email'))->with('status',
            'A password reset link has been sent to your registered email address. Check your inbox.');
    }

    public function form(Request $request, string $token): View
    {
        $email = $request->query('email', '');

        return view('auth.reset-password', ['token' => $token, 'email' => is_string($email) ? $email : '']);
    }

    public function update(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['bail', 'required', 'string', 'confirmed', PasswordRule::min(10), 'max:72', function (string $attribute, string $value, \Closure $fail) {
                if (strlen($value) > 72) {
                    $fail('Your password is too long. Use fewer characters.');
                } elseif (str_contains($value, "\0")) {
                    $fail('Your password contains an unsupported character.');
                }
            }],
        ]);

        $status = DB::transaction(function () use ($data, $audit) {
            $user = User::where('email', $data['email'])->where('status', RecordStatus::Active->value)
                ->lockForUpdate()->first();
            if (! $user) {
                return Password::INVALID_TOKEN;
            }
            // Serialize token consumption so concurrent requests cannot reuse a link.
            DB::table(config('auth.passwords.users.table'))->where('email', $user->getEmailForPasswordReset())
                ->lockForUpdate()->first();

            return Password::broker('users')->reset([
                ...$data,
                'email' => $user->getEmailForPasswordReset(),
                'status' => RecordStatus::Active->value,
            ], function (User $account, string $password) use ($audit) {
                $account->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                if (config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                        ->where('user_id', $account->id)->delete();
                }
                $audit->record($account, 'user.password_reset', $account);
                event(new PasswordReset($account));
            });
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This password reset link is invalid or has expired. Request a new link.']);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Sign in with your new password.');
    }
}
