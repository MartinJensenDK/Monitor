<?php

declare(strict_types=1);

/**
 * Front controller. This is the only file the web server needs to reach —
 * everything else, including .env, lives one level up and outside the webroot.
 */

// Let PHP's built-in server hand out the files in public/ directly, so
// `php -S localhost:8000 -t public public/index.php` works for a quick look.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/app/Support/helpers.php';

use App\Core\App;
use App\Core\Request;

App::boot(dirname(__DIR__));

$request = Request::capture();
$response = App::handle($request);

foreach ([
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'SAMEORIGIN',
    'Referrer-Policy' => 'same-origin',
    'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
        . "script-src 'self'; font-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
] as $name => $value) {
    $response->withHeader($name, $value);
}

$response->send();
