# รายงานตรวจสอบ–แก้ไข–ทดสอบ Workflow: ตรวจเช็กรถ → ตัดยอดบนรถ → สร้างรายการรอเติม → เติมของขึ้นรถ → ตรวจซ้ำ

วันที่ทดสอบ: 15/10/2569 (2026-10-15) • ระบบ: refer_system บน XAMPP (http://localhost/emer/public/) • โฟลเดอร์ source: `Desktop/sea/emer` → live: `C:/xampp/htdocs/emer`
ข้อมูลที่ใช้ทดสอบ: **ข้อมูลจำลอง (MOCK-) เท่านั้น** • สถานะปัจจุบันของฐานข้อมูลจริง: **ไม่มีการเปลี่ยนแปลง** (ตรวจสอบด้วย fingerprint md5 ก่อน–หลัง, ดูส่วนที่ 5)

---

## ส่วนที่ 1 — Flow เดิมก่อนแก้ไข (ตามที่ระบบทำงานจริง ไม่ใช่ตามที่ต้องการให้เป็น)

ลำดับเดิมของระบบ:

1. ผู้ตรวจเลือกรถ → เลือกเวร/ผู้ตรวจ → หน้าตรวจแสดง "รายการอุปกรณ์" จาก `refer_items` (มาตรฐานเป็น **ข้อความ** เช่น `1 ชุด`, `10`) พร้อมปุ่มสถานะ 3 แบบ: `✓ ครบ` / `ใช้ไป` / `ขาด` และช่อง "หมายเหตุ/สิ่งที่ขาด" เป็น **ข้อความอิสระ**
2. กดบันทึก → เขียน `refer_checks` 1 แถว + `refer_check_items` หลายแถว (status + detail เป็น text) และลบ `refer_drafts`
3. **ไม่มี** การเขียนยอดคงเหลือที่ใด ๆ ทั้งสิ้น — `refer_items.current_qty` มีอยู่ในตารางแต่ค่าเป็น `0.00` ทั้ง 253 แถวตั้งแต่ต้น (เป็น default ไม่ใช่ยอดจริง) และไม่มีโค้ด PHP/JS บรรทัดใดอ่านหรือเขียนคอลัมน์นี้ (ยืนยันด้วย `grep` ทั้ง source และ `htdocs`)
4. หน้า "รอเติม" (`refer_restock_requests` / `_items`) ถูกสร้างจากรอบตรวจ **แบบ manual/kick-off บางเส้นทาง** และเก็บจำนวนที่ต้องเติมไว้ใน `qty_requested` เป็น **VARCHAR(100)**; มีคอลัมน์ `vehicle_stock_before/_after` อยู่จริงแต่เป็น NULL ทั้ง 19 แถว (ไม่มีโค้ดเขียน)
5. ผู้ดำเนินการกด "เริ่มเติม" → กรอกจำนวน → "ยืนยันครบ" → ระบบอัปเดตสถานะคำขอเป็น COMPLETED แต่ **ไม่มีการตัดคลังกลาง ไม่มีการเพิ่มยอดบนรถ** และไม่มีการเก็บว่า "เติมจริงกี่ชิ้น" ในเชิงตัวเลข (เก็บเป็น text)
6. คลังกลาง `refer_stock_items` / `refer_stock_transactions` (สร้างไว้ในงานก่อนหน้า) ทำงานแยกโลกกับรอบตรวจรถโดยสิ้นเชิง — มีเฉพาะหน้า "คลังสต็อกกลาง" ที่รับเข้า/ปรับยอดได้

สรุปสาเหตุเชิงโครงสร้าง: **ระบบเดิม "นับสถานะ" แต่ "ไม่นับจำนวน"** จึงคำนวณใช้ไป/ต้องเติมไม่ได้ และผูกกับสต็อกไม่ได้

---

## ส่วนที่ 2 — ปัญหาที่พบและสาเหตุจริง (root cause)

| # | ปัญหา ( symptom ) | สาเหตุจริง | ผลกระทบถ้าไม่แก้ |
|---|---|---|---|
| P1 | หน้าตรวจแสดง "สต็อกปัจจุบัน" ไม่ได้ | คอลัมน์ `current_qty` มีอยู่จริงแต่เป็น placeholder รกร้าง (default 0 ทั้ง 253 แถว ไม่มีโค้ดอ่าน/เขียน) จึงไม่มียอดคงเหลือต่อรถที่ใช้ได้ | ผู้ใช้เห็น 0 แล้วตีความว่า "ของหมด" ซึ่งไม่จริง (ข้อ 1.5 ผิดทันที) |
| P2 | "ต้องเติมกี่ชิ้น" คำนวณไม่ได้ | มาตรฐานเก็บเป็นข้อความ (`1 ชุด`) ไม่มีตัวเลข; `refer_restock_items.qty_*` เป็น VARCHAR | ต้องเดาจำนวน, ตรวจย้อนกลับไม่ได้ |
| P3 | ผู้ใช้กรอก 2 แบบปนกัน (ใช้ไป / คงเหลือจริง) | UX เดิมให้พิมพ์ข้อความอิสระในช่อง "สิ่งที่ขาด" ไม่มี contract ตัวเลข | `ก่อน − ใช้ไป` กับ `คงเหลือจริง` ปนกัน → ยอดเพี้ยนถาวร |
| P4 | สมมติว่า "ต้องเติม = ใช้ไป" | ตรรกะเดิมไม่มีเป้าหมาย (target) แยกจากยอดคงเหลือ | เติมเกิน/ขาดเป้าหมาย เมื่อเป้าหมาย ≠ ยอดก่อนตรวจ |
| P5 | ตัดคลังตอน "ตรวจรถ" ทำให้ยอดคลังไม่ตรงของจริง | ถ้าตัดคลังตอนตรวจ ทั้งที่คนยังไม่ได้หยิบของจากคลัง → คลังหายทั้งที่ยังอยู่ บนรถ | reconcile คลัง–รถไม่ได้, ของหายในบัญชี |
| P6 | กด "เติมครบ" ซ้ำ / 2 คนทำพร้อมกัน → ยอดบวม | ไม่มี row lock, ไม่มี state machine ตรวจซ้ำ, `qty_filled` เขียนทับแบบ not monotonically-checked | สต็อกเพิ่มผี, ประวัติผู้ทำไม่ตรง |
| P7 | ตรวจแล้วระบบไม่ยืนยันว่า "บันทึกครบทั้งชุด" | การตรวจรถ/สร้างคำขอ/ตัดยอดอยู่คนละ statement ไม่มี transaction รวม | ครึ่ง ๆ กลาง ๆ: ได้สถานะแต่ไม่ได้ยอด |
| P8 | Authorization อิงปุ่มบน UI เป็นหลัก | บาง endpoint ตรวจแค่ role หยาบ ไม่ได้ตรวจเจ้าของงาน/สิทธิ์จริง | staff คนอื่นแก้งานของคนอื่นได้ (ข้อ 7.5) |
| P9 | โครงสร้าง DDL ซ้ำซ้อน / คอลัมน์ placeholder รกร้าง, `RestockController` มี method ที่ไม่ถูก route | ตกค้างจากการพัฒนาก่อนหน้า | สับสน, ยิงแล้ว 500 — **ยังไม่แก้ (เลื่อนไว้, ดูส่วนที่ 6)** |

---

## ส่วนที่ 3 — สิ่งที่แก้ไข (ไฟล์ / ฟังก์ชัน / ตาราง)

### 3.1 Database (additive เท่านั้น — ไม่มี DROP TABLE/COLUMN, ไม่ลบ/เขียนทับข้อมูลจริง)
`database/migrations/006-vehicle-stock.sql` (สร้างใหม่, รันบน dev แล้ว, สำรองก่อนรันที่ `.work/backup-pre-006.sql`)

* `refer_items`: `+ target_qty DECIMAL(10,2) NULL`, `+ unit VARCHAR(50)`, `CHANGE last_restocked_at → stock_updated_at` (คอลัมน์ placeholder ที่ว่าง 100% และไม่มีโค้ดอ้าง), `+ CHECK (current_qty >= 0)`, `+ INDEX idx_items_name`
* `refer_check_items`: `+ qty_before`, `+ qty_target`, `+ unit`, แก้ COMMENT ของ `actual_qty`/`used_qty` เดิม (ชนิด/ค่าเดิมไม่แตะ), `+ CHECK` ทุกยอดต้อง ≥ 0
* `refer_restock_items`: **ไม่มี schema change** — ใช้ `vehicle_stock_before/_after` ที่ว่างอยู่เดิมให้ถูกเขียนจริง + แก้ COMMENT
* สสัญญาใหม่ที่สำคัญ: **"ยังไม่เคยตรวจนับ" ⇔ `stock_updated_at IS NULL`** (ไม่ใช่ `current_qty = 0`) — ไม่ได้ UPDATE ค่า default เดิมให้เป็น NULL จึงไม่ทับข้อมูลเดิม

### 3.2 Backend — Services
* `app/Services/InspectionService.php` (เขียนใหม่ส่วนใหญ่, +792 บรรทัดใน diff)
  * `itemTargetQty()/itemUnit()/itemCountedQty()` — อ่านเป้าหมาย/หน่วย/ยอดนับจริง (คืน `null` เมื่อยังไม่เคยนับ ไม่คืน 0)
  * `deriveNumbers($before,$actual,$target)` — Case B เท่านั้นที่รับจากผู้ใช้ (`คงเหลือจริง`) → คำนวณ `used = before − actual`; `before = null` → ไม่สร้าง used; `actual > before` → บันทึกเป็น adjustment (used=NULL) ไม่สร้างติดลบ
  * `decorateResults()` — คืน `qty_before/target_qty/unit/car_qty/car_qty_at/warehouse_qty/warehouse_min/warehouse_status` ให้หน้าตรวจ+หน้าประวัติ
  * `submitInspection()` — **transaction เดียว**: ตรวจค่า → ล็อกรถ/รายการ → เขียน `refer_checks`+`refer_check_items` (รวมรอยเท้าตัวเลข) → เขียน `refer_items.current_qty` → สร้าง/อัปเดตคำขอรอเติม → commit; ผิดคือ rollback ทั้งชุด
  * ต้องตรวจครบทุกรายการก่อนบันทึก; กัน submit ซ้ำด้วย draft + lock
* `app/Services/RestockService.php` (+286)
  * สถานะ machine: `PENDING → IN_PROGRESS → PARTIAL → COMPLETED / CANCELLED`
  * `assertOperator()` — งานที่ยังไม่มอบหมาย: staff ทุกคนเริ่มได้; **มอบหมายแล้ว: ผู้ได้รับมอบหมายเท่านั้น** (ตัดข้อยกเว้น "ผู้สร้างคำขอ" ออก เพราะทำให้ประวัติผู้ทำไม่ตรงจริง + แข่งกับคนยึดงาน)
  * `restock()` — จำ `qty_filled` แบบสะสมและ clamp `≤ qty_requested`; ยอดบนรถใหม่ = `ของเดิม + ที่เติมจริง`; `press ซ้ำ` ที่ COMPLETED → 400; ใช้ `RestockRequest::lockById` + `RestockItem::lockLine` + `FOR UPDATE`
  * เรียก `StockService::issueForRestockFill()` **ตอนเติมจริงเท่านั้น** และคืนผลเป็น `restock_result {applied, skipped, alerts, untracked}` ให้ UI
* `app/Services/StockService.php` (+199)
  * `getQtyByNameMap()` เพิ่ม `status` ต่อชื่อ (ok/low/out/unset)
  * `issueForRestockFill()` — ล็อกคลังตามลำดับชื่อ (ksort) กัน deadlock, ตัด ISSUE + balance_after, ไม่พอ → `throw` Thai error เพื่อให้ caller rollback ทั้งชุด, **กรณียังไม่ตั้งยอดคลัง (0 + ไม่มี ledger)** → ลงทะเบียน `untracked` ไม่ fabricated ISSUE
  * `hasLedgerRows()` (new) — แยก "คลังว่างจริง" ออกจาก "ยังไม่เคยตั้งยอด"

### 3.3 Backend — Models / Controllers / Routes
* `app/Models/Item.php` — `countedQty()/countedAt()/lockByIds()` (FOR UPDATE แบบเรียง id)
* `app/Models/InspectionItem.php` — เขียน `qty_before/actual_qty/used_qty/qty_target/unit`
* `app/Models/RestockItem.php` (+92) — `lockLine()`, สะสม `qty_filled`, เก็บยอดก่อน/หลังเติม
* `app/Models/RestockRequest.php` (+41) — `lockById()`, numbering `RS-YYYYMMDD-####` ด้วย current-read `FOR UPDATE` + retry เมื่อ duplicate key (แก้ปัญหา REPEATABLE READ snapshot)
* `app/Models/Draft.php` (+12) — `lockByVehicle()` กันสองคนเปิดร่างของรถเดียวกัน
* `app/Controllers/InspectionController.php` (+169) — validation ตัวเลขรายบรรทัด (422 ภาษาไทย), ส่ง `errors{'results[i]'}`
* `app/Controllers/StaffController.php` (+56) — ตรวจสิทธิ์/เจ้าของ draft ฝั่งเซิร์ฟเวอร์ทุกครั้ง
* `app/Controllers/RestockController.php` (+29) — ปิดเส้นทาง method ที่ไม่มีอยู่แล้ว
* `routes/web.php` (+8) — ถอดเส้นทาง `/api/restock/{id}/draft|submit|issue|fill|fulfil` ที่ยิงแล้ว 500 ออก พร้อม comment ระบุเหตุผล

### 3.4 Frontend
* `resources/assets/js/pages/inspections.js` (+341) — แถบยอด `renderStockRow()` (บนรถ/ณ/เป้าหมาย/คลังกลาง + ไม่ตั้งยอด/ใกล้หมด), `renderRemainingRow()` รับ **ช่องเดียวคือ "คงเหลือจริง"** (`type=number, min=0, step=0.5`) + พรีวิวสด `ใช้ไป X • ต้องเติม Y`, ปุ่มสถานะเดิมยังใช้ได้แต่บังคับกรอกตัวเลข, `commitActualRemaining()` กันกรอก 2 แบบปนกัน
* `resources/assets/js/pages/restock.js` (+108) — คอลัมน์ **บนรถ (ก่อน→หลัง)**, `เติมแล้ว` เป็นตัวเลขสะสม (`max = ต้องเติม`), พรีวิว `+ n = projected`, toast แยกกรณียอดไม่พอ/ใกล้หมด/clamp/**untracked** และ **เพิ่มใหม่** แถบเตือน "ต่ำกว่าที่บันทึกแล้ว N" + tooltip บนปุ่มบันทึก (กันผู้ใช้เข้าใจว่ากรอกยอดของครั้งเดียว), ปุ่มกดครั้งเดียวล็อก (`data-busy`)
* `resources/assets/js/pages/history.js` (+41) — บรรทัด `renderStockAudit()` แสดง `ก่อนตรวจ / คงเหลือจริง / ใช้ไป / ต้องเติม` ต่อรายการ (NULL → ไม่แสดงเป็น 0)
* `core/api.js`, `components/notifications.js`, `css/pages/{inspections,restock,history}.css`, `views/pages/dashboard/index.php` — ไฟล์ประกอบ: ป้ายสถานะ, สไตล์ (`.stock-chip`, `.remaining-calc`, `.hist-audit`, `.rs-stock-projected`)

ทุกไฟล์ที่แก้ถูกซิงค์ไป `C:/xampp/htdocs/emer` และตรวจ `md5sum` ตรงกันต้นต่อต้น (โฟลเดอร์ `public/assets` ว่าง → asset เสิร์ฟจาก `resources/assets` จึงมีปลายทางเดียว); ไฟล์ก่อนซิงค์ 21 ไฟล์เก็บไว้ที่ `.work/htdocs-backup-20261015/` เพื่อ revert ทันที

---

## ส่วนที่ 4 — พฤติกรรมก่อน → หลัง (เทียบทีละจุด)

| จุด | ก่อน | หลัง |
|---|---|---|
| ก่อนตรวจนับ | ไม่มียอดให้ดู (หรือเห็น 0 หลอก) | `บนรถ: ยังไม่เคยตรวจนับ` + ณ เวลา; `current_qty` ไม่ถูกใช้เป็น 0 |
| ช่องกรอก | ข้อความอิสระ "สิ่งที่ขาด" | ตัวเลข `จำนวนคงเหลือจริงบนรถ` เท่านั้น (label ระบุ "ไม่ใช่จำนวนที่ใช้ไป") พร้อมสูตรกำกับ `ใช้ไป = ยอดเดิม − คงเหลือจริง` |
| คำนวณใช้ไป | ไม่มี | `used = before − actual` บันทึกใน `refer_check_items.used_qty`; `actual > before` → ใช้ไป = NULL (ถือเป็น adjustment/นับผิด) ไม่สร้างติดลบ |
| ต้องเติม | ไม่มี / สมมติ = ใช้ไป | `MAX(0, target − actual)` เขียน `refer_restock_items.qty_requested` (ตัวอย่างจริง: เป้า 10, คงเหลือ 7 → ต้องเติม 3; อีกกรณีต้องเติม 2 เมื่อมี outstanding 5 อยู่แล้ว = **ไม่ใช้ used**) |
| คลังกลางตอนตรวจรถ | ไม่แตะ (ไม่มีเส้นทางเชื่อมถึงกัน) | **ไม่ตัด** (ออกแบบตาม Business Logic ที่อนุมัติ: ตัดตอนยกขึ้นรถจริง) และแสดงสถานะคลัง + คำเตือนใกล้หมด |
| เติมของขึ้นรถ | อัปเดตสถานะอย่างเดียว | `บนรถ = ก่อน + ที่เติมจริง`, `ISSUE` ตัดคลัง + `balance_after`, เก็บ `vehicle_stock_before/_after`, `completed_at` เฉพาะเมื่อทุกการเขียนสำเร็จ |
| เติมไม่ครบ | ไม่มีสถานะกลาง | `PARTIAL` + เหลือเท่าไร + progress % + บันทึกผู้ทำต่อบรรทัด (timeline) |
| กดซ้ำ | ยอดเพิ่มได้ 2 รอบ | ปุ่ม UI หายหลัง COMPLETED + เซิร์ฟเวอร์ตอบ 400; `qty_filled` clamp ไม่เกิน `qty_requested` (ทดสอบ: 4 → 6 ไม่ได้ 13) |
| ความปลอดภัย | อิง role หยาบ/ปุ่ม | เซิร์ฟเวอร์ตรวจเจ้าของ draft, สิทธิ์ admin endpoint, ผู้ได้รับมอบหมายงาน, CSRF+Origin, validation 422 ทุก input |
| ประวัติย้อนรอย | text ล้วน | ตัวเลขครบชุดใน `refer_check_items` + ledger `refer_stock_transactions` + timeline `refer_restock_actions` |

---

## ส่วนที่ 5 — ผลทดสอบรายกรณี (§8 + §7)

ข้อมูลจำลองที่ใช้: `MOCK-E2E-CAR`, `MOCK-E2E-ITEM-A/B/C` (เป้า 10/5/8), `MOCK-E2E-STK-A` (12, min 0), `MOCK-E2E-STK-B` (5, min 6), `MOCK-E2E-NURSE-1/2`, `MOCK-E2E-STAFF-1/2`, `MOCK-E2E-ADMIN` — สร้างด้วย `.work/e2e-006-fixture.php`, ตรวจด้วย `.work/e2e-fingerprint.php`, ลบด้วย `.work/e2e-006-cleanup.php`

**Tier A — ทดสอบจริง (test-verified: ยิง API/curl + UI จริงบนเบราว์เซอร์ + อ่าน MySQL)**

| กรณี | ผล | หลักฐาน (จริงใน DB / หน้าจอ) |
|---|---|---|
| §1 แสดงยอดล่าสุด + หน่วย + ไม่แสดง 0 เมื่อไม่เคยนับ | PASS | UI: `บนรถ 10 ชิ้น ณ 15/10 17:19 เป้าหมาย 10 ชิ้น คลังกลาง 1` / `คลังกลาง 5 (ใกล้หมด)` / `คลังกลาง: ยังไม่ตั้งยอด`; C (stock_updated_at=NULL) ไม่แสดง 0 |
| §1 กรอกได้ type เดียว (คงเหลือจริง) + พรีวิว | PASS | label `จำนวนคงเหลือจริงบนรถ (ไม่ใช่จำนวนที่ใช้ไป ชิ้น)`, `input type=number min=0 step=0.5`, calc `ใช้ไป 6 ชิ้น • ต้องเติม 6 ชิ้น` |
| §2 บันทึกตรวจรถ (10 → 7) | PASS | `refer_checks`+`refer_check_items` (qty_before 10, actual 7, used 3, target 10, unit ชิ้น) + `refer_items.current_qty=7` + `stock_updated_at` |
| §2 actual > before (ไม่เคยนับ) | PASS | แถว C: `qty_before=NULL, actual=9, used=NULL` — ไม่สร้าง used ติดลบ/ไม่ fabricated |
| §2 validation: ตัวอักษร/ค่าติดลบ/ว่าง/ item แปลกปลอม | PASS | HTTP 422 + `errors{'results[i]'}` ภาษาไทย (payload `.work/p-bad-*.json`) |
| §3 สร้างรายการรอเติมอัตโนมัติ + ต้องเติม = MAX(0,เป้า−คงเหลือ) | PASS | `RS-…-0008` line เดียว: `qty_requested=6` (เป้า10−จริง4); B/C ที่ครบไม่ถูกสร้าง; §3 "ไม่เท่า used" ยืนยันด้วยกรณี netting ต้องเติม 2 (ไม่ใช่ used 3) |
| §4 เติมจริง 4/6 → PARTIAL | PASS | `qty_filled=4`, บนรถ `4.00→8.00`, คลัง `11→7` ISSUE −4, status PARTIAL, เหลือ 2, progress 67% |
| §4 เติมต่อจนครบ 6 → COMPLETED | PASS | `qty_filled=6`, บนรถ 10, คลัง 5 (ISSUE −2), `completed_at` ถูกเขียน |
| §4 กด "เติมครบ" ซ้ำ | PASS (UI+DB) | การ์ด COMPLETED เหลือปุ่ม "ดูรายละเอียด" เท่านั้น; API ซ้ำ → 400; บนรถคง 10 ไม่ได้ 13 |
| §4 เติม > ที่ขอ (clamp) | PASS | `max=6` ฝั่ง UI + เซิร์ฟเวอร์ clamp; `qty_filled ≤ qty_requested` ทุกกรณี |
| §4 คลังไม่พอ →ล้มทั้งชุด | PASS | HTTP 400 ข้อความไทย ("คลังกลางมี … ไม่พอ…"), ไม่มี ISSUE row, สถานะคำขอไม่เปลี่ยน (log: restock 400 ที่ 10:18:18 / 10:18:57) |
| §4 untracked warehouse (ยังไม่ตั้งยอดคลัง) | PASS | สร้าง `refer_stock_items` ของ C (0/0) แต่ **ไม่มี ISSUE ledger**; toast/ผล `untracked` เตือนผู้ใช้; บนรถยังอัปเดตถูกต้อง |
| §5 เปิดหน้าตรวจใหม่แล้วอ่านยอดล่าสุด | PASS | reopen รถจำลอง → `บนรถ 10 … ณ 17:32` + `คลังกลาง 5`; ตรวจรอบถัดไปคำนวณจาก 10 (`ใช้ไป 3 ต้องเติม 3`) → `RS-…-0009` |
| §5 ของที่เติมครบไม่โผล่เป็น pending ซ้ำ | PASS | B/C (ครบเป้าหมาย) ไม่มี line; รายการ COMPLETED ไม่กลับมาในคิวยังไม่เติม (UI upแสดงเฉพาะที่ขาด: "ซ่อน 1 รายการที่ครบ") |
| §6 audit trail ตรวจย้อนกลับได้ | PASS | `refer_check_items` ครบชุด + `refer_stock_transactions` (INIT/RECEIVE/ISSUE พร้อม balance_after/car_name/request no) + timeline `_actions` 5 ประเภท + ประวัติแสดง `.hist-audit` on screen |
| §7 2 user พร้อมกัน: start/claim เดียว | PASS | staff2 ยึดงาน staff1 → 400 "งานนี้มอบหมายให้ผู้ดำเนินการท่านอื่นแล้ว…"; concurrent start มีผู้ชนะคนเดียว (FOR UPDATE) |
| §7 draft ownership / admin endpoint | PASS | staff2 submit draft ของคนอื่น → 403; staff เรียก admin endpoint → 403 |
| §7 double submit หน้าตรวจ | PASS | ปุ่มถูก disable ทันทีหลัง click แรก; click ซ้ำเป็น no-op; DB มี 1 check/1 request |
| §8 happy path ครบวงจร (10→7→3→fill→10→reopen→dup→next round) | PASS | ทำซ้ำ 2 รอบเต็มบน UI + API; ค่าตรงทุกรอบตามตารางด้านบน |

**Tier B — code-verified เท่านั้น (ยังไม่ยืนยันด้วยการรัน):**
* การถอด route `/api/restock/{id}/draft|submit|issue|fill|fulfil` — ตรวจแล้วว่า method ไม่มีจริง (ยิงแล้ว 500) และไม่มี client เรียก แต่ **ไม่ได้ทดสอบเชิง regression ทั้งไฟล์ RestockController**
* พฤติกรรม retry เมื่อเลขคำขอชนกันระดับ "ผู้ใช้จริง 2 คนสร้างพร้อมกันหลายรถ" — ทดสอบเพียงระดับ single-car + duplicate-key path ในโค้ด
* ผลของ `CHECK` constraint เมื่อมีการเขียนจากภายนอก (phpMyAdmin/สคริปต์อื่น) — ไม่ได้ทดสอบ
* หน้า Dashboard/notification ที่แก้ประกอบ — ดูด้วยตาว่า render ได้ แต่ไม่ได้ทดสอบเชิงตัวเลข

**Tier C — ข้อค้นพบระหว่างทดสอบที่ต้องรายงาน (สำคัญ):**
1. **ข้อมูลจำลองหลุดเข้าคิวยังทำงานจริง**: `RS-…-0002` (mock, ยังไม่มอบหมาย) ปรากฏในหน้า "เติมอุปกรณ์" ของบัญชีจริง และถูกกดยึด+เติมโดยบัญชี `admin` (by_name `Admin User`) ภายใน ~21 วินาที — ยืนยันจาก Apache access.log UA `Chrome/155…` (ไม่ใช่ automation) ที่ 10:11:28–29 ระบบออกแบบเดิม: คำขอ PENDING ที่ยังไม่มอบหมาย **ทุกคนเห็นและทำได้** จึง unavoidable เมื่อทดสอบบน DB ที่คนใช้งานอยู่จริง
2. **สลับตัวตนระหว่างทดสอบ**: หลัง `location.reload()` ที่ 10:36:23 เซสชันของเบราว์เซอร์เปลี่ยนเป็นบัญชีจริง `admin` (ยืนยันด้วย `/api/auth/me`: `id=""`, `username=admin`) การยึด/เติมใน `RS-…-0009` (10:38:29/37/59) จึงถูกบันทึกชื่อ `Admin User` ทั้งที่เป็นคำสั่งของ test automation — **ไม่กระทบข้อมูลจริง** (ทุกแถวเป้าหมายเป็น MOCK และถูกล้างแล้ว) แต่รอยเท้าผู้ทำของ 5 แถวใน `_restock_actions` หายไปพร้อมการลบ mock ต้องรายงานไว้ตรงนี้
3. **บัญชี `admin` มี `id` เป็น string ว่าง** — ทำให้ FK `by_user_id` พึ่งค่าว่าง และ `assigned_to_user_id=''` ตีความได้ 2 ความหมาย (ยังไม่มอบหมาย / มอบหมายให้อดมิน) เป็นข้อจำกัดการระบุตัวบุคคลที่มีอยู่เดิม

**ผลตรวจว่า "ข้อมูลจริงไม่ถูกแก้":** `.work/fp-before.json` → `fp-final.json` เทียบจำนวนแถวจริง + md5 ของแถวจริงทุกตาราง:

```
_items_real 369824ffe44163ef069365f232a08b53 (SAME) • _checks_real ebc76efa… (SAME)
_requests_real 751a3eb1… (SAME) • _stock_real 8fc83c26… (SAME) • _ledger_real 1cd289f6… (SAME)
real rows หลังล้าง: vehicles 5, items 317, checks 7, check_items 443, drafts 2, draft_items 127,
restock_requests 7, restock_items 21, restock_actions 22, stock_items 64, ledger 15, nurses 15, users 6
residual MOCK ทุกตาราง = 0  • mock users = 0 • คำขอเปิดค้าง (PENDING/IN_PROGRESS/PARTIAL) = 0 • GET / => HTTP 200
```

(ต่างเดียวที่เห็นระหว่างทางคือ 5 แถวใน `refer_restock_actions` ซึ่ง "ดูเหมือน real" เพราะ `by_id=''` ของบัญชี admin แต่ทุกแถวผูกกับ **คำขอ mock** และถูกล้างไปแล้ว — ตรวจรายการจริงทั้ง 12 รายการว่าไม่ถูกแตะ)

---

## ส่วนที่ 6 — ความเสี่ยง / ข้อจำกัดที่ยังไม่ถูกแก้

1. **ยอดบนรถของรถจริงทั้ง 317 รายการยังไม่เคยตรวจนับ** — ต้องตรวจรอบแรกเพื่อตั้งยอดจริง ระหว่างนั้นหน้าตรวจจะแสดง "ยังไม่เคยตรวจนับ" ทั้งหมด (ตั้งใจไว้เพื่อให้หลอกไม่ได้)
2. **คลังกลางมีของขึ้นทะเบียนเพียงส่วนน้อย** — `refer_stock_items` มี 64 ชื่อ (ยอดรวม 177 หน่วย) ขณะที่อุปกรณ์บนรถ 317 ชื่อจำนวนมากไม่ตรงกัน → การเติมจะออกอาการ "untracked" (ไม่ตัดคลัง) บ่อย จนกว่าผู้ดูแลจะรับของเข้าคลัง/ตั้งชื่อให้ตรง
3. **`refer_stock_items.unit` ว่างทั้ง 64 แถว** — หน่วยที่แสดงตอนนี้มาจาก `refer_items.standard` ที่สกัดด้วย REGEXP; ชื่อที่มาตรฐานไม่มีตัวเลขจะไร้หน่วย
4. **กรณียอดคลังไม่พอ ระบบให้ล้มทั้งชุด** — ดีต่อความถูกต้อง แต่ทำให้ "เติมไม่ครบเพราะคลังหมด" ต้องแก้ด้วยการรับของเข้าก่อน ไม่ใช่การบันทึกบางส่วนข้ามคำขอ; ยังไม่มี UI "ขอซื้อ/โอนระหว่างรถ"
5. **เวลาตัดคลังย้ายเป็น "ตอนเติมจริง"** ตามการอนุมัติ — หมายความเป็นว่ายอดคลัง ≠ ยอดที่รถรับไปจนกว่าจะยกขึ้นรถ; ผู้ตรวจบัญชีต้องรับทราบและปรับรอบนับสต็อก
6. **ร่างตรวจเดิม (`refer_draft_items`) ที่เก็บบันทึกแบบ text** ไม่เข้า contract ตัวเลขใหม่ ผู้ที่เปิดร่างค้างไว้ก่อนการแก้ต้องกรอกตัวเลขใหม่ (ตรวจพบ 2 drafts ของรถจริง — ไม่ถูกเขียนทับ)
7. **ยังใช้บัญชีร่วม (`admin` id=ว่าง)** → ระบุผู้ดำเนินการไม่น่าเชื่อถือ, ระบบไม่กัน "ตัวเองอนุมัติตัวเอง"
8. **P9 (DDL placeholder ซ้ำซ้อน + method ที่ไม่ถูก route) เลื่อนออกไป** ตาม scope ที่เลือก (core flow + security/race) — ยังมีความสี่ยงผู้พัฒนาอ่าน schema ผิดเจตนา
9. **ไม่มี automated regression suite** — การทดสอบทั้งหมดเป็น manual/evidence-based; ถ้าแก้ logic อื่นต้องทดสอบชุดนี้ใหม่
10. **การทดสอบบน DB ที่ใช้งานจริง**: ไม่ควรทำอีก — คำขอ PENDING ที่ไม่มอบหมายจะโผล่ให้ staff จริงทันที (ดูข้อ 5 ส่วน Tier C) ควรใช้ staging DB หรือให้ mock ทุกคำขอถูกสร้างในสถานะ `CANCELLED`/แยก queue ก่อน

---

## ส่วนที่ 7 — ขั้นตอนที่ต้องให้ผู้ดูแล (admin) ตรวจ/อนุมัติก่อนขึ้น Production

1. **อนุมัติการย้ายเวลาตัดคลัง**: ตรวจรถ = ตัดเฉพาะยอดบนรถ, คลังถูกตัดตอน "ยกขึ้นรถจริง" (ISSUE) — ยืนยันกับงานบัญชี/ตรวจนับ
2. **รัน migration `006-vehicle-stock.sql` บนฐาน production** หลังสำรองแล้ว (`mysqldump`) และตรวจ `SELECT COUNT(*) WHERE stock_updated_at IS NOT NULL` = 0 ก่อนเริ่มรอบตรวจแรก
3. **ตั้งเป้าหมาย/หน่วยของอุปกรณ์จริง 317 รายการ**: ตรวจ `target_qty` ที่สกัดจาก `standard` (บางรายการได้ NULL โดยตั้งใจ) และกรอก `unit` ใน `refer_stock_items` ทั้ง 64 ชื่อที่ยังว่าง — **อนุมัติตัวเลขเป้าหมาย** เพราะเป็นฐานของ "ต้องเติม"
4. **ตรวจนับตั้งต้นยอดบนรถทีละคัน** (ตรวจรถรอบแรกโดยไม่ได้ "เติมของ") เพื่อให้ `current_qty`/`stock_updated_at` เป็นข้อมูลจริง
5. **รับของเข้าคลังกลาง + ตั้ง `min_qty`** ให้ครอบคลุมรายการที่รถใช้จริง มิฉะนั้นจะเกิด untracked/ไม่พอตัดซ้ำ ๆ
6. **นโยบายผู้ดำเนินการ**: อนุมัติ (ก) การให้ staff ทุกคนยึดงานที่ยังไม่มอบหมาย (ข) การตัดสิทธิ์ "ผู้สร้างคำขอทำเองเมื่อยึดแล้ว" (ค) การทำบัญชีรายบุคคลแทนบัญชี `admin` ร่วม — แนะนำให้สร้าง user ต่อคนก่อน production เพราะผลทดสอบแสดงว่า `by_id=''` ทำให้ระบุตัวคนไม่ได้จริง
7. **ตัดสินใจเรื่อง "คลังไม่พอ = ล้มทั้งคำขอ"**: อนุมัติให้ใช้ policy นี้ หรือขอ variant "อนุญาติ partial + เปิดคำขอใหม่ต่อ"
8. **ทบทวนค่า `warehouse_min`** และระดับการแจ้งเตือน (ใกล้หมด/หมด) ที่แสดงบนหน้าตรวจรถ
9. **อนุมัติการล้าง P9** (ตาราง placeholder + method ที่ไม่ถูก route) ว่าจะเก็บไว้เป็น backlog หรือลบจริง พร้อมแผน revert
10. **กำหนดรอบ test บน staging** — ห้ามทดสอบที่สร้าง PENDING request บน DB ที่มี staff ใช้งานจริง (บทเรียนจาก RS-0002)

---

### ภาคผนวก — ไฟล์หลักฐาน (.work/)

`e2e-006-fixture.php` (สร้าง mock) • `e2e-006-cleanup.php` (dry-run + `--apply`, FK-ordered, residual=0) • `e2e-fingerprint.php` (before/after/final) • `e2e-lib.sh` (curl + Origin/CSRF recipe) • `e2e-show-results.php` (แสดงผล API ต่อรายการ) • `fp-before.json` / `fp-after.json` / `fp-final.json` • `backup-pre-006.sql` • `htdocs-backup-20261015/` (21 ไฟล์ก่อนซิงค์) • `p-bad-*.json` (payload validation)

สิ่งที่ลบหลังทดสอบเสร็จ: ตาราง fixture ทั้งหมด (MOCK-) + ไฟล์ `.work/e2e-006-credentials.json` (รหัสผ่าน mock) และ cookie jar ของการทดสอบ
