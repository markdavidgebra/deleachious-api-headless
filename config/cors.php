<?php

/*
|--------------------------------------------------------------------------
| Helper: parse a comma-separated env value into a trimmed, filtered array.
|--------------------------------------------------------------------------
|
| Lets ops add or change allowed CORS origins from the server `.env` without
| editing committed code. Example:
|
|     CORS_ALLOWED_ORIGINS="https://admin.daleachious.cloud,https://app.daleachious.cloud"
|     CORS_ALLOWED_ORIGIN_PATTERNS="#^https://.*\.daleachious\.cloud$#"
|
*/
$csvToArray = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

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
    | Dev origins the React admin (Vite) and Expo web client may use, plus the
    | production frontends. Additional production / staging / preview origins
    | should be added through the CORS_ALLOWED_ORIGINS env var (comma-separated)
    | rather than editing this file.
    */

    'allowed_origins' => array_values(array_unique(array_merge(
        [
            // Local dev (React admin via Vite)
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            // Local dev (Next / generic SPA)
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            // Local dev (Expo web)
            'http://localhost:8081',
            'http://127.0.0.1:8081',
            'http://localhost:19006',

            // Production frontends
            'https://admin.daleachious.cloud',
            'https://app.daleachious.cloud',
            'https://daleachious.cloud',
            'https://www.daleachious.cloud',
        ],
        $csvToArray(env('CORS_ALLOWED_ORIGINS'))
    ))),

    /*
    | Regex patterns (delimited, e.g. "#^https://.*\.daleachious\.cloud$#") for
    | dynamic origins such as Vercel preview deploys. Configure through
    | CORS_ALLOWED_ORIGIN_PATTERNS in `.env`.
    */

    'allowed_origins_patterns' => $csvToArray(env('CORS_ALLOWED_ORIGIN_PATTERNS')),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 60 * 60,

    /*
    | Bearer-token auth (Sanctum's plainTextToken) does not need cookies, so
    | this stays false. If you switch to first-party cookie auth, flip this
    | to true and tighten allowed_origins to a finite list.
    */

    'supports_credentials' => false,

];
