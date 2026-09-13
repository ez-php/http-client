<?php

declare(strict_types=1);

namespace EzPhp\HttpClient;

use CurlHandle;
use CurlMultiHandle;

/**
 * Class CurlStreamHandle
 *
 * Per-transfer state for CurlTransport::stream(): one easy handle driven by
 * its own multi handle. cURL pushes headers and body bytes into callbacks;
 * pump() advances the transfer only when a consumer asks for more, which
 * turns that push into a pull and gives natural back-pressure.
 *
 * @internal Used by CurlTransport only; HttpStream never sees it.
 *
 * @package EzPhp\HttpClient
 */
final class CurlStreamHandle
{
    private readonly CurlMultiHandle $multi;

    private string $buffer = '';

    private int $status = 0;

    /**
     * @var array<string, string>
     */
    private array $headers = [];

    private bool $headersComplete = false;

    private bool $done = false;

    private bool $failed = false;

    private string $error = '';

    private bool $closed = false;

    /**
     * CurlStreamHandle Constructor
     *
     * @param CurlHandle $handle A configured easy handle without RETURNTRANSFER.
     */
    public function __construct(private readonly CurlHandle $handle)
    {
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, $this->onHeader(...));
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, $this->onWrite(...));

        $this->multi = curl_multi_init();
        curl_multi_add_handle($this->multi, $handle);
    }

    /**
     * Advance the transfer until data arrived, the headers completed, or it finished.
     *
     * The idle clock starts with this call, so time a slow consumer spends
     * between pulls never counts as provider idleness.
     *
     * @param int $idleSeconds
     *
     * @return bool False when $idleSeconds passed without any progress.
     */
    public function pump(int $idleSeconds): bool
    {
        if ($this->closed) {
            return true;
        }

        $deadline = microtime(true) + $idleSeconds;
        $headersBefore = $this->headersComplete;

        while (true) {
            do {
                $code = curl_multi_exec($this->multi, $running);
            } while ($code === CURLM_CALL_MULTI_PERFORM);

            if ($code !== CURLM_OK) {
                $this->finish(true, (string) curl_multi_strerror($code));

                return true;
            }

            $this->readCompletion();

            if ($this->done || $this->buffer !== '' || $this->headersComplete !== $headersBefore) {
                return true;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return false;
            }

            // -1: nothing to select on yet (documented libcurl behaviour) — back off briefly.
            if (curl_multi_select($this->multi, min($remaining, 1.0)) === -1) {
                usleep(1000);
            }
        }
    }

    /**
     * @return int Status code of the final response; 0 before headers.
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string> Headers of the final response, lowercase names.
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return bool
     */
    public function headersComplete(): bool
    {
        return $this->headersComplete;
    }

    /**
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * @return bool Whether the finished transfer ended with a cURL error.
     */
    public function failed(): bool
    {
        return $this->failed;
    }

    /**
     * @return string
     */
    public function error(): string
    {
        return $this->error;
    }

    /**
     * @return bool
     */
    public function hasBufferedData(): bool
    {
        return $this->buffer !== '';
    }

    /**
     * Return and clear everything received since the previous call.
     *
     * @return string
     */
    public function takeBuffer(): string
    {
        $data = $this->buffer;
        $this->buffer = '';

        return $data;
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Abort the transfer and release the connection. Idempotent.
     *
     * curl_close() is deliberately not called: it is deprecated in PHP 8.5
     * and has had no effect since 8.0.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = '';

        curl_multi_remove_handle($this->multi, $this->handle);
        curl_multi_close($this->multi);
    }

    /**
     * HEADERFUNCTION callback. Keeps only the final header block: a status
     * line starts a new block; an empty line ends it, and the block is final
     * unless it is 1xx or a 3xx with Location (FOLLOWLOCATION follows it).
     *
     * @param CurlHandle $handle
     * @param string     $line
     *
     * @return int
     */
    private function onHeader(CurlHandle $handle, string $line): int
    {
        $trimmed = rtrim($line, "\r\n");

        if (str_starts_with($trimmed, 'HTTP/')) {
            $parts = explode(' ', $trimmed, 3);
            $this->status = (int) ($parts[1] ?? 0);
            $this->headers = [];
        } elseif ($trimmed === '') {
            $interim = ($this->status >= 100 && $this->status < 200)
                || ($this->status >= 300 && $this->status < 400 && isset($this->headers['location']));

            if (!$interim) {
                $this->headersComplete = true;
            }
        } elseif (str_contains($trimmed, ':')) {
            [$name, $value] = explode(':', $trimmed, 2);
            $this->headers[strtolower(trim($name))] = trim($value);
        }

        return strlen($line);
    }

    /**
     * WRITEFUNCTION callback. Body bytes imply the headers are complete.
     *
     * @param CurlHandle $handle
     * @param string     $data
     *
     * @return int
     */
    private function onWrite(CurlHandle $handle, string $data): int
    {
        $this->headersComplete = true;
        $this->buffer .= $data;

        return strlen($data);
    }

    /**
     * @return void
     */
    private function readCompletion(): void
    {
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $result = $info['result'];

            if ($result === CURLE_OK) {
                $this->finish(false, '');
            } else {
                $message = curl_error($this->handle);
                $this->finish(true, $message !== '' ? $message : (string) curl_strerror($result));
            }
        }
    }

    /**
     * @param bool   $failed
     * @param string $error
     *
     * @return void
     */
    private function finish(bool $failed, string $error): void
    {
        $this->done = true;
        $this->failed = $failed;
        $this->error = $error;
    }
}
