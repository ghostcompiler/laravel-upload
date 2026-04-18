<?php

namespace GhostCompiler\LaravelUploads\Models;

use GhostCompiler\LaravelUploads\Services\LaravelUploadsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    protected $table = 'laravel_uploads_uploads';

    protected $fillable = [
        'disk',
        'visibility',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'size',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
    ];

    public function getUrlAttribute(): ?string
    {
        /** @var LaravelUploadsManager $manager */
        $manager = app(LaravelUploadsManager::class);

        return $manager->url($this);
    }

    public function links(): HasMany
    {
        return $this->hasMany(UploadLink::class);
    }
}
