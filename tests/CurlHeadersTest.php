<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\CurlHeaders;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class CurlHeadersTest
 *
 * @package Tests
 */
#[CoversClass(CurlHeaders::class)]
final class CurlHeadersTest extends TestCase
{
    /**
     * @return void
     */
    public function test_format_builds_name_value_lines(): void
    {
        $this->assertSame(
            ['Accept: application/json', 'X-Trace: a:b'],
            CurlHeaders::format(['Accept' => 'application/json', 'X-Trace' => 'a:b']),
        );
    }

    /**
     * @return void
     */
    public function test_parse_lowercases_names_and_keeps_colons_in_values(): void
    {
        $raw = "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Time: 12:30:00\r\n\r\n";

        $this->assertSame(
            ['content-type' => 'text/plain', 'x-time' => '12:30:00'],
            CurlHeaders::parse($raw),
        );
    }

    /**
     * @return void
     */
    public function test_parse_keeps_only_the_final_block_after_redirects(): void
    {
        $raw = "HTTP/1.1 302 Found\r\nLocation: /next\r\nX-Hop: 1\r\n\r\n"
            . "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n\r\n";

        $this->assertSame(['content-type' => 'application/json'], CurlHeaders::parse($raw));
    }

    /**
     * @return void
     */
    public function test_parse_returns_empty_array_for_empty_input(): void
    {
        $this->assertSame([], CurlHeaders::parse(''));
    }
}
