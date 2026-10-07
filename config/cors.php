<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env('FRONTEND_ORIGINS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Idempotency-Key'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
