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
}

class TestUser extends Model
{
    use LaravelUploads;

    protected $table = 'test_users';

    protected $guarded = [];

    protected $uploadable = [
        'avatar_id' => [
            'name' => 'avatar',
            'id' => 'hide',
            'expiry' => 60,
        ],
    ];
}
