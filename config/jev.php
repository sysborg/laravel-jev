<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | The connection used when none is given, e.g. Jev::decide($request).
    | Use Jev::connection('name') to pick another one.
    |
    */

    'default' => env('JEV_AI_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection has its own API key, base URL, default model and
    | timeouts (seconds), e.g. one per tenant or per Jev account.
    |
    | The API key is read only from the environment and is never logged,
    | dispatched in events, or serialized into queued jobs. The base URL
    | must use https:// outside the testing environment.
    |
    */

    'connections' => [

        'default' => [
            'api_key' => env('JEV_AI_API_KEY'),
            'base_url' => env('JEV_AI_BASE_URL', 'https://jev-ai.pro/api'),
            'model' => env('JEV_AI_MODEL', 'jev-latest'),
            'timeout' => [
                'connect' => (int) env('JEV_AI_CONNECT_TIMEOUT', 5),
                'request' => (int) env('JEV_AI_REQUEST_TIMEOUT', 30),
            ],
        ],

    ],

];
