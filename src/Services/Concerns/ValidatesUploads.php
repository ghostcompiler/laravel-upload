<?php

namespace GhostCompiler\LaravelUploads\Services\Concerns;

use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use Illuminate\Http\UploadedFile;

trait ValidatesUploads
{
    protected function validateUploadedFile(UploadedFile $file, array $options = []): void
    {
        $this->validateUploadSize($file);
        $this->validateUploadType($file, $options);
        $this->validateImageDimensions($file, $options);
    }

    protected function validateUploadSize(UploadedFile $file): void
    {
        $maxSize = config('laravel-uploads.validation.max_size');

        if ($maxSize === null) {
            return;
        }

        $maxSize = (int) $maxSize;

        if ($maxSize > 0 && (int) $file->getSize() > $maxSize) {
            throw new LaravelUploadsException("LaravelUploads: Uploaded file exceeds the maximum size of {$maxSize} bytes.");
        }
    }

    protected function validateUploadType(UploadedFile $file, array $options = []): void
    {
        $mimeType = $this->detectMimeType($file);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $allowedMimeTypes = $this->normalizeConfigList('laravel-uploads.validation.allowed_mime_types', $options['allowed_mime_types'] ?? null);
        $allowedExtensions = $this->normalizeConfigList('laravel-uploads.validation.allowed_extensions', $options['allowed_extensions'] ?? null);
        $excludedMimeTypes = $this->normalizeConfigList('laravel-uploads.validation.excluded_mime_types', $options['excluded_mime_types'] ?? null);
        $excludedExtensions = $this->normalizeConfigList('laravel-uploads.validation.excluded_extensions', $options['excluded_extensions'] ?? null);
        $neverAllowedExtensions = $this->neverAllowedExtensions();
        $allowedExcludedExtensions = $this->normalizeValueList($options['allow_excluded_extensions'] ?? []);
        $allowsExcludedExtension = $extension !== '' && in_array($extension, $allowedExcludedExtensions, true);

        if ($extension !== '' && in_array($extension, $neverAllowedExtensions, true)) {
            throw new LaravelUploadsException("LaravelUploads: Uploads with extension [{$extension}] are never allowed.");
        }

        if (! $allowsExcludedExtension && $mimeType !== '' && in_array($mimeType, $excludedMimeTypes, true)) {
            throw new LaravelUploadsException("LaravelUploads: Uploads with mime type [{$mimeType}] are excluded.");
        }

        if (! $allowsExcludedExtension && $extension !== '' && in_array($extension, $excludedExtensions, true)) {
            throw new LaravelUploadsException("LaravelUploads: Uploads with extension [{$extension}] are excluded.");
        }

        if (! $allowsExcludedExtension && $allowedMimeTypes !== [] && ($mimeType === '' || ! in_array($mimeType, $allowedMimeTypes, true))) {
            throw new LaravelUploadsException("LaravelUploads: Uploads with mime type [{$mimeType}] are not allowed.");
        }

        if (! $allowsExcludedExtension && $allowedExtensions !== [] && ($extension === '' || ! in_array($extension, $allowedExtensions, true))) {
            throw new LaravelUploadsException("LaravelUploads: Uploads with extension [{$extension}] are not allowed.");
        }

        if ($this->resolveUploadVariant($options) === 'favicon' && ! $this->isSupportedFaviconSource($file, $mimeType, $extension)) {
            throw new LaravelUploadsException('LaravelUploads: Favicon uploads must be ICO, PNG, JPEG, or WEBP images.');
        }
    }

    protected function validateImageDimensions(UploadedFile $file, array $options = []): void
    {
        if (
            ! $this->isCompressibleImage($file)
            || (! $this->shouldCompressImages() && $this->resolveUploadVariant($options) !== 'favicon')
        ) {
            return;
        }

        $realPath = $file->getRealPath();

        if (! is_string($realPath) || ! is_file($realPath)) {
            return;
        }

        $dimensions = @getimagesize($realPath);

        if ($dimensions === false) {
            return;
        }

        [$width, $height] = $dimensions;
        $maxWidth = (int) config('laravel-uploads.image_optimization.max_input_width', 8000);
        $maxHeight = (int) config('laravel-uploads.image_optimization.max_input_height', 8000);
        $maxPixels = (int) config('laravel-uploads.image_optimization.max_input_pixels', 20000000);
        $pixels = $width * $height;

        if (($maxWidth > 0 && $width > $maxWidth) || ($maxHeight > 0 && $height > $maxHeight) || ($maxPixels > 0 && $pixels > $maxPixels)) {
            throw new LaravelUploadsException('LaravelUploads: Uploaded image dimensions exceed the configured safety limits.');
        }
    }

    protected function normalizeConfigList(string $key, mixed $override = null): array
    {
        $values = $override ?? config($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $values
        ), fn ($value) => $value !== ''));
    }

    protected function neverAllowedExtensions(): array
    {
        return array_values(array_unique([
            ...$this->normalizeConfigList('laravel-uploads.validation.never_allowed_extensions'),
            'phar',
            'php',
            'php3',
            'php4',
            'php5',
            'phtml',
        ]));
    }

    protected function normalizeValueList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($value) => strtolower(trim((string) $value)),
            $values
        ), fn ($value) => $value !== ''));
    }

    protected function detectMimeType(UploadedFile $file): ?string
    {
        $mimeType = strtolower(trim((string) $file->getMimeType()));

        return $mimeType !== '' ? $mimeType : null;
    }
}
