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

    /**
     * ยอดคงเหลือ "คลังกลาง" ตามชื่ออุปกรณ์ (read-only, ไม่มี lock)
     * ใช้แสดงในหน้าตรวจรถว่าของชื่อนี้ในคลังมีเท่าไร / ขั้นต่ำเท่าไร
     *
     * @param string[]|null $names จำกัดเฉพาะชื่อที่ต้องการ (null = ทั้งคลัง)
     * @return array<string,array{name:string,qty:float,min:float,unit:string}>
     */
    public function getQtyByNameMap(?array $names = null): array
    {
        if ($names !== null) {
            $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($n) => $n !== '')));
            if (empty($names)) {
                return [];
            }
        }

        $sql = "SELECT name, current_qty, min_qty, unit FROM " . $this->stockItemModel->getTable()
             . " WHERE active = 1";
        $params = [];
        if ($names !== null) {
            $sql .= " AND name IN (" . implode(',', array_fill(0, count($names), '?')) . ")";
            $params = $names;
        }

        $stmt = $this->stockItemModel->query($sql, $params);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['name']] = [
                'name' => $row['name'],
                'qty' => (float)$row['current_qty'],
                'min' => (float)$row['min_qty'],
                'unit' => (string)$row['unit'],
                // 'unset' = ยอด 0 และไม่มีขั้นต่ำ → ยังไม่เคยตั้งยอดคลัง ห้าม UI ทำให้ดูเหมือน "ของหมด"
                'status' => $this->computeStatus($row),
            ];
        }
        return $map;
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
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
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

            if ($ownsTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
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
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
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

            if ($ownsTx) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
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
        $ownsTx = !$db->inTransaction();
        if ($ownsTx) {
            $db->beginTransaction();
        }
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

                if ($ownsTx) {
                    $db->commit();
                }
                return $this->getItemWithStatus($id);
            }

            $id = $row['id'];
            $oldCurrent = (float)$row['current_qty'];
            $oldMin = (float)$row['min_qty'];
            $newCurrent = $currentQty !== null ? round($currentQty, 2) : $oldCurrent;
            $newMin = $minQty !== null ? round($minQty, 2) : $oldMin;

            if ($newCurrent === $oldCurrent && $newMin === $oldMin) {
                if ($ownsTx) {
                    $db->commit();
                }
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

            if ($ownsTx) {
                $db->commit();
            }
            return $this->getItemWithStatus($id);
        } catch (\Throwable $e) {
            if ($ownsTx) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * ตัดคลังกลาง "เมื่อเติมของขึ้นรถจริง" (ISSUE)
     *
     * หลักการ (ตาม Business Logic ที่อนุมัติ): ของถือว่าออกจากคลังก็ต่อเมื่อถูกยกขึ้นรถจริง
     * เท่านั้น จึงจะสอดคล้องกับ "ยอดคงเหลือบนรถ = ยอดก่อนเติม + ยอดที่เติมจริง"
     * และทำให้ยอดคลังกับยอดรถ reconciled กันได้จริง (รอบตรวจรถลดยอดเฉพาะบนรถ ไม่ได้หยิบของจากคลัง)
     *
     * ถ้าของในคลังไม่พอจะ throw ทันที เพื่อให้ transaction ของผู้เรียก rollback ทั้งชุด
     * — กันสถานะ "รถได้ของ แต่คลังไม่ถูกตัด" หรือตัดบางส่วนแล้วค้างกลางคัน
     * ยกเว้นกรณี "คลังยังไม่เปิดสมุดบัญชี" (ยอด 0 และไม่มีประวัติ ledger เลย) ซึ่งจะไม่ throw
     * แต่คืนกลับมาใน untracked เพื่อไม่ให้ระบบงานจริงค้างในช่วงที่ยังไม่เคยตรวจนับคลัง
     *
     * ฟังก์ชันนี้ไม่เปิด transaction เอง และล็อกแถวคลังตามลำดับชื่อ (ksort)
     * เพื่อป้องกัน deadlock เมื่อหลายรถเติมพร้อมกัน
     *
     * @param array $lines   แต่ละรายการ ['name' => string, 'qty' => float]
     * @param array $context ['car_id','car_name','check_id','request_no']
     * @return array{issued: array, alerts: array, untracked: array}
     */
    public function issueForRestockFill(array $lines, array $context, ?string $userId = null, string $userName = ''): array
    {
        $toIssue = [];
        foreach ($lines as $line) {
            $name = trim((string)($line['name'] ?? ''));
            $qty = round((float)($line['qty'] ?? 0), 2);
            if ($name === '' || $qty <= 0) {
                continue;
            }
            $toIssue[$name] = round(($toIssue[$name] ?? 0) + $qty, 2);
        }

        if (empty($toIssue)) {
            return ['issued' => [], 'alerts' => [], 'untracked' => []];
        }
        ksort($toIssue, SORT_STRING);

        $carId = $context['car_id'] ?? null;
        $carName = (string)($context['car_name'] ?? '');
        $checkId = $context['check_id'] ?? null;
        $requestNo = (string)($context['request_no'] ?? '');

        $issued = [];
        $alerts = [];
        $untracked = [];

        foreach ($toIssue as $name => $qty) {
            $row = $this->lockItemByName($name);
            $now = (new \DateTime())->format('Y-m-d\TH:i:s.u\Z');

            if (!$row) {
                // ยังไม่มีรายการคลังชื่อนี้ → สร้างยอด 0/0 ไว้รองรับการตัดจ่าย
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

            if ($current < $qty) {
                // "คลังยังไม่เปิดสมุดบัญชี" = ไม่เคยมีการรับเข้า/ปรับยอด/จ่ายออกใน ledger เลย และยอดเป็น 0
                // กรณีนี้ปล่อยให้เติมขึ้นรถได้ แต่ไม่สร้างแถว ISSUE ปลอม แล้วแจ้งให้ผู้ดูแลไปตรวจนับคลัง
                // เมื่อผู้ดูแลรับของเข้าคลังครั้งแรก (มี ledger row) การตัดจ่ายจะกลับมาบังคับเข้มทันที
                if ($current <= 0 && !$this->hasLedgerRows($id)) {
                    $untracked[] = [
                        'stock_item_id' => $id,
                        'name' => $name,
                        'qty' => $qty,
                        'status' => 'untracked',
                    ];
                    continue;
                }

                throw new \RuntimeException(sprintf(
                    'คลังกลางมี "%s" เหลือ %s ไม่พอเติมขึ้นรถ %s — กรุณารับของเข้าคลังหรือลดจำนวนที่เติมก่อนบันทึก',
                    $name,
                    $this->fmtQty($current),
                    $this->fmtQty($qty)
                ));
            }

            $balance = round($current - $qty, 2);

            $this->stockItemModel->update($id, [
                'current_qty' => $balance,
                'updated_at' => $now,
            ]);

            $this->transactionModel->create([
                'id' => generate_id(),
                'stock_item_id' => $id,
                'name' => $name,
                'type' => self::TYPE_ISSUE,
                'qty' => -$qty,
                'balance_after' => $balance,
                'car_id' => $carId,
                'car_name' => $carName,
                'check_id' => $checkId,
                'by_user_id' => $userId,
                'by_name' => $userName,
                'note' => $this->truncateNote('จ่ายจากคลังเพื่อเติมขึ้นรถ ' . $this->fmtQty($qty)
                    . ($carName !== '' ? ' • รถ ' . $carName : '')
                    . ($requestNo !== '' ? ' • คำขอ ' . $requestNo : '')),
                'at' => $now,
            ]);

            $issued[] = [
                'stock_item_id' => $id,
                'name' => $name,
                'qty' => $qty,
                'balance_after' => $balance,
            ];

            if ($min > 0 && $balance < $min) {
                $alerts[] = [
                    'stock_item_id' => $id,
                    'name' => $name,
                    'balance' => $balance,
                    'min' => $min,
                    'deducted' => $qty,
                    'status' => $balance <= 0 ? 'out' : 'low',
                ];
            }
        }

        return ['issued' => $issued, 'alerts' => $alerts, 'untracked' => $untracked];
    }

    // ==================== Helpers ====================

    /**
     * มีประวัติการเคลื่อนไหวใน ledger ของรายการคลังนี้หรือไม่
     * ใช้แยก "คลังยอด 0 เพราะจ่ายหมดจริง" (ต้องห้ามเติม) ออกจาก "คลังที่ยังไม่เคยตรวจนับ/เปิดสมุด" (เตือนได้)
     */
    private function hasLedgerRows(string $stockItemId): bool
    {
        $stmt = $this->transactionModel->query(
            "SELECT 1 FROM " . $this->transactionModel->getTable() . " WHERE stock_item_id = ? LIMIT 1",
            [$stockItemId]
        );
        return (bool)$stmt->fetch();
    }

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

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function truncateNote(string $note): string
    {
        return mb_substr($note, 0, 500);
    }
}
