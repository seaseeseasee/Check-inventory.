<?php
declare(strict_types=1);

namespace App\Models;

/** หัวรายการเติมของเข้ารถโดยตรง 1 ครั้ง — client_token UNIQUE กันกดบันทึกซ้ำ */
class RefillBatch extends Model
{
    protected string $table = 'refer_refill_batches';
    protected string $primaryKey = 'id';
    protected array $fillable = ['id', 'car_id', 'client_token', 'by_user_id', 'by_name', 'note', 'at'];
    protected array $casts = [];

    public function findByToken(string $token): ?array
    {
        return $this->firstWhere('client_token', $token);
    }
}
