<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Jev AI API
    |--------------------------------------------------------------------------
    |
    | The API key is read only from the environment and is never logged,
    | dispatched in events, or serialized into queued jobs.
    |
    */

    'api_key' => env('JEV_AI_API_KEY'),

    'base_url' => env('JEV_AI_BASE_URL', 'https://jev-ai.pro/api'),

    'default_model' => env('JEV_AI_MODEL', 'jev-latest'),

    /*
    |--------------------------------------------------------------------------
    | HTTP timeouts (seconds)
    |--------------------------------------------------------------------------
    */

    'timeout' => [
        'connect' => (int) env('JEV_AI_CONNECT_TIMEOUT', 5),
        'request' => (int) env('JEV_AI_REQUEST_TIMEOUT', 30),
    ],

];
