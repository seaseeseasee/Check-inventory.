-- ============================================================================
-- 016: ประเภทรถ — รถ Refer (ส่งต่อผู้ป่วย) หรือ รถ EMR (ประจำวอร์ด)
--
--   vehicle_type       = 'refer' (ค่าเริ่มต้น — รถเดิมทั้งหมดเป็นรถ Refer) | 'emr'
--   home_department_id = วอร์ดที่รถ EMR ประจำอยู่ (ไม่บังคับ) — รถ Refer เป็น NULL เสมอ
--                        ลบวอร์ดแล้วค่านี้กลายเป็น NULL (ไม่ขวางการลบวอร์ด)
-- รันซ้ำได้
-- ============================================================================

ALTER TABLE refer_vehicles
  ADD COLUMN IF NOT EXISTS vehicle_type VARCHAR(10) NOT NULL DEFAULT 'refer'
    COMMENT 'refer = รถ Refer | emr = รถ EMR ประจำวอร์ด' AFTER status,
  ADD COLUMN IF NOT EXISTS home_department_id VARCHAR(100) COLLATE utf8mb4_bin NULL DEFAULT NULL
    COMMENT 'วอร์ดที่รถ EMR ประจำอยู่ (refer_departments.id)' AFTER vehicle_type,
  ADD INDEX IF NOT EXISTS idx_vehicle_type (vehicle_type),
  ADD INDEX IF NOT EXISTS idx_vehicle_home_department (home_department_id);

ALTER TABLE refer_vehicles
  ADD CONSTRAINT fk_vehicle_home_department
    FOREIGN KEY IF NOT EXISTS (home_department_id) REFERENCES refer_departments (id) ON DELETE SET NULL ON UPDATE CASCADE;
