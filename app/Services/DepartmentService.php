<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Exceptions\ApiException;
use App\Models\Department;

/**
 * ทะเบียนวอร์ด/หน่วยงาน (Department Management) — แหล่งตรรกะเดียวของทุกหน้าจอ
 *
 * แบบข้อมูลที่ใช้อธิบายตัวเองไว้ท้าย migration 008:
 *   refer_departments  = แหล่งความจริงของชื่อวอร์ด (เพิ่ม/แก้/ลบ/ปิดใช้งานที่นี่)
 *   refer_nurses.department_id = FK ไปยังแถว master
 *   refer_nurses.department    = "สำเนาชื่อ" ที่ระบบเขียนทับให้อัตโนมัติเมื่อแก้ชื่อ
 *   เหตุผลที่ยังคงสำเนาไว้: ทุกหน้าจอที่อ่านวอร์ดของพยาบาล (การ์ดรายชื่อ, dropdown
 *   ผู้ตรวจในหน้าตรวจรถ/หน้าเข้าสู่ระบบ/หน้าจัดการผู้ใช้, ไฟล์ที่นำจาก Excel)
 *   อ่านจาก nurse.department การเก็บสำเนาไว้ทำให้แก้ชื่อแล้วหายทั้งระบบโดยไม่มี
 *   จุดไหนอ่านค่าไม่ได้ และไม่มีทางที่หน้าจอจะแสดงรหัส id แทนชื่อ
 *
 * ข้อตกลงที่คลาสนี้ต้องรักษา:
 *   1) ห้ามมีวอร์ดชื่อซ้ำ (เทียบแบบ trim และไม่วัดตัวพิมพ์) — ตรวจใน transaction
 *      พร้อมล๊อคแถว master ด้วย SELECT ... FOR UPDATE
 *   2) ห้ามลบวอร์ดที่ยังมีพยาบาลสังกัด (ป้องกันข้อมูลพยาบาลหลุดลอย) — ตอบ 409 พร้อมจำนวน
 *      และเสนอ "ปิดใช้งาน" (active=0) แทน ส่วน FK ON DELETE RESTRICT เป็นเกราะชั้นที่สอง
 *   3) แก้ชื่อวอร์ด = อัปเดตชื่อในรายชื่อพยาบาลที่สังกัดด้วย ให้อยู่ใน transaction เดียว
 *      ถ้าล้มเหลวกลางทางต้อง rollback ทั้งหมด (ห้ามเหลือสภาพ "master ชื่อใหม่ พยาบาลชื่อเก่า")
 *   4) สิทธิ์เขียนต้องตรวจฝั่งเซิร์ฟเวอร์ (requireAdmin ที่ controller) — ไม่พึ่งการซ่อนปุ่ม
 *   5) ชื่อที่แสดงต้องมาจากแถว master เสมอ ห้ามเชื่อ text ที่ client ส่งมา-alone
 */
class DepartmentService
{
    public const NAME_MAX = 100;
    public const NOTES_MAX = 500;

    private Department $departmentModel;

    public function __construct()
    {
        $this->departmentModel = new Department();
    }

    // ==================== อ่านข้อมูล ====================

    /**
     * วอร์ดทุกแถว + จำนวนพยาบาลที่สังกัด (รวมแถวเดิมที่ยังไม่ผูก FK ตามชื่อ)
     * เรียงตามชื่อเพื่อให้ dropdown และตารางจัดการลำดับคงที่
     */
    public function listDepartments(): array
    {
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM refer_nurses n
                          WHERE n.department_id = d.id
                             OR (n.department_id IS NULL AND TRIM(n.department) = d.name)) AS nurse_count
                  FROM refer_departments d
                 ORDER BY d.name";
        return Database::getConnection()->query($sql)->fetchAll();
    }

    // ==================== สร้าง ====================

    public function createDepartment(array $input, ?array $actor): array
    {
        $name = $this->name($input['name'] ?? true);
        $notes = $this->notes($input['notes'] ?? '');
        $active = $this->active($input);
        $now = $this->now();

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $existing = $this->departmentModel->findByName($name);
            if ($existing) {
                throw new ApiException('มีวอร์ดชื่อนี้อยู่แล้ว: ' . $existing['name'], 409, [
                    'reason' => 'name_exists',
                    'existing' => $existing,
                ]);
            }

            $id = $this->newId($name);
            $this->departmentModel->create([
                'id' => $id,
                'name' => $name,
                'notes' => $notes,
                'active' => $active,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $created = $this->departmentModel->find($id) ?? [];
        $created['nurse_count'] = 0;
        $created['created_by'] = $actor['id'] ?? null;
        return $created;
    }

    // ==================== แก้ไข (รวมการเปลี่ยนชื่อแบบ cascade) ====================

    /**
     * แก้ชื่อ/รายละเอียด/สถานะของวอร์ด
     *
     * เมื่อ "ชื่อเปลี่ยน" จะเขียนชื่อใหม่ทับลงไปใน refer_nurses.department ของทุกแถว
     * ที่สังกัดวอร์ดนี้ (และผูกแถวเดิมที่ยังไม่ผูก FK แต่ชื่อตรงกับชื่อเก่า) ใน transaction เดียว
     * จึงไม่มีทางเกิดสภาพ "หน้าจัดการแจ้งชื่อหนึ่ง แต่รายชื่อพยาบาลยังแสดงอีกชื่อ"
     */
    public function updateDepartment(string $id, array $input, ?array $actor): array
    {
        // ส่ง key มาเป็น false/ขาด = ไม่ได้แก้ชื่อนั้น (ต่างจากส่งค่าว่างซึ่งผิดเงื่อนไขบังคับ)
        $name = array_key_exists('name', $input) ? $this->name($input['name']) : null;
        $notes = array_key_exists('notes', $input) ? $this->notes($input['notes']) : null;
        $active = array_key_exists('active', $input) ? $this->active($input) : null;
        $now = $this->now();

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $current = $this->departmentModel->lockById($id);
            if (!$current) {
                throw new ApiException('ไม่พบวอร์ดนี้ในระบบ: ' . $id, 404);
            }

            $data = ['updated_at' => $now];
            if ($name !== null && $name !== $current['name']) {
                $dup = $this->departmentModel->findByName($name);
                if ($dup && (string)$dup['id'] !== $id) {
                    throw new ApiException('มีวอร์ดชื่อ "' . $name . '" อยู่แล้ว (รหัส ' . $dup['id'] . ')', 409, [
                        'reason' => 'name_exists',
                        'existing' => $dup,
                    ]);
                }
                $data['name'] = $name;
            }
            if ($notes !== null) {
                $data['notes'] = $notes;
            }
            if ($active !== null) {
                $data['active'] = $active;
            }

            $this->departmentModel->update($id, $data);

            $synced = 0;
            if (isset($data['name'])) {
                $stmt = $db->prepare("UPDATE refer_nurses SET department = ? WHERE department_id = ?");
                $stmt->execute([$data['name'], $id]);
                $synced += $stmt->rowCount();

                // แถวเดิมที่ยังไม่ผูก FK แต่ใช้ชื่อเก่าอยู่: ผูกให้ + เขียนชื่อใหม่ไปด้วย
                $stmt = $db->prepare(
                    "UPDATE refer_nurses SET department = ?, department_id = ?
                      WHERE department_id IS NULL AND TRIM(department) = ?"
                );
                $stmt->execute([$data['name'], $id, $current['name']]);
                $synced += $stmt->rowCount();
            }

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $updated = $this->departmentModel->find($id) ?? [];
        $updated['nurse_count'] = $this->departmentModel->countAssigned($id, (string)($updated['name'] ?? ''));
        $updated['nurses_updated'] = $synced;
        $updated['renamed'] = isset($data['name']);
        $updated['updated_by'] = $actor['id'] ?? null;
        return $updated;
    }

    // ==================== ลบ / ปิดใช้งาน ====================

    /**
     * mode 'auto'  = ลบจริงเมื่อไม่มีพยาบาลสังกัดเท่านั้น ถ้ามีให้ตอบ 409 พร้อมจำนวน
     * mode 'disable' = ปิดการใช้งาน (วอร์ดหายจาก dropdown แต่สังกัดเดิมของพยาบาลยังอยู่)
     */
    public function deleteDepartment(string $id, ?array $actor, string $mode = 'auto'): array
    {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $ward = $this->departmentModel->lockById($id);
            if (!$ward) {
                throw new ApiException('ไม่พบวอร์ดนี้ในระบบ: ' . $id, 404);
            }

            $assigned = $this->departmentModel->countAssigned($id, (string)$ward['name']);

            if ($mode === 'disable') {
                if (!$ward['active']) {
                    $db->rollBack();
                    return ['mode' => 'already_disabled', 'id' => $id, 'name' => $ward['name'], 'nurse_count' => $assigned];
                }
                $this->departmentModel->update($id, ['active' => false, 'updated_at' => $this->now()]);
                $db->commit();
                return ['mode' => 'disabled', 'id' => $id, 'name' => $ward['name'], 'nurse_count' => $assigned];
            }

            if ($assigned > 0) {
                throw new ApiException(
                    'ลบวอร์ด "' . $ward['name'] . '" ไม่ได้ เพราะมีพยาบาลสังกัดอยู่ ' . $assigned . ' คน',
                    409,
                    [
                        'reason' => 'department_in_use',
                        'nurse_count' => $assigned,
                        'suggestion' => 'disable',
                        'name' => $ward['name'],
                    ]
                );
            }

            $stmt = $db->prepare("DELETE FROM refer_departments WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() < 1) {
                throw new ApiException('ลบวอร์ดไม่สำเร็จ: ไม่พบแถวที่ต้องลบ');
            }
            $db->commit();

            return ['mode' => 'deleted', 'id' => $id, 'name' => $ward['name'], 'nurse_count' => 0];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // ==================== ใช้ตอนบันทึกพยาบาล ====================

    /**
     * แปลงข้อมูลที่หน้าพยาบาลส่งมา ("id ที่เลือก" หรือ "ข้อความหน่วยงานแบบเดิม")
     * ให้เป็นแถว master แล้วคืน ['id' => ..., 'name' => ...]
     *
     * ผู้เรียกต้องอยู่ใน Transaction เดียวกับที่เขียน refer_nurses (ใช้ connection เดียวกัน)
     *
     * $currentDepartmentId = FK เดิมของพยาบาลแถวนี้ (ตอนแก้) ใช้ยอมให้ "เก็บวอร์ดที่ปิดใช้งานอยู่"
     * ต่อไปได้โดยไม่บังคับให้เลือกใหม่ ถ้าไม่ได้เปลี่ยนวอร์ด
     */
    public function resolveForNurse(array $input, ?string $currentDepartmentId = null): array
    {
        $picked = trim((string)($input['department_id'] ?? ''));

        if ($picked !== '') {
            $ward = $this->departmentModel->find($picked);
            if (!$ward) {
                throw new ApiException('ไม่พบวอร์ดที่เลือกในระบบ กรุณาเลือกใหม่หรือเพิ่มวอร์ดก่อน', 422, [
                    'errors' => ['department_id' => ['ไม่พบวอร์ดที่เลือกในระบบ']],
                ]);
            }
            if (!$ward['active'] && $picked !== $currentDepartmentId) {
                throw new ApiException('วอร์ด "' . $ward['name'] . '" ถูกปิดใช้งานแล้ว — เลือกวอร์ดอื่นหรือเปิดใช้งานก่อน', 422, [
                    'errors' => ['department_id' => ['วอร์ดนี้ถูกปิดใช้งาน']],
                ]);
            }
            return ['id' => (string)$ward['id'], 'name' => (string)$ward['name']];
        }

        // เส้นทางเดิม (นำเข้า Excel / client เก่า) ส่งชื่อมาเป็นข้อความ: ใช้ชื่อหา master ก่อน
        // ถ้าไม่มีให้สร้างแถว master ให้ใหม่ เพื่อไม่ให้ชื่อในทะเบียนพยาบาลหลุดจากระบบจัดการวอร์ด
        $name = $this->name($input['department'] ?? null, false);
        if ($name === '') {
            throw new ApiException('กรุณาเลือกหน่วยงาน/วอร์ด', 422, [
                'errors' => ['department' => ['กรุณาเลือกหน่วยงาน/วอร์ด']],
            ]);
        }

        $ward = $this->departmentModel->findByName($name);
        if ($ward) {
            return ['id' => (string)$ward['id'], 'name' => (string)$ward['name']];
        }

        $now = $this->now();
        $id = $this->newId($name);
        $this->departmentModel->create([
            'id' => $id,
            'name' => $name,
            'notes' => '',
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'name' => $name, 'auto_created' => true];
    }

    // ==================== helpers ====================

    /**
     * ตรวจชื่อวอร์ด: trim +ยุบช่องว่างซ้ำ + ห้ามว่าง + ห้ามยาวเกิน NAME_MAX
     * $required = false ใช้กับเส้นทางที่เลือกไม่ส่งชื่อกำกับ (คืนค่าว่างให้ caller ตัดสินใจ)
     */
    private function name(mixed $value, bool $required = true): string
    {
        if ($value === true || $value === null) {
            if ($required) {
                throw new ApiException('กรุณากรอกชื่อวอร์ด', 422, ['errors' => ['name' => ['กรุณากรอกชื่อวอร์ด']]]);
            }
            return '';
        }

        $name = is_scalar($value) ? preg_replace('/\s+/u', ' ', trim((string)$value)) : '';
        $name = (string)($name ?? '');

        if ($name === '') {
            if ($required) {
                throw new ApiException('กรุณากรอกชื่อวอร์ด', 422, ['errors' => ['name' => ['กรุณากรอกชื่อวอร์ด']]]);
            }
            return '';
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            throw new ApiException('ชื่อวอร์ดยาวเกิน ' . self::NAME_MAX . ' ตัวอักษร', 422, [
                'errors' => ['name' => ['ชื่อวอร์ดสั้นกว่า ' . self::NAME_MAX . ' ตัวอักษรได้เท่านั้น']],
            ]);
        }
        return $name;
    }

    private function notes(mixed $value): string
    {
        $notes = is_scalar($value) ? trim((string)$value) : '';
        if (mb_strlen($notes) > self::NOTES_MAX) {
            throw new ApiException('รายละเอียดวอร์ดยาวเกิน ' . self::NOTES_MAX . ' ตัวอักษร', 422, [
                'errors' => ['notes' => ['รายละเอียดสั้นกว่า ' . self::NOTES_MAX . ' ตัวอักษรได้เท่านั้น']],
            ]);
        }
        return $notes;
    }

    /** ค่า default คือ "เปิดใช้งาน" — ตัดสินจาก key 'active' เท่านั้น */
    private function active(array $input): bool
    {
        if (!array_key_exists('active', $input)) {
            return true;
        }
        $value = $input['active'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['false', '0', '', 'no'], true);
        }
        return (bool)$value;
    }

    /**
     * id ของแถว master — ใช้ 'dept-' + md5 ชื่อ สำหรับแถวที่ backfill จากชื่อเดิม
     * และ random สำหรับแถวที่เกิดใหม่ (loop กันชนของเก่า)
     */
    private function newId(string $name): string
    {
        $fromName = 'dept-' . md5($name);
        if (!$this->departmentModel->find($fromName)) {
            return $fromName;
        }
        do {
            $id = 'dept-' . generate_id();
        } while ($this->departmentModel->find($id));
        return $id;
    }

    private function now(): string
    {
        return (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');
    }
}
