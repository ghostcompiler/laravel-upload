<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Concerns\LaravelUploads;
use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LaravelUploadsTraitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('avatar_id')->nullable();
            $table->unsignedBigInteger('resume_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_exposes_upload_urls_and_hides_configured_id_columns(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar.png',
            'original_name' => 'avatar.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        $user = TestUser::query()->create([
            'avatar_id' => $upload->id,
        ]);

        $attributes = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('avatar_id', $attributes);
        $this->assertArrayHasKey('avatar', $attributes);
        $this->assertStringContainsString('/_laravel-uploads/file/', $attributes['avatar']);
    }

    public function test_it_accepts_string_backed_upload_ids_when_serializing_attributes(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar-string-id.png',
            'original_name' => 'avatar-string-id.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        $user = new TestUser();
        $user->setRawAttributes([
            'avatar_id' => (string) $upload->id,
        ], true);

        $attributes = $user->toArray();

        $this->assertArrayNotHasKey('avatar_id', $attributes);
        $this->assertArrayHasKey('avatar', $attributes);
        $this->assertStringContainsString('/_laravel-uploads/file/', $attributes['avatar']);
    }

    public function test_it_can_disable_upload_url_exposure_for_serialized_attributes(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar-hidden.png',
            'original_name' => 'avatar-hidden.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        $user = SecureTestUser::query()->create([
            'avatar_id' => $upload->id,
        ]);

        $attributes = $user->fresh()->toArray();

        $this->assertArrayNotHasKey('avatar_id', $attributes);
        $this->assertArrayNotHasKey('avatar', $attributes);
        $this->assertStringContainsString('/_laravel-uploads/file/', $user->fresh()->avatar);
    }

    public function test_it_deletes_associated_files_when_the_model_is_deleted(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.delete_files_with_model', true);

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar-delete.png',
            'original_name' => 'avatar-delete.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 321,
        ]);

        Storage::disk('local')->put($upload->path, 'avatar');

        $user = TestUser::query()->create([
            'avatar_id' => $upload->id,
        ]);

        $user->delete();

        Storage::disk('local')->assertMissing($upload->path);
        $this->assertDatabaseMissing('laravel_uploads_uploads', ['id' => $upload->id]);
    }

    public function test_it_allows_models_to_customize_uploadable_values(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar-custom.png',
            'original_name' => 'avatar-custom.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        $user = CustomValueTestUser::query()->create([
            'avatar_id' => $upload->id,
        ]);

        $this->assertSame([
            'url' => $user->avatar['url'],
            'exists' => true,
        ], $user->avatar);
        $this->assertStringContainsString('/_laravel-uploads/file/', $user->avatar['url']);
    }

    public function test_it_allows_generic_hooks_to_manage_multiple_uploadable_values(): void
    {
        $avatar = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/avatar-multiple.png',
            'original_name' => 'avatar-multiple.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);
        $resume = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/resume-multiple.pdf',
            'original_name' => 'resume-multiple.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 456,
        ]);

        $user = MultipleUploadableTestUser::query()->create([
            'avatar_id' => $avatar->id,
            'resume_id' => $resume->id,
        ]);

        $attributes = $user->fresh()->toArray();

        $this->assertSame('avatar_id', $attributes['avatar']['column']);
        $this->assertSame('avatar', $attributes['avatar']['name']);
        $this->assertStringContainsString('/_laravel-uploads/file/', $attributes['avatar']['url']);
        $this->assertSame('resume-field', $attributes['resume']['kind']);
        $this->assertStringContainsString('/_laravel-uploads/file/', $attributes['resume']['url']);
        $this->assertSame($attributes['avatar'], $user->fresh()->uploadableValue('avatar'));
        $this->assertSame($attributes['avatar'], $user->fresh()->uploadableValue('avatar_id'));
        $this->assertStringContainsString('/_laravel-uploads/file/', $user->fresh()->uploadUrl('avatar'));
    }
}

class TestUser extends Model
{
    use LaravelUploads;

    protected $table = 'test_users';

    protected $guarded = [];

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'visibility' => 'private',
            'id' => 'hide',
            'expiry' => 60,
            'expose' => true,
        ],
    ];
}

class SecureTestUser extends Model
{
    use LaravelUploads;

    protected $table = 'test_users';

    protected $guarded = [];

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'visibility' => 'private',
            'id' => 'hide',
            'expiry' => 60,
            'expose' => false,
        ],
    ];
}

class CustomValueTestUser extends Model
{
    use LaravelUploads;

    protected $table = 'test_users';

    protected $guarded = [];

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'visibility' => 'private',
            'id' => 'hide',
            'expiry' => 60,
            'expose' => true,
        ],
    ];

    public function setUploadableValue($value): array
    {
        return [
            'url' => $value,
            'exists' => $value !== null,
        ];
    }
}

class MultipleUploadableTestUser extends Model
{
    use LaravelUploads;

    protected $table = 'test_users';

    protected $guarded = [];

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'id' => 'hide',
            'expiry' => 60,
            'expose' => true,
        ],
        'resume_id' => [
            'name' => 'resume',
            'id' => 'hide',
            'expiry' => 60,
            'expose' => true,
        ],
    ];

    public function setUploadableValue($value, string $column, array $options): array
    {
        return [
            'url' => $value,
            'column' => $column,
            'name' => $options['name'],
        ];
    }

    public function setResumeUploadableValue($value): array
    {
        return [
            'url' => $value,
            'kind' => 'resume-field',
        ];
    }
}
