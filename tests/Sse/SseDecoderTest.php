<?php

declare(strict_types=1);

namespace Tests\HttpClient\Sse;

use EzPhp\HttpClient\Sse\SseDecoder;
use EzPhp\HttpClient\Sse\SseMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class SseDecoderTest
 *
 * @package Tests\HttpClient\Sse
 */
#[CoversClass(SseDecoder::class)]
#[CoversClass(SseMessage::class)]
final class SseDecoderTest extends TestCase
{
    /**
     * Mixed line endings, comments, named events, ids, multi-line data.
     */
    private const string FIXTURE = "\xEF\xBB\xBF: stream opened\n"
        . "event: message_start\r\n"
        . "data: {\"type\":\"message_start\"}\r\n"
        . "\r\n"
        . "data: first line\n"
        . "data: second line\n"
        . "id: 7\n"
        . "\n"
        . "event: content_block_delta\r"
        . "data: {\"text\":\"Hällo\"}\r"
        . "\r"
        . "retry: 3000\n"
        . "data\n"
        . "\n"
        . "data: [DONE]\n\n";

    public function test_decodes_a_single_event(): void
    {
        $this->assertSame([['message', 'hello', null]], self::decodeAll(["data: hello\n\n"]));
    }

    public function test_joins_multiple_data_lines_with_a_newline(): void
    {
        $this->assertSame([['message', "a\nb", null]], self::decodeAll(["data: a\ndata: b\n\n"]));
    }

    public function test_reads_event_and_id_fields(): void
    {
        $this->assertSame([['ping', 'x', '42']], self::decodeAll(["event: ping\nid: 42\ndata: x\n\n"]));
    }

    public function test_id_persists_across_events_and_event_type_resets(): void
    {
        $this->assertSame(
            [['custom', '1', 'a'], ['message', '2', 'a']],
            self::decodeAll(["id: a\nevent: custom\ndata: 1\n\ndata: 2\n\n"]),
        );
    }

    public function test_ignores_comment_lines(): void
    {
        $this->assertSame([['message', 'x', null]], self::decodeAll([": ping\ndata: x\n: another\n\n"]));
    }

    public function test_strips_exactly_one_optional_space_after_the_colon(): void
    {
        $this->assertSame(
            [['message', 'a', null], ['message', 'b', null], ['message', ' c', null]],
            self::decodeAll(["data:a\n\ndata: b\n\ndata:  c\n\n"]),
        );
    }

    public function test_field_without_colon_has_an_empty_value(): void
    {
        $this->assertSame([['message', '', null]], self::decodeAll(["data\n\n"]));
    }

    public function test_ignores_retry_and_unknown_fields(): void
    {
        $this->assertSame([['message', 'x', null]], self::decodeAll(["retry: 10\nfoo: bar\ndata: x\n\n"]));
    }

    public function test_accepts_crlf_and_cr_line_endings(): void
    {
        $this->assertSame(
            [['message', 'a', null], ['message', 'b', null]],
            self::decodeAll(["data: a\r\n\r\ndata: b\r\r"]),
        );
    }

    public function test_crlf_split_across_chunks_is_one_line_ending(): void
    {
        $this->assertSame(
            [['message', "a\nb", null]],
            self::decodeAll(["data: a\r", "\ndata: b\r", "\n\r", "\n"]),
        );
    }

    public function test_trailing_cr_at_end_of_stream_terminates_the_line(): void
    {
        $this->assertSame([['message', 'a', null]], self::decodeAll(["data: a\r\r"]));
    }

    public function test_skips_a_bom_even_when_split_across_chunks(): void
    {
        $this->assertSame([['message', 'x', null]], self::decodeAll(["\xEF", "\xBB\xBFdata: x\n\n"]));
    }

    public function test_discards_a_trailing_event_without_a_blank_line(): void
    {
        $this->assertSame([['message', 'complete', null]], self::decodeAll(["data: complete\n\ndata: cut"]));
        $this->assertSame([], self::decodeAll(["data: cut\n"]));
    }

    public function test_does_not_dispatch_an_event_without_data(): void
    {
        $this->assertSame([], self::decodeAll(["event: start\nid: 1\n\n"]));
    }

    public function test_empty_input_yields_nothing(): void
    {
        $this->assertSame([], self::decodeAll([]));
        $this->assertSame([], self::decodeAll(['', '']));
    }

    public function test_fixture_decodes_to_the_expected_messages(): void
    {
        $this->assertSame(self::expectedFixtureMessages(), self::decodeAll([self::FIXTURE]));
    }

    public function test_fixture_split_at_every_byte_offset_decodes_identically(): void
    {
        $expected = self::expectedFixtureMessages();
        $length = strlen(self::FIXTURE);

        for ($offset = 0; $offset <= $length; $offset++) {
            $chunks = [substr(self::FIXTURE, 0, $offset), substr(self::FIXTURE, $offset)];

            $this->assertSame($expected, self::decodeAll($chunks), "split at byte {$offset}");
        }
    }

    public function test_fixture_fed_byte_by_byte_decodes_identically(): void
    {
        $this->assertSame(self::expectedFixtureMessages(), self::decodeAll(str_split(self::FIXTURE)));
    }

    /**
     * @return list<array{string, string, string|null}>
     */
    private static function expectedFixtureMessages(): array
    {
        return [
            ['message_start', '{"type":"message_start"}', null],
            ['message', "first line\nsecond line", '7'],
            ['content_block_delta', '{"text":"Hällo"}', '7'],
            ['message', '', '7'],
            ['message', '[DONE]', '7'],
        ];
    }

    /**
     * @param iterable<string> $chunks
     *
     * @return list<array{string, string, string|null}>
     */
    private static function decodeAll(iterable $chunks): array
    {
        $messages = [];

        foreach (SseDecoder::decode($chunks) as $message) {
            $messages[] = [$message->event(), $message->data(), $message->id()];
        }

        return $messages;
    }
}
