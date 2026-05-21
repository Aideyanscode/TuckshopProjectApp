<?php
/**
 * Copy this file to config.local.php and adjust for your school server.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'tuckshop',
        'user' => 'tuckshop_user',
        'pass' => 'your_secure_password',
        'charset' => 'utf8mb4',
    ],
    'admin_password' => 'change_this_password',
    'timezone' => 'Africa/Lagos',
    'cors_origins' => ['*'],
];
