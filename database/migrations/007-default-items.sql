-- ============================================================================
-- Migration 007 — ชุดอุปกรณ์มาตรฐานเริ่มต้น (Default Items Checklist)
-- ============================================================================
-- วัตถุประสงค์
--   งาน "ทะเบียนรถและอุปกรณ์ประจำรถ" กำหนดให้เมื่อ "เพิ่มรถใหม่" แล้วระบบต้องสร้าง
--   รายการอุปกรณ์เริ่มต้นให้ทันที (ไม่ต้องรอให้เลือกคัดลอกจาก รถคันอื่น เท่านั้น)
--   เดิมไม่มี "แม่แบบ" ที่เป็นที่เก็บได้ — refer_items ผูกกับรถเป็นรายคันเท่านั้น
--
-- แนวทาง (additive เท่านั้น — ไม่แก้โครงสร้าง ไม่ลบข้อมูลเดิม)
--   ใช้ตาราง refer_metadata ที่มีอยู่แล้ว (id + payload JSON) เก็บแม่แบบ 1 แถว
--     id = 'default_items'
--     payload = { source_car_id, source_label, updated_at, items: [ {name, standard,
--                 expiry_text, is_battery, target_qty, unit}, ... ] }
--   ค่าเริ่มต้นสกัดจาก "รถที่มีรายการอุปกรณ์ที่ใช้งานอยู่มากที่สุด" ในฐานข้อมูลปัจจุบัน
--   (บันทึกแหล่งที่มาไว้ใน source_car_id / source_label เพื่อให้ผู้ดูแลตรวจได้ว่า
--    ชุดมาตรฐานนี้มาจากรถคันไหน — ไม่ได้เดาขึ้นใหม่)
--
-- ผลกระทบ
--   refer_metadata เดิมมี 2 แถว (legacy, schema) → เพิ่มอีก 1 แถว
--   ไม่แตะ refer_vehicles / refer_items / ประวัติการตรวจใด ๆ
--   โค้ดฝั่งแอปอ่านแม่แบบผ่าน VehicleService::defaultItemsTemplate() เท่านั้น
--   ถ้าไม่มีแถวนี้ ระบบยังทำงานได้ (fallback: คัดจากรถที่มีรายการมากที่สุดที่มีชีวิตอยู่)
--
-- แผน rollback
--   DELETE FROM refer_metadata WHERE id = 'default_items';
--
-- ความต้องการ
--   MariaDB 10.2+ (JSON_OBJECT / JSON_QUOTE)
--   หมายเหตุ: MariaDB 10.4 ที่ระบบใช้อยู่ "ไม่มี" JSON_ARRAYAGG (มีตั้งแต่ 10.5+)
--   จึงประกอบ array ด้วย GROUP_CONCAT แทน และเพิ่ม group_concat_max_len เป็น 4MB
--   (ค่า default ของเซิร์ฟเวอร์นี้คือ 1MB ซึ่งใหญ่พอสำหรับ 64 รายการ แต่ตั้งให้ชัดไว้ก่อน)
-- ============================================================================

SET SESSION group_concat_max_len = 4194304;

INSERT INTO refer_metadata (id, payload)
SELECT
    'default_items',
    CONCAT(
        '{"source_car_id":',  JSON_QUOTE(src.car_id),
        ',"source_label":',   COALESCE(JSON_QUOTE(v.name), JSON_QUOTE(src.car_id)),
        ',"updated_at":',     JSON_QUOTE(DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')),
        ',"items":',          CASE WHEN agg.items IS NULL THEN '[]'
                                   ELSE CONCAT('[', agg.items, ']') END,
        '}'
    )
FROM (
    SELECT car_id
      FROM refer_items
     WHERE active = 1
     GROUP BY car_id
     ORDER BY COUNT(*) DESC, car_id ASC
     LIMIT 1
) src
LEFT JOIN refer_vehicles v ON v.id = src.car_id
LEFT JOIN (
    SELECT
        i.car_id,
        GROUP_CONCAT(
            JSON_OBJECT(
                'name', i.name,
                'standard', i.standard,
                'expiry_text', i.expiry_text,
                'is_battery', COALESCE(i.is_battery, 0),
                'target_qty', i.target_qty,
                'unit', i.unit
            )
            ORDER BY i.name, i.id
            SEPARATOR ','
        ) AS items
      FROM refer_items i
     WHERE i.active = 1
     GROUP BY i.car_id
) agg ON agg.car_id = src.car_id
WHERE NOT EXISTS (SELECT 1 FROM refer_metadata m WHERE m.id = 'default_items');
