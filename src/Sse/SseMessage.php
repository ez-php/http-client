<?php

declare(strict_types=1);

namespace EzPhp\HttpClient\Sse;

/**
 * Class SseMessage
 *
 * One Server-Sent Event as received by a client. The server-side counterpart
 * that writes events is EzPhp\Http\Sse\SseEvent in ez-php/http.
 *
 * @package EzPhp\HttpClient\Sse
 */
final readonly class SseMessage
{
    /**
     * SseMessage Constructor
     *
     * @param string      $data  The `data` lines joined with "\n".
     * @param string      $event The event type; `message` when the stream named none.
     * @param string|null $id    The last event id seen on the stream, if any.
     */
    public function __construct(
        private string $data,
        private string $event = 'message',
        private ?string $id = null,
    ) {
    }

    /**
     * @return string
     */
    public function data(): string
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function event(): string
    {
        return $this->event;
    }

    /**
     * @return string|null
     */
    public function id(): ?string
    {
        return $this->id;
    }
}
