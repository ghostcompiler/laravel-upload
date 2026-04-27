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
        $field = $this->uploadableField($column);

        if (! $field) {
            return null;
        }

        return app(LaravelUploadsManager::class)->urlFromId(
            $this->getRawOriginal($field['column']),
            $field['options']['expiry']
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

            if ($type === 'favicon') {
                $variant = 'favicon';
            }

            if ((bool) Arr::get($options, 'favicon', false)) {
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

    public function uploadableValue(string $column): mixed
    {
        $field = $this->uploadableField($column);

        if (! $field) {
            return null;
        }

        return $this->resolveUploadableValue($field['column'], $field['options']);
    }

    public function getAttribute($key)
    {
        if (is_string($key)) {
            foreach ($this->uploadableFields() as $column => $options) {
                if ($options['name'] === $key) {
                    return $this->resolveUploadableValue($column, $options);
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
                $attributes[$options['name']] = $this->resolveUploadableValue($column, $options);
            }
        }

        return $attributes;
    }

    protected function resolveUploadableValue(string $column, array $options): mixed
    {
        $value = app(LaravelUploadsManager::class)->urlFromId(
            $this->getRawOriginal($column),
            $options['expiry']
        );

        $fieldMethod = 'set'.Str::studly($options['name']).'UploadableValue';

        if (method_exists($this, $fieldMethod)) {
            return $this->callUploadableValueHook($fieldMethod, $value, $column, $options);
        }

        if (method_exists($this, 'setUploadableValue')) {
            return $this->callUploadableValueHook('setUploadableValue', $value, $column, $options);
        }

        return $value;
    }

    protected function uploadableField(string $columnOrName): ?array
    {
        foreach ($this->uploadableFields() as $column => $options) {
            if ($column === $columnOrName || $options['name'] === $columnOrName) {
                return [
                    'column' => $column,
                    'options' => $options,
                ];
            }
        }

        return null;
    }

    protected function callUploadableValueHook(string $method, mixed $value, string $column, array $options): mixed
    {
        $reflection = new \ReflectionMethod($this, $method);
        $arguments = [$value, $column, $options];

        return $this->{$method}(...array_slice($arguments, 0, $reflection->getNumberOfParameters()));
    }
}
