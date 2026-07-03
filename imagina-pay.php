<?php
/**
 * Plugin Name:       Imagina Pay
 * Plugin URI:        https://imaginawp.com
 * Description:       Venta de productos digitales y suscripciones para LATAM con Mercado Pago y PayPal. Sin WooCommerce.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Imagina WP
 * Author URI:        https://imaginawp.com
 * License:           Proprietary
 * Text Domain:       imagina-pay
 * Domain Path:       /languages
 *
 * @package ImaginaPay
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('IMPAY_VERSION', '1.1.0');
define('IMPAY_PLUGIN_FILE', __FILE__);
define('IMPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));

$impayAutoload = __DIR__ . '/vendor/autoload.php';

if (!is_readable($impayAutoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html('Imagina Pay: faltan las dependencias de Composer. Ejecuta "composer install" en el directorio del plugin.');
        echo '</p></div>';
    });

    return;
}

require_once $impayAutoload;

register_activation_hook(__FILE__, static function (): void {
    \ImaginaPay\Core\Activator::activate();
});

add_action('plugins_loaded', static function (): void {
    \ImaginaPay\Core\Plugin::boot();
});
