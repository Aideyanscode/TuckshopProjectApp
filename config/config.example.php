<?php
/**
 * Copy this file to config.local.php and adjust for your school server.
 */
return [
    'db' => [
        'host' => '192.168.0.101',
        'port' => 3306,
        'name' => 'tuckshop',
        'user' => 'tuckshop_user',
        'pass' => 'your_secure_password',
        'charset' => 'utf8mb4',
    ],
    'admin_password' => 'change_this_password',
    'timezone' => 'Africa/Lagos',
    'cors_origins' => ['*'],
    'paystack' => [
        'public_key' => 'pk_test_your_public_key',
        'secret_key' => 'sk_test_your_secret_key',
    ],
    'order_sync' => [
        'instance_name' => 'online-pwa',
        'shared_token' => 'shared_secret_between_online_and_local_servers',
        'export_orders' => true,
        'pull_remote_orders' => false,
        'remote_base_url' => 'https://your-online-domain.example/api',
        'push_inventory_snapshot' => false,
        'daily_sync_hour' => 15,
    ],
];
