<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Tests\TestCase;
use Illuminate\Support\Facades\Storage;

class UploadControllerTest extends TestCase
{
    public function test_private_file_route_has_no_middleware_by_default(): void
    {
        $route = app('router')->getRoutes()->getByName('laravel-uploads.show');

        $this->assertNotNull($route);
        $this->assertSame([], $route->gatherMiddleware());
    }

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
        $response->assertHeader('content-disposition', 'inline; filename="upload-1.txt"');
        $response->assertHeader('x-content-type-options', 'nosniff');
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
        $response->assertHeader('content-disposition', 'attachment; filename="upload-1.pdf"');
        $response->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_it_downloads_files_when_preview_config_is_missing(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.preview_mime_types', null);

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/readme-no-config.txt',
            'original_name' => 'readme-no-config.txt',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 12,
        ]);

        Storage::disk('local')->put($upload->path, 'hello world');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('n', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', ['token' => $link->token]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition') ?: '');
        $response->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_it_sanitizes_download_filenames_before_setting_content_disposition(): void
    {
        Storage::fake('local');

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/report.txt',
            'original_name' => "\";\r\nX-Injected: yes.txt",
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 24,
        ]);

        Storage::disk('local')->put($upload->path, 'safe');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('h', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', [
            'token' => $link->token,
            'download' => 1,
        ]));

        $response->assertOk();
        $this->assertStringNotContainsString("\r", $response->headers->get('content-disposition') ?: '');
        $this->assertStringNotContainsString("\n", $response->headers->get('content-disposition') ?: '');
        $this->assertFalse($response->headers->has('X-Injected'));
    }

    public function test_it_can_use_the_original_filename_when_explicitly_enabled(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.downloads.use_original_name', true);

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/original-name.pdf',
            'original_name' => 'Quarterly Report.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 24,
        ]);

        Storage::disk('local')->put($upload->path, 'fake-pdf');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('o', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', [
            'token' => $link->token,
            'download' => 1,
        ]));

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="Quarterly Report.pdf"');
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
            ->assertNotFound();
    }

    public function test_it_downloads_svg_files_instead_of_previewing_them_inline_by_default(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.preview_mime_types', [
            'image/avif',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ]);

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/icon.svg',
            'original_name' => 'icon.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'size' => 120,
        ]);

        Storage::disk('local')->put($upload->path, '<svg><script>alert(1)</script></svg>');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('s', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', ['token' => $link->token]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition') ?: '');
    }

    public function test_it_downloads_svg_files_even_when_svg_is_added_to_preview_config(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.preview_mime_types', [
            'image/svg+xml',
        ]);

        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => 'LaravelUploads/configured-icon.svg',
            'original_name' => 'configured-icon.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'size' => 120,
        ]);

        Storage::disk('local')->put($upload->path, '<svg><script>alert(1)</script></svg>');

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('g', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->get(route('laravel-uploads.show', ['token' => $link->token]));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition') ?: '');
    }

    public function test_it_rejects_unsafe_stored_paths(): void
    {
        $upload = Upload::query()->create([
            'disk' => 'local',
            'visibility' => 'private',
            'path' => '../.env',
            'original_name' => '.env',
            'mime_type' => 'text/plain',
            'extension' => 'txt',
            'size' => 120,
        ]);

        $link = UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => str_repeat('u', 64),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->get(route('laravel-uploads.show', ['token' => $link->token]))
            ->assertNotFound();
    }
}
