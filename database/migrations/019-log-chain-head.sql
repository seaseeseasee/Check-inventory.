-- ============================================================================
-- 019: หัวสาย hash chain ของ audit log (ต้องรันด้วย root, หลัง 017/018)
--
--   เดิมแอปหาแถวก่อนหน้าจาก MAX(chain_seq) ใน log_changes — เมื่อแถวท้ายสายถูกย้ายไป archive
--   (เช่น log ที่นำเข้าย้อนหลังมีวันที่เก่าแต่ seq สูงสุด) แอปจะออก seq ซ้ำ = สายแตก
--   ตารางนี้เก็บ seq/hash ล่าสุดแยกไว้ ไม่ถูก archive และ bin/logs-verify.php ใช้ตรวจว่าท้ายสายไม่ถูกตัด
--
-- บัญชีแอป: UPDATE ได้เฉพาะ 2 คอลัมน์นี้ (SELECT/INSERT มีจาก 018 ระดับฐานอยู่แล้ว)
-- รันซ้ำได้: แถวหัวสายตั้งจากแถวล่าสุดใน log_changes เฉพาะครั้งแรก (INSERT IGNORE)
--   docker exec -i emer-db-1 sh -c 'mariadb -uroot -p"$MYSQL_ROOT_PASSWORD"' < database/migrations/019-log-chain-head.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS `refer_logs`.`log_chain_head` (
    `id`        TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `last_seq`  BIGINT UNSIGNED NOT NULL,
    `last_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_chain_head_single` CHECK (`id` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Audit hash chain head (survives archiving)';

INSERT IGNORE INTO `refer_logs`.`log_chain_head` (`id`, `last_seq`, `last_hash`)
SELECT 1, `chain_seq`, `row_hash` FROM `refer_logs`.`log_changes` ORDER BY `chain_seq` DESC LIMIT 1;

GRANT UPDATE (`last_seq`, `last_hash`) ON `refer_logs`.`log_chain_head` TO `refer_app`@`%`;
FLUSH PRIVILEGES;
