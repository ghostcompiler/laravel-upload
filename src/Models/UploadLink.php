<?php

namespace GhostCompiler\LaravelUploads\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadLink extends Model
{
    protected $table = 'laravel_uploads_links';

    protected $fillable = [
        'upload_id',
        'token',
        'expires_at',
        'last_accessed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(Upload::class);
    }

    public function url(): string
    {
        return route(
            config('laravel-uploads.route.name', 'laravel-uploads.show'),
            ['token' => $this->token]
        );
    }
}
