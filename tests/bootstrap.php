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
