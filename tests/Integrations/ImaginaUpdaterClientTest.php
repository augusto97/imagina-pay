<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Integrations;

use Brain\Monkey\Functions;
use ImaginaPay\Core\Settings;
use ImaginaPay\Exceptions\GatewayException;
use ImaginaPay\Http\HttpClient;
use ImaginaPay\Http\HttpResponse;
use ImaginaPay\Integrations\ImaginaUpdaterClient;
use ImaginaPay\Support\Crypto;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\Support\EntityFactory;
use ImaginaPay\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

final class ImaginaUpdaterClientTest extends TestCase
{
    use EntityFactory;

    /** @var HttpClient&MockInterface */
    private HttpClient $http;

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_option')->alias(fn (string $key, mixed $default = false): mixed => $this->options[$key] ?? $default);

        /** @var HttpClient&MockInterface $http */
        $http = Mockery::mock(HttpClient::class);
        $this->http = $http;
    }

    private function client(bool $configured = true): ImaginaUpdaterClient
    {
        $crypto = Crypto::fromAuthKey('clave-de-tests-para-updater');
        $apiKey = $crypto->encrypt('api-key-secreta');

        $this->options['impay_settings'] = $configured
            ? ['updater_api_url' => 'https://updates.imaginawp.com/', 'updater_api_key' => $apiKey]
            : [];

        return new ImaginaUpdaterClient($this->http, new Settings($crypto), new NullLogger());
    }

    public function testCreateLicensePostsToUpdaterWithApiKey(): void
    {
        $this->http->shouldReceive('post')
            ->once()
            ->andReturnUsing(function (string $url, array $headers, string $body): HttpResponse {
                $this->assertSame('https://updates.imaginawp.com/wp-json/imagina-updater/v1/licenses', $url);
                $this->assertSame('api-key-secreta', $headers['X-Api-Key']);

                $decoded = json_decode($body, true);
                $this->assertSame('cliente@example.com', $decoded['email']);
                $this->assertSame(12, $decoded['product_id']);
                $this->assertSame('sub-uuid-ref', $decoded['reference']);

                return new HttpResponse(201, (string) json_encode(['license_key' => 'LIC-999']));
            });

        $key = $this->client()->createLicense($this->makeCustomer(), 12, 'sub-uuid-ref');

        $this->assertSame('LIC-999', $key);
    }

    public function testCreateLicenseFailureThrows(): void
    {
        $this->http->shouldReceive('post')->andReturn(new HttpResponse(500, '{}'));

        $this->expectException(GatewayException::class);
        $this->client()->createLicense($this->makeCustomer(), 12, 'ref');
    }

    public function testDeactivateLicensePostsToDeactivateEndpoint(): void
    {
        $this->http->shouldReceive('post')
            ->once()
            ->andReturnUsing(function (string $url): HttpResponse {
                $this->assertStringContainsString('/licenses/LIC-1/deactivate', $url);

                return new HttpResponse(200, '{}');
            });

        $this->client()->deactivateLicense('LIC-1');
    }

    public function testUnconfiguredClientThrows(): void
    {
        $this->expectException(GatewayException::class);
        $this->client(false)->createLicense($this->makeCustomer(), 12, 'ref');
    }

    public function testIsConfigured(): void
    {
        $this->assertTrue($this->client()->isConfigured());
        $this->assertFalse($this->client(false)->isConfigured());
    }
}
