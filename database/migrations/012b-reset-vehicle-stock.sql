-- ============================================================================
-- 012b: ล้างสต็อกอุปกรณ์บนรถทุกคัน เพื่อจัดสต็อกใหม่ตาม Logic 3 ประเภท
--
-- !! รันครั้งเดียวด้วยมือหลังสำรองฐานข้อมูล (ไม่ mount เข้า docker init) !!
--
-- ปิดยอดด้วยประวัติ — ไม่ลบแถว:
--   ล็อต/ชิ้นที่ยังมีของ → movement RESET (−ยอดคงเหลือ) แล้ว qty 0 + สถานะ void
--   เครื่อง/ถังที่ใช้งาน → active 0 (เก็บไว้เป็นประวัติ)
--   refer_items → current_qty 0, stock_updated_at NULL (= ยังไม่ได้จัดขึ้นรถ), expiry_text ''
--   ร่างการตรวจที่ค้าง → ลบ (อ้างยอดเดิมที่ถูกล้างแล้ว; ยังไม่เคยบันทึกเป็นการตรวจ)
-- ไม่แตะ: รายการอุปกรณ์ประจำรถ (ชื่อ/มาตรฐาน/ประเภท), รถ, ผู้ใช้, พยาบาล, ประวัติการตรวจ, ประวัติการเติม
-- ============================================================================

START TRANSACTION;

SET @at := DATE_FORMAT(UTC_TIMESTAMP(6), '%Y-%m-%dT%H:%i:%s.%fZ');

-- 1) ปิดยอดล็อต/ชิ้น (ledger ก่อน แล้วค่อยแก้ยอด เพื่อให้ SUM(movements) = SUM(lots) = 0)
INSERT INTO refer_stock_movements (id, item_id, car_id, lot_id, type, qty, balance_after, by_name, reason, at)
SELECT CONCAT('mv-reset-', l.id), l.item_id, i.car_id, l.id, 'RESET', -l.qty_remaining, 0,
       'reset 012b', 'ล้างสต็อกเริ่มระบบใหม่ (3 ประเภทอุปกรณ์)', @at
  FROM refer_item_lots l JOIN refer_items i ON i.id = l.item_id
 WHERE l.qty_remaining > 0
   AND NOT EXISTS (SELECT 1 FROM refer_stock_movements m WHERE m.id = CONCAT('mv-reset-', l.id));

UPDATE refer_item_lots
   SET qty_remaining = 0, lot_status = 'void',
       note = LEFT(CONCAT('ล้างสต็อก 012b ', note), 500)
 WHERE qty_remaining > 0 OR lot_status = 'active';

-- 2) ปลดเครื่อง/ถังทั้งหมด (เก็บแถวไว้)
UPDATE refer_item_units
   SET active = 0, updated_at = @at, updated_by = 'reset 012b',
       note = LEFT(CONCAT('ล้างสต็อก 012b ', note), 500)
 WHERE active = 1;

-- 3) รายการอุปกรณ์ทุกคัน = ยังไม่ได้จัดขึ้นรถ
UPDATE refer_items SET current_qty = 0, stock_updated_at = NULL, expiry_text = '';

-- 4) ร่างการตรวจที่ค้าง (draft_items ถูกลบตาม FK ON DELETE CASCADE)
DELETE FROM refer_drafts;

COMMIT;

-- ตรวจผล
SELECT 'lots with stock (expect 0)' k, COUNT(*) v FROM refer_item_lots WHERE qty_remaining > 0
UNION ALL SELECT 'active units (expect 0)', COUNT(*) FROM refer_item_units WHERE active = 1
UNION ALL SELECT 'items with qty (expect 0)', COUNT(*) FROM refer_items WHERE current_qty <> 0
UNION ALL SELECT 'ledger net per lot != 0 (expect 0)', COUNT(*) FROM (SELECT lot_id, SUM(qty) s FROM refer_stock_movements WHERE lot_id IS NOT NULL GROUP BY lot_id HAVING ROUND(s,2) <> 0) x
UNION ALL SELECT 'items kept', COUNT(*) FROM refer_items
UNION ALL SELECT 'vehicles kept', COUNT(*) FROM refer_vehicles
UNION ALL SELECT 'users kept', COUNT(*) FROM refer_users
UNION ALL SELECT 'nurses kept', COUNT(*) FROM refer_nurses
UNION ALL SELECT 'checks kept', COUNT(*) FROM refer_checks
UNION ALL SELECT 'movements kept', COUNT(*) FROM refer_stock_movements;
