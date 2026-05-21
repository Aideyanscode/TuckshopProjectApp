<?php
/**
 * Tuckshop system configuration.
 * Copy config.example.php to config.local.php and edit for your school LAN.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'tuckshop',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'admin_password' => 'tuckshop2026',
    'timezone' => 'Africa/Lagos',
    'cors_origins' => ['*'],
];
