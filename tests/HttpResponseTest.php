<?php

declare(strict_types=1);

namespace Tests\HttpClient;

use EzPhp\HttpClient\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class HttpResponseTest
 *
 * @package Tests\HttpClient
 */
#[CoversClass(HttpResponse::class)]
final class HttpResponseTest extends TestCase
{
    /**
     * @return void
     */
    public function test_status_returns_status_code(): void
    {
        $response = new HttpResponse(200, 'body');

        $this->assertSame(200, $response->status());
    }

    /**
     * @return void
     */
    public function test_body_returns_raw_body(): void
    {
        $response = new HttpResponse(200, 'hello world');

        $this->assertSame('hello world', $response->body());
    }

    /**
     * @return void
     */
    public function test_json_decodes_json_body(): void
    {
        $response = new HttpResponse(200, '{"name":"Alice","age":30}');

        $this->assertSame(['name' => 'Alice', 'age' => 30], $response->json());
    }

    /**
     * @return void
     */
    public function test_json_returns_null_for_empty_body(): void
    {
        $response = new HttpResponse(200, '');

        $this->assertNull($response->json());
    }

    /**
     * @return void
     */
    public function test_json_returns_null_for_invalid_json(): void
    {
        $response = new HttpResponse(200, 'not json');

        $this->assertNull($response->json());
    }

    /**
     * @return void
     */
    public function test_ok_returns_true_for_200(): void
    {
        $response = new HttpResponse(200, '');

        $this->assertTrue($response->ok());
    }

    /**
     * @return void
     */
    public function test_ok_returns_true_for_201(): void
    {
        $response = new HttpResponse(201, '');

        $this->assertTrue($response->ok());
    }

    /**
     * @return void
     */
    public function test_ok_returns_true_for_299(): void
    {
        $response = new HttpResponse(299, '');

        $this->assertTrue($response->ok());
    }

    /**
     * @return void
     */
    public function test_ok_returns_false_for_400(): void
    {
        $response = new HttpResponse(400, '');

        $this->assertFalse($response->ok());
    }

    /**
     * @return void
     */
    public function test_ok_returns_false_for_500(): void
    {
        $response = new HttpResponse(500, '');

        $this->assertFalse($response->ok());
    }

    /**
     * @return void
     */
    public function test_ok_returns_false_for_404(): void
    {
        $response = new HttpResponse(404, '');

        $this->assertFalse($response->ok());
    }

    /**
     * @return void
     */
    public function test_header_returns_header_value(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'application/json']);

        $this->assertSame('application/json', $response->header('content-type'));
    }

    /**
     * @return void
     */
    public function test_header_is_case_insensitive(): void
    {
        $response = new HttpResponse(200, '', ['content-type' => 'application/json']);

        $this->assertSame('application/json', $response->header('Content-Type'));
    }

    /**
     * @return void
     */
    public function test_header_returns_default_when_absent(): void
    {
        $response = new HttpResponse(200, '');

        $this->assertSame('default', $response->header('x-missing', 'default'));
    }

    /**
     * @return void
     */
    public function test_header_returns_empty_string_as_default(): void
    {
        $response = new HttpResponse(200, '');

        $this->assertSame('', $response->header('x-missing'));
    }
}
