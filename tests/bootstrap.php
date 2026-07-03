<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Stub mínimo de wpdb para poder mockear repositorios sin cargar WordPress.
if (!class_exists('wpdb')) {
    #[\AllowDynamicProperties]
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public int $insert_id = 0;
    }
}

// Stub mínimo de WP_Error para probar reintentos del HttpClient.
if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private readonly string $code = '',
            private readonly string $message = '',
        ) {
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}
