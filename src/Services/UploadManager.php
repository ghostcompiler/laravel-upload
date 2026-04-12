<?php

namespace GhostCompiler\UploadsManager\Services;

use GhostCompiler\UploadsManager\Contracts\ResolvesUploadUrls;
use GhostCompiler\UploadsManager\Models\Upload;
use GhostCompiler\UploadsManager\Models\UploadLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class UploadManager implements ResolvesUploadUrls
{
    public function upload(string $type, UploadedFile $file): Upload
    {
        $visibility = $this->normalizeVisibility($type);
        $disk = $this->disk();
        $directory = $this->directoryFor($visibility);
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

    public function find(?int $id): ?Upload
    {
        if (! $id) {
            return null;
        }

        return Upload::query()->find($id);
    }

    public function remove(Upload|int|null $upload): bool
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

    public function urlFromId(?int $id, ?int $expiry = null): ?string
    {
        $upload = $this->find($id);

        if (! $upload) {
            return null;
        }

        return $this->createLink($upload, $expiry)->url();
    }

    protected function normalizeVisibility(string $type): string
    {
        $visibility = strtolower(trim($type));

        if (! in_array($visibility, ['public', 'private'], true)) {
            throw new InvalidArgumentException('Upload type must be either public or private.');
        }

        return $visibility;
    }

    protected function disk(): string
    {
        return (string) config('uploads-manager.disk', config('filesystems.default', 'local'));
    }

    protected function directoryFor(string $visibility): string
    {
        $basePath = trim((string) config('uploads-manager.base_path', 'UploadsManager'), '/');
        $directory = trim((string) config("uploads-manager.paths.{$visibility}", $visibility), '/');

        return "{$basePath}/{$directory}";
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
            $name = $this->generateFilename($file);
            $size = filesize($file->getRealPath()) ?: $file->getSize();

            return [
                'name' => $name,
                'download_name' => $file->getClientOriginalName(),
                'real_path' => $file->getRealPath(),
                'mime_type' => $file->getClientMimeType(),
                'extension' => $file->getClientOriginalExtension(),
                'size' => $size,
                'temporary' => false,
                'compression' => [
                    'enabled' => false,
                    'applied' => false,
                ],
            ];
        }

        $converted = $this->convertImageToAvif($file);

        if ($converted === null) {
            $name = $this->generateFilename($file);
            $size = filesize($file->getRealPath()) ?: $file->getSize();

            return [
                'name' => $name,
                'download_name' => $file->getClientOriginalName(),
                'real_path' => $file->getRealPath(),
                'mime_type' => $file->getClientMimeType(),
                'extension' => $file->getClientOriginalExtension(),
                'size' => $size,
                'temporary' => false,
                'compression' => [
                    'enabled' => true,
                    'applied' => false,
                    'fallback' => true,
                ],
            ];
        }

        $downloadBaseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        return [
            'name' => Str::uuid()->toString().'.avif',
            'download_name' => $downloadBaseName !== '' ? "{$downloadBaseName}.avif" : Str::uuid()->toString().'.avif',
            'real_path' => $converted,
            'mime_type' => 'image/avif',
            'extension' => 'avif',
            'size' => filesize($converted) ?: $file->getSize(),
            'temporary' => true,
            'compression' => [
                'enabled' => true,
                'applied' => true,
                'quality' => $this->compressionQuality(),
                'converted_to' => 'avif',
            ],
        ];
    }

    protected function shouldCompressImages(): bool
    {
        return (bool) config('uploads-manager.image_optimization.enabled', false);
    }

    protected function compressionQuality(): int
    {
        return max(1, min(100, (int) config('uploads-manager.image_optimization.quality', 75)));
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

    protected function convertImageToAvif(UploadedFile $file): ?string
    {
        if (! (bool) config('uploads-manager.image_optimization.convert_to_avif', true)) {
            return null;
        }

        if (! function_exists('imageavif')) {
            return null;
        }

        $source = $this->createImageResource($file);

        if (! $source) {
            return null;
        }

        $temporaryFile = tempnam(sys_get_temp_dir(), 'uploads-manager-avif-');

        if ($temporaryFile === false) {
            imagedestroy($source);

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

            return null;
        }

        return $avifPath;
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

    protected function createLink(Upload $upload, ?int $expiry = null): UploadLink
    {
        $minutes = $expiry ?? (int) config('uploads-manager.defaults.expiry', 60);

        return UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }
}
