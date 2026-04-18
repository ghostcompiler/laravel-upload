<?php

namespace GhostCompiler\LaravelUploads;

use GhostCompiler\LaravelUploads\Commands\CleanupExpiredLinksCommand;
use GhostCompiler\LaravelUploads\Commands\InstallCommand;
use GhostCompiler\LaravelUploads\Http\Controllers\UploadController;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class LaravelUploadsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-uploads.php', 'laravel-uploads');

        $this->app->singleton('laravel-uploads', fn () => new LaravelUploadsManager());
        $this->app->singleton(LaravelUploadsManager::class, fn ($app) => $app->make('laravel-uploads'));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/laravel-uploads.php' => config_path('laravel-uploads.php'),
        ], 'laravel-uploads-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_laravel_uploads_uploads_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_laravel_uploads_uploads_table.php'
            ),
            __DIR__.'/../database/migrations/create_laravel_uploads_links_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 1).'_create_laravel_uploads_links_table.php'
            ),
        ], 'laravel-uploads-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredLinksCommand::class,
                InstallCommand::class,
            ]);
        }

        Route::middleware(config('laravel-uploads.route.middleware', ['web']))
            ->prefix(config('laravel-uploads.route.prefix', '_laravel-uploads'))
            ->group(function (): void {
                Route::get('/file/{token}', [UploadController::class, 'show'])
                    ->name(config('laravel-uploads.route.name', 'laravel-uploads.show'));
            });
    }
}
