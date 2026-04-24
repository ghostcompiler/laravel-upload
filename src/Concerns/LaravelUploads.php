<?php

namespace GhostCompiler\LaravelUploads\Concerns;

use GhostCompiler\LaravelUploads\Exceptions\LaravelUploadsException;
use GhostCompiler\LaravelUploads\Models\Upload;
use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Http\UploadedFile;
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
                    app(LaravelUploadsManager::class)->remove($upload);
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

        return app(LaravelUploadsManager::class)->urlFromId(
            $this->getRawOriginal($column),
            $config['expiry']
        );
    }

    public function resolveUploadFromColumn(string $column): ?Upload
    {
        if ($this->relationLoaded($column)) {
            $related = $this->getRelation($column);

            return $related instanceof Upload ? $related : null;
        }

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
            $type = strtolower(trim((string) Arr::get($options, 'type', '')));
            $visibility = Arr::get($options, 'visibility', Arr::get($defaults, 'visibility', Arr::get($defaults, 'type', 'private')));
            $variant = Arr::get($options, 'variant', Arr::get($defaults, 'variant'));

            if (in_array($type, ['public', 'private'], true)) {
                $visibility = $type;
            }

            if (in_array($type, ['favicon', 'favicaon'], true)) {
                $variant = 'favicon';
            }

            $normalized[$column] = [
                'name' => $name,
                'visibility' => strtolower(trim((string) $visibility)) ?: 'private',
                'variant' => $variant ? strtolower(trim((string) $variant)) : null,
                'id' => Arr::get($options, 'id', Arr::get($defaults, 'id', 'hide')),
                'expiry' => (int) Arr::get($options, 'expiry', Arr::get($defaults, 'expiry', 60)),
                'expose' => (bool) Arr::get($options, 'expose', Arr::get($defaults, 'expose', false)),
            ];
        }

        return $normalized;
    }

    public function uploadToField(string $column, UploadedFile $file, ?string $path = null, array|string|null $options = null): Upload
    {
        $config = $this->uploadableFields()[$column] ?? null;

        if (! $config) {
            throw new LaravelUploadsException("LaravelUploads: Unknown uploadable field [{$column}].");
        }

        $options = $this->mergeUploadOptionsForField($config, $options);
        $manager = app(LaravelUploadsManager::class);
        $upload = $path === null
            ? $manager->upload($file, $options)
            : $manager->upload($path, $file, $options);

        $this->setAttribute($column, $upload->getKey());

        return $upload;
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

            if ($options['expose']) {
                $attributes[$options['name']] = app(LaravelUploadsManager::class)->urlFromId(
                    $this->getRawOriginal($column),
                    $options['expiry']
                );
            }
        }

        return $attributes;
    }

    protected function mergeUploadOptionsForField(array $config, array|string|null $options): array
    {
        $fieldOptions = [
            'visibility' => $config['visibility'],
        ];

        if (! empty($config['variant'])) {
            $fieldOptions['variant'] = $config['variant'];
        }

        if ($options === null) {
            return $fieldOptions;
        }

        if (is_string($options)) {
            return $fieldOptions + [
                'allow_excluded_extensions' => [$options],
            ];
        }

        if (array_is_list($options)) {
            return $fieldOptions + [
                'allow_excluded_extensions' => $options,
            ];
        }

        return $options + $fieldOptions;
    }
}
