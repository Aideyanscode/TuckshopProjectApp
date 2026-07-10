<?php
/**
 * Tuckshop system configuration.
 * Copy config.example.php to config.local.php and edit for your school LAN.
 */

return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'tuckshop',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'admin_password' => 'tuckshop2026',
    'timezone' => 'Africa/Lagos',
    'cors_origins' => ['*'],
    'paystack' => [
        'public_key' => 'pk_test_c40f81dc7cf9e251b3ba774e48d627cc18e311dc',
        'secret_key' => 'sk_test_f3babc3b345b3aeb3f44e2ca5b250ca6e32369de',
    ],
    'order_sync' => [
        'instance_name' => 'local-school-server',
        'shared_token' => 'change_sync_token',
        'export_orders' => false,
        'pull_remote_orders' => false,
        'remote_base_url' => '',
        'push_inventory_snapshot' => false,
        'daily_sync_hour' => 15,
    ],
];
