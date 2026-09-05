<?php

$allowedOrigins = explode(',', env('FRONTEND_URL', 'http://localhost:3000,http://localhost:3001,http://127.0.0.1:3000,http://127.0.0.1:3001'));

// Dynamically whitelist current request origin/referer in local development to avoid IP mismatches
if (env('APP_ENV') === 'local' && !app()->runningInConsole()) {
    $origin = request()->headers->get('origin');
    if ($origin && !in_array($origin, $allowedOrigins)) {
        $allowedOrigins[] = $origin;
    }
    $referer = request()->headers->get('referer');
    if ($referer) {
        $scheme = parse_url($referer, PHP_URL_SCHEME);
        $host = parse_url($referer, PHP_URL_HOST);
        $port = parse_url($referer, PHP_URL_PORT);
        if ($scheme && $host) {
            $refOrigin = $scheme . '://' . $host . ($port ? ':' . $port : '');
            if (!in_array($refOrigin, $allowedOrigins)) {
                $allowedOrigins[] = $refOrigin;
            }
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
