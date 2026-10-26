<?php
declare(strict_types=1);

namespace App\Models;

class User extends Model
{
    protected string $table = 'refer_users';
    protected string $primaryKey = 'id';
    protected array $fillable = [
        'id', 'username', 'password_hash', 'full_name', 
        'role', 'nurse_id', 'active', 'last_login_at', 
        'created_at', 'updated_at'
    ];
    protected array $casts = [
        'active' => 'boolean',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const VALID_ROLES = [self::ROLE_ADMIN, self::ROLE_STAFF];

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    public function findByNurseId(string $nurseId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE nurse_id = ? LIMIT 1");
        $stmt->execute([$nurseId]);
        $result = $stmt->fetch();
        return $result ? $this->cast($result) : null;
    }

    /**
     * Map nurse_id => username for the given nurse IDs.
     *
     * @param string[] $nurseIds
     * @return array<string, string>
     */
    /**
     * บัญชีที่ผูกกับพยาบาลแต่ละคน (ไม่รวม password_hash) — ใช้แสดงในหน้าจัดการผู้ใช้หน้าเดียว
     * @return array<string, array{id:string, username:string, role:string, active:bool, last_login_at:?string}>
     */
    public function getAccountsByNurseIds(array $nurseIds): array
    {
        if (empty($nurseIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($nurseIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, nurse_id, username, role, active, last_login_at FROM {$this->table}
              WHERE nurse_id IN ($placeholders) ORDER BY active DESC, created_at"
        );
        $stmt->execute(array_values($nurseIds));
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = (string)$row['nurse_id'];
            if (!isset($map[$key])) {   // ถ้าข้อมูลเก่ามีหลายบัญชี ใช้บัญชีที่ใช้งานอยู่ก่อน
                $map[$key] = [
                    'id' => (string)$row['id'],
                    'username' => (string)$row['username'],
                    'role' => (string)$row['role'],
                    'active' => (bool)$row['active'],
                    'last_login_at' => $row['last_login_at'],
                ];
            }
        }
        return $map;
    }

    /** จำนวนผู้ดูแลระบบที่ยังใช้งานอยู่ (กันปิด/ลดสิทธิ์ admin คนสุดท้าย) */
    public function countActiveAdmins(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM {$this->table} WHERE role = 'admin' AND active = 1");
        return (int)$stmt->fetchColumn();
    }

    public function getUsernamesByNurseIds(array $nurseIds): array
    {
        if (empty($nurseIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($nurseIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT nurse_id, username FROM {$this->table} WHERE nurse_id IN ($placeholders)"
        );
        $stmt->execute(array_values($nurseIds));

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['nurse_id']] = (string)$row['username'];
        }
        return $map;
    }

    public function getActiveUsers(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE active = 1 ORDER BY full_name");
        $stmt->execute();
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getByRole(string $role): array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE role = ? AND active = 1 ORDER BY full_name");
        $stmt->execute([$role]);
        return array_map([$this, 'cast'], $stmt->fetchAll());
    }

    public function getStaffUsers(): array
    {
        return $this->getByRole(self::ROLE_STAFF);
    }

    public function getAdminUsers(): array
    {
        return $this->getByRole(self::ROLE_ADMIN);
    }

    public function updateLastLogin(string $id): bool
    {
        return $this->update($id, [
            'last_login_at' => (new \DateTime())->format('Y-m-d\TH:i:s.u\Z'),
        ]);
    }

    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function createUser(array $data): array
    {
        if (!in_array($data['role'] ?? '', self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException('Invalid role');
        }

        $data['password_hash'] = $this->hashPassword($data['password']);
        unset($data['password']);

        // refer_users.nurse_id has a FK to refer_nurses.id — '' must become NULL,
        // otherwise "no linked nurse" accounts fail the constraint
        if (array_key_exists('nurse_id', $data) && ($data['nurse_id'] === '' || $data['nurse_id'] === null)) {
            $data['nurse_id'] = null;
        }

        // refer_users.id is a non-autoincrement varchar PK; without this the INSERT fails
        if (empty($data['id'])) {
            $data['id'] = generate_id();
        }

        $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $data['active'] = $data['active'] ?? true;

        return $this->create($data);
    }

    public function updateUser(string $id, array $data): bool
    {
        if (isset($data['role']) && !in_array($data['role'], self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException('Invalid role');
        }

        if (isset($data['password'])) {
            $data['password_hash'] = $this->hashPassword($data['password']);
            unset($data['password']);
        }

        // Unlinking must store NULL, not '' (FK on nurse_id)
        if (array_key_exists('nurse_id', $data) && ($data['nurse_id'] === '' || $data['nurse_id'] === null)) {
            $data['nurse_id'] = null;
        }

        $data['updated_at'] = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

        return $this->update($id, $data);
    }

    public function isAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === self::ROLE_ADMIN;
    }

    public function isStaff(array $user): bool
    {
        return ($user['role'] ?? '') === self::ROLE_STAFF;
    }
}