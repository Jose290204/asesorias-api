<?php

return [
    // Rutas a las que se aplican las reglas CORS
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Con credenciales NO puede ser '*': debe ser el origen exacto del front
    'allowed_origins' => ['http://localhost:5173'],

    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // Permite que el navegador envíe y reciba la cookie de sesión
    'supports_credentials' => true,
];