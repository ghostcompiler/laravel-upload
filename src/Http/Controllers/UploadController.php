<?php

namespace GhostCompiler\LaravelUploads\Http\Controllers;

use GhostCompiler\LaravelUploads\Models\UploadLink;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UploadController extends Controller
{
    public function show(Request $request, string $token): StreamedResponse|BinaryFileResponse
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
        abort_if(! app(LaravelUploadsManager::class)->isSafeStoragePath($upload->path), 404);

        $disk = Storage::disk($upload->disk);
        $downloadName = $this->safeDownloadName($upload->original_name);
        $download = $request->boolean('download');
        $previewable = $this->isPreviewable($upload->mime_type);

        if ($download || ! $previewable) {
            return $disk->download($upload->path, $downloadName);
        }

        if ($upload->visibility === 'public') {
            return $disk->response($upload->path, $downloadName);
        }

        return $disk->response(
            $upload->path,
            $downloadName,
            [
                'Content-Disposition' => 'inline; filename="'.$downloadName.'"',
            ]
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

        $previewableMimeTypes = config('laravel-uploads.preview_mime_types', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ]);

        return in_array($mimeType, $previewableMimeTypes, true);
    }

    protected function safeDownloadName(?string $name): string
    {
        $name = basename(str_replace('\\', '/', (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\]/', '', $name) ?: '';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
    }
}
