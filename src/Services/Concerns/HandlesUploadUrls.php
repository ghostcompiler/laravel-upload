<?php

namespace GhostCompiler\LaravelUploads\Services\Concerns;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Models\UploadLink;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait HandlesUploadUrls
{
    protected function resolveUrlForUploadId(int $uploadId, ?int $expiry = null, ?Upload $upload = null): ?string
    {
        $upload ??= $this->find($uploadId);

        if (! $upload) {
            return null;
        }

        if ($upload->visibility === 'public') {
            return $this->publicUrlForUpload($upload);
        }

        $minutes = $this->resolveLinkExpiryMinutes($expiry);
        $cacheEnabled = $this->shouldCacheGeneratedUrls() && $minutes > 0;
        $cacheKey = $this->uploadUrlCacheKey($uploadId, $minutes);

        if ($cacheEnabled) {
            $cachedUrl = Cache::get($cacheKey);

            if (is_string($cachedUrl) && $cachedUrl !== '') {
                return $cachedUrl;
            }
        }

        $link = $this->createLink($upload, $minutes);
        $url = $link->url();

        if ($cacheEnabled && $link->expires_at) {
            Cache::put($cacheKey, $url, $link->expires_at);
            $this->rememberUploadUrlCacheKey($uploadId, $cacheKey);
        }

        return $url;
    }

    protected function publicUrlForUpload(Upload $upload): ?string
    {
        $disk = Storage::disk($upload->disk);

        if (! $this->isSafeUpload($upload, $disk)) {
            return null;
        }

        $resolvedUrl = $this->resolvePublicUrlWithCustomResolver($upload, $disk);

        if ($resolvedUrl !== null) {
            return $resolvedUrl;
        }

        try {
            return $disk->url($upload->path);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function resolvePublicUrlWithCustomResolver(Upload $upload, FilesystemAdapter $disk): ?string
    {
        $resolver = $this->publicUrlResolver ?? config('laravel-uploads.urls.public_resolver');

        if (! $resolver) {
            return null;
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver);
        }

        if (is_object($resolver) && method_exists($resolver, 'publicUrl')) {
            $resolver = [$resolver, 'publicUrl'];
        }

        if (! is_callable($resolver)) {
            return null;
        }

        try {
            $url = $resolver($upload, $disk, $upload->path);
        } catch (\Throwable $exception) {
            Log::warning('LaravelUploads: Public URL resolver failed. '.$exception->getMessage());

            return null;
        }

        $url = is_string($url) ? trim($url) : '';

        return $url !== '' ? $url : null;
    }

    protected function createLink(Upload $upload, ?int $expiry = null): UploadLink
    {
        $minutes = $this->resolveLinkExpiryMinutes($expiry);

        return UploadLink::query()->create([
            'upload_id' => $upload->id,
            'token' => Str::random(64),
            'expires_at' => now()->addMinutes($minutes),
        ]);
    }

    protected function resolveLinkExpiryMinutes(?int $expiry = null): int
    {
        $minutes = $expiry ?? (int) config('laravel-uploads.defaults.expiry', 60);

        return max(0, $minutes);
    }

    protected function shouldCacheGeneratedUrls(): bool
    {
        return (bool) config('laravel-uploads.cache.enabled', true);
    }

    protected function uploadUrlCacheKey(int $uploadId, int $minutes): string
    {
        return "laravel-uploads:url:{$uploadId}:{$minutes}";
    }

    protected function uploadUrlCacheRegistryKey(int $uploadId): string
    {
        return "laravel-uploads:url-keys:{$uploadId}";
    }

    protected function rememberUploadUrlCacheKey(int $uploadId, string $cacheKey): void
    {
        $registryKey = $this->uploadUrlCacheRegistryKey($uploadId);
        $cacheKeys = Cache::get($registryKey, []);

        if (! is_array($cacheKeys)) {
            $cacheKeys = [];
        }

        if (! in_array($cacheKey, $cacheKeys, true)) {
            $cacheKeys[] = $cacheKey;
            Cache::forever($registryKey, $cacheKeys);
        }
    }

    protected function forgetCachedUrlsForUploadId(int $uploadId): void
    {
        if (! $this->shouldCacheGeneratedUrls()) {
            return;
        }

        $registryKey = $this->uploadUrlCacheRegistryKey($uploadId);
        $cacheKeys = Cache::pull($registryKey, []);

        if (! is_array($cacheKeys)) {
            return;
        }

        foreach ($cacheKeys as $cacheKey) {
            if (is_string($cacheKey) && $cacheKey !== '') {
                Cache::forget($cacheKey);
            }
        }
    }
}
