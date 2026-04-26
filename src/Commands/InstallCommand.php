<?php

namespace GhostCompiler\LaravelUploads\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'install:laravel-uploads {--force : Overwrite existing Laravel Uploads files}';

    protected $description = 'Publish the Laravel Uploads config and migration files';

    public function __construct(protected Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->publishConfig($force);
        $this->publishMigrations($force);

        $this->components->info('Laravel Uploads assets published successfully.');

        return self::SUCCESS;
    }

    protected function publishConfig(bool $force): void
    {
        $source = dirname(__DIR__, 2).'/config/laravel-uploads.php';
        $target = config_path('laravel-uploads.php');

        if ($this->files->exists($target) && ! $this->shouldOverwrite('Config file already exists', $force)) {
            $this->components->warn('Skipped config publishing.');

            return;
        }

        $this->ensureDirectoryExists(dirname($target));
        $this->files->copy($source, $target);

        $this->components->info('Published config/laravel-uploads.php');
    }

    protected function publishMigrations(bool $force): void
    {
        $migrationName = 'create_laravel_uploads_tables.php';
        $source = dirname(__DIR__, 2).'/database/migrations/create_laravel_uploads_tables.php.stub';
        $legacyMigrations = [
            'create_laravel_uploads_uploads_table.php',
            'create_laravel_uploads_links_table.php',
        ];

        $existingFiles = glob(database_path('migrations/*_'.$migrationName)) ?: [];

        foreach ($legacyMigrations as $legacyMigration) {
            $existingFiles = [
                ...$existingFiles,
                ...(glob(database_path('migrations/*_'.$legacyMigration)) ?: []),
            ];
        }

        $existingFiles = array_values(array_unique($existingFiles));

        if ($existingFiles !== [] && ! $this->shouldOverwrite("Migration [{$migrationName}] already exists", $force)) {
            $this->components->warn("Skipped migration {$migrationName}.");

            return;
        }

        foreach ($existingFiles as $existingFile) {
            $this->files->delete($existingFile);
        }

        $target = database_path('migrations/'.date('Y_m_d_His').'_'.$migrationName);

        $this->ensureDirectoryExists(dirname($target));
        $this->files->copy($source, $target);

        $this->components->info("Published {$migrationName}");
    }

    protected function shouldOverwrite(string $label, bool $force): bool
    {
        if ($force) {
            return true;
        }

        return $this->confirm("{$label}. Do you want to overwrite it?", false);
    }

    protected function ensureDirectoryExists(string $path): void
    {
        if (! $this->files->isDirectory($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }
}
