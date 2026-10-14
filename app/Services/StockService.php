<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\StockItem;
use App\Models\StockTransaction;
use App\Config\Database;

class StockService
{
    public const TYPE_INIT = 'INIT';
    public const TYPE_RECEIVE = 'RECEIVE';
    public const TYPE_ISSUE = 'ISSUE';
    public const TYPE_ADJUST = 'ADJUST';

    private StockItem $stockItemModel;
    private StockTransaction $transactionModel;

    public function __construct()
    {
        $this->stockItemModel = new StockItem();
        $this->transactionModel = new StockTransaction();
    }

    // ==================== Queries ====================

    public function getOverview(): array
    {
        $items = $this->stockItemModel->getAllOrdered();

        $low = 0;
        $out = 0;
        $unset = 0;
        foreach ($items as &$item) {
            $item['status'] = $this->computeStatus($item);
            if ($item['status'] === 'out') {
                $out++;
            } elseif ($item['status'] === 'low') {
                $low++;
            } elseif ($item['status'] === 'unset') {
                $unset++;
            }
        }
        unset($item);

        return [
            'items' => $items,
            'summary' => [
                'total' => count($items),
                'low' => $low,
                'out' => $out,
                'unset' => $unset,
                'alerts' => $low + $out,
            ],
        ];
    }

    public function getTransactions(int $limit = 200, ?string $stockItemId = null): array
    {
        return $this->transactionModel->getRecent($limit, $stockItemId);
    }

    public function getItemWithStatus(string $id): ?array
    {
        $item = $this->stockItemModel->find($id);
        if (!$item) {
            return null;
        }
        $item['status'] = $this->computeStatus($item);
        return $item;
    }

    private function computeStatus(array $item): string
    {
        $current = (float)$item['current_qty'];
        $min = (float)$item['min_qty'];

        if ($min <= 0) {
            return $current <= 0 ? 'unset' : 'ok';
        }
        if ($current <= 0) {
            return 'out';
        }
        return $current < $min ? 'low' : 'ok';
    }

    // ==================== Mutations ====================

    /**
     * รับอุปกรณ์เข้าคลัง (รับเข้า) — ล็อกรายการด้วย FOR UPDATE กันตัดพร้อมกัน
     */
    public function receive(string $id, float $qty, string $note, ?string $userId, string $userName): array
    {
        $qty = round($qty, 2);
        if ($qty <= 0) {
            throw new \RuntimeException('จำนวนรับเข้าต้องมากกว่า 0');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $row = $this->lockItem($id);
            if (!$row) {
                throw new \RuntimeException('ไม่พบรายการสต็อกนี้');
            }

            $balance = round((float)$row['current_qty'] + $qty, 2);
            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            $this->stockItemModel->update($id, [
                'current_qty' => $balance,
                'updated_at' => $now,
            ]);

            $this->transactionModel->create([
                'id' => generate_id(),
                'stock_item_id' => $id,
                'name' => $row['name'],
                'type' => self::TYPE_RECEIVE,
                'qty' => $qty,
                'balance_after' => $balance,
                'car_id' => null,
                'car_name' => '',
                'check_id' => null,
                'by_user_id' => $userId,
                'by_name' => $userName,
                'note' => $this->truncateNote($note !== '' ? $note : 'รับอุปกรณ์เข้าคลัง'),
                'at' => $now,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->getItemWithStatus($id) ?? [];
    }

    /**
     * ปรับยอดสต็อก/ขั้นต่ำ (ผู้ดูแล) — บันทึก ADJUST ครั้งเดียว โดย qty = ผลต่างที่เกิดขึ้นจริง
     */
    public function adjust(
        string $id,
        ?float $currentQty,
        ?float $minQty,
        string $note,
        ?string $userId,
        string $userName
    ): array {
        if ($currentQty === null && $minQty === null) {
            throw new \RuntimeException('ไม่มีการเปลี่ยนแปลงให้บันทึก');
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $row = $this->lockItem($id);
            if (!$row) {
                throw new \RuntimeException('ไม่พบรายการสต็อกนี้');
            }

            $oldCurrent = (float)$row['current_qty'];
            $oldMin = (float)$row['min_qty'];
            $newCurrent = $currentQty !== null ? round($currentQty, 2) : $oldCurrent;
            $newMin = $minQty !== null ? round($minQty, 2) : $oldMin;

            if ($newCurrent === $oldCurrent && $newMin === $oldMin) {
                throw new \RuntimeException('ค่าใหม่ไม่ต่างจากค่าเดิม');
            }

            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            $this->stockItemModel->update($id, [
                'current_qty' => $newCurrent,
                'min_qty' => $newMin,
                'updated_at' => $now,
            ]);

            $parts = [];
            if ($newCurrent !== $oldCurrent) {
                $parts[] = 'สต็อก ' . $this->fmtQty($oldCurrent) . ' → ' . $this->fmtQty($newCurrent);
            }
            if ($newMin !== $oldMin) {
                $parts[] = 'ขั้นต่ำ ' . $this->fmtQty($oldMin) . ' → ' . $this->fmtQty($newMin);
            }
            $autoNote = 'ปรับปรุงสต็อก: ' . implode(', ', $parts);
            $fullNote = $note !== '' ? $autoNote . ' • ' . $note : $autoNote;

            $this->transactionModel->create([
                'id' => generate_id(),
                'stock_item_id' => $id,
                'name' => $row['name'],
                'type' => self::TYPE_ADJUST,
                'qty' => round($newCurrent - $oldCurrent, 2),
                'balance_after' => $newCurrent,
                'car_id' => null,
                'car_name' => '',
                'check_id' => null,
                'by_user_id' => $userId,
                'by_name' => $userName,
                'note' => $this->truncateNote($fullNote),
                'at' => $now,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $this->getItemWithStatus($id) ?? [];
    }

    /**
     * ตั้ง/ปรับสต็อกตามชื่ออุปกรณ์ (ใช้จากฟอร์มเพิ่มอุปกรณ์ประจำรถ)
     * - ยังไม่มีรายการคลังในชื่อนี้ → สร้างใหม่ + บันทึก INIT
     * - มีอยู่แล้ว → ปรับเฉพาะค่าที่ต่าง + บันทึก ADJUST
     */
    public function setStockForItemName(
        string $name,
        ?float $currentQty,
        ?float $minQty,
        ?string $userId,
        string $userName,
        string $note = ''
    ): ?array {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        if ($currentQty === null && $minQty === null) {
            return null;
        }

        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            $row = $this->lockItemByName($name);
            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            if (!$row) {
                $id = 'stk-' . generate_id();
                $current = $currentQty !== null ? round($currentQty, 2) : 0.0;
                $min = $minQty !== null ? round($minQty, 2) : 0.0;

                $this->stockItemModel->create([
                    'id' => $id,
                    'name' => $name,
                    'unit' => '',
                    'current_qty' => $current,
                    'min_qty' => $min,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $autoNote = 'ตั้งต้นสต็อกจากฟอร์มเพิ่มอุปกรณ์: เริ่มที่ ' . $this->fmtQty($current)
                    . ($min > 0 ? ' • ขั้นต่ำ ' . $this->fmtQty($min) : '');
                $this->transactionModel->create([
                    'id' => generate_id(),
                    'stock_item_id' => $id,
                    'name' => $name,
                    'type' => self::TYPE_INIT,
                    'qty' => $current,
                    'balance_after' => $current,
                    'car_id' => null,
                    'car_name' => '',
                    'check_id' => null,
                    'by_user_id' => $userId,
                    'by_name' => $userName,
                    'note' => $this->truncateNote($note !== '' ? $autoNote . ' • ' . $note : $autoNote),
                    'at' => $now,
                ]);

                $db->commit();
                return $this->getItemWithStatus($id);
            }

            $id = $row['id'];
            $oldCurrent = (float)$row['current_qty'];
            $oldMin = (float)$row['min_qty'];
            $newCurrent = $currentQty !== null ? round($currentQty, 2) : $oldCurrent;
            $newMin = $minQty !== null ? round($minQty, 2) : $oldMin;

            if ($newCurrent === $oldCurrent && $newMin === $oldMin) {
                $db->commit();
                return $this->getItemWithStatus($id);
            }

            $this->stockItemModel->update($id, [
                'current_qty' => $newCurrent,
                'min_qty' => $newMin,
                'updated_at' => $now,
            ]);

            $parts = [];
            if ($newCurrent !== $oldCurrent) {
                $parts[] = 'สต็อก ' . $this->fmtQty($oldCurrent) . ' → ' . $this->fmtQty($newCurrent);
            }
            if ($newMin !== $oldMin) {
                $parts[] = 'ขั้นต่ำ ' . $this->fmtQty($oldMin) . ' → ' . $this->fmtQty($newMin);
            }
            $autoNote = 'ปรับปรุงจากฟอร์มเพิ่มอุปกรณ์: ' . implode(', ', $parts);
            $fullNote = $note !== '' ? $autoNote . ' • ' . $note : $autoNote;

            $this->transactionModel->create([
                'id' => generate_id(),
                'stock_item_id' => $id,
                'name' => $name,
                'type' => self::TYPE_ADJUST,
                'qty' => round($newCurrent - $oldCurrent, 2),
                'balance_after' => $newCurrent,
                'car_id' => null,
                'car_name' => '',
                'check_id' => null,
                'by_user_id' => $userId,
                'by_name' => $userName,
                'note' => $this->truncateNote($fullNote),
                'at' => $now,
            ]);

            $db->commit();
            return $this->getItemWithStatus($id);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * ตัดสต็อกอัตโนมัติเมื่อบันทึกผลการตรวจรถ (เฉพาะรายการสถานะ "ใช้ไป")
     * จำนวนที่ตัด = max(0, จำนวนมาตรฐาน − จำนวนที่เหลือจริง) เท่ากับยอดที่ขอเติม
     * ฟังก์ชันนี้ไม่เปิด transaction เอง เพราะถูกเรียกภายใน transaction ของ saveInspection
     *
     * @return array รายการแจ้งเตือนสต็อกต่ำกว่าขั้นต่ำหลังตัดแล้ว
     */
    public function deductForInspection(array $inspectionData, ?string $userId = null, string $userName = ''): array
    {
        // รวมจำนวนที่ต้องตัดต่อชื่ออุปกรณ์ (เฉพาะ "ใช้ไป")
        $toDeduct = [];
        foreach ($inspectionData['results'] ?? [] as $item) {
            if (($item['status'] ?? '') !== 'ใช้ไป') {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $standard = $this->parseLeadingQty((string)($item['standard'] ?? ''));
            $actual = $this->parseLeadingQty((string)($item['missing'] ?? ''));
            $qty = round(max(0, $standard - $actual), 2);
            if ($qty <= 0) {
                continue;
            }
            if (!isset($toDeduct[$name])) {
                $toDeduct[$name] = 0.0;
            }
            $toDeduct[$name] = round($toDeduct[$name] + $qty, 2);
        }

        if (empty($toDeduct)) {
            return [];
        }

        $actorName = $userName !== '' ? $userName : (string)($inspectionData['nurse_name'] ?? '');
        $carId = $inspectionData['car_id'] ?? null;
        $carName = (string)($inspectionData['car_name'] ?? '');
        $checkId = $inspectionData['id'] ?? null;

        $alerts = [];
        foreach ($toDeduct as $name => $qty) {
            // หารายการคลังตามชื่อ — ถ้ายังไม่มี ให้สร้างรายการใหม่ยอด 0/0 เพื่อรองรับการตัด
            $row = $this->lockItemByName($name);
            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            if (!$row) {
                $id = 'stk-' . generate_id();
                $this->stockItemModel->create([
                    'id' => $id,
                    'name' => $name,
                    'unit' => '',
                    'current_qty' => 0,
                    'min_qty' => 0,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $row = ['id' => $id, 'name' => $name, 'current_qty' => 0, 'min_qty' => 0];
            }

            $id = $row['id'];
            $current = (float)$row['current_qty'];
            $min = (float)$row['min_qty'];
            $balance = round(max(0, $current - $qty), 2);
            $deducted = round($current - $balance, 2);

            $this->stockItemModel->update($id, [
                'current_qty' => $balance,
                'updated_at' => $now,
            ]);

            if ($deducted > 0) {
                $this->transactionModel->create([
                    'id' => generate_id(),
                    'stock_item_id' => $id,
                    'name' => $name,
                    'type' => self::TYPE_ISSUE,
                    'qty' => -$deducted,
                    'balance_after' => $balance,
                    'car_id' => $carId,
                    'car_name' => $carName,
                    'check_id' => $checkId,
                    'by_user_id' => $userId,
                    'by_name' => $actorName,
                    'note' => $this->truncateNote('ตัดจ่ายอัตโนมัติจากการตรวจรถ: ใช้ไป ' . $this->fmtQty($qty)
                        . ($carName !== '' ? ' • รถ ' . $carName : '')),
                    'at' => $now,
                ]);
            }

            // แจ้งเตือนเมื่อคงเหลือต่ำกว่าขั้นต่ำหลังตัด (รวมกรณีตัดไม่ได้เพราะคลังหมด)
            if ($min > 0 && $balance < $min) {
                $alerts[] = [
                    'stock_item_id' => $id,
                    'name' => $name,
                    'balance' => $balance,
                    'min' => $min,
                    'deducted' => $deducted,
                    'status' => $balance <= 0 ? 'out' : 'low',
                ];
            }
        }

        return $alerts;
    }

    // ==================== Helpers ====================

    private function lockItem(string $id): ?array
    {
        $row = $this->stockItemModel->query(
            "SELECT * FROM " . $this->stockItemModel->getTable() . " WHERE id = ? FOR UPDATE",
            [$id]
        )->fetch();
        return $row ?: null;
    }

    private function lockItemByName(string $name): ?array
    {
        $row = $this->stockItemModel->query(
            "SELECT * FROM " . $this->stockItemModel->getTable() . " WHERE name = ? FOR UPDATE",
            [$name]
        )->fetch();
        return $row ?: null;
    }

    private function parseLeadingQty(string $value): float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)/', $value, $m)) {
            return (float)$m[1];
        }
        return 0.0;
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function truncateNote(string $note): string
    {
        return mb_substr($note, 0, 500);
    }
}
