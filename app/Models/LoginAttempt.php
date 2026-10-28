<?php
declare(strict_types=1);

namespace App\Models;

/**
 * ตัวนับการลองผิด (login / ยืนยันรหัสผ่านเดิม) ต่อ key — key เป็น sha256 ของ "ip|..." หรือ "user|..."
 * คอลัมน์ยังชื่อ ip_hash ตามตารางเดิม (ไม่ต้องแก้ schema)
 */
class LoginAttempt extends Model
{
    protected string $table = 'refer_login_attempts';
    protected string $primaryKey = 'ip_hash';
    protected array $fillable = ['ip_hash', 'attempts', 'window_start'];
    protected array $casts = [
        'attempts' => 'integer',
        'window_start' => 'integer',
    ];

    /**
     * +1 ภายในหน้าต่างเวลา (เริ่มนับใหม่เมื่อหน้าต่างเก่าหมด) — คำสั่งเดียวแบบ atomic
     * เดิม find แล้วค่อย create: สอง request พร้อมกันชน PK ได้ 500 และนับขาด
     */
    public function increment(string $key, int $windowSeconds = 60): int
    {
        $now = time();
        $expired = $now - $windowSeconds;
        // MariaDB ประเมิน SET จากซ้ายไปขวา: attempts ต้องมาก่อน window_start (ยังเห็นค่าเดิม)
        $this->query(
            "INSERT INTO {$this->table} (ip_hash, attempts, window_start) VALUES (?, 1, ?)
             ON DUPLICATE KEY UPDATE
                 attempts = IF(window_start < ?, 1, attempts + 1),
                 window_start = IF(window_start < ?, VALUES(window_start), window_start)",
            [$key, $now, $expired, $expired]
        );
        return $this->getAttempts($key, $windowSeconds);
    }

    public function getAttempts(string $key, int $windowSeconds = 60): int
    {
        $existing = $this->find($key);
        if (!$existing) return 0;

        if ($existing['window_start'] < time() - $windowSeconds) {
            return 0;
        }
        return $existing['attempts'];
    }

    public function reset(string $key): bool
    {
        return $this->delete($key);
    }
}
