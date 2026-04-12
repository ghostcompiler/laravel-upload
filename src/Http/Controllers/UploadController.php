<?php

namespace GhostCompiler\UploadsManager\Http\Controllers;

use GhostCompiler\UploadsManager\Models\UploadLink;
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
            ->firstOrFail();

        abort_if(! $link->upload, 404);
        abort_if($link->expires_at && $link->expires_at->isPast(), 403);

        $link->forceFill([
            'last_accessed_at' => now(),
        ])->save();

        $upload = $link->upload;
        $disk = Storage::disk($upload->disk);
        $download = $request->boolean('download');
        $previewable = $this->isPreviewable($upload->mime_type);

        if ($download || ! $previewable) {
            return $disk->download($upload->path, $upload->original_name);
        }

        if ($upload->visibility === 'public') {
            return $disk->response($upload->path, $upload->original_name);
        }

        return $disk->response(
            $upload->path,
            $upload->original_name,
            [
                'Content-Disposition' => 'inline; filename="'.addslashes($upload->original_name).'"',
            ]
        );
    }

    protected function isPreviewable(?string $mimeType): bool
    {
        if (! $mimeType) {
            return false;
        }

        $previewableMimeTypes = config('uploads-manager.preview_mime_types', [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'application/pdf',
            'text/plain',
        ]);

        return in_array($mimeType, $previewableMimeTypes, true);
    }
}
