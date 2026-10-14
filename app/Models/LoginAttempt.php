<?php
declare(strict_types=1);

namespace App\Models;

class LoginAttempt extends Model
{
    protected string $table = 'refer_login_attempts';
    protected string $primaryKey = 'ip_hash';
    protected array $fillable = ['ip_hash', 'attempts', 'window_start'];
    protected array $casts = [
        'attempts' => 'integer',
        'window_start' => 'integer',
    ];

    public function increment(string $ipHash): int
    {
        $now = time();
        $existing = $this->find($ipHash);
        
        if (!$existing) {
            $this->create([
                'ip_hash' => $ipHash,
                'attempts' => 1,
                'window_start' => $now,
            ]);
            return 1;
        }
        
        if ($existing['window_start'] < $now - 60) {
            $this->update($ipHash, ['attempts' => 1, 'window_start' => $now]);
            return 1;
        }
        
        $newAttempts = $existing['attempts'] + 1;
        $this->update($ipHash, ['attempts' => $newAttempts]);
        return $newAttempts;
    }

    public function getAttempts(string $ipHash): int
    {
        $existing = $this->find($ipHash);
        if (!$existing) return 0;
        
        $now = time();
        if ($existing['window_start'] < $now - 60) {
            return 0;
        }
        return $existing['attempts'];
    }

    public function reset(string $ipHash): bool
    {
        return $this->delete($ipHash);
    }
}