<?php
/**
 * One-time setup helper. Run: php install.php
 * Or open in browser while PHP server is running.
 */

declare(strict_types=1);

$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    die("Missing config/config.php\n");
}

$config = require $configPath;
$local = __DIR__ . '/config/config.local.php';
if (file_exists($local)) {
    $config = array_replace_recursive($config, require $local);
}

$host = $config['db']['host'];
$port = (int) $config['db']['port'];
$user = $config['db']['user'];
$pass = $config['db']['pass'];
$dbName = $config['db']['name'];

echo "NFC Tuckshop — Database installer\n\n";

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('Could not read sql/schema.sql');
    }
    $pdo->exec($sql);

    $migration = __DIR__ . '/sql/migration_inventory_sellers.sql';
    if (file_exists($migration)) {
        $migrationSql = file_get_contents($migration);
        if ($migrationSql !== false) {
            foreach (preg_split('/;\s*\n/', $migrationSql) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '' || stripos($stmt, 'USE ') === 0) {
                    continue;
                }
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    if (stripos($e->getMessage(), 'duplicate column') === false) {
                        throw $e;
                    }
                }
            }
        }
    }

    echo "✓ Database '$dbName' created and seeded.\n";
    echo "✓ Default admin password: " . ($config['admin_password'] ?? '(see config)') . "\n";
    echo "\nNext steps:\n";
    echo "  1. Change admin_password in config/config.local.php\n";
    echo "  2. php -S 0.0.0.0:8080 -t " . __DIR__ . "\n";
    echo "  3. Open http://localhost:8080 on POS machines\n";
} catch (Throwable $e) {
    echo "✗ Install failed: " . $e->getMessage() . "\n";
    echo "\nEnsure MySQL is running and credentials in config/config.php are correct.\n";
    exit(1);
}
