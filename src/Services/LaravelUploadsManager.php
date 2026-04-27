<?php

namespace GhostCompiler\LaravelUploads\Services;

use GhostCompiler\LaravelUploads\Contracts\ResolvesUploadUrls;
use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Services\Concerns\HandlesStoragePaths;
use GhostCompiler\LaravelUploads\Services\Concerns\HandlesUploadUrls;
use GhostCompiler\LaravelUploads\Services\Concerns\ValidatesUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LaravelUploadsManager implements ResolvesUploadUrls
{
    use HandlesStoragePaths;
    use HandlesUploadUrls;
    use ValidatesUploads;

    protected mixed $publicUrlResolver = null;

    public function upload(UploadedFile|string $pathOrFile, UploadedFile|array|string|null $file = null, array|string|null $options = []): Upload
    {
        [$path, $file, $options] = $this->parseUploadArguments($pathOrFile, $file, $options);
        $visibility = $this->resolveUploadVisibility($options);
        $this->validateUploadedFile($file, $options);
        $disk = $this->disk();
        $directory = $this->directoryFor($path);
        $prepared = $this->prepareFileForStorage($file, $options);
        $path = "{$directory}/{$prepared['name']}";
        $stream = fopen($prepared['real_path'], 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read the prepared upload file.');
        }

        try {
            $this->storePreparedFile($disk, $path, $stream, $visibility);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            if (($prepared['temporary'] ?? false) && is_file($prepared['real_path'])) {
                @unlink($prepared['real_path']);
            }
        }

        return Upload::query()->create([
            'disk' => $disk,
            'visibility' => $visibility,
            'path' => $path,
            'original_name' => $prepared['download_name'],
            'mime_type' => $prepared['mime_type'],
            'extension' => $prepared['extension'],
            'size' => $prepared['size'],
            'metadata' => [
                'uploaded_at' => now()?->toIso8601String(),
                'original_name' => $file->getClientOriginalName(),
                'original_extension' => $file->getClientOriginalExtension(),
                'original_mime_type' => $file->getClientMimeType(),
                'original_size' => $file->getSize(),
                'compression' => $prepared['compression'],
            ],
        ]);
    }

    public function uploadMany(array $files, ?string $path = null, array|string|null $options = []): array
    {
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw new LaravelUploadsException('LaravelUploads: Every file must be a valid uploaded file.');
            }
        }

        $uploads = [];

        foreach ($files as $file) {
            $uploads[] = $path === null
                ? $this->upload($file, $options)
                : $this->upload($path, $file, $options);
        }

        return $uploads;
    }

    public function url(?Upload $upload, ?int $expiry = null): ?string
    {
        if (! $upload) {
            return null;
        }

        return $this->resolveUrlForUploadId((int) $upload->getKey(), $expiry, $upload);
    }

    public function resolvePublicUrlsUsing(?callable $resolver): static
    {
        $this->publicUrlResolver = $resolver;

        return $this;
    }

    public function find(int|string|null $id): ?Upload
    {
        $id = $this->normalizeUploadId($id);

        if ($id === null) {
            return null;
        }

        return Upload::query()->find($id);
    }

    public function remove(Upload|int|string|null $upload): bool
    {
        $upload = $upload instanceof Upload ? $upload : $this->find($upload);

        if (! $upload) {
            return false;
        }

        $this->forgetCachedUrlsForUploadId((int) $upload->getKey());

        $disk = Storage::disk($upload->disk);

        if (! $this->isSafeUpload($upload, $disk)) {
            return false;
        }

        if ($disk->exists($upload->path)) {
            $disk->delete($upload->path);
        }

        UploadLink::query()->where('upload_id', $upload->id)->delete();

        return (bool) $upload->delete();
    }

    public function delete(Upload|int|string|null $upload): bool
    {
        return $this->remove($upload);
    }

    public function urlFromId(int|string|null $id, ?int $expiry = null): ?string
    {
        $id = $this->normalizeUploadId($id);

        if ($id === null) {
            return null;
        }

        return $this->resolveUrlForUploadId($id, $expiry);
    }

    protected function normalizeUploadId(int|string|null $id): ?int
    {
        if ($id === null) {
            return null;
        }

        if (is_string($id)) {
            $id = trim($id);

            if ($id === '' || ! ctype_digit($id)) {
                return null;
            }
        }

        return (int) $id;
    }

    protected function parseUploadArguments(UploadedFile|string $pathOrFile, UploadedFile|array|string|null $file = null, array|string|null $options = []): array
    {
        $options = $this->normalizeUploadOptions($options);

        if ($pathOrFile instanceof UploadedFile) {
            if (is_array($file)) {
                $options = $this->normalizeUploadOptions($file) + $options;
            }

            if (is_string($file)) {
                $options = $this->normalizeUploadOptions($file) + $options;
            }

            return [null, $pathOrFile, $options];
        }

        if (! $file instanceof UploadedFile) {
            $message = 'LaravelUploads: A valid uploaded file is required.';
            Log::error($message);

            throw new LaravelUploadsException($message);
        }

        return [$pathOrFile, $file, $options];
    }

    protected function normalizeUploadOptions(array|string|null $options): array
    {
        if ($options === null) {
            return [];
        }

        if (is_string($options)) {
            return [
                'allow_excluded_extensions' => [$options],
            ];
        }

        if (array_is_list($options)) {
            return [
                'allow_excluded_extensions' => $options,
            ];
        }

        return $options;
    }

    protected function defaultVisibility(): string
    {
        $visibility = strtolower(trim((string) config(
            'laravel-uploads.defaults.visibility',
            config('laravel-uploads.defaults.type', 'private')
        )));

        return in_array($visibility, ['public', 'private'], true) ? $visibility : 'private';
    }

    protected function resolveUploadVisibility(array $options): string
    {
        $visibility = strtolower(trim((string) ($options['visibility'] ?? '')));

        if ($visibility === '' && isset($options['type']) && in_array(strtolower(trim((string) $options['type'])), ['public', 'private'], true)) {
            $visibility = strtolower(trim((string) $options['type']));
        }

        return in_array($visibility, ['public', 'private'], true) ? $visibility : $this->defaultVisibility();
    }

    protected function resolveUploadVariant(array $options): ?string
    {
        if ((bool) ($options['favicon'] ?? false)) {
            return 'favicon';
        }

        $variant = strtolower(trim((string) ($options['variant'] ?? $options['format'] ?? '')));

        if ($variant === '' && isset($options['type'])) {
            $type = strtolower(trim((string) $options['type']));

            if ($type === 'favicon') {
                $variant = 'favicon';
            }
        }

        return $variant !== '' ? $variant : null;
    }

    protected function generateFilename(UploadedFile $file): string
    {
        $basename = Str::uuid()->toString();
        $extension = $file->getClientOriginalExtension();

        return $extension ? "{$basename}.{$extension}" : $basename;
    }

    protected function storePreparedFile(string $disk, string $path, mixed $stream, string $visibility = 'private'): void
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to read the prepared upload file.');
        }

        $stored = Storage::disk($disk)->put($path, $stream, $visibility);

        if ($stored === false) {
            throw new RuntimeException('Unable to store the prepared upload file.');
        }
    }

    protected function prepareFileForStorage(UploadedFile $file, array $options = []): array
    {
        if ($this->resolveUploadVariant($options) === 'favicon') {
            return $this->prepareFaviconForStorage($file);
        }

        if (! $this->shouldCompressImages() || ! $this->isCompressibleImage($file)) {
            return $this->prepareOriginalFileForStorage($file);
        }

        $converted = $this->convertOptimizedImage($file);

        if ($converted === null) {
            if ($this->strictImageOptimization()) {
                throw new LaravelUploadsException('LaravelUploads: Image optimization failed and strict image optimization is enabled.');
            }

            $this->validateFallbackImageForStorage($file);

            return $this->prepareOriginalFileForStorage($file, [
                'enabled' => true,
                'applied' => false,
                'fallback' => true,
            ]);
        }

        $downloadBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $extension = $converted['extension'];

        return [
            'name' => Str::uuid()->toString().'.'.$extension,
            'download_name' => $downloadBaseName !== '' ? "{$downloadBaseName}.{$extension}" : Str::uuid()->toString().'.'.$extension,
            'real_path' => $converted['path'],
            'mime_type' => $converted['mime_type'],
            'extension' => $extension,
            'size' => filesize($converted['path']) ?: $file->getSize(),
            'temporary' => true,
            'compression' => $converted['compression'] + [
                'enabled' => true,
                'applied' => true,
                'quality' => $this->compressionQuality(),
            ],
        ];
    }

    protected function prepareOriginalFileForStorage(UploadedFile $file, array $compression = []): array
    {
        $realPath = $file->getRealPath();

        if (! is_string($realPath) || trim($realPath) === '' || ! is_file($realPath)) {
            $message = 'LaravelUploads: Unable to read the uploaded file from its temporary path.';
            Log::error($message);

            throw new LaravelUploadsException($message);
        }

        $size = filesize($realPath) ?: $file->getSize();

        return [
            'name' => $this->generateFilename($file),
            'download_name' => $file->getClientOriginalName(),
            'real_path' => $realPath,
            'mime_type' => $this->detectMimeType($file) ?: 'application/octet-stream',
            'extension' => $file->getClientOriginalExtension(),
            'size' => $size,
            'temporary' => false,
            'compression' => $compression + [
                'enabled' => false,
                'applied' => false,
            ],
        ];
    }

    protected function shouldCompressImages(): bool
    {
        return (bool) config('laravel-uploads.image_optimization.enabled', false);
    }

    protected function strictImageOptimization(): bool
    {
        return (bool) config('laravel-uploads.image_optimization.strict', false);
    }

    protected function compressionQuality(): int
    {
        return max(1, min(100, (int) config('laravel-uploads.image_optimization.quality', 75)));
    }

    protected function isCompressibleImage(UploadedFile $file): bool
    {
        $mimeType = $this->detectMimeType($file);

        return in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }

    protected function convertOptimizedImage(UploadedFile $file): ?array
    {
        if (! (bool) config('laravel-uploads.image_optimization.convert_to_avif', true)) {
            $converted = $this->convertImageToWebp($file);

            if ($converted === null) {
                Log::warning('LaravelUploads: Image optimization skipped. WEBP conversion is unavailable. '.$this->webpSupportMessage());
            }

            return $converted;
        }

        $converted = $this->convertImageToAvif($file);

        if ($converted !== null) {
            return $converted;
        }

        $converted = $this->convertImageToWebp($file);

        if ($converted !== null) {
            Log::warning('LaravelUploads: AVIF conversion unavailable. Falling back to WEBP.');

            return $converted;
        }

        Log::warning('LaravelUploads: Image optimization skipped. AVIF and WEBP conversion are unavailable. '.$this->avifSupportMessage().' '.$this->webpSupportMessage());

        return null;
    }

    protected function validateFallbackImageForStorage(UploadedFile $file): void
    {
        $maxOutputPixels = (int) config('laravel-uploads.image_optimization.max_output_pixels', 8000000);

        if ($maxOutputPixels <= 0) {
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

        if (($width * $height) > $maxOutputPixels) {
            throw new LaravelUploadsException('LaravelUploads: Original image exceeds the configured output pixel limit after optimization fallback.');
        }
    }

    protected function convertImageToAvif(UploadedFile $file): ?array
    {
        if (! (bool) config('laravel-uploads.image_optimization.convert_to_avif', true)) {
            return null;
        }

        $converted = $this->convertImageToAvifUsingGd($file);

        if ($converted !== null) {
            return $converted;
        }

        $converted = $this->convertImageToAvifUsingImagick($file);

        if ($converted !== null) {
            return $converted;
        }

        Log::warning('LaravelUploads: AVIF conversion is unavailable. '.$this->avifSupportMessage());

        return null;
    }

    protected function convertImageToAvifUsingGd(UploadedFile $file): ?array
    {
        if (! function_exists('imageavif')) {
            return null;
        }

        $source = $this->createImageResource($file);

        if (! $source) {
            return null;
        }

        $dimensions = $this->resizeGdImageResource($source);
        $source = $dimensions['resource'];

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laravel-uploads-avif-');

        if ($temporaryFile === false) {
            imagedestroy($source);

            $message = 'LaravelUploads: Unable to create a temporary file for AVIF conversion.';
            Log::error($message);

            return null;
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $saved = imageavif($source, $temporaryFile, $this->compressionQuality());

        imagedestroy($source);

        if (! $saved || ! is_file($temporaryFile)) {
            @unlink($temporaryFile);

            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $temporaryFile,
            'mime_type' => 'image/avif',
            'extension' => 'avif',
            'compression' => [
                'converted_to' => 'avif',
                'driver' => 'gd',
                'resized' => $dimensions['resized'],
                'original_width' => $dimensions['original_width'],
                'original_height' => $dimensions['original_height'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ],
        ];
    }

    protected function convertImageToAvifUsingImagick(UploadedFile $file): ?array
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        if (! $this->imagickSupportsAvif()) {
            return null;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laravel-uploads-avif-');

        if ($temporaryFile === false) {
            $message = 'LaravelUploads: Unable to create a temporary file for AVIF conversion.';
            Log::error($message);

            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImage($file->getRealPath());
            $dimensions = $this->resizeImagickImage($imagick);
            $imagick->setImageFormat('AVIF');
            $imagick->setImageCompressionQuality($this->compressionQuality());
            $imagick->writeImage($temporaryFile);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $exception) {
            @unlink($temporaryFile);

            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image with Imagick. '.$exception->getMessage();
            Log::error($message);

            return null;
        }

        if (! is_file($temporaryFile)) {
            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image with Imagick.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $temporaryFile,
            'mime_type' => 'image/avif',
            'extension' => 'avif',
            'compression' => [
                'converted_to' => 'avif',
                'driver' => 'imagick',
                'resized' => $dimensions['resized'] ?? false,
                'original_width' => $dimensions['original_width'] ?? null,
                'original_height' => $dimensions['original_height'] ?? null,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
            ],
        ];
    }

    protected function convertImageToWebp(UploadedFile $file): ?array
    {
        $converted = $this->convertImageToWebpUsingGd($file);

        if ($converted !== null) {
            return $converted;
        }

        $converted = $this->convertImageToWebpUsingImagick($file);

        if ($converted !== null) {
            return $converted;
        }

        return null;
    }

    protected function convertImageToWebpUsingGd(UploadedFile $file): ?array
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $source = $this->createImageResource($file);

        if (! $source) {
            return null;
        }

        $dimensions = $this->resizeGdImageResource($source);
        $source = $dimensions['resource'];

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laravel-uploads-webp-');

        if ($temporaryFile === false) {
            imagedestroy($source);

            $message = 'LaravelUploads: Unable to create a temporary file for WEBP conversion.';
            Log::error($message);

            return null;
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $saved = imagewebp($source, $temporaryFile, $this->compressionQuality());

        imagedestroy($source);

        if (! $saved || ! is_file($temporaryFile)) {
            @unlink($temporaryFile);

            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $temporaryFile,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'compression' => [
                'converted_to' => 'webp',
                'driver' => 'gd',
                'resized' => $dimensions['resized'],
                'original_width' => $dimensions['original_width'],
                'original_height' => $dimensions['original_height'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
            ],
        ];
    }

    protected function convertImageToWebpUsingImagick(UploadedFile $file): ?array
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        if (! $this->imagickSupportsWebp()) {
            return null;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laravel-uploads-webp-');

        if ($temporaryFile === false) {
            $message = 'LaravelUploads: Unable to create a temporary file for WEBP conversion.';
            Log::error($message);

            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImage($file->getRealPath());
            $dimensions = $this->resizeImagickImage($imagick);
            $imagick->setImageFormat('WEBP');
            $imagick->setImageCompressionQuality($this->compressionQuality());
            $imagick->writeImage($temporaryFile);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $exception) {
            @unlink($temporaryFile);

            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image with Imagick. '.$exception->getMessage();
            Log::error($message);

            return null;
        }

        if (! is_file($temporaryFile)) {
            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image with Imagick.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $temporaryFile,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'compression' => [
                'converted_to' => 'webp',
                'driver' => 'imagick',
                'resized' => $dimensions['resized'] ?? false,
                'original_width' => $dimensions['original_width'] ?? null,
                'original_height' => $dimensions['original_height'] ?? null,
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
            ],
        ];
    }

    protected function createImageResource(UploadedFile $file): mixed
    {
        $mimeType = $this->detectMimeType($file);
        $path = $file->getRealPath();

        return match ($mimeType) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : null,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : null,
            default => null,
        };
    }

    protected function imageResourceFailureMessage(UploadedFile $file): string
    {
        $mimeType = $this->detectMimeType($file);

        return match ($mimeType) {
            'image/jpeg' => function_exists('imagecreatefromjpeg')
                ? 'AVIF conversion failed: could not read the JPEG image.'
                : 'PHP image support not installed: GD JPEG loader missing (imagecreatefromjpeg).',
            'image/png' => function_exists('imagecreatefrompng')
                ? 'AVIF conversion failed: could not read the PNG image.'
                : 'PHP image support not installed: GD PNG loader missing (imagecreatefrompng).',
            'image/webp' => function_exists('imagecreatefromwebp')
                ? 'AVIF conversion failed: could not read the WEBP image.'
                : 'PHP image support not installed: GD WEBP loader missing (imagecreatefromwebp).',
            default => sprintf(
                'AVIF conversion failed: unsupported source mime type [%s].',
                $mimeType ?: 'unknown'
            ),
        };
    }

    protected function imagickSupportsAvif(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $formats = \Imagick::queryFormats('AVIF');
        } catch (\Throwable) {
            return false;
        }

        return $formats !== false && $formats !== [];
    }

    protected function imagickSupportsWebp(): bool
    {
        if (! class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $formats = \Imagick::queryFormats('WEBP');
        } catch (\Throwable) {
            return false;
        }

        return $formats !== false && $formats !== [];
    }

    protected function resizeGdImageResource(mixed $source): array
    {
        $originalWidth = imagesx($source);
        $originalHeight = imagesy($source);
        [$targetWidth, $targetHeight] = $this->targetImageDimensions($originalWidth, $originalHeight);

        if ($targetWidth === $originalWidth && $targetHeight === $originalHeight) {
            return [
                'resource' => $source,
                'resized' => false,
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        if (! $this->canAllocateImagePixels($targetWidth, $targetHeight)) {
            return [
                'resource' => $source,
                'resized' => false,
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($resized === false) {
            return [
                'resource' => $source,
                'resized' => false,
                'original_width' => $originalWidth,
                'original_height' => $originalHeight,
                'width' => $originalWidth,
                'height' => $originalHeight,
            ];
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);

        imagecopyresampled(
            $resized,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $originalWidth,
            $originalHeight
        );

        imagedestroy($source);

        return [
            'resource' => $resized,
            'resized' => true,
            'original_width' => $originalWidth,
            'original_height' => $originalHeight,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    protected function resizeImagickImage(\Imagick $imagick): array
    {
        $originalWidth = $imagick->getImageWidth();
        $originalHeight = $imagick->getImageHeight();
        [$targetWidth, $targetHeight] = $this->targetImageDimensions($originalWidth, $originalHeight);

        if ($targetWidth !== $originalWidth || $targetHeight !== $originalHeight) {
            if (! $this->canAllocateImagePixels($targetWidth, $targetHeight)) {
                return [
                    'resized' => false,
                    'original_width' => $originalWidth,
                    'original_height' => $originalHeight,
                    'width' => $originalWidth,
                    'height' => $originalHeight,
                ];
            }

            $imagick->thumbnailImage($targetWidth, $targetHeight, true, false);
        }

        return [
            'resized' => $targetWidth !== $originalWidth || $targetHeight !== $originalHeight,
            'original_width' => $originalWidth,
            'original_height' => $originalHeight,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }

    protected function targetImageDimensions(int $width, int $height): array
    {
        $maxWidth = $this->maxResizeWidth();
        $maxHeight = $this->maxResizeHeight();

        if ($width <= 0 || $height <= 0) {
            return [$width, $height];
        }

        if (! $maxWidth && ! $maxHeight) {
            return [$width, $height];
        }

        $widthRatio = $maxWidth ? $maxWidth / $width : null;
        $heightRatio = $maxHeight ? $maxHeight / $height : null;

        $ratio = match (true) {
            $widthRatio !== null && $heightRatio !== null => min($widthRatio, $heightRatio, 1),
            $widthRatio !== null => min($widthRatio, 1),
            $heightRatio !== null => min($heightRatio, 1),
            default => 1,
        };

        $targetWidth = max(1, (int) round($width * $ratio));
        $targetHeight = max(1, (int) round($height * $ratio));

        return [$targetWidth, $targetHeight];
    }

    protected function canAllocateImagePixels(int $width, int $height): bool
    {
        $maxOutputPixels = (int) config('laravel-uploads.image_optimization.max_output_pixels', 8000000);

        if ($maxOutputPixels > 0 && ($width * $height) > $maxOutputPixels) {
            return false;
        }

        $memoryLimit = $this->memoryLimitInBytes();

        if ($memoryLimit === null) {
            return true;
        }

        $availableMemory = $memoryLimit - memory_get_usage(true);
        $requiredMemory = $width * $height * 8;

        return $availableMemory > 0 && $requiredMemory < ($availableMemory * 0.8);
    }

    protected function memoryLimitInBytes(): ?int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return null;
        }

        $unit = strtolower(substr($value, -1));
        $bytes = (int) $value;

        return match ($unit) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    protected function maxResizeWidth(): ?int
    {
        $value = (int) config('laravel-uploads.image_optimization.max_width');

        return $value > 0 ? $value : null;
    }

    protected function maxResizeHeight(): ?int
    {
        $value = (int) config('laravel-uploads.image_optimization.max_height');

        return $value > 0 ? $value : null;
    }

    protected function isSupportedFaviconSource(UploadedFile $file, ?string $mimeType = null, ?string $extension = null): bool
    {
        $mimeType ??= $this->detectMimeType($file);
        $extension ??= strtolower((string) $file->getClientOriginalExtension());

        return in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/x-icon',
            'image/vnd.microsoft.icon',
        ], true) || in_array($extension, ['ico'], true);
    }

    protected function prepareFaviconForStorage(UploadedFile $file): array
    {
        if ($this->isExistingFaviconFile($file)) {
            return $this->prepareOriginalFileForStorage($file, [
                'enabled' => false,
                'applied' => false,
                'variant' => 'favicon',
                'favicon_source' => 'original',
            ]);
        }

        $source = $this->createImageResource($file);

        if (! $source) {
            throw new LaravelUploadsException('LaravelUploads: Unable to convert the uploaded image into a favicon.');
        }

        $size = max(16, (int) config('laravel-uploads.favicon.size', 64));

        if (! $this->canAllocateImagePixels($size, $size)) {
            imagedestroy($source);

            throw new LaravelUploadsException('LaravelUploads: Unable to allocate enough memory for favicon conversion.');
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($size / max(1, $sourceWidth), $size / max(1, $sourceHeight), 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $targetX = (int) floor(($size - $targetWidth) / 2);
        $targetY = (int) floor(($size - $targetHeight) / 2);
        $canvas = imagecreatetruecolor($size, $size);

        if ($canvas === false) {
            imagedestroy($source);

            throw new LaravelUploadsException('LaravelUploads: Unable to allocate a favicon canvas.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight
        );

        imagedestroy($source);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laravel-uploads-favicon-');

        if ($temporaryFile === false) {
            imagedestroy($canvas);

            throw new LaravelUploadsException('LaravelUploads: Unable to create a temporary file for favicon conversion.');
        }

        $saved = imagepng($canvas, $temporaryFile, 9);

        imagedestroy($canvas);

        if (! $saved || ! is_file($temporaryFile)) {
            @unlink($temporaryFile);

            throw new LaravelUploadsException('LaravelUploads: Favicon conversion failed while encoding the uploaded image.');
        }

        $downloadBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return [
            'name' => Str::uuid()->toString().'.png',
            'download_name' => ($downloadBaseName !== '' ? $downloadBaseName : 'favicon').'.png',
            'real_path' => $temporaryFile,
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => filesize($temporaryFile) ?: $file->getSize(),
            'temporary' => true,
            'compression' => [
                'enabled' => true,
                'applied' => true,
                'variant' => 'favicon',
                'driver' => 'gd',
                'width' => $size,
                'height' => $size,
            ],
        ];
    }

    protected function isExistingFaviconFile(UploadedFile $file): bool
    {
        $mimeType = $this->detectMimeType($file);
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return in_array($mimeType, ['image/x-icon', 'image/vnd.microsoft.icon'], true)
            || $extension === 'ico';
    }

    protected function avifSupportMessage(): string
    {
        $reasons = [];

        if (! function_exists('imageavif')) {
            $reasons[] = 'Install ext-gd with AVIF support (missing imageavif).';
        }

        if (! class_exists(\Imagick::class)) {
            $reasons[] = 'Install ext-imagick for Imagick fallback support.';
        } elseif (! $this->imagickSupportsAvif()) {
            $reasons[] = 'Your Imagick/ImageMagick build does not support AVIF.';
        }

        return implode(' ', array_unique($reasons));
    }

    protected function webpSupportMessage(): string
    {
        $reasons = [];

        if (! function_exists('imagewebp')) {
            $reasons[] = 'Install ext-gd with WEBP support (missing imagewebp).';
        }

        if (! class_exists(\Imagick::class)) {
            $reasons[] = 'Install ext-imagick for Imagick fallback support.';
        } elseif (! $this->imagickSupportsWebp()) {
            $reasons[] = 'Your Imagick/ImageMagick build does not support WEBP.';
        }

        return implode(' ', array_unique($reasons));
    }

}
