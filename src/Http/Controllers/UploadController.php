<?php

namespace GhostCompiler\LaravelUploads\Http\Controllers;

use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends Controller
{
    public function show(Request $request, string $token): StreamedResponse
    {
        $link = UploadLink::query()
            ->with('upload')
            ->where('token', $token)
            ->first();

        abort_if(! $link, 404);
        abort_if(! $link->upload, 404);
        abort_if($link->expires_at && $link->expires_at->isPast(), 404);

        $link->forceFill([
            'last_accessed_at' => now(),
        ])->save();

        $upload = $link->upload;
        $manager = app(LaravelUploadsManager::class);
        abort_if(! $manager->isSafeUpload($upload), 404);

        $disk = Storage::disk($upload->disk);
        abort_if(! $disk->exists($upload->path), 404);

        $downloadName = $this->safeDownloadName($this->downloadNameForUpload($upload));
        $headers = $this->securityHeaders();
        $download = $request->boolean('download');
        $previewable = $this->isPreviewable($upload->mime_type);

        if ($download || ! $previewable) {
            return $this->streamDownload($disk, $upload->path, $downloadName, $headers, 'attachment', $upload->mime_type);
        }

        return $this->streamDownload(
            $disk,
            $upload->path,
            $downloadName,
            $headers + [
                'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
            ],
            'inline',
            $upload->mime_type
        );
    }

    protected function isPreviewable(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        if ($mimeType === 'image/svg+xml') {
            return false;
        }

        $previewableMimeTypes = config('laravel-uploads.preview_mime_types', []);

        if (! is_array($previewableMimeTypes)) {
            return false;
        }

        return in_array($mimeType, $previewableMimeTypes, true);
    }

    protected function securityHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    protected function safeDownloadName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name) ?: '';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
    }

    protected function downloadNameForUpload($upload): string
    {
        if ((bool) config('laravel-uploads.downloads.use_original_name', false)) {
            return (string) $upload->original_name;
        }

        $extension = trim((string) $upload->extension);

        return $extension !== ''
            ? "upload-{$upload->id}.{$extension}"
            : "upload-{$upload->id}";
    }

    protected function streamDownload($disk, string $path, string $downloadName, array $headers, string $disposition, ?string $mimeType): StreamedResponse
    {
        $headers['Content-Type'] ??= $mimeType ?: ($disk->mimeType($path) ?: 'application/octet-stream');
        $headers['Content-Disposition'] ??= $disposition.'; filename="'.$downloadName.'"';

        return response()->stream(function () use ($disk, $path): void {
            $stream = $disk->readStream($path);

            if (! is_resource($stream)) {
                throw new RuntimeException('Unable to stream the requested upload.');
            }

            try {
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers);
    }
}
