<?php

namespace GhostCompiler\LaravelUploads\Services;

use GhostCompiler\LaravelUploads\Contracts\ResolvesUploadUrls;
use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LaravelUploadsManager implements ResolvesUploadUrls
{
    public function upload(UploadedFile|string $pathOrFile, ?UploadedFile $file = null): Upload
    {
        [$path, $file] = $this->parseUploadArguments($pathOrFile, $file);
        $visibility = $this->defaultVisibility();
        $disk = $this->disk();
        $directory = $this->directoryFor($path);
        $prepared = $this->prepareFileForStorage($file);
        $path = "{$directory}/{$prepared['name']}";
        $stream = fopen($prepared['real_path'], 'rb');

        if ($stream === false) {
            throw new RuntimeException('Unable to read the prepared upload file.');
        }

        Storage::disk($disk)->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        if (($prepared['temporary'] ?? false) && is_file($prepared['real_path'])) {
            @unlink($prepared['real_path']);
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

    public function url(?Upload $upload, ?int $expiry = null): ?string
    {
        if (! $upload) {
            return null;
        }

        return $this->createLink($upload, $expiry)->url();
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

        $disk = Storage::disk($upload->disk);

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
        $upload = $this->find($id);

        if (! $upload) {
            return null;
        }

        return $this->createLink($upload, $expiry)->url();
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

    protected function parseUploadArguments(UploadedFile|string $pathOrFile, ?UploadedFile $file = null): array
    {
        if ($pathOrFile instanceof UploadedFile) {
            return [null, $pathOrFile];
        }

        if (! $file instanceof UploadedFile) {
            $message = 'LaravelUploads: A valid uploaded file is required.';
            Log::error($message);

            throw new LaravelUploadsException($message);
        }

        return [$pathOrFile, $file];
    }

    protected function defaultVisibility(): string
    {
        $visibility = strtolower(trim((string) config('laravel-uploads.defaults.type', 'private')));

        return in_array($visibility, ['public', 'private'], true) ? $visibility : 'private';
    }

    protected function disk(): string
    {
        return (string) config('laravel-uploads.disk', config('filesystems.default', 'local'));
    }

    protected function directoryFor(?string $path = null): string
    {
        $basePath = trim((string) config('laravel-uploads.base_path', 'LaravelUploads'), '/');
        $directory = trim((string) $path, '/');

        return $directory !== '' ? "{$basePath}/{$directory}" : $basePath;
    }

    protected function generateFilename(UploadedFile $file): string
    {
        $basename = Str::uuid()->toString();
        $extension = $file->getClientOriginalExtension();

        return $extension ? "{$basename}.{$extension}" : $basename;
    }

    protected function prepareFileForStorage(UploadedFile $file): array
    {
        if (! $this->shouldCompressImages() || ! $this->isCompressibleImage($file)) {
            return $this->prepareOriginalFileForStorage($file);
        }

        $converted = $this->convertOptimizedImage($file);

        if ($converted === null) {
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
            'mime_type' => $file->getClientMimeType(),
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

    protected function compressionQuality(): int
    {
        return max(1, min(100, (int) config('laravel-uploads.image_optimization.quality', 75)));
    }

    protected function isCompressibleImage(UploadedFile $file): bool
    {
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();

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

        $avifPath = $temporaryFile.'.avif';
        @unlink($temporaryFile);

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $saved = imageavif($source, $avifPath, $this->compressionQuality());

        imagedestroy($source);

        if (! $saved || ! is_file($avifPath)) {
            @unlink($avifPath);

            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $avifPath,
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

        $avifPath = $temporaryFile.'.avif';
        @unlink($temporaryFile);

        try {
            $imagick = new \Imagick();
            $imagick->readImage($file->getRealPath());
            $dimensions = $this->resizeImagickImage($imagick);
            $imagick->setImageFormat('AVIF');
            $imagick->setImageCompressionQuality($this->compressionQuality());
            $imagick->writeImage($avifPath);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $exception) {
            @unlink($avifPath);

            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image with Imagick. '.$exception->getMessage();
            Log::error($message);

            return null;
        }

        if (! is_file($avifPath)) {
            $message = 'LaravelUploads: AVIF conversion failed while encoding the uploaded image with Imagick.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $avifPath,
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

        $webpPath = $temporaryFile.'.webp';
        @unlink($temporaryFile);

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $saved = imagewebp($source, $webpPath, $this->compressionQuality());

        imagedestroy($source);

        if (! $saved || ! is_file($webpPath)) {
            @unlink($webpPath);

            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $webpPath,
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

        $webpPath = $temporaryFile.'.webp';
        @unlink($temporaryFile);

        try {
            $imagick = new \Imagick();
            $imagick->readImage($file->getRealPath());
            $dimensions = $this->resizeImagickImage($imagick);
            $imagick->setImageFormat('WEBP');
            $imagick->setImageCompressionQuality($this->compressionQuality());
            $imagick->writeImage($webpPath);
            $imagick->clear();
            $imagick->destroy();
        } catch (\Throwable $exception) {
            @unlink($webpPath);

            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image with Imagick. '.$exception->getMessage();
            Log::error($message);

            return null;
        }

        if (! is_file($webpPath)) {
            $message = 'LaravelUploads: WEBP conversion failed while encoding the uploaded image with Imagick.';
            Log::error($message);

            return null;
        }

        return [
            'path' => $webpPath,
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
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();
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
        $mimeType = $file->getMimeType() ?: $file->getClientMimeType();

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

    protected function createLink(Upload $upload, ?int $expiry = null): UploadLink
    {
        $minutes = $expiry ?? (int) config('laravel-uploads.defaults.expiry', 60);

        return UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }
}
