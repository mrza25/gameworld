<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AvatarCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    private function uploadAvatar(User $user, UploadedFile $file): TestResponse
    {
        return $this->actingAs($user)->post(route('api.auth.avatar'), ['avatar' => $file], ['Accept' => 'application/json']);
    }

    public function test_second_upload_deletes_the_first_file(): void
    {
        $user = User::factory()->create();

        $this->uploadAvatar($user, UploadedFile::fake()->image('first.jpg'))->assertOk();
        $firstPath = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($firstPath);

        $this->uploadAvatar($user->fresh(), UploadedFile::fake()->image('second.jpg'))->assertOk();
        $secondPath = $user->fresh()->avatar;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_invalid_upload_leaves_the_current_avatar_untouched(): void
    {
        Storage::disk('public')->put('avatars/current.jpg', 'image');
        $user = User::factory()->create(['avatar' => 'avatars/current.jpg']);

        $this->uploadAvatar($user, UploadedFile::fake()->create('notes.txt', 1, 'text/plain'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');

        $this->assertSame('avatars/current.jpg', $user->fresh()->avatar);
        Storage::disk('public')->assertExists('avatars/current.jpg');
    }

    public function test_url_avatar_is_not_treated_as_a_file_on_our_disk(): void
    {
        Storage::disk('public')->put('avatars/keep.jpg', 'someone else');
        $user = User::factory()->create(['avatar' => 'https://cdn.example.com/avatars/keep.jpg']);

        $this->uploadAvatar($user, UploadedFile::fake()->image('new.jpg'))->assertOk();

        Storage::disk('public')->assertExists('avatars/keep.jpg');
    }

    public function test_only_paths_inside_the_avatars_folder_are_ever_deleted(): void
    {
        Storage::disk('public')->put('games/cover.jpg', 'cover');
        $user = User::factory()->create(['avatar' => 'games/cover.jpg']);

        $this->uploadAvatar($user, UploadedFile::fake()->image('new.jpg'))->assertOk();

        Storage::disk('public')->assertExists('games/cover.jpg');
        $this->assertNull(User::storedAvatarPath('avatars/../../.env'));
        $this->assertNull(User::storedAvatarPath(null));
        $this->assertSame('avatars/a.jpg', User::storedAvatarPath('/avatars/a.jpg'));
    }

    public function test_deleting_a_user_deletes_their_avatar(): void
    {
        Storage::disk('public')->put('avatars/gone.jpg', 'image');
        $user = User::factory()->create(['avatar' => 'avatars/gone.jpg']);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('api.admin.users.delete', $user->id))
            ->assertOk();

        Storage::disk('public')->assertMissing('avatars/gone.jpg');
    }

    public function test_user_that_cannot_be_deleted_keeps_their_avatar(): void
    {
        Storage::disk('public')->put('avatars/kept.jpg', 'image');
        $user = User::factory()->create(['avatar' => 'avatars/kept.jpg']);
        $user->orders()->create(['console_type' => 'PS5', 'total' => 1000, 'status' => 'pending', 'payment_status' => 'paid']);

        $this->actingAs(User::factory()->admin()->create())
            ->deleteJson(route('api.admin.users.delete', $user->id))
            ->assertUnprocessable();

        Storage::disk('public')->assertExists('avatars/kept.jpg');
    }

    public function test_prune_deletes_only_unreferenced_files(): void
    {
        $disk = Storage::disk('public');
        $disk->put('avatars/orphan.jpg', 'image');
        $disk->put('avatars/used.jpg', 'image');
        $disk->put('avatars/slashed.jpg', 'image');
        $disk->put('avatars/.gitignore', '*');
        $disk->put('games/cover.jpg', 'cover');
        User::factory()->create(['avatar' => 'avatars/used.jpg']);
        User::factory()->create(['avatar' => '/avatars/slashed.jpg']);
        User::factory()->create(['avatar' => 'https://cdn.example.com/avatars/orphan.jpg']);

        $this->artisan('avatars:prune')
            ->expectsOutputToContain('Deleted 1 file(s)')
            ->assertSuccessful();

        $disk->assertMissing('avatars/orphan.jpg');
        $disk->assertExists('avatars/used.jpg');
        $disk->assertExists('avatars/slashed.jpg');
        $disk->assertExists('avatars/.gitignore');
        $disk->assertExists('games/cover.jpg');
    }

    public function test_prune_dry_run_lists_files_and_sizes_without_deleting(): void
    {
        Storage::disk('public')->put('avatars/orphan.jpg', str_repeat('x', 2048));

        $this->artisan('avatars:prune', ['--dry-run' => true])
            ->expectsOutputToContain('avatars/orphan.jpg')
            ->expectsOutputToContain('Dry run: would delete 1 file(s), 2.0 KB.')
            ->assertSuccessful();

        Storage::disk('public')->assertExists('avatars/orphan.jpg');
    }

    public function test_prune_reports_when_there_is_nothing_to_do(): void
    {
        $this->artisan('avatars:prune')
            ->expectsOutputToContain('No orphaned avatars found.')
            ->assertSuccessful();
    }
}
