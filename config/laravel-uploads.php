<?php

return [
    'disk' => 'local',

    'base_path' => 'LaravelUploads',

    'defaults' => [
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 60,
    ],

    // When enabled, the package optimizes supported images for faster browser delivery.
    // It tries AVIF first and automatically falls back to WEBP when AVIF is unavailable.
    // If only max_width or max_height is set, the other dimension is calculated automatically
    // from the original aspect ratio without stretching or upscaling the image.
    'image_optimization' => [
        'enabled' => false,
        'quality' => 75,
        'convert_to_avif' => true,
        'max_width' => null,
        'max_height' => null,
    ],

    'preview_mime_types' => [
        'image/avif',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
    ],

    'delete_files_with_model' => false,

    'route' => [
        'prefix' => '_laravel-uploads',
        'name' => 'laravel-uploads.show',
        'middleware' => ['web'],
    ],
];
