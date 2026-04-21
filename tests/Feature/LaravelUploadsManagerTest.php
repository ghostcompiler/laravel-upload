<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
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

    public function test_it_rejects_excluded_file_extensions_by_default(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('are never allowed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('shell.php', 1, 'text/x-php')
        );
    }

    public function test_it_can_allow_a_specific_excluded_extension_when_explicitly_requested(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
            'sh'
        );

        $this->assertSame('sh', $upload->extension);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_it_can_allow_multiple_specific_excluded_extensions_when_explicitly_requested(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
            ['sh', 'rb']
        );

        $this->assertSame('sh', $upload->extension);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_it_never_allows_critical_excluded_extensions(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('extension [php] are never allowed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('shell.php', 1, 'text/x-php'),
            'php'
        );
    }

    public function test_it_never_allows_critical_extensions_even_when_they_are_in_an_allowed_array(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('extension [php] are never allowed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('shell.php', 1, 'text/x-php'),
            ['sh', 'php']
        );
    }

    public function test_it_never_allows_critical_extensions_even_when_options_try_to_override_the_critical_list(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('extension [php] are never allowed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('shell.php', 1, 'text/x-php'),
            [
                'allow_excluded_extensions' => ['php'],
                'never_allowed_extensions' => [],
            ]
        );
    }

    public function test_it_only_allows_the_requested_excluded_extension(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('are excluded');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
            'rb'
        );
    }

    public function test_it_can_allow_a_specific_excluded_extension_with_a_custom_path(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            'trusted/path',
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
            'sh'
        );

        $this->assertSame('sh', $upload->extension);
        $this->assertStringStartsWith('LaravelUploads/trusted/path/', $upload->path);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_it_can_upload_many_files(): void
    {
        Storage::fake('local');

        $uploads = app(LaravelUploadsManager::class)->uploadMany([
            UploadedFile::fake()->create('first.pdf', 1, 'application/pdf'),
            UploadedFile::fake()->create('second.txt', 1, 'text/plain'),
        ], 'documents');

        $this->assertCount(2, $uploads);
        $this->assertContainsOnlyInstancesOf(Upload::class, $uploads);

        foreach ($uploads as $upload) {
            $this->assertStringStartsWith('LaravelUploads/documents/', $upload->path);
            Storage::disk('local')->assertExists($upload->path);
        }
    }

    public function test_it_rejects_upload_paths_with_traversal_segments(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('Unsafe upload path');

        app(LaravelUploadsManager::class)->upload(
            '../outside',
            UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')
        );
    }

    public function test_it_rejects_absolute_upload_paths(): void
    {
        Storage::fake('local');

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('Unsafe upload path');

        app(LaravelUploadsManager::class)->upload(
            '/tmp/outside',
            UploadedFile::fake()->create('document.pdf', 1, 'application/pdf')
        );
    }

    public function test_it_rejects_files_larger_than_the_configured_max_size(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.validation.max_size', 1024);

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('maximum size of 1024 bytes');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('large.pdf', 2, 'application/pdf')
        );
    }

    public function test_it_rejects_images_that_exceed_configured_processing_limits(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.image_optimization.enabled', true);
        config()->set('laravel-uploads.image_optimization.max_input_width', 100);
        config()->set('laravel-uploads.image_optimization.max_input_height', 100);
        config()->set('laravel-uploads.image_optimization.max_input_pixels', 10000);

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('image dimensions exceed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->image('huge.png', 101, 100)
        );
    }
}
