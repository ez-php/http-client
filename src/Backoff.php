<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

/**
 * Class Backoff
 *
 * Delay strategy between the attempts of `HttpRequest::retry()`.
 *
 *     Http::get($url)->retry(5)->backoff(Backoff::exponential(baseMs: 200, maxMs: 10_000))->send();
 *
 * `exponential()` waits `baseMs`, `2·baseMs`, `4·baseMs`, … capped at `maxMs`. With jitter (the
 * default) each wait is spread over the upper half of that value — "equal jitter": between
 * `delay/2` and `delay` — so clients that failed together do not retry in lock-step against a
 * service that is already struggling, while the wait still never collapses to zero.
 *
 * @package EzPhp\HttpClient
 */
final class Backoff
{
    /**
     * @param int                                   $baseMs
     * @param int                                   $maxMs
     * @param float                                 $multiplier
     * @param bool                                  $jitter
     * @param (\Closure(int, int): int)|null        $random     Returns an int in [min, max]; defaults to `random_int` (tests inject a fixed source).
     */
    private function __construct(
        private readonly int $baseMs,
        private readonly int $maxMs,
        private readonly float $multiplier,
        private readonly bool $jitter,
        private readonly ?\Closure $random,
    ) {
    }

    /**
     * The same wait before every retry.
     *
     * @param int $ms
     *
     * @return self
     */
    public static function constant(int $ms): self
    {
        return new self(max(0, $ms), max(0, $ms), 1.0, false, null);
    }

    /**
     * Exponentially growing waits.
     *
     * @param int                            $baseMs     Wait before the first retry.
     * @param int                            $maxMs      Upper bound for any single wait.
     * @param float                          $multiplier Growth factor per retry (>= 1).
     * @param bool                           $jitter     Randomise each wait within [delay/2, delay].
     * @param (\Closure(int, int): int)|null $random     Random source returning an int in [min, max] (for tests).
     *
     * @return self
     */
    public static function exponential(
        int $baseMs = 100,
        int $maxMs = 30_000,
        float $multiplier = 2.0,
        bool $jitter = true,
        ?\Closure $random = null,
    ): self {
        return new self(max(0, $baseMs), max(0, $maxMs), max(1.0, $multiplier), $jitter, $random);
    }

    /**
     * Wait before the given retry, in milliseconds.
     *
     * @param int $retry 1-based number of the retry about to happen (1 = first retry).
     *
     * @return int
     */
    public function delayMs(int $retry): int
    {
        $exponent = max(0, $retry - 1);
        $delay = (int) min((float) $this->maxMs, $this->baseMs * ($this->multiplier ** $exponent));

        if (!$this->jitter || $delay <= 1) {
            return $delay;
        }

        $floor = intdiv($delay, 2);

        return $this->random !== null
            ? ($this->random)($floor, $delay)
            : random_int($floor, $delay);
    }
}
