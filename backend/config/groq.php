<?php

return [
    'api_key' => env('GROQ_API_KEY', ''),
    'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
    'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    'timeout' => (int) env('GROQ_TIMEOUT', 20),
];
