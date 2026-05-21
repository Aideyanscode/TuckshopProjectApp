<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config/config.php';
$local = dirname(__DIR__) . '/config/config.local.php';
if (file_exists($local)) {
    $config = array_replace_recursive($config, require $local);
}

date_default_timezone_set($config['timezone'] ?? 'UTC');

function app_config(string $key, mixed $default = null): mixed
{
    global $config;
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $k) {
        if (!is_array($value) || !array_key_exists($k, $value)) {
            return $default;
        }
        $value = $value[$k];
    }
    return $value;
}
