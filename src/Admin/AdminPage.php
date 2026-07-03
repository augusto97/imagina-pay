<?php

declare(strict_types=1);

namespace ImaginaPay\Admin;

/**
 * Página "Imagina Pay" en wp-admin: monta el SPA React full-screen en
 * #impay-root (oculta la UI de WP dentro de la vista, estilo Imagina
 * Reports). Los assets salen del manifest de Vite.
 */
final class AdminPage
{
    private const MENU_SLUG = 'imagina-pay';

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page(
                'Imagina Pay',
                'Imagina Pay',
                'manage_impay',
                self::MENU_SLUG,
                function (): void {
                    $this->render();
                },
                'dashicons-money-alt',
                56,
            );
        });

        add_action('admin_enqueue_scripts', function (string $hookSuffix): void {
            if (str_contains($hookSuffix, self::MENU_SLUG)) {
                $this->enqueueAssets();
            }
        });
    }

    private function render(): void
    {
        $bootData = [
            'restUrl' => esc_url_raw(rest_url('impay/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'adminUrl' => esc_url_raw(admin_url()),
            'userName' => wp_get_current_user()->display_name,
            'gateways' => ['mercadopago', 'paypal'],
        ];

        echo '<style>
            #wpcontent { padding-left: 0 !important; }
            #wpbody-content { padding-bottom: 0 !important; }
            #wpfooter, .update-nag, .notice { display: none !important; }
            #impay-root { min-height: calc(100vh - 32px); background: #FAFAFA; }
        </style>';
        echo '<script type="application/json" id="impay-boot">' . wp_json_encode($bootData) . '</script>';
        echo '<div id="impay-root"></div>';

        if (!$this->manifestPath()) {
            echo '<div style="padding:48px;font-family:sans-serif;color:#71717A;">';
            echo esc_html('El frontend de Imagina Pay no está compilado. Ejecuta "npm install && npm run build" en el directorio frontend/ del plugin.');
            echo '</div>';
        }
    }

    private function enqueueAssets(): void
    {
        $manifestPath = $this->manifestPath();

        if ($manifestPath === null) {
            return;
        }

        // Archivo local del plugin, no una URL remota.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $manifestJson = file_get_contents($manifestPath);
        $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;

        if (!is_array($manifest)) {
            return;
        }

        $entry = $manifest['src/admin/main.tsx'] ?? null;

        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            return;
        }

        $baseUrl = plugins_url('frontend/dist/', constant('IMPAY_PLUGIN_FILE'));
        $version = defined('IMPAY_VERSION') ? (string) constant('IMPAY_VERSION') : '1.0';

        wp_enqueue_script('impay-admin', $baseUrl . $entry['file'], [], $version, true);

        // Vite genera módulos ES.
        add_filter('script_loader_tag', static function (string $tag, string $handle): string {
            if ($handle === 'impay-admin') {
                return str_replace('<script ', '<script type="module" ', $tag);
            }

            return $tag;
        }, 10, 2);

        $styles = is_array($entry['css'] ?? null) ? $entry['css'] : [];

        foreach ($styles as $index => $cssFile) {
            if (is_string($cssFile)) {
                wp_enqueue_style('impay-admin-' . $index, $baseUrl . $cssFile, [], $version);
            }
        }
    }

    private function manifestPath(): ?string
    {
        if (!defined('IMPAY_PLUGIN_DIR')) {
            return null;
        }

        $path = constant('IMPAY_PLUGIN_DIR') . 'frontend/dist/.vite/manifest.json';

        return is_string($path) && is_readable($path) ? $path : null;
    }
}
