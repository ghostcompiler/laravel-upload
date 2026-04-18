<?php

namespace GhostCompiler\LaravelUploads\Concerns;

use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

trait LaravelUploads
{
    public function initializeLaravelUploads(): void
    {
        $hidden = $this->hidden ?? [];

        foreach ($this->uploadableFields() as $column => $options) {
            if ($options['id'] === 'hide' && ! in_array($column, $hidden, true)) {
                $hidden[] = $column;
            }
        }

        $this->hidden = $hidden;
    }

    public static function bootLaravelUploads(): void
    {
        static::deleting(function ($model): void {
            foreach ($model->uploadableFields() as $column => $options) {
                $upload = $model->resolveUploadFromColumn($column);

                if (! $upload) {
                    continue;
                }

                $deleteOnModelDelete = (bool) config('laravel-uploads.delete_files_with_model', false);

                if ($deleteOnModelDelete) {
                    $disk = app('filesystem')->disk($upload->disk);

                    if ($disk->exists($upload->path)) {
                        $disk->delete($upload->path);
                    }

                    $upload->delete();
                }
            }
        });
    }

    public function upload(string $column): BelongsTo
    {
        return $this->belongsTo(Upload::class, $column);
    }

    public function uploadUrl(string $column): ?string
    {
        $config = $this->uploadableFields()[$column] ?? null;

        if (! $config) {
            return null;
        }

        return app(LaravelUploadsManager::class)->url(
            $this->resolveUploadFromColumn($column),
            $config['expiry']
        );
    }

    public function resolveUploadFromColumn(string $column): ?Upload
    {
        $id = $this->getRawOriginal($column) ?? parent::getAttribute($column);

        if (! $id) {
            return null;
        }

        return app(LaravelUploadsManager::class)->find((int) $id);
    }

    public function uploadableFields(): array
    {
        $fields = property_exists($this, 'uploadable') ? $this->uploadable : [];
        $defaults = config('laravel-uploads.defaults', []);
        $normalized = [];

        foreach ($fields as $column => $options) {
            $name = Arr::get($options, 'name', Str::beforeLast($column, '_id'));

            $normalized[$column] = [
                'name' => $name,
                'type' => Arr::get($options, 'type', Arr::get($defaults, 'type', 'private')),
                'id' => Arr::get($options, 'id', Arr::get($defaults, 'id', 'hide')),
                'expiry' => (int) Arr::get($options, 'expiry', Arr::get($defaults, 'expiry', 60)),
            ];
        }

        return $normalized;
    }

    public function getAttribute($key)
    {
        if (is_string($key)) {
            foreach ($this->uploadableFields() as $column => $options) {
                if ($options['name'] === $key) {
                    return app(LaravelUploadsManager::class)->urlFromId(
                        $this->getRawOriginal($column),
                        $options['expiry']
                    );
                }
            }
        }

        return parent::getAttribute($key);
    }

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        foreach ($this->uploadableFields() as $column => $options) {
            if ($options['id'] === 'hide') {
                unset($attributes[$column]);
            }

            $attributes[$options['name']] = app(LaravelUploadsManager::class)->urlFromId(
                $this->getRawOriginal($column),
                $options['expiry']
            );
        }

        return $attributes;
    }
}
