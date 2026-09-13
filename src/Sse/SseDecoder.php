<?php

declare(strict_types=1);

namespace EzPhp\HttpClient\Sse;

use Generator;

/**
 * Class SseDecoder
 *
 * Decodes a text/event-stream body into SseMessage objects, following the
 * WHATWG "event stream interpretation" rules a client needs: CRLF, LF and CR
 * line endings (also split across chunks), an optional leading BOM, comment
 * lines, `data` / `event` / `id` fields, and dispatch on an empty line.
 * `retry` and unknown fields are ignored; an event not terminated by an empty
 * line before the stream ends is discarded.
 *
 * Usage:
 *
 *   foreach (SseDecoder::decode(Http::get($url)->stream()) as $message) {
 *       $payload = json_decode($message->data(), true);
 *   }
 *
 * @package EzPhp\HttpClient\Sse
 */
final class SseDecoder
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @param iterable<string> $chunks Raw body chunks in arrival order.
     *
     * @return Generator<int, SseMessage, void, void>
     */
    public static function decode(iterable $chunks): Generator
    {
        /** @var list<string> $dataLines */
        $dataLines = [];
        $event = '';
        $id = null;

        foreach (self::lines($chunks) as $line) {
            if ($line === '') {
                if ($dataLines !== []) {
                    yield new SseMessage(implode("\n", $dataLines), $event === '' ? 'message' : $event, $id);
                }

                $dataLines = [];
                $event = '';

                continue;
            }

            if ($line[0] === ':') {
                continue;
            }

            $colon = strpos($line, ':');

            if ($colon === false) {
                $field = $line;
                $value = '';
            } else {
                $field = substr($line, 0, $colon);
                $value = substr($line, $colon + 1);

                if (str_starts_with($value, ' ')) {
                    $value = substr($value, 1);
                }
            }

            if ($field === 'data') {
                $dataLines[] = $value;
            } elseif ($field === 'event') {
                $event = $value;
            } elseif ($field === 'id' && !str_contains($value, "\0")) {
                $id = $value;
            }
        }
    }

    /**
     * Split chunks into lines without their terminators.
     *
     * A trailing "\r" is held back until the next chunk shows whether "\n"
     * follows; at the end of the stream it counts as a line ending. A final
     * line without any terminator is dropped (it cannot complete an event).
     *
     * @param iterable<string> $chunks
     *
     * @return Generator<int, string, void, void>
     */
    private static function lines(iterable $chunks): Generator
    {
        $buffer = '';
        $bomChecked = false;

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            if (!$bomChecked) {
                if (strlen($buffer) < strlen(self::BOM) && str_starts_with(self::BOM, $buffer)) {
                    continue;
                }

                if (str_starts_with($buffer, self::BOM)) {
                    $buffer = substr($buffer, strlen(self::BOM));
                }

                $bomChecked = true;
            }

            while (($line = self::takeLine($buffer, false)) !== null) {
                yield $line;
            }
        }

        while (($line = self::takeLine($buffer, true)) !== null) {
            yield $line;
        }
    }

    /**
     * Remove and return the first complete line from $buffer, or null.
     *
     * @param string $buffer Consumed in place.
     * @param bool   $eof    Whether no more chunks will follow.
     *
     * @return string|null
     */
    private static function takeLine(string &$buffer, bool $eof): ?string
    {
        $length = strlen($buffer);
        $position = strcspn($buffer, "\r\n");

        if ($position === $length) {
            return null;
        }

        $terminatorLength = 1;

        // A bare "\r" at the end of the stream IS a line terminator; a final
        // line with no terminator at all is not (strcspn above returns null for it).
        if ($buffer[$position] === "\r") {
            if ($position + 1 === $length) {
                if (!$eof) {
                    return null;
                }
            } elseif ($buffer[$position + 1] === "\n") {
                $terminatorLength = 2;
            }
        }

        $line = substr($buffer, 0, $position);
        $buffer = substr($buffer, $position + $terminatorLength);

        return $line;
    }
}
