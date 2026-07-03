<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Http;

use Brain\Monkey\Functions;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\TestCase;

final class HttpClientTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->sleeps = [];

        Functions\when('is_wp_error')->alias(static fn (mixed $thing): bool => $thing instanceof \WP_Error);
        Functions\when('wp_remote_retrieve_response_code')->alias(
            static fn (array $response): int => (int) $response['response']['code'],
        );
        Functions\when('wp_remote_retrieve_body')->alias(
            static fn (array $response): string => (string) $response['body'],
        );
    }

    private function client(): HttpClient
    {
        return new HttpClient(new NullLogger(), function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });
    }

    /**
     * @return array{response: array{code: int}, body: string}
     */
    private static function wpResponse(int $code, string $body = ''): array
    {
        return ['response' => ['code' => $code], 'body' => $body];
    }

    public function testReturnsSuccessfulResponseWithoutRetrying(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn(self::wpResponse(200, '{"ok":true}'));

        $response = $this->client()->get('https://api.example.com/x');

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->ok());
        $this->assertSame(['ok' => true], $response->json());
        $this->assertSame([], $this->sleeps);
    }

    public function testRetriesOnNetworkErrorThenSucceeds(): void
    {
        Functions\expect('wp_remote_request')
            ->times(3)
            ->andReturn(
                new \WP_Error('http_request_failed', 'timeout'),
                new \WP_Error('http_request_failed', 'timeout'),
                self::wpResponse(200, 'ok'),
            );

        $response = $this->client()->get('https://api.example.com/x');

        $this->assertSame(200, $response->status);
        $this->assertSame([1, 4], $this->sleeps);
    }

    public function testRetriesOn5xxAnd429(): void
    {
        Functions\expect('wp_remote_request')
            ->times(3)
            ->andReturn(
                self::wpResponse(500),
                self::wpResponse(429),
                self::wpResponse(201, 'creado'),
            );

        $response = $this->client()->post('https://api.example.com/x', [], '{}');

        $this->assertSame(201, $response->status);
        $this->assertSame([1, 4], $this->sleeps);
    }

    public function testDoesNotRetryClientErrors(): void
    {
        Functions\expect('wp_remote_request')
            ->once()
            ->andReturn(self::wpResponse(422, '{"message":"bad"}'));

        $response = $this->client()->post('https://api.example.com/x', [], '{}');

        $this->assertSame(422, $response->status);
        $this->assertFalse($response->ok());
        $this->assertSame([], $this->sleeps);
    }

    public function testThrowsAfterExhaustingRetriesWithBackoff1s4s9s(): void
    {
        Functions\expect('wp_remote_request')
            ->times(4)
            ->andReturn(self::wpResponse(503));

        try {
            $this->client()->get('https://api.example.com/x');
            $this->fail('Se esperaba GatewayException.');
        } catch (GatewayException $exception) {
            $this->assertStringContainsString('4 intentos', $exception->getMessage());
        }

        $this->assertSame([1, 4, 9], $this->sleeps);
    }
}
