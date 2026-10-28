<?php
declare(strict_types=1);

namespace App\Models;

class User extends Model
{
    protected string $table = 'refer_users';
    protected string $primaryKey = 'id';
    protected array $fillable = [
        'id', 'username', 'password_hash', 'must_change_password', 'password_changed_at',
        'full_name', 'role', 'nurse_id', 'active', 'last_login_at',
        'created_at', 'updated_at'
    ];
    protected array $casts = [
        'active' => 'boolean',
        'must_change_password' => 'boolean',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STAFF = 'staff';
    public const VALID_ROLES = [self::ROLE_ADMIN, self::ROLE_STAFF];

    public const PASSWORD_MIN = 8;
    public const PASSWORD_MAX = 72;   // bcrypt ใช้แค่ 72 ไบต์แรก — ยาวกว่านี้ส่วนเกินไม่มีผล จึงไม่รับ

    /**
     * เบอร์โทรศัพท์ → ชื่อผู้ใช้ (ตัวเลขล้วน) เช่น "081-234-5678" → "0812345678"
     * คืน null ถ้าไม่ใช่เบอร์ไทย (ขึ้นต้น 0, 9–10 หลัก)
     */
    public static function normalizePhone(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || preg_match('/[^\d\s\-()]/', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        return preg_match('/^0\d{8,9}$/', $digits) ? $digits : null;
    }

    /**
     * ตรวจรหัสผ่านใหม่ที่ผู้ใช้ตั้งเอง — คืนรายการข้อที่ไม่ผ่าน (ว่าง = ผ่าน)
     * กติกา: 8–72 ตัวอักษร, มีตัวอักษรภาษาอังกฤษและตัวเลข, ไม่มีช่องว่าง, ห้ามเป็นเบอร์โทร/ชื่อผู้ใช้
     */
    public static function passwordProblems(string $password, string $username): array
    {
        $problems = [];
        if (strlen($password) < self::PASSWORD_MIN) {
            $problems[] = 'ต้องมีอย่างน้อย ' . self::PASSWORD_MIN . ' ตัว';
        }
        if (strlen($password) > self::PASSWORD_MAX) {
            $problems[] = 'ยาวได้ไม่เกิน ' . self::PASSWORD_MAX . ' ตัว';
        }
        // ตัวเลขล้วนได้ หรือผสมตัวอักษรได้ — ไม่บังคับให้มีตัวอักษร
        if (preg_match('/\s/', $password)) {
            $problems[] = 'ห้ามมีช่องว่าง';
        }
        $digits = preg_replace('/\D/', '', $password);
        if (strcasecmp($password, $username) === 0 || ($digits !== '' && $digits === preg_replace('/\D/', '', $username) && strlen($digits) >= 9)) {
            $problems[] = 'ห้ามใช้เบอร์โทรศัพท์หรือชื่อผู้ใช้เป็นรหัสผ่าน';
        }
        return $problems;
    }

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
        // รหัสผ่านที่ admin ตั้ง/รหัสเริ่มต้น (= เบอร์โทร) เป็นรหัสชั่วคราว — ต้องเปลี่ยนเองตอนเข้าใช้ครั้งแรก
        $data['must_change_password'] = (int)($data['must_change_password'] ?? 1);

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

        $created = $this->create($data);
        AuditLog::record('user.create', 'user', (string)$data['id'], [
            'username' => $data['username'] ?? null,
            'role' => $data['role'],
            'nurse_id' => $data['nurse_id'] ?? null,
        ]);
        return $created;
    }

    public function updateUser(string $id, array $data): bool
    {
        if (isset($data['role']) && !in_array($data['role'], self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException('Invalid role');
        }

        if (isset($data['password'])) {
            $data['password_hash'] = $this->hashPassword($data['password']);
            unset($data['password']);
            // admin รีเซ็ตรหัสให้ = รหัสชั่วคราว (ผู้ใช้เปลี่ยนเองส่ง must_change_password = false มาเอง)
            $data['must_change_password'] = (int)($data['must_change_password'] ?? 1);
        }

        // Unlinking must store NULL, not '' (FK on nurse_id)
        if (array_key_exists('nurse_id', $data) && ($data['nurse_id'] === '' || $data['nurse_id'] === null)) {
            $data['nurse_id'] = null;
        }

        $data['updated_at'] = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

        $before = $this->find($id);
        $ok = $this->update($id, $data);
        if ($ok && $before) {
            $this->auditChanges($id, $before, $data);
        }
        return $ok;
    }

    /** บันทึกเฉพาะช่องที่เปลี่ยนจริง — รหัสผ่านบันทึกแค่ว่าเปลี่ยน (ไม่เก็บค่า/hash) */
    private function auditChanges(string $id, array $before, array $data): void
    {
        $changes = [];
        foreach (['username', 'full_name', 'role', 'nurse_id', 'active', 'must_change_password'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $old = $before[$field] ?? null;
            $new = $data[$field];
            if (in_array($field, ['active', 'must_change_password'], true)) {
                $old = (bool)$old;
                $new = (bool)$new;
            }
            if ($old !== $new) {
                $changes[$field] = [$old, $new];
            }
        }
        $passwordChanged = isset($data['password_hash']);
        if (!$changes && !$passwordChanged) {
            return;
        }
        // เจ้าของบัญชีเปลี่ยนรหัสเอง vs ผู้อื่น (admin) ตั้งรหัสให้
        $self = (string)($_SESSION['user']['id'] ?? '') === $id;
        if ($passwordChanged) {
            $changes['password'] = $self ? 'changed_by_owner' : 'reset_by_admin';
        }
        $action = $passwordChanged && $self && count($changes) <= 2 ? 'user.password_change' : 'user.update';
        AuditLog::record($action, 'user', $id, $changes);
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