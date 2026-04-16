<?php

use GhostCompiler\UploadsManager\Services\UploadManager;

if (! function_exists('GhostCompiler')) {
    function GhostCompiler(): UploadManager
    {
        return app(UploadManager::class);
    }
}
