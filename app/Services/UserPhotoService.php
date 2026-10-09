<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserPhotoService
{
    public static function rules(): array
    {
        return ['profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'], 'remove_photo' => ['nullable', 'boolean']];
    }

    public function attributes(Request $request): array
    {
        if ($request->hasFile('profile_photo')) {
            $path = $request->file('profile_photo')->store('user-photos', 'public');
            if (! $path) {
                throw ValidationException::withMessages(['profile_photo' => 'The picture could not be saved. Please try again.']);
            }

            return ['profile_photo_path' => $path];
        }

        return $request->boolean('remove_photo') ? ['profile_photo_path' => null] : [];
    }
}
