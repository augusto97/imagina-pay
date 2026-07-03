<?php

declare(strict_types=1);

namespace ImaginaPay\Mail;

use ImaginaPay\Core\Settings;
use ImaginaPay\Support\Logger;

/**
 * Envío de correo transaccional sobre wp_mail (un plugin SMTP externo
 * puede engancharse a wp_mail sin tocar este código). Cada envío queda
 * en impay_logs.
 */
class Mailer
{
    public function __construct(
        private readonly EmailTemplate $template,
        private readonly Settings $settings,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param list<string> $paragraphs Párrafos HTML del cuerpo.
     */
    public function send(
        string $to,
        string $subject,
        string $heading,
        array $paragraphs,
        ?string $ctaLabel = null,
        ?string $ctaUrl = null,
    ): bool {
        $html = $this->template->render($heading, $paragraphs, $ctaLabel, $ctaUrl);

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $fromName = $this->settings->get('email_from_name', '');
        $fromAddress = $this->settings->get('email_from_address', '');

        if (is_string($fromName) && $fromName !== '' && is_string($fromAddress) && $fromAddress !== '') {
            $headers[] = sprintf('From: %s <%s>', $fromName, $fromAddress);
        }

        $sent = wp_mail($to, $subject, $html, $headers);

        if ($sent) {
            $this->logger->info('mail', sprintf('Email enviado: "%s".', $subject), ['to' => $to]);
        } else {
            $this->logger->error('mail', sprintf('Fallo al enviar email: "%s".', $subject), ['to' => $to]);
        }

        return $sent;
    }

    /**
     * Correo al administrador del sitio (o al remitente configurado).
     *
     * @param list<string> $paragraphs
     */
    public function sendToAdmin(string $subject, string $heading, array $paragraphs): bool
    {
        $adminEmail = get_option('admin_email', '');

        if (!is_string($adminEmail) || $adminEmail === '') {
            return false;
        }

        return $this->send($adminEmail, $subject, $heading, $paragraphs);
    }
}
