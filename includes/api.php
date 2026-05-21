<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $origins = app_config('cors_origins', ['*']);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    if (in_array('*', $origins, true) || in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . (in_array('*', $origins, true) ? '*' : $origin));
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token, X-Seller-Token');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $code = 400): void
{
    json_response(['ok' => false, 'error' => $message], $code);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function is_admin(): bool
{
    $token = $_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ($_GET['admin_token'] ?? '');
    return $token !== '' && hash_equals((string) app_config('admin_password'), (string) $token);
}

function require_admin(): void
{
    if (!is_admin()) {
        json_error('Unauthorized', 401);
    }
}

function get_seller_from_request(): ?array
{
    static $seller = null;
    if ($seller !== null) {
        return $seller ?: null;
    }
    $token = trim($_SERVER['HTTP_X_SELLER_TOKEN'] ?? '');
    if ($token === '') {
        $seller = false;
        return null;
    }
    require_once __DIR__ . '/database.php';
    $stmt = db()->prepare('SELECT id, username, full_name FROM sellers WHERE api_token = ? AND is_active = 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    $seller = $row ?: false;
    return $row ?: null;
}

function require_admin_or_seller(): void
{
    if (is_admin() || get_seller_from_request()) {
        return;
    }
    json_error('Unauthorized', 401);
}

function require_seller(): void
{
    if (!get_seller_from_request()) {
        json_error('Seller login required', 401);
    }
}

function generate_wallet_id(): string
{
    return sprintf('%s-%s', date('Y'), bin2hex(random_bytes(4)));
}

function get_settings(PDO $pdo): array
{
    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[$row['setting_key']] = $row['setting_value'];
    }
    return $out;
}

function get_setting(PDO $pdo, string $key, string $default = ''): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string) $val : $default;
}

function student_daily_spend(PDO $pdo, int $studentId, string $date): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_amount), 0) FROM transactions
         WHERE student_id = ? AND status = 'completed' AND DATE(created_at) = ?"
    );
    $stmt->execute([$studentId, $date]);
    return (float) $stmt->fetchColumn();
}

function category_count_today(PDO $pdo, int $studentId, string $category, string $date): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(count, 0) FROM daily_purchase_limits
         WHERE student_id = ? AND purchase_date = ? AND category = ?'
    );
    $stmt->execute([$studentId, $date, $category]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int) $val : 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    json_response(['ok' => true]);
}
