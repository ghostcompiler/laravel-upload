<?php

return [
    'disk' => 'local',

    'base_path' => 'LaravelUploads',

    'defaults' => [
        'visibility' => 'private',
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 60,
        'expose' => false,
        'variant' => null,
    ],

    // Cache generated file URLs so repeated model serialization does not create
    // a new UploadLink row on every request. Cache lifetime matches the URL expiry.
    'cache' => [
        'enabled' => true,
    ],

    'validation' => [
        // Maximum upload size in bytes. Set to null to disable the package-level size check.
        'max_size' => 10 * 1024 * 1024,

        // Leave allowed lists empty to allow all non-excluded files.
        'allowed_mime_types' => [],
        'allowed_extensions' => [],

        // Excluded files are blocked unless the excluded extension is explicitly allowed per upload.
        'excluded_mime_types' => [
            'application/x-httpd-php',
            'application/x-php',
            'text/x-php',
        ],
        'excluded_extensions' => [
            'cgi',
            'phar',
            'php',
            'php3',
            'php4',
            'php5',
            'phtml',
            'pl',
            'py',
            'rb',
            'sh',
        ],

        'never_allowed_extensions' => [
            'phar',
            'php',
            'php3',
            'php4',
            'php5',
            'phtml',
        ],
    ],

    // When enabled, the package optimizes supported images for faster browser delivery.
    // It tries AVIF first and automatically falls back to WEBP when AVIF is unavailable.
    // If only max_width or max_height is set, the other dimension is calculated automatically
    // from the original aspect ratio without stretching or upscaling the image.
    'image_optimization' => [
        'enabled' => false,
        'strict' => false,
        'quality' => 75,
        'convert_to_avif' => true,
        'max_width' => null,
        'max_height' => null,
        'max_input_width' => 8000,
        'max_input_height' => 8000,
        'max_input_pixels' => 12000000,
        'max_output_pixels' => 4000000,
    ],

    'favicon' => [
        'size' => 64,
    ],

    'downloads' => [
        'use_original_name' => false,
    ],

    'preview_mime_types' => [
        'image/avif',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
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
