<?php

declare(strict_types=1);

namespace ImaginaPay\Tests\Mail;

use Brain\Monkey\Functions;
use ImaginaPay\Core\Settings;
use ImaginaPay\Mail\EmailTemplate;
use ImaginaPay\Mail\Mailer;
use ImaginaPay\Support\Crypto;
use ImaginaPay\Support\NullLogger;
use ImaginaPay\Tests\TestCase;

final class MailerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(function (string $key, mixed $default = false): mixed {
            return $this->options[$key] ?? $default;
        });
        Functions\when('esc_url')->returnArg();
    }

    private function mailer(): Mailer
    {
        $settings = new Settings(Crypto::fromAuthKey('clave-de-tests-para-mailer'));

        return new Mailer(new EmailTemplate($settings), $settings, new NullLogger());
    }

    public function testSendsHtmlEmailWithTemplateAndBranding(): void
    {
        $this->options['impay_settings'] = [
            'email_from_name' => 'Imagina WP',
            'email_from_address' => 'pagos@imaginawp.com',
            'brand_color' => '#123456',
        ];

        Functions\expect('wp_mail')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $body, array $headers): bool {
                $this->assertSame('cliente@example.com', $to);
                $this->assertSame('Asunto de prueba', $subject);
                $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $headers[0]);
                $this->assertContains('From: Imagina WP <pagos@imaginawp.com>', $headers);
                $this->assertStringContainsString('Título del correo', $body);
                $this->assertStringContainsString('Hola mundo', $body);
                $this->assertStringContainsString('#123456', $body);
                $this->assertStringContainsString('Ver más', $body);
                $this->assertStringContainsString('https://site.test/cta', $body);
                $this->assertStringContainsString('width="600"', $body);

                return true;
            });

        $sent = $this->mailer()->send(
            'cliente@example.com',
            'Asunto de prueba',
            'Título del correo',
            ['Hola mundo'],
            'Ver más',
            'https://site.test/cta',
        );

        $this->assertTrue($sent);
    }

    public function testOmitsFromHeaderWhenNotConfigured(): void
    {
        Functions\expect('wp_mail')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $body, array $headers): bool {
                $this->assertCount(1, $headers);

                return true;
            });

        $this->mailer()->send('a@b.co', 'X', 'X', ['x']);
    }

    public function testInvalidBrandColorFallsBackToDefault(): void
    {
        $this->options['impay_settings'] = ['brand_color' => 'javascript:alert(1)'];

        Functions\expect('wp_mail')
            ->once()
            ->andReturnUsing(function (string $to, string $subject, string $body): bool {
                $this->assertStringNotContainsString('javascript:alert', $body);
                $this->assertStringContainsString('#4F46E5', $body);

                return true;
            });

        $this->mailer()->send('a@b.co', 'X', 'X', ['x']);
    }

    public function testAdminEmailUsesAdminOption(): void
    {
        $this->options['admin_email'] = 'admin@imaginawp.com';

        Functions\expect('wp_mail')
            ->once()
            ->andReturnUsing(function (string $to): bool {
                $this->assertSame('admin@imaginawp.com', $to);

                return true;
            });

        $this->assertTrue($this->mailer()->sendToAdmin('Aviso', 'Aviso', ['contenido']));
    }
}
