<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Cloud Vision API Key
    |--------------------------------------------------------------------------
    |
    | API key dari Google Cloud Console untuk menggunakan Vision API.
    | Gratis 1000 request/bulan. Dapatkan di:
    | https://console.cloud.google.com/apis/credentials
    |
    */
    'api_key' => env('GOOGLE_VISION_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Enable/Disable Google Vision OCR
    |--------------------------------------------------------------------------
    */
    'enabled' => env('GOOGLE_VISION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Request Timeout (seconds)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('GOOGLE_VISION_TIMEOUT', 30),

];
