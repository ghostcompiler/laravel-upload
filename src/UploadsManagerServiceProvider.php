<?php

namespace GhostCompiler\UploadsManager;

use GhostCompiler\UploadsManager\Commands\CleanupExpiredLinksCommand;
use GhostCompiler\UploadsManager\Commands\InstallCommand;
use GhostCompiler\UploadsManager\Http\Controllers\UploadController;
use GhostCompiler\UploadsManager\Services\UploadManager;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class UploadsManagerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uploads-manager.php', 'uploads-manager');

        $this->app->singleton('uploads-manager', fn () => new UploadManager());
        $this->app->singleton(UploadManager::class, fn ($app) => $app->make('uploads-manager'));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/uploads-manager.php' => config_path('uploads-manager.php'),
        ], 'uploads-manager-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_uploads_manager_uploads_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His').'_create_uploads_manager_uploads_table.php'
            ),
            __DIR__.'/../database/migrations/create_uploads_manager_links_table.php.stub' => database_path(
                'migrations/'.date('Y_m_d_His', time() + 1).'_create_uploads_manager_links_table.php'
            ),
        ], 'uploads-manager-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanupExpiredLinksCommand::class,
                InstallCommand::class,
            ]);
        }

        Route::middleware(config('uploads-manager.route.middleware', ['web']))
            ->prefix(config('uploads-manager.route.prefix', '_uploads-manager'))
            ->group(function (): void {
                Route::get('/file/{token}', [UploadController::class, 'show'])
                    ->name(config('uploads-manager.route.name', 'uploads-manager.show'));
            });
    }
}
