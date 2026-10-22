-- ============================================================================
-- ย้อนกลับ migration 011 (สำรองฐานข้อมูลก่อนเสมอ — dump อยู่ที่ storage/backups/pre_011_*)
--
-- ⚠ การแปลงประเภท (ปิดล็อต → สร้างเครื่อง/ถัง) ย้อนด้วย SQL อัตโนมัติไม่ได้อย่างปลอดภัย
--   เพราะหลัง 011 อาจมีการตรวจ/เติมเพิ่มแล้ว — ถ้าต้องการสถานะก่อน 011 ทุกประการ ให้ restore dump
-- สคริปต์นี้ถอดเฉพาะโครงสร้างที่ 011 เพิ่ม โดยแปลงประเภทใหม่กลับเป็นประเภทที่ 010 รู้จัก
-- ============================================================================

UPDATE refer_items SET item_type = 'piece' WHERE item_type = 'durable';
UPDATE refer_items SET item_type = 'electric', is_battery = 1 WHERE item_type = 'oxygen';
ALTER TABLE refer_items
  MODIFY COLUMN item_type ENUM('box','piece','electric') NOT NULL DEFAULT 'piece',
  DROP COLUMN IF EXISTS battery_max_bars, DROP COLUMN IF EXISTS battery_low_bars,
  DROP COLUMN IF EXISTS o2_low_psi, DROP COLUMN IF EXISTS expiry_warn_days;

UPDATE refer_item_units SET status = 'not_ready' WHERE status IN ('pending', 'no_power');
UPDATE refer_item_units SET battery_level = NULL WHERE battery_level = 0 OR battery_level > 4;
ALTER TABLE refer_item_units DROP CONSTRAINT IF EXISTS chk_unit_psi;
ALTER TABLE refer_item_units DROP CONSTRAINT IF EXISTS chk_unit_battery;
ALTER TABLE refer_item_units
  MODIFY COLUMN status ENUM('ready','not_ready') NOT NULL DEFAULT 'ready',
  DROP COLUMN IF EXISTS pressure_psi,
  ADD CONSTRAINT chk_unit_battery CHECK (battery_level IS NULL OR battery_level BETWEEN 1 AND 4);

ALTER TABLE refer_check_items DROP COLUMN IF EXISTS condition_status, DROP COLUMN IF EXISTS lot_detail;
ALTER TABLE refer_item_lots
  DROP COLUMN IF EXISTS lot_number, DROP COLUMN IF EXISTS received_date,
  DROP COLUMN IF EXISTS lot_status, DROP COLUMN IF EXISTS note;
