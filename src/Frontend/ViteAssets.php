<?php

declare(strict_types=1);

namespace ImaginaPay\Frontend;

/**
 * Encola una entry del manifest de Vite. El CSS puede vivir en los
 * chunks compartidos importados por la entry (no en la entry misma),
 * así que se recorren los imports recursivamente.
 */
final class ViteAssets
{
    public static function enqueue(string $handle, string $entryKey): void
    {
        $manifest = self::manifest();

        if ($manifest === null || !is_array($manifest[$entryKey] ?? null)) {
            return;
        }

        $entry = $manifest[$entryKey];

        if (!is_string($entry['file'] ?? null)) {
            return;
        }

        $baseUrl = plugins_url('frontend/dist/', constant('IMPAY_PLUGIN_FILE'));
        $version = defined('IMPAY_VERSION') ? (string) constant('IMPAY_VERSION') : '1.0';
        $scriptHandle = 'impay-' . $handle;

        wp_enqueue_script($scriptHandle, $baseUrl . $entry['file'], [], $version, true);

        // Vite genera módulos ES.
        add_filter('script_loader_tag', static function (string $tag, string $currentHandle) use ($scriptHandle): string {
            if ($currentHandle === $scriptHandle) {
                return str_replace('<script ', '<script type="module" ', $tag);
            }

            return $tag;
        }, 10, 2);

        $visited = [];
        $cssFiles = self::collectCss($manifest, $entryKey, $visited);

        foreach (array_values(array_unique($cssFiles)) as $index => $cssFile) {
            wp_enqueue_style($scriptHandle . '-' . $index, $baseUrl . $cssFile, [], $version);
        }
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, bool> $visited
     * @return list<string>
     */
    private static function collectCss(array $manifest, string $key, array &$visited): array
    {
        if (isset($visited[$key])) {
            return [];
        }

        $visited[$key] = true;
        $chunk = $manifest[$key] ?? null;

        if (!is_array($chunk)) {
            return [];
        }

        $cssFiles = [];

        foreach (is_array($chunk['css'] ?? null) ? $chunk['css'] : [] as $cssFile) {
            if (is_string($cssFile)) {
                $cssFiles[] = $cssFile;
            }
        }

        foreach (is_array($chunk['imports'] ?? null) ? $chunk['imports'] : [] as $import) {
            if (is_string($import)) {
                $cssFiles = array_merge($cssFiles, self::collectCss($manifest, $import, $visited));
            }
        }

        return $cssFiles;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function manifest(): ?array
    {
        if (!defined('IMPAY_PLUGIN_FILE')) {
            return null;
        }

        $path = constant('IMPAY_PLUGIN_DIR') . 'frontend/dist/.vite/manifest.json';

        if (!is_string($path) || !is_readable($path)) {
            return null;
        }

        // Archivo local del plugin, no una URL remota.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $json = file_get_contents($path);
        $manifest = is_string($json) ? json_decode($json, true) : null;

        /** @var array<string, mixed>|null */
        return is_array($manifest) ? $manifest : null;
    }

    public static function isBuilt(): bool
    {
        return self::manifest() !== null;
    }
}
