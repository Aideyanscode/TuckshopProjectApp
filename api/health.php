<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/api.php';

try {
    require_once dirname(__DIR__) . '/includes/database.php';
    db()->query('SELECT 1');
    json_response(['ok' => true, 'database' => 'connected', 'time' => date('c')]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'database' => 'error', 'message' => $e->getMessage()], 503);
}
