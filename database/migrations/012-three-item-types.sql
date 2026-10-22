-- ============================================================================
-- 012: เหลือประเภทอุปกรณ์ 3 แบบ + รายการรอเติมแบบ "ตรวจสอบ/เปลี่ยน"
--
--   box          ของเป็นกล่อง/ล็อต — 1 ล็อต = จำนวน + วันหมดอายุเดียวกัน
--   individual   ของเป็นชิ้น — 1 แถวใน refer_item_lots = 1 ชิ้น (qty 1) มีรหัส (id) และวันหมดอายุของตัวเอง
--   non_expiring ไม่มีวันหมดอายุ — นับรายเครื่อง/รายชิ้น (refer_item_units) สถานะพร้อม/ไม่พร้อมใช้
--                + is_battery (มีแบตเตอรี่) / has_pressure (วัดแรงดัน PSI เช่นถัง O2)
--
-- แปลงประเภทเดิม: piece → individual, durable/electric/oxygen → non_expiring (oxygen → has_pressure = 1)
-- ไม่แปลงยอดสต็อก — ล้างสต็อกแยกด้วย 012b-reset-vehicle-stock.sql (ปิดยอดด้วยประวัติ)
-- รันซ้ำได้
-- ============================================================================

ALTER TABLE refer_items
  MODIFY COLUMN item_type ENUM('box','piece','durable','electric','oxygen','individual','non_expiring') NOT NULL DEFAULT 'individual',
  ADD COLUMN IF NOT EXISTS has_pressure TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'non_expiring: วัดแรงดัน PSI ทุกครั้งที่ตรวจ (ถัง O2)' AFTER is_battery;

UPDATE refer_items SET has_pressure = 1 WHERE item_type = 'oxygen';
UPDATE refer_items SET is_battery = 1 WHERE item_type = 'electric';
UPDATE refer_items SET is_battery = 0 WHERE item_type IN ('durable', 'oxygen');
UPDATE refer_items SET item_type = 'individual' WHERE item_type = 'piece';
UPDATE refer_items SET item_type = 'non_expiring' WHERE item_type IN ('durable', 'electric', 'oxygen');

ALTER TABLE refer_items
  MODIFY COLUMN item_type ENUM('box','individual','non_expiring') NOT NULL DEFAULT 'individual'
    COMMENT 'box=กล่อง/ล็อต, individual=รายชิ้น (วันหมดอายุรายชิ้น), non_expiring=ไม่มีวันหมดอายุ (รายเครื่อง)';

-- ประเภทในประวัติการตรวจ (ข้อความ) — ให้ตรงกับชื่อใหม่
UPDATE refer_check_items SET item_type = 'individual' WHERE item_type = 'piece';
UPDATE refer_check_items SET item_type = 'non_expiring' WHERE item_type IN ('durable', 'electric', 'oxygen');

-- รายการรอเติม: refill = เติมจำนวนที่ขาด | replace = ตรวจสอบ/เปลี่ยนอุปกรณ์ที่ไม่พร้อมใช้ (ไม่มีจำนวนขาด)
ALTER TABLE refer_restock_items
  ADD COLUMN IF NOT EXISTS line_type VARCHAR(20) NOT NULL DEFAULT 'refill' COMMENT 'refill | replace' AFTER item_id,
  ADD COLUMN IF NOT EXISTS note VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'เหตุผล เช่น สาเหตุที่ไม่พร้อมใช้' AFTER vehicle_stock_after;
