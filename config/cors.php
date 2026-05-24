<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Paths the CORS middleware will apply to. Add Sanctum's CSRF cookie
    | route too in case you ever switch to SPA cookie auth.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | List the dev origins the React admin (Vite) and Expo web client may
    | use. In production replace these with your real frontend origins, or
    | add additional entries.
    */

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:8081',
        'http://127.0.0.1:8081',
        'http://localhost:19006',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    /*
    | Bearer-token auth (Sanctum's plainTextToken) does not need cookies, so
    | this stays false. If you switch to first-party cookie auth, flip this
    | to true and tighten allowed_origins to a finite list.
    */

    'supports_credentials' => false,

];
