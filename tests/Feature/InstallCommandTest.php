<?php

namespace GhostCompiler\LaravelUploads\Tests\Feature;

use GhostCompiler\LaravelUploads\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_publishes_config_and_migration_stubs(): void
    {
        $this->artisan('install:laravel-uploads --force')
            ->expectsOutputToContain('Published config/laravel-uploads.php')
            ->expectsOutputToContain('Published create_laravel_uploads_tables.php')
            ->expectsOutputToContain('Laravel Uploads assets published successfully.')
            ->assertSuccessful();

        $this->assertFileExists(config_path('laravel-uploads.php'));
        $this->assertCount(1, glob(database_path('migrations/*_create_laravel_uploads_tables.php')) ?: []);
    }
}
