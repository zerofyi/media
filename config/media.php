<?php

return [
    'disk' => env('MEDIA_DISK', 'public'),
    'max_size_kb' => (int) env('MEDIA_MAX_KB', 5120),
    'max_pixel_count' => (int) env('MEDIA_MAX_PIXELS', 25_000_000),

    /*
    |--------------------------------------------------------------------------
    | Eloquent Asset Model Binding
    |--------------------------------------------------------------------------
    */
    'model' => \Zerofyi\Media\Models\Asset::class,

    'allowed_mime' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/svg+xml',
    ],

    'default_quality' => 85,

    'variants' => [
        'thumb' => [
            'width' => 200,
            'height' => 200,
            'fit' => 'cover',
            'quality' => 75,
        ],
        'sm' => [
            'width' => 480,
            'height' => null,
            'fit' => 'scale_down',
            'quality' => 80,
        ],
        'md' => [
            'width' => 768,
            'height' => null,
            'fit' => 'scale_down',
            'quality' => 85,
        ],
        'lg' => [
            'width' => 1200,
            'height' => null,
            'fit' => 'scale_down',
            'quality' => 85,
        ],
    ],

    'default_variants' => ['thumb', 'sm', 'md'],
];