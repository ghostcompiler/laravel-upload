<?php

use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;

if (! function_exists('GhostCompiler')) {
    function GhostCompiler(): LaravelUploadsManager
    {
        return app(LaravelUploadsManager::class);
    }
}
