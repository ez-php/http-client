<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\HttpClient\Http;
use EzPhp\HttpClient\HttpClient;
use EzPhp\HttpClient\HttpRequest;
use EzPhp\HttpClient\HttpResponse;
use EzPhp\HttpClient\TransportInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Transport spy that captures the full request for multipart inspection.
 */
final class MultipartCaptureTransport implements TransportInterface
{
    public ?string $capturedMethod = null;

    public ?string $capturedUrl = null;

    /**
     * @var array<string, string>
     */
    public array $capturedHeaders = [];

    public ?string $capturedBody = null;

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, string $body): HttpResponse
    {
        $this->capturedMethod = $method;
        $this->capturedUrl = $url;
        $this->capturedHeaders = $headers;
        $this->capturedBody = $body;

        return new HttpResponse(200, 'ok');
    }
}

/**
 * Class MultipartTest
 *
 * Tests HttpRequest::attach() — multipart/form-data uploads.
 *
 * @package Tests
 */
#[CoversClass(HttpRequest::class)]
#[UsesClass(HttpClient::class)]
#[UsesClass(HttpResponse::class)]
#[UsesClass(Http::class)]
final class MultipartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::resetClient();
    }

    protected function tearDown(): void
    {
        Http::resetClient();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function test_attach_sets_multipart_content_type_header(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('file', 'contents', 'report.pdf', 'application/pdf')
            ->send();

        $this->assertArrayHasKey('Content-Type', $spy->capturedHeaders);
        $this->assertStringStartsWith(
            'multipart/form-data; boundary=',
            $spy->capturedHeaders['Content-Type'],
        );
    }

    /**
     * @return void
     */
    public function test_attach_body_contains_field_name(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('report', 'file contents', 'report.pdf')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $this->assertStringContainsString('name="report"', (string) $spy->capturedBody);
    }

    /**
     * @return void
     */
    public function test_attach_body_contains_filename(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('file', 'pdf bytes', 'my-file.pdf')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $this->assertStringContainsString('filename="my-file.pdf"', (string) $spy->capturedBody);
    }

    /**
     * @return void
     */
    public function test_attach_body_contains_file_contents(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('data', 'hello world', 'hello.txt', 'text/plain')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $this->assertStringContainsString('hello world', (string) $spy->capturedBody);
    }

    /**
     * @return void
     */
    public function test_attach_body_contains_mime_type(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('img', 'png data', 'photo.png', 'image/png')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $this->assertStringContainsString('Content-Type: image/png', (string) $spy->capturedBody);
    }

    /**
     * @return void
     */
    public function test_attach_without_filename_omits_filename_attribute(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('field', 'value')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $this->assertStringNotContainsString('filename=', (string) $spy->capturedBody);
    }

    /**
     * @return void
     */
    public function test_multiple_attachments_all_present_in_body(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('file1', 'contents1', 'a.txt')
            ->attach('file2', 'contents2', 'b.txt')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $body = (string) $spy->capturedBody;
        $this->assertStringContainsString('name="file1"', $body);
        $this->assertStringContainsString('name="file2"', $body);
        $this->assertStringContainsString('contents1', $body);
        $this->assertStringContainsString('contents2', $body);
    }

    /**
     * @return void
     */
    public function test_attach_is_immutable(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $base = $client->post('https://api.example.com/upload');
        $withFile = $base->attach('file', 'data', 'file.txt');

        // Base request must not have multipart content-type
        $base->send();
        $this->assertArrayNotHasKey('Content-Type', $spy->capturedHeaders);

        // Only the clone has the attachment — use a fresh spy to avoid type-narrowing
        $spy2 = new MultipartCaptureTransport();
        $client2 = new HttpClient($spy2);
        $client2->post('https://api.example.com/upload')
            ->attach('file', 'data', 'file.txt')
            ->send();
        $this->assertArrayHasKey('Content-Type', $spy2->capturedHeaders);
        $this->assertStringStartsWith('multipart/form-data', $spy2->capturedHeaders['Content-Type']);
    }

    /**
     * @return void
     */
    public function test_attach_body_has_valid_multipart_boundaries(): void
    {
        $spy = new MultipartCaptureTransport();
        $client = new HttpClient($spy);

        $client->post('https://api.example.com/upload')
            ->attach('f', 'data', 'f.bin')
            ->send();

        $this->assertNotNull($spy->capturedBody);
        $body = (string) $spy->capturedBody;

        // Extract boundary from Content-Type
        preg_match('/boundary=(.+)$/', $spy->capturedHeaders['Content-Type'], $matches);
        $boundary = $matches[1] ?? '';

        $this->assertNotEmpty($boundary);
        $this->assertStringContainsString("--{$boundary}\r\n", $body);
        $this->assertStringContainsString("--{$boundary}--\r\n", $body);
    }
}
