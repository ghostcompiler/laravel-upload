<?php

namespace GhostCompiler\LaravelUploads\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'ghost:laravel-uploads {--force : Overwrite existing Laravel Uploads files}';

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
        $migrations = [
            'create_laravel_uploads_uploads_table.php' => dirname(__DIR__, 2).'/database/migrations/create_laravel_uploads_uploads_table.php.stub',
            'create_laravel_uploads_links_table.php' => dirname(__DIR__, 2).'/database/migrations/create_laravel_uploads_links_table.php.stub',
        ];

        $timestamp = time();

        foreach ($migrations as $migrationName => $source) {
            $existingFiles = glob(database_path('migrations/*_'.$migrationName)) ?: [];

            if ($existingFiles !== [] && ! $this->shouldOverwrite("Migration [{$migrationName}] already exists", $force)) {
                $this->components->warn("Skipped migration {$migrationName}.");
                $timestamp++;

                continue;
            }

            foreach ($existingFiles as $existingFile) {
                $this->files->delete($existingFile);
            }

            $target = database_path('migrations/'.date('Y_m_d_His', $timestamp).'_'.$migrationName);

            $this->ensureDirectoryExists(dirname($target));
            $this->files->copy($source, $target);

            $this->components->info("Published {$migrationName}");
            $timestamp++;
        }
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
