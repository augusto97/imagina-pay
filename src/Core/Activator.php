<?php

declare(strict_types=1);

namespace ImaginaPay\Core;

/**
 * Rutina de activación: tablas (dbDelta), capability manage_impay,
 * rol impay_customer y páginas propias (checkout / gracias / mi-cuenta).
 *
 * Nota: las columnas JSON del spec se crean como longtext por
 * compatibilidad con MariaDB 10.4 (donde JSON es un alias de longtext)
 * y con dbDelta. dbDelta no soporta FOREIGN KEY, por lo que las
 * relaciones se garantizan a nivel de aplicación + índices.
 */
final class Activator
{
    private const DB_VERSION = '1.2.0';

    public static function activate(): void
    {
        self::createTables();
        self::registerRolesAndCaps();
        self::createPages();

        update_option('impay_db_version', self::DB_VERSION);

        // La regla /checkout/{slug} debe existir antes del flush.
        \ImaginaPay\Frontend\Shortcodes::registerRewrite();
        flush_rewrite_rules();
    }

    /**
     * Actualización de esquema tras actualizar el plugin por zip (el hook de
     * activación no se dispara en updates). Cuesta un get_option autoloaded;
     * dbDelta solo corre cuando cambió DB_VERSION.
     */
    public static function maybeUpgrade(): void
    {
        if (get_option('impay_db_version') === self::DB_VERSION) {
            return;
        }

        self::createTables();
        self::registerRolesAndCaps();
        update_option('impay_db_version', self::DB_VERSION);
    }

    private static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'impay_';

        $schemas = [];

        $schemas[] = "CREATE TABLE {$prefix}products (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            type enum('one_time','subscription','annual_hybrid') NOT NULL,
            description text NULL,
            features longtext NULL,
            image_url varchar(500) NULL,
            status enum('active','archived','draft') NOT NULL DEFAULT 'draft',
            provisioning longtext NULL,
            custom_fields longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_slug (slug),
            KEY idx_status (status)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}prices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            currency char(3) NOT NULL,
            amount bigint(20) unsigned NOT NULL,
            `interval` enum('one_time','month','year') NOT NULL,
            trial_days smallint(5) unsigned NOT NULL DEFAULT 0,
            gateway_refs longtext NULL,
            status enum('active','archived') NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_product (product_id)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            wp_user_id bigint(20) unsigned NULL,
            email varchar(190) NOT NULL,
            full_name varchar(190) NOT NULL,
            company varchar(190) NULL,
            tax_id_type enum('CC','NIT','CE','PAS','RUT','OTRO') NULL,
            tax_id varchar(40) NULL,
            country char(2) NOT NULL DEFAULT 'CO',
            phone varchar(40) NULL,
            gateway_refs longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_wp_user (wp_user_id),
            UNIQUE KEY idx_email (email)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            price_id bigint(20) unsigned NOT NULL,
            subscription_id bigint(20) unsigned NULL,
            kind enum('purchase','renewal','subscription_initial') NOT NULL,
            status enum('pending','paid','failed','refunded','expired','cancelled') NOT NULL DEFAULT 'pending',
            currency char(3) NOT NULL,
            amount bigint(20) unsigned NOT NULL,
            gateway varchar(30) NOT NULL,
            gateway_ref varchar(120) NULL,
            gateway_payment_id varchar(120) NULL,
            external_reference char(36) NOT NULL,
            paid_at datetime NULL,
            meta longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_extref (external_reference),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_gateway_payment (gateway_payment_id)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}subscriptions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            price_id bigint(20) unsigned NOT NULL,
            gateway varchar(30) NOT NULL,
            gateway_sub_id varchar(120) NULL,
            status enum('pending','active','past_due','paused','cancelled','expired') NOT NULL,
            current_period_start datetime NULL,
            current_period_end datetime NULL,
            cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
            cancelled_at datetime NULL,
            failed_payments tinyint(3) unsigned NOT NULL DEFAULT 0,
            meta longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_gateway_sub (gateway, gateway_sub_id),
            KEY idx_customer (customer_id),
            KEY idx_status (status),
            KEY idx_period_end (current_period_end)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}payments (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            order_id bigint(20) unsigned NULL,
            subscription_id bigint(20) unsigned NULL,
            customer_id bigint(20) unsigned NOT NULL,
            gateway varchar(30) NOT NULL,
            gateway_payment_id varchar(120) NOT NULL,
            status enum('approved','pending','rejected','refunded','charged_back') NOT NULL,
            currency char(3) NOT NULL,
            amount bigint(20) unsigned NOT NULL,
            method varchar(60) NULL,
            paid_at datetime NULL,
            raw longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_gw_payment (gateway, gateway_payment_id),
            KEY idx_subscription (subscription_id),
            KEY idx_customer (customer_id)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}payment_links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            subscription_id bigint(20) unsigned NULL,
            price_id bigint(20) unsigned NOT NULL,
            gateway varchar(30) NOT NULL,
            gateway_ref varchar(120) NULL,
            url varchar(600) NOT NULL,
            status enum('open','paid','expired','void') NOT NULL DEFAULT 'open',
            expires_at datetime NULL,
            paid_order_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            KEY idx_subscription (subscription_id)
        ) {$charsetCollate};";

        // Medios de pago tokenizados (gateways modo Tokenized: Wompi).
        $schemas[] = "CREATE TABLE {$prefix}payment_sources (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            customer_id bigint(20) unsigned NOT NULL,
            gateway varchar(30) NOT NULL,
            gateway_source_id varchar(120) NOT NULL,
            type varchar(20) NOT NULL,
            brand varchar(40) NULL,
            last_four varchar(4) NULL,
            status varchar(30) NOT NULL DEFAULT 'available',
            expires_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_uuid (uuid),
            UNIQUE KEY idx_gw_source (gateway, gateway_source_id),
            KEY idx_customer (customer_id)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}webhook_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gateway varchar(30) NOT NULL,
            event_id varchar(160) NOT NULL,
            topic varchar(80) NULL,
            payload longtext NULL,
            status enum('received','processed','skipped','failed') NOT NULL DEFAULT 'received',
            error text NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            received_at datetime NOT NULL,
            processed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_event (gateway, event_id),
            KEY idx_status (status)
        ) {$charsetCollate};";

        $schemas[] = "CREATE TABLE {$prefix}logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(10) NOT NULL,
            channel varchar(60) NOT NULL,
            message text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_level (level),
            KEY idx_channel (channel),
            KEY idx_created (created_at)
        ) {$charsetCollate};";

        foreach ($schemas as $schema) {
            dbDelta($schema);
        }
    }

    private static function registerRolesAndCaps(): void
    {
        $administrator = get_role('administrator');

        if ($administrator !== null) {
            $administrator->add_cap('manage_impay');
        }

        // Rol de cliente: solo lectura + acceso al portal. Sin wp-admin.
        add_role('impay_customer', 'Cliente Imagina Pay', [
            'read' => true,
            'impay_customer' => true,
        ]);
    }

    /**
     * Crea las 3 páginas propias con sus shortcodes y guarda sus IDs.
     * Slugs deliberadamente sin conflicto con WooCommerce y similares
     * ("checkout" y "mi-cuenta" son slugs reservados por WC).
     */
    private static function createPages(): void
    {
        $pages = [
            'impay_page_checkout' => ['slug' => 'pagar', 'title' => 'Pagar', 'content' => '[impay_checkout]'],
            'impay_page_gracias' => ['slug' => 'gracias-compra', 'title' => 'Gracias por tu compra', 'content' => '[impay_gracias]'],
            'impay_page_portal' => ['slug' => 'portal-cliente', 'title' => 'Portal de cliente', 'content' => '[impay_portal]'],
        ];

        foreach ($pages as $option => $page) {
            $existingId = (int) get_option($option, 0);

            if ($existingId > 0 && get_post($existingId) !== null) {
                continue;
            }

            $pageId = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_name' => $page['slug'],
                'post_title' => $page['title'],
                'post_content' => $page['content'],
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ]);

            if (is_int($pageId) && $pageId > 0) {
                update_option($option, $pageId);
            }
        }
    }
}
