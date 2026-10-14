<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use Throwable;

class HealthController extends BaseController
{
    public function health(): void
    {
        $config = require __DIR__ . '/../../config/database.php';
        $driver = $config['default'];
        $connConfig = $config['connections'][$driver] ?? [];
        $database = (string)($connConfig['database'] ?? '');

        try {
            $pdo = Database::getConnection();
            $row = $pdo->query('SELECT DATABASE() AS db, VERSION() AS version')->fetch();
            $this->success([
                'connected' => true,
                'driver' => (string)($connConfig['driver'] ?? $driver),
                'database' => (string)($row['db'] ?? $database),
                'version' => (string)($row['version'] ?? ''),
            ]);
        } catch (Throwable $e) {
            // Never leak credentials/DSN details; report only that the connection failed
            $this->error('เชื่อมต่อฐานข้อมูลไม่สำเร็จ', 503, [
                'connected' => false,
                'driver' => (string)($connConfig['driver'] ?? $driver),
                'database' => $database,
            ]);
        }
    }
}
