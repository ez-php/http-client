<?php

declare(strict_types=1);

/**
 * Performance benchmark for EzPhp\HttpClient\HttpClient.
 *
 * Measures the overhead of building HTTP request objects with headers,
 * query parameters, and body payload — using FakeTransport so no actual
 * network I/O is performed.
 *
 * Exits with code 1 if the per-build time exceeds the defined threshold,
 * allowing CI to detect performance regressions automatically.
 *
 * Usage:
 *   php benchmarks/request-build.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use EzPhp\HttpClient\FakeTransport;
use EzPhp\HttpClient\HttpClient;

const ITERATIONS = 5000;
const THRESHOLD_MS = 1.0; // per-build upper bound in milliseconds

// ── Setup ─────────────────────────────────────────────────────────────────────

$transport = new FakeTransport();
$client = new HttpClient($transport);

// Warm-up
$client->get('https://api.example.com/users')
    ->withHeader('Accept', 'application/json')
    ->withHeader('Authorization', 'Bearer token123')
    ->withQuery('page', '1')
    ->withQuery('limit', '25');

// ── Benchmark ─────────────────────────────────────────────────────────────────

$start = hrtime(true);

for ($i = 0; $i < ITERATIONS; $i++) {
    $client->post('https://api.example.com/users')
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Accept', 'application/json')
        ->withHeader('Authorization', 'Bearer token123')
        ->withHeader('X-Request-Id', 'bench-' . $i)
        ->withBody(json_encode(['name' => 'Alice', 'email' => 'alice@example.com']) ?: '');
}

$end = hrtime(true);

$totalMs = ($end - $start) / 1_000_000;
$perBuild = $totalMs / ITERATIONS;

echo sprintf(
    "HTTP Client Request Build Benchmark\n" .
    "  Headers per request  : 4\n" .
    "  Iterations           : %d\n" .
    "  Total time           : %.2f ms\n" .
    "  Per build            : %.3f ms\n" .
    "  Threshold            : %.1f ms\n",
    ITERATIONS,
    $totalMs,
    $perBuild,
    THRESHOLD_MS,
);

if ($perBuild > THRESHOLD_MS) {
    echo sprintf(
        "FAIL: %.3f ms exceeds threshold of %.1f ms\n",
        $perBuild,
        THRESHOLD_MS,
    );
    exit(1);
}

echo "PASS\n";
exit(0);
