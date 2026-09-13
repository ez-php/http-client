<?php

declare(strict_types=1);

/**
 * Router for the `php -S` loopback server started by CurlTransportStreamTest.
 *
 * Each path simulates one transfer behaviour. STREAM_TEST_MARKER_DIR (set by
 * the test) receives marker files the test polls for server-side effects.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);

$emit = static function (string $chunk): void {
    echo $chunk;

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();
};

switch ($path) {
    case '/chunks':
        header('Content-Type: text/plain');
        header('X-Stream-Test: chunks');

        for ($i = 0; $i < 3; $i++) {
            if ($i > 0) {
                usleep(300_000);
            }

            $emit("chunk-{$i}\n");
        }

        break;

    case '/redirect':
        header('Location: /chunks', true, 302);

        break;

    case '/hang-after-first-chunk':
        header('Content-Type: text/plain');
        $emit("first\n");
        sleep(3);

        break;

    case '/hang-before-headers':
        sleep(3);
        echo 'late';

        break;

    case '/drop':
        header('Content-Length: 100');
        $emit(str_repeat('x', 10));

        exit;

    case '/error':
        http_response_code(500);
        echo 'server exploded';

        break;

    case '/endless':
        $markerDir = getenv('STREAM_TEST_MARKER_DIR');

        register_shutdown_function(static function () use ($markerDir): void {
            if (!is_string($markerDir) || $markerDir === '') {
                return;
            }

            file_put_contents($markerDir . '/endless.tmp', (string) connection_status());
            rename($markerDir . '/endless.tmp', $markerDir . '/endless-finished');
        });

        header('Content-Type: text/plain');

        for ($i = 0; $i < 100; $i++) {
            $emit("tick\n");
            usleep(100_000);
        }

        break;

    case '/echo':
        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'] ?? null,
            'header' => $_SERVER['HTTP_X_TEST'] ?? null,
            'body' => file_get_contents('php://input'),
        ]);

        break;

    default:
        http_response_code(404);
}

return true;
