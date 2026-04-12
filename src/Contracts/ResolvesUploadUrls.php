<?php

namespace GhostCompiler\UploadsManager\Contracts;

use GhostCompiler\UploadsManager\Models\Upload;

interface ResolvesUploadUrls
{
    public function url(?Upload $upload, ?int $expiry = null): ?string;
}
