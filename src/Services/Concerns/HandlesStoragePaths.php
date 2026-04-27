<?php

namespace GhostCompiler\LaravelUploads\Services\Concerns;

use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use GhostCompiler\LaravelUploads\Models\Upload;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

trait HandlesStoragePaths
{
    protected function disk(): string
    {
        return (string) config('laravel-uploads.disk', config('filesystems.default', 'local'));
    }

    protected function directoryFor(?string $path = null): string
    {
        $basePath = $this->normalizeRelativePath((string) config('laravel-uploads.base_path', 'LaravelUploads'), 'base_path');
        $directory = $this->normalizeRelativePath((string) $path, 'upload path', true);

        return $directory !== '' ? "{$basePath}/{$directory}" : $basePath;
    }

    protected function normalizeRelativePath(string $path, string $label, bool $allowEmpty = false): string
    {
        $originalPath = $path;
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new LaravelUploadsException("LaravelUploads: Invalid {$label}.");
        }

        if (str_starts_with($originalPath, '/') || str_starts_with($originalPath, '\\') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $originalPath)) {
            throw new LaravelUploadsException("LaravelUploads: Unsafe {$label}.");
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new LaravelUploadsException("LaravelUploads: Unsafe {$label}.");
            }
        }

        return implode('/', $segments);
    }

    public function isSafeStoragePath(string $path): bool
    {
        try {
            $path = $this->normalizeRelativePath($path, 'storage path');
            $basePath = $this->normalizeRelativePath((string) config('laravel-uploads.base_path', 'LaravelUploads'), 'base_path');
        } catch (LaravelUploadsException) {
            return false;
        }

        return $path === $basePath || str_starts_with($path, "{$basePath}/");
    }

    public function isSafeUpload(Upload $upload, ?FilesystemAdapter $disk = null): bool
    {
        $disk ??= Storage::disk($upload->disk);

        return $this->isSafeStoragePath($upload->path)
            && $this->isContainedWithinBaseDirectory($disk, $upload->path);
    }

    protected function isContainedWithinBaseDirectory(FilesystemAdapter $disk, string $path): bool
    {
        if (! method_exists($disk, 'path')) {
            return true;
        }

        try {
            $basePath = $this->normalizeRelativePath((string) config('laravel-uploads.base_path', 'LaravelUploads'), 'base_path');
            $absoluteBasePath = $this->canonicalizeAbsolutePath($disk->path($basePath));
            $absoluteTargetPath = $this->canonicalizeAbsolutePath($disk->path($path));
        } catch (\Throwable) {
            return false;
        }

        return $absoluteTargetPath === $absoluteBasePath
            || str_starts_with($absoluteTargetPath, $absoluteBasePath.'/');
    }

    protected function canonicalizeAbsolutePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            $prefix = substr($normalized, 0, 2);
            $normalized = substr($normalized, 2);
        }

        if (str_starts_with($normalized, '/')) {
            $normalized = ltrim($normalized, '/');
            $prefix .= '/';
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return rtrim($prefix.implode('/', $segments), '/') ?: '/';
    }
}
