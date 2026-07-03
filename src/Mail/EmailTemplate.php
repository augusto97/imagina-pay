<?php

declare(strict_types=1);

namespace ImaginaPay\Mail;

use ImaginaPay\Core\Settings;

/**
 * Plantilla HTML única para todos los correos (600px, estilos inline,
 * compatible con clientes de correo). Logo y color de marca configurables.
 */
final class EmailTemplate
{
    private const DEFAULT_COLOR = '#4F46E5';

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param list<string> $paragraphs Párrafos ya escapados/formateados en HTML.
     */
    public function render(string $heading, array $paragraphs, ?string $ctaLabel = null, ?string $ctaUrl = null): string
    {
        $color = $this->brandColor();
        $logo = $this->settings->get('brand_logo_url', '');
        $fromName = $this->settings->get('email_from_name', 'Imagina WP');
        $fromName = is_string($fromName) && $fromName !== '' ? $fromName : 'Imagina WP';

        $logoHtml = is_string($logo) && $logo !== ''
            ? sprintf(
                '<img src="%s" alt="%s" height="40" style="height:40px;max-height:40px;border:0;" />',
                esc_url($logo),
                esc_attr($fromName),
            )
            : sprintf(
                '<span style="font-size:20px;font-weight:600;color:%s;">%s</span>',
                esc_attr($color),
                esc_html($fromName),
            );

        $bodyHtml = '';

        foreach ($paragraphs as $paragraph) {
            $bodyHtml .= sprintf(
                '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#18181B;">%s</p>',
                $paragraph,
            );
        }

        $ctaHtml = '';

        if ($ctaLabel !== null && $ctaUrl !== null) {
            $ctaHtml = sprintf(
                '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="border-radius:10px;background:%s;">'
                . '<a href="%s" style="display:inline-block;padding:12px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">%s</a>'
                . '</td></tr></table>',
                esc_attr($color),
                esc_url($ctaUrl),
                esc_html($ctaLabel),
            );
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#FAFAFA;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAFAFA;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #E4E4E7;border-radius:12px;">
<tr><td style="padding:32px 40px 0;">{$logoHtml}</td></tr>
<tr><td style="padding:24px 40px 8px;">
<h1 style="margin:0 0 16px;font-size:20px;font-weight:600;color:#18181B;">{$heading}</h1>
{$bodyHtml}
{$ctaHtml}
</td></tr>
<tr><td style="padding:16px 40px 32px;">
<p style="margin:0;font-size:12px;color:#71717A;">Este es un mensaje automático de {$fromName}. Si tienes dudas, responde este correo.</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
    }

    private function brandColor(): string
    {
        $color = $this->settings->get('brand_color', self::DEFAULT_COLOR);

        if (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
            return $color;
        }

        return self::DEFAULT_COLOR;
    }
}
