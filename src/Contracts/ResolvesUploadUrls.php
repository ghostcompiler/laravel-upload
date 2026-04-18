<?php

namespace GhostCompiler\LaravelUploads\Contracts;

use GhostCompiler\LaravelUploads\Models\Upload;

interface ResolvesUploadUrls
{
    public function url(?Upload $upload, ?int $expiry = null): ?string;
}
