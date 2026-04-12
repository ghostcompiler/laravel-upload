<?php

return [
    'disk' => 'local',

    'base_path' => 'UploadsManager',

    'paths' => [
        'public' => 'public',
        'private' => 'private',
    ],

    'defaults' => [
        'type' => 'private',
        'id' => 'hide',
        'expiry' => 60,
    ],

    'image_optimization' => [
        'enabled' => false,
        'quality' => 75,
        'convert_to_avif' => true,
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
        'prefix' => '_uploads-manager',
        'name' => 'uploads-manager.show',
        'middleware' => ['web'],
    ],
];
