<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\Backoff;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Backoff::class)]
final class BackoffTest extends TestCase
{
    public function test_constant_waits_the_same_every_time(): void
    {
        $backoff = Backoff::constant(250);

        self::assertSame(250, $backoff->delayMs(1));
        self::assertSame(250, $backoff->delayMs(7));
    }

    public function test_negative_constant_is_clamped_to_zero(): void
    {
        self::assertSame(0, Backoff::constant(-5)->delayMs(1));
    }

    public function test_exponential_without_jitter_doubles_each_retry(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 100_000, jitter: false);

        self::assertSame([100, 200, 400, 800, 1600], array_map($backoff->delayMs(...), [1, 2, 3, 4, 5]));
    }

    public function test_exponential_is_capped_at_max(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 1000, jitter: false);

        self::assertSame(800, $backoff->delayMs(4));
        self::assertSame(1000, $backoff->delayMs(5));
        self::assertSame(1000, $backoff->delayMs(50));
    }

    public function test_multiplier_controls_the_growth(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 100_000, multiplier: 3.0, jitter: false);

        self::assertSame([100, 300, 900], array_map($backoff->delayMs(...), [1, 2, 3]));
    }

    public function test_a_multiplier_below_one_never_shrinks_the_delay(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 1000, multiplier: 0.1, jitter: false);

        self::assertSame(100, $backoff->delayMs(5));
    }

    public function test_jitter_draws_from_the_upper_half_of_the_delay(): void
    {
        $seen = [];
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 1000, jitter: true, random: static function (int $min, int $max) use (&$seen): int {
            $seen[] = [$min, $max];

            return $max;
        });

        $backoff->delayMs(1);
        $backoff->delayMs(3);

        self::assertSame([[50, 100], [200, 400]], $seen);
    }

    public function test_jitter_uses_the_random_source_result(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, jitter: true, random: static fn (int $min, int $max): int => $min);

        self::assertSame(50, $backoff->delayMs(1));
    }

    public function test_default_random_source_stays_within_bounds(): void
    {
        $backoff = Backoff::exponential(baseMs: 100, maxMs: 100, jitter: true);

        for ($i = 0; $i < 50; $i++) {
            $delay = $backoff->delayMs(3);
            self::assertGreaterThanOrEqual(50, $delay);
            self::assertLessThanOrEqual(100, $delay);
        }
    }

    public function test_tiny_delays_skip_jitter(): void
    {
        self::assertSame(1, Backoff::exponential(baseMs: 1, jitter: true)->delayMs(1));
        self::assertSame(0, Backoff::exponential(baseMs: 0, jitter: true)->delayMs(3));
    }
}
