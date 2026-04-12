<?php

namespace GhostCompiler\UploadsManager\Models;

use GhostCompiler\UploadsManager\Services\UploadManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Upload extends Model
{
    protected $table = 'uploads_manager_uploads';

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
        /** @var UploadManager $manager */
        $manager = app(UploadManager::class);

        return $manager->url($this);
    }

    public function links(): HasMany
    {
        return $this->hasMany(UploadLink::class);
    }
}
