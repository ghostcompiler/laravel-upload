<?php

namespace GhostCompiler\UploadsManager\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadLink extends Model
{
    protected $table = 'uploads_manager_links';

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
            config('uploads-manager.route.name', 'uploads-manager.show'),
            ['token' => $this->token]
        );
    }
}
