<?php
declare(strict_types=1);

namespace App\Services\Logging;

use PDO;

/**
 * Connection แยกไปฐาน log (ค่าเริ่มต้น refer_logs — migration 017/018)
 *
 * แยกจาก Database::getConnection() โดยตั้งใจ:
 *   - log ไม่ไปปนกับ transaction ของงานหลัก (rollBack งานหลักไม่ลบ log ความล้มเหลว)
 *   - บัญชีเดียวกับแอป แต่บนฐานนี้มีสิทธิ์แค่ SELECT/INSERT (018) — แก้/ลบ log ย้อนหลังไม่ได้
 * ต่อเมื่อมีการเขียน log ครั้งแรกใน request เท่านั้น (lazy) และถ้าต่อไม่ได้จะไม่ลองซ้ำใน request เดิม
 */
final class LogConnection
{
    private static ?PDO $pdo = null;
    private static bool $failed = false;

    public static function get(): ?PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        if (self::$failed) {
            return null;
        }
        try {
            $config = require __DIR__ . '/../../../config/database.php';
            $conn = $config['connections']['mysql'];
            $dbName = (string)(getenv('LOG_DB_DATABASE') ?: 'refer_logs');
            $dsn = "mysql:host={$conn['host']};port={$conn['port']};dbname={$dbName};charset={$conn['charset']}";
            $options = $conn['options'] ?? [];
            $options[PDO::ATTR_TIMEOUT] = 3;
            self::$pdo = new PDO($dsn, $conn['username'] ?? null, $conn['password'] ?? null, $options);
        } catch (\Throwable $e) {
            self::$failed = true;
            error_log('LOG db connect failed: ' . $e->getMessage());
            return null;
        }
        return self::$pdo;
    }

    /** สำหรับสคริปต์ทดสอบ */
    public static function reset(): void
    {
        self::$pdo = null;
        self::$failed = false;
    }
}
