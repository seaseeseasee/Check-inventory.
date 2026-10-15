<?php
declare(strict_types=1);

namespace App\Exceptions;

/**
 * ข้อผิดพลาดทางธุรกิจที่ต้องตอบกลับเป็น JSON พร้อม HTTP status เฉพาะกรณี
 * เช่น 409 = ลบรถถาวรไม่ได้เพราะมีประวัติตรวจหรืองานเติมค้างอยู่
 * (ต้องส่งจำนวนรายการที่ผูกกลับไปให้ UI ใช้ตัดสินใจ "งดใช้งานแทน" ด้วย)
 *
 * สืบทอด RuntimeException เพื่อให้ dispatcher เดิมใน routes/web.php ยังจับได้
 * แม้เส้นทางนั้นจะยังไม่เพิ่ม catch ของคลาสนี้โดยเฉพาะ
 */
class ApiException extends \RuntimeException
{
    private int $status;
    private array $extra;

    public function __construct(string $message, int $status = 400, array $extra = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->extra = $extra;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }
}
