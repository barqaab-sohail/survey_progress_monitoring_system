<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UserPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function picture(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('picture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1cAAAAASUVORK5CYII='));
    }

    public function test_user_can_upload_replace_and_remove_own_picture(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('My Profile');
        $this->put(route('profile.update'), ['profile_photo' => $this->picture(), 'user_id' => $other->id])->assertSessionHasNoErrors();
        $first = $user->fresh()->profile_photo_path;
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);
        $this->assertNull($other->fresh()->profile_photo_path);
        $this->assertStringContainsString('/storage/user-photos/', $user->fresh()->getFilamentAvatarUrl());
        $this->put(route('profile.update'), ['profile_photo' => $this->picture()])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($first);
        $second = $user->fresh()->profile_photo_path;
        $this->put(route('profile.update'), ['remove_photo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_super_admin_can_create_and_edit_users_with_pictures(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $data = ['name' => 'Photo User', 'email' => 'photo@example.com', 'role' => 'management_viewer', 'password' => 'long-password', 'password_confirmation' => 'long-password'];
        $this->actingAs($admin)->post(route('admin.users.store'), $data + ['profile_photo' => $this->picture()])->assertSessionHasNoErrors();
        $user = User::where('email', $data['email'])->firstOrFail();
        $first = $user->profile_photo_path;
        Storage::disk('public')->assertExists($first);
        unset($data['password'], $data['password_confirmation']);
        $data['status'] = 'active';
        $this->put(route('admin.users.update', $user), $data)->assertSessionHasNoErrors();
        $this->assertSame($first, $user->fresh()->profile_photo_path);
        $this->get(route('admin.users.index'))->assertOk()->assertSee('Profile picture')->assertSee($first);
        $this->put(route('admin.users.update', $user), $data + ['profile_photo' => $this->picture()])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($first);
        $second = $user->fresh()->profile_photo_path;
        $this->put(route('admin.users.update', $user), $data + ['remove_photo' => '1'])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($second);
        $this->assertNull($user->fresh()->profile_photo_path);
    }

    public function test_invalid_and_oversized_uploads_are_rejected_without_changing_picture(): void
    {
        $user = User::factory()->create(['profile_photo_path' => 'user-photos/existing.png']);
        Storage::disk('public')->put($user->profile_photo_path, 'existing');
        $this->actingAs($user)->put(route('profile.update'), ['profile_photo' => UploadedFile::fake()->createWithContent('bad.png', '<?php echo "bad";')])->assertSessionHasErrors('profile_photo');
        $picture = $this->picture();
        $large = UploadedFile::fake()->createWithContent('large.png', file_get_contents($picture->getPathname()).str_repeat('x', 2097152));
        $this->put(route('profile.update'), ['profile_photo' => $large])->assertSessionHasErrors('profile_photo');
        $this->assertSame('user-photos/existing.png', $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists('user-photos/existing.png');
    }

    public function test_other_roles_cannot_change_another_users_picture_and_guests_cannot_upload(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->put(route('profile.update'), ['profile_photo' => $this->picture()])->assertRedirect(route('login'));
        $this->actingAs($user)->put(route('admin.users.update', $other), ['profile_photo' => $this->picture()])->assertForbidden();
        $this->assertNull($other->fresh()->profile_photo_path);
    }

    public function test_filament_super_admin_can_upload_and_save_existing_picture(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $user = User::factory()->create();
        $this->actingAs($admin);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->fillForm(['profile_photo_path' => $this->picture()])
            ->call('save')
            ->assertHasNoFormErrors();
        $path = $user->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        Livewire::test(EditUser::class, ['record' => $user->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($path, $user->fresh()->profile_photo_path);
    }

    public function test_filament_other_roles_cannot_change_picture_even_with_user_edit_permission(): void
    {
        $manager = User::factory()->create(['role' => 'project_manager']);
        $user = User::factory()->create(['profile_photo_path' => 'user-photos/original.png']);
        Storage::disk('public')->put($user->profile_photo_path, 'original');
        $this->actingAs($manager);
        Gate::before(fn () => true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->assertFormFieldIsHidden('profile_photo_path')
            ->fillForm(['profile_photo_path' => null])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('user-photos/original.png', $user->fresh()->profile_photo_path);
        Storage::disk('public')->assertExists('user-photos/original.png');
    }
}
