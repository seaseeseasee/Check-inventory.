<?php
declare(strict_types=1);

namespace App\Config;

use PDO;

class Database
{
    private static ?PDO $connection = null;
    private static array $config = [];

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::connect();
        }
        return self::$connection;
    }

    public static function connect(array $config = []): void
    {
        if (empty($config)) {
            $config = require __DIR__ . '/../../config/database.php';
        }
        self::$config = $config;

        $driver = $config['default'];
        $connConfig = $config['connections'][$driver];

        $dsn = self::buildDsn($connConfig);
        self::$connection = new PDO(
            $dsn,
            $connConfig['username'] ?? null,
            $connConfig['password'] ?? null,
            $connConfig['options'] ?? []
        );
    }

    private static function buildDsn(array $config): string
    {
        $driver = $config['driver'];
        if ($driver === 'sqlite') {
            return "sqlite:{$config['database']}";
        }

        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$config['charset']}";
        return $dsn;
    }

    public static function disconnect(): void
    {
        self::$connection = null;
    }

    public static function table(string $table): string
    {
        $prefix = self::$config['connections'][self::$config['default']]['prefix'] ?? 'refer_';
        return $prefix . $table;
    }
}