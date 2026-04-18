<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Tests\TestCase;

class CleanupExpiredLinksCommandTest extends TestCase
{
    public function test_it_removes_only_expired_links(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/example.png',
            'original_name' => 'example.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('a', 64),
            'expires_at' => now()->subMinute(),
        ]);

        UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('b', 64),
            'expires_at' => now()->addMinute(),
        ]);

        $this->artisan('ghost:laravel-uploads-clean')
            ->expectsOutputToContain('Removed 1 expired Laravel Uploads links.')
            ->assertSuccessful();

        $this->assertSame(1, UploadLink::query()->count());
        $this->assertTrue(UploadLink::query()->where('token', str_repeat('b', 64))->exists());
    }

    public function test_dry_run_reports_expired_link_count_without_deleting_anything(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/example.png',
            'original_name' => 'example.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 123,
        ]);

        UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('c', 64),
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('ghost:laravel-uploads-clean --dry-run')
            ->expectsOutputToContain('1 expired Laravel Uploads links would be removed.')
            ->assertSuccessful();

        $this->assertSame(1, UploadLink::query()->count());
    }
}
