<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Kolosal AI API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Kolosal AI service used for machine translation.
    |
    */

    'api_key' => env('KOLOSAL_API_KEY'),

    'base_url' => env('KOLOSAL_BASE_URL', 'https://api.kolosal.ai/v1'),

    'model' => env('KOLOSAL_MODEL', 'Claude Sonnet 4.5'),

    'timeout' => env('KOLOSAL_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Supported Translation Languages
    |--------------------------------------------------------------------------
    */
    'supported_languages' => [
        'id' => 'Indonesian',
    ],

    'default_target_language' => 'id',
];
