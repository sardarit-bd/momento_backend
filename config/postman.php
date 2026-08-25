<?php

return [

    'name' => env('APP_NAME', 'Laravel API'),
    'description' => env('API_DESCRIPTION', 'API Documentation'),
    'base_url' => env('APP_URL', 'http://localhost'),

    'routes' => [
        'prefix' => 'api',
        'include' => [

            'patterns' => [],

            'middleware' => [],

            'controllers' => [],
        ],

        'exclude' => [
            'patterns' => [],
            'middleware' => [],
            'controllers' => [],
        ],
    ],


    'structure' => [
        'folders' => [
            'strategy' => 'nested_path',
            'max_depth' => 10, //  when strategy is nested_path
            'mapping' => [

            ],
        ],

        'naming_format' => '[{method}] {uri}',


        'requests' => [
            'default_body_type' => 'raw',
            'default_values' => [
                // 'email' => 'test@example.com',
                // 'password' => '123456',
                // 'otp_code' => '1234',
            ],
        ],
    ],


    'auth' => [
        'enabled' => false,
        'type' => 'bearer',
        'location' => 'header',
        'default' => [
            'token' => 'your-access-token',  
            'username' => 'user@example.com', 
            'password' => 'password', 
            'key_name' => 'X-API-KEY',   
            'key_value' => 'your-api-key-here',    
        ],
        'protected_middleware' => ['auth:api'],
    ],


    'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ],


    'output' => [
        'driver' => env('POSTMAN_STORAGE_DISK', 'local'),
        'path' => env('POSTMAN_STORAGE_DIR', storage_path('postman')),
        'filename' => env('POSTMAN_STORAGE_FILE', 'api_collection'),
    ],
];
