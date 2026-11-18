-- ============================================================================
-- ย้อนกลับ 017 + 018 + 019 (ต้องรันด้วย root)
--
-- ⚠️ ลบ log ทั้งหมดที่เก็บไว้ในฐาน refer_logs และสิทธิ์ดู log ทั้งหมด — กู้คืนไม่ได้
--    ถ้าระบบใช้งานจริงแล้ว ให้ dump ก่อน:
--    docker exec emer-db-1 sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --databases refer_logs' > refer_logs-backup.sql
--    และถอดโค้ดแอปที่เขียน log ออกก่อน (ไม่งั้นทุก request ที่เขียน log จะไปลง fallback file แทน)
--
-- ไม่แตะตารางเดิม (refer_audit_log ฯลฯ) และไม่แตะสิทธิ์ refer_app บน refer_system
-- รันด้วย --force (ข้าม REVOKE ของสิทธิ์ที่ไม่มี เช่นยังไม่เคยรัน 019):
--   docker exec -i emer-db-1 sh -c 'mariadb --force -uroot -p"$MYSQL_ROOT_PASSWORD"' < database/migrations/017-rollback.sql
-- ============================================================================

-- ถอนเฉพาะสิทธิ์ที่ 018 ให้ (DROP DATABASE ไม่ลบ grant ให้เอง) — สิทธิ์บน refer_system คงเดิม
-- ถ้ารันซ้ำแล้ว error "There is no such grant" = ถอนไปแล้วรอบก่อน ข้ามได้
REVOKE UPDATE (`last_seq`, `last_hash`) ON `refer_logs`.`log_chain_head` FROM `refer_app`@`%`;   -- 019 (ถ้ารันแล้ว)
REVOKE UPDATE (`repeat_count`, `last_seen_at`) ON `refer_logs`.`log_events` FROM `refer_app`@`%`;
REVOKE SELECT, INSERT ON `refer_logs`.* FROM `refer_app`@`%`;

DROP USER IF EXISTS `refer_log_archiver`@`%`;

DROP TABLE IF EXISTS `refer_system`.`refer_user_permissions`;
DROP DATABASE IF EXISTS `refer_logs`;

FLUSH PRIVILEGES;
