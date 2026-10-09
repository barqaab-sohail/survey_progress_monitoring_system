<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\UserPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(Request $request, UserPhotoService $photos, AuditService $audit): RedirectResponse
    {
        $request->validate(UserPhotoService::rules());
        $user = $request->user();
        $old = ['profile_photo_path' => $user->profile_photo_path];
        $user->update($photos->attributes($request));
        $audit->record($user, 'user.photo_updated', $user, $old, ['profile_photo_path' => $user->profile_photo_path]);

        return back()->with('success', 'Profile picture updated.');
    }
}
