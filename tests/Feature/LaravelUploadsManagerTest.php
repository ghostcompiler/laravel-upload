<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use GhostCompiler\LaravelUploads\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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

    public function test_it_reuses_cached_urls_until_their_expiry(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.cache.enabled', true);

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('cached.pdf', 24, 'application/pdf')
        );

        $firstUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);
        $secondUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);

        $this->assertSame($firstUrl, $secondUrl);
        $this->assertSame(1, UploadLink::query()->count());

        $this->travel(16)->minutes();

        $thirdUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);

        $this->assertNotSame($firstUrl, $thirdUrl);
        $this->assertSame(2, UploadLink::query()->count());
    }

    public function test_generated_url_cache_registry_expires_instead_of_living_forever(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.cache.enabled', true);
        config()->set('laravel-uploads.cache.registry_ttl', 5);

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('registry.pdf', 24, 'application/pdf')
        );

        app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);
        $registryKey = "laravel-uploads:url-keys:{$upload->id}";

        $this->assertNotNull(cache()->get($registryKey));

        $this->travel(14)->minutes();
        $this->assertNotNull(cache()->get($registryKey));

        $this->travel(2)->minutes();
        $this->assertNull(cache()->get($registryKey));
    }

    public function test_it_can_disable_generated_url_caching(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.cache.enabled', false);

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('uncached.pdf', 24, 'application/pdf')
        );

        $firstUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);
        $secondUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);

        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertSame(2, UploadLink::query()->count());
    }

    public function test_public_upload_urls_use_the_disk_url_without_generating_links(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->image('public-avatar.png', 120, 120),
            [
                'visibility' => 'public',
            ]
        );

        $firstUrl = app(LaravelUploadsManager::class)->url($upload, 15);
        $secondUrl = app(LaravelUploadsManager::class)->urlFromId($upload->id, 15);

        $this->assertSame('public', $upload->visibility);
        $this->assertSame($firstUrl, $secondUrl);
        $this->assertStringContainsString($upload->path, $firstUrl);
        $this->assertSame(0, UploadLink::query()->count());
    }

    public function test_public_upload_urls_can_use_a_runtime_resolver_for_tenant_domains(): void
    {
        Storage::fake('local');

        $manager = app(LaravelUploadsManager::class);
        $manager->resolvePublicUrlsUsing(
            fn (Upload $upload, $disk, string $path): string => 'https://tenant-one.test/assets/'.$path
        );

        $upload = $manager->upload(
            'avatars',
            UploadedFile::fake()->image('tenant-avatar.png', 120, 120),
            [
                'visibility' => 'public',
            ]
        );

        $this->assertSame('https://tenant-one.test/assets/'.$upload->path, $manager->url($upload));
        $this->assertSame(0, UploadLink::query()->count());
    }

    public function test_public_upload_urls_can_use_a_configured_resolver_class(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.urls.public_resolver', TenantPublicUrlResolver::class);

        $upload = app(LaravelUploadsManager::class)->upload(
            'avatars',
            UploadedFile::fake()->image('tenant-avatar.png', 120, 120),
            [
                'visibility' => 'public',
            ]
        );

        $url = app(LaravelUploadsManager::class)->url($upload);

        $this->assertSame('https://configured-tenant.test/storage/'.$upload->path, $url);
        $this->assertSame(0, UploadLink::query()->count());
    }

    public function test_public_uploads_are_stored_with_public_visibility(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->image('public-file.png', 120, 120),
            [
                'visibility' => 'public',
            ]
        );

        $this->assertSame('public', Storage::disk('local')->getVisibility($upload->path));
    }

    public function test_it_clears_cached_urls_when_an_upload_is_removed(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.cache.enabled', true);

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('removed.pdf', 24, 'application/pdf')
        );

        $this->assertNotNull(app(LaravelUploadsManager::class)->urlFromId($upload->id, 15));

        $this->assertTrue(app(LaravelUploadsManager::class)->remove($upload->id));
        $this->assertNull(app(LaravelUploadsManager::class)->urlFromId($upload->id, 15));
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

    public function test_explicitly_allowed_excluded_extensions_bypass_global_allowlists(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.validation.allowed_mime_types', ['image/png']);
        config()->set('laravel-uploads.validation.allowed_extensions', ['png']);

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('script.sh', 1, 'text/x-shellscript'),
            'sh'
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

    public function test_upload_accepts_boolean_favicon_option_for_processing(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            'icons',
            UploadedFile::fake()->image('avatar.png', 120, 60),
            [
                'favicon' => true,
            ]
        );

        $this->assertSame('private', $upload->visibility);
        $this->assertSame('png', $upload->extension);
        $this->assertSame('favicon', $upload->metadata['compression']['variant']);
        $this->assertStringStartsWith('LaravelUploads/icons/', $upload->path);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_favicon_variant_keeps_existing_ico_uploads_without_conversion(): void
    {
        Storage::fake('local');

        $upload = app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->create('favicon.ico', 1, 'image/x-icon'),
            [
                'favicon' => true,
            ]
        );

        $this->assertSame('ico', $upload->extension);
        $this->assertFalse($upload->metadata['compression']['applied']);
        $this->assertSame('favicon', $upload->metadata['compression']['variant']);
        Storage::disk('local')->assertExists($upload->path);
    }

    public function test_favicon_variant_rejects_oversized_source_images_before_conversion(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.image_optimization.enabled', false);
        config()->set('laravel-uploads.image_optimization.max_input_width', 100);
        config()->set('laravel-uploads.image_optimization.max_input_height', 100);
        config()->set('laravel-uploads.image_optimization.max_input_pixels', 10000);

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('image dimensions exceed');

        app(LaravelUploadsManager::class)->upload(
            UploadedFile::fake()->image('favicon.png', 101, 100),
            [
                'favicon' => true,
            ]
        );
    }

    public function test_it_does_not_create_upload_records_when_storage_fails(): void
    {
        $manager = new class extends LaravelUploadsManager {
            protected function storePreparedFile(string $disk, string $path, mixed $stream, string $visibility = 'private'): void
            {
                throw new RuntimeException('Unable to store the prepared upload file.');
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to store the prepared upload file.');

        try {
            $manager->upload(
                UploadedFile::fake()->create('contract.pdf', 24, 'application/pdf')
            );
        } finally {
            $this->assertDatabaseCount('laravel_uploads_uploads', 0);
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

    public function test_it_rejects_failed_image_optimization_when_strict_mode_is_enabled(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.image_optimization.enabled', true);
        config()->set('laravel-uploads.image_optimization.strict', true);

        $manager = new class extends LaravelUploadsManager {
            protected function convertOptimizedImage(UploadedFile $file): ?array
            {
                return null;
            }
        };

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('strict image optimization is enabled');

        $manager->upload(
            UploadedFile::fake()->image('avatar.png', 120, 120)
        );
    }

    public function test_it_rejects_large_original_images_after_optimization_fallback(): void
    {
        Storage::fake('local');
        config()->set('laravel-uploads.image_optimization.enabled', true);
        config()->set('laravel-uploads.image_optimization.max_input_width', 0);
        config()->set('laravel-uploads.image_optimization.max_input_height', 0);
        config()->set('laravel-uploads.image_optimization.max_input_pixels', 0);
        config()->set('laravel-uploads.image_optimization.max_output_pixels', 100);

        $manager = new class extends LaravelUploadsManager {
            protected function convertOptimizedImage(UploadedFile $file): ?array
            {
                return null;
            }
        };

        $this->expectException(LaravelUploadsException::class);
        $this->expectExceptionMessage('output pixel limit');

        $manager->upload(
            UploadedFile::fake()->image('avatar.png', 11, 10)
        );
    }
}

class TenantPublicUrlResolver
{
    public function publicUrl(Upload $upload, $disk, string $path): string
    {
        return 'https://configured-tenant.test/storage/'.$path;
    }
}
