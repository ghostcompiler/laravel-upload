<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use GhostCompiler\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class LaravelUploadsManagerTest extends TestCase
{
    public function test_it_stores_an_uploaded_file_and_tracks_metadata(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            'avatars',
            UploadedFile::fake()->image('avatar.png', 120, 120)
        );

        $this->assertInstanceOf(Upload::class, $upload);
        $this->assertSame('local', $upload->disk);
        $this->assertStringStartsWith('LaravelUploads/avatars/', $upload->path);
        $this->assertSame('avatar.png', $upload->original_name);
        $this->assertSame('png', $upload->extension);
        $this->assertSame('avatar.png', $upload->metadata['original_name']);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_it_generates_urls_and_removes_uploads_with_their_links(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('contract.pdf', 24, 'application/pdf')
        );

        $url = app(LaravelUploadsManager::class)->url($upload, 15);
        $link = UploadLink::query()->sole();

        $this->assertNotNull($url);
        $this->assertStringEndsWith('/_laravel-uploads/file/'.$link->token, parse_url($url, PHP_URL_PATH) ?: '');

        $this->assertTrue(app(LaravelUploadsManager::class)->remove($upload->id));
        Storage::disk('local')->assertMissing($upload->path);
        $this->assertDatabaseMissing('laravel_uploads_uploads', ['id' => $upload->id]);
        $this->assertDatabaseMissing('laravel_uploads_links', ['id' => $link->id]);
    }
}
