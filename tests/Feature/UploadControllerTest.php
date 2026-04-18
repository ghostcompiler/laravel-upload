<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class UploadControllerTest extends TestCase
{
    public function test_it_streams_previewable_private_files_inline_and_updates_access_time(): void
    {
        Storage::fake('local');

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/readme.txt',
            'original_name' => 'readme.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 12,
        ]);

        Storage::disk('local')->put($upload->path, 'hello world');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('p', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', ['token' => $link->token]));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'inline; filename="readme.txt"');
        $this->assertSame('hello world', $response->streamedContent());
        $this->assertNotNull($link->fresh()->last_accessed_at);
    }

    public function test_it_forces_downloads_when_requested(): void
    {
        Storage::fake('local');

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/report.pdf',
            'original_name' => 'report.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 24,
        ]);

        Storage::disk('local')->put($upload->path, 'fake-pdf');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('d', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', [
            'token' => $link->token,
            'download' => 1,
        ]));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename=report.pdf');
    }

    public function test_it_rejects_expired_links(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/expired.txt',
            'original_name' => 'expired.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 10,
        ]);

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('e', 64),
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('laravel-uploads.show', ['token' => $link->token]))
            ->assertForbidden();
    }
}
