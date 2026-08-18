<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'http://localhost:3000',
        'http://192.168.25.165:3000',
        'https://momentocardgames.com',
        'http://momentocardgames.com',
        'https://backend.momentocardgames.com',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // Set to true if sending cookies/auth headers
];