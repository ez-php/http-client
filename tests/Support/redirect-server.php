<?php

declare(strict_types=1);

/**
 * Router for the `php -S` loopback server started by WithoutRedirectsTest.
 *
 * `/hop` redirects to `/target`, which leaves a marker file in
 * REDIRECT_TEST_MARKER_DIR so the test can tell whether it was ever requested.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);

if ($path === '/hop') {
    header('Location: /target', true, 302);

    return;
}

if ($path === '/file-hop') {
    header('Location: file:///etc/hostname', true, 302);

    return;
}

$markerDir = getenv('REDIRECT_TEST_MARKER_DIR');

if (is_string($markerDir)) {
    touch($markerDir . '/target-hit');
}

echo 'reached';
