# Ambulance Refer Checklist System - Version 5

ระบบตรวจเช็ครถพยาบาล (Ambulance Refer Checklist) เวอร์ชัน 5 พร้อมระบบเบิก-เติมอุปกรณ์ครบวงจร

## โครงสร้างโปรเจกต์

```
refer-system/
├── app/                    # Application Logic
│   ├── Config/             # Configuration classes
│   ├── Controllers/        # HTTP Controllers
│   ├── Helpers/            # Helper classes
│   ├── Middleware/         # HTTP Middleware
│   ├── Models/             # Data Models
│   └── Services/           # Business Logic Services
├── config/                 # Configuration files
│   ├── app.php
│   ├── database.php
│   └── bootstrap.php
├── database/               # Database files
│   ├── migrations/         # SQL Migrations
│   └── seeds/              # Seed data
├── public/                 # Web Document Root
│   ├── index.php           # Entry point
│   ├── .htaccess           # Apache config
│   └── assets/             # Compiled assets
├── resources/              # Frontend Resources
│   ├── assets/
│   │   ├── css/            # Stylesheets
│   │   └── js/             # JavaScript modules
│   └── views/              # View templates
│       ├── layouts/        # Layout templates
│       ├── components/     # Reusable components
│       └── pages/          # Page templates
├── routes/                 # Route definitions
│   └── web.php
├── storage/                # Storage
│   ├── logs/
│   └── backups/
├── tests/                  # Test files
├── vendor/                 # Composer dependencies
├── .env.example            # Environment template
└── README.md
```

## การติดตั้ง

### 1. เตรียมสิ่งที่จำเป็น
- PHP 8.1+ with PDO MySQL
- MySQL 8.0+ หรือ MariaDB 10.5+
- Apache 2.4+ หรือ Nginx
- ไม่ต้องใช้ Composer — ระบบใช้ autoloader เขียนเองที่ `vendor/autoload.php` (ดูคอมเมนต์ในไฟล์)

### 2. Clone และตั้งค่า
```bash
git clone <repository>
cd emer
cp .env.example .env
# แก้ไข .env ให้ตรงกับสภาพแวดล้อม (ดูคีย์ทั้งหมดใน .env.example)
```

### 3. สร้างฐานข้อมูล

**ฐานข้อมูลใหม่ (ยังไม่มีข้อมูลเลย):** รันไฟล์เดียว
```bash
mysql -u root -p < database/schema.sql
mysql -u root -p refer_system < database/seed.sql   # ข้อมูลตัวอย่าง ไม่บังคับ
```

**หรือไล่รัน migrations ทีละไฟล์ตามลำดับ** (ใช้วิธีนี้ถ้าฐานข้อมูลมีข้อมูลอยู่แล้วและต้อง
อัปเกรดจากเวอร์ชันเก่า — ทุกไฟล์เขียนแบบ idempotent รันซ้ำได้ไม่พัง):
```bash
for f in database/migrations/*.sql; do mysql -u root -p refer_system < "$f"; done
# หรือรันเรียงเลขเอง: 001-initial, 002-restock, 003-users-and-roles, 004-vehicle-details,
# 005-stock, 006-vehicle-stock, 007-default-items, 008-departments, 009-restock-action-reconciliation
```

### 4. ตั้งค่า Web Server
- Document Root: `public/`
- เปิดใช้งาน mod_rewrite (Apache) หรือ config rewrite rules (Nginx)

### 5. สร้างผู้ใช้แอดมินคนแรก
ระบบยืนยันตัวตนด้วยตาราง `refer_users` (ไม่ใช่ตัวแปร `PASSWORD_HASH` ใน .env ที่เป็นของ
เวอร์ชันเก่าและไม่มีโค้ดอ่านแล้ว) ฐานข้อมูลใหม่ยังไม่มีผู้ใช้เลย ต้อง insert แถวแรกด้วยมือ
ก่อนจึงจะ login เข้าไปสร้างผู้ใช้อื่นต่อจากหน้าแอดมินได้:
```bash
php -r "echo password_hash(readline('Password: '), PASSWORD_DEFAULT), PHP_EOL;"
```
```sql
INSERT INTO refer_users (id, username, password_hash, full_name, role, active, created_at, updated_at)
VALUES ('usr-admin-001', 'admin', '<ค่า hash จากคำสั่งข้างบน>', 'System Administrator', 'admin', 1,
        NOW(), NOW());
```

## รันด้วย Docker

แยกจาก XAMPP โดยสมบูรณ์ (ฐานข้อมูลอยู่ใน volume ของ Docker เอง) มี 3 containers ที่ทำงานประจำ:

```
Browser ─127.0.0.1:8080─▶ frontend (nginx) ── /assets/* เสิร์ฟเอง
                              │ / และ /api/*  (network "edge")
                              ▼
                          backend (PHP 8.5 + Apache, Document Root = public/)
                              │ (network "data" — internal)
                              ▼
                          db (MariaDB 10.4)
```

- `frontend` — container เดียวที่เปิด port; เสิร์ฟ CSS/JS/รูปจาก `resources/assets` และ proxy
  หน้าเว็บ + API ไป backend (ต้องอยู่ origin เดียวกัน เพราะหน้าเว็บฝัง CSRF token จาก session
  และ cookie เป็น `SameSite=Strict`) — ไฟล์อยู่ที่ `docker/frontend/`
- `backend` — ไม่เปิด port ออก host; รับ IP จริงของผู้ใช้จาก nginx ผ่าน `mod_remoteip`
- `db` — เข้าถึงได้จาก backend (และ phpMyAdmin) เท่านั้น
- `phpmyadmin` — เครื่องมือ ไม่ใช่ส่วนของแอป อยู่ใน profile `tools` เปิดเฉพาะตอนใช้

### 1. ตั้งค่า
```bash
cp .env.docker.example .env
```
แก้ใน `.env` อย่างน้อย:
- `DB_PASSWORD` กับ `MYSQL_PASSWORD` — ต้องเป็นค่าเดียวกัน
- `MYSQL_ROOT_PASSWORD`
- `APP_PORT` / `PHPMYADMIN_PORT` ถ้า 8080/8081 ถูกใช้อยู่ (และแก้ `APP_URL`, `APP_ORIGIN` ให้ตรง port)
- `DB_HOST` ต้องเป็น `db` (ชื่อ service) ไม่ใช่ `localhost`

### 2. เริ่มระบบ
```bash
docker compose up -d --build
docker compose ps        # frontend, backend, db ต้องขึ้น (healthy)
```
- เว็บ: http://localhost:8080
- phpMyAdmin (เปิดเมื่อต้องการ): `docker compose --profile tools up -d phpmyadmin` แล้วเข้า
  http://localhost:8081 (login ด้วย `MYSQL_USER` / `MYSQL_PASSWORD`); ปิดด้วย `docker compose stop phpmyadmin`
- port ผูกกับ `127.0.0.1` เท่านั้น — เข้าได้จากเครื่องนี้เครื่องเดียว; `db` ไม่เปิด port ออกนอก Docker

ครั้งแรกที่ volume ว่าง MariaDB จะรัน `database/schema.sql` แล้วตามด้วย `database/seed.sql`
ให้อัตโนมัติ จากนั้นสร้างผู้ใช้แอดมินคนแรกตามข้อ 5 ด้านบน (ใช้ phpMyAdmin หรือ
`docker compose exec db mariadb -u root -p refer_system`)

### 3. ข้อควรรู้
- **โค้ดถูก copy เข้า image ไม่ได้ mount** — แก้โค้ดแล้วต้อง `docker compose up -d --build` ทุกครั้ง
  (build ทั้ง frontend และ backend พร้อมกัน — `?v=` ของ asset คำนวณจากไฟล์ใน backend)
- **schema/seed รันแค่ตอน volume ว่าง** — แก้ `schema.sql`/`seed.sql` ทีหลังจะไม่มีผลกับฐานข้อมูลเดิม
  ต้องรัน migration เองผ่าน phpMyAdmin หรือ reset ทั้งหมดด้วย `docker compose down -v`
  (**ลบข้อมูลทั้งหมด**)
- healthcheck ของ `backend` ใช้ `docker/healthcheck.php` (รันผ่าน CLI เช็คว่าเว็บตอบ และ login DB ได้);
  `frontend` เช็คที่ `/healthz` ของ nginx
- ดู log: `docker compose logs -f frontend backend`
- หยุดระบบ: `docker compose down` (ข้อมูลยังอยู่ใน volume `db_data`) — **ห้ามใส่ `-v`**
- **บัญชี DB ของแอปมีสิทธิ์แค่ SELECT/INSERT/UPDATE/DELETE** (migration 015) — migration ใหม่ต้องรันด้วย root:
  `docker exec -i emer-db-1 sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < database/migrations/0xx-....sql`
- phpMyAdmin เปิดเฉพาะตอนใช้ แล้ว `docker compose stop phpmyadmin` ทันที
- container ทำงานแบบ hardening (cap_drop ALL, no-new-privileges, read-only filesystem สำหรับ frontend/backend)
  — ถ้าเพิ่มฟีเจอร์ที่ต้องเขียนไฟล์ ให้เขียนใน `storage/` (volume) เท่านั้น
- ไฟล์ backup ฐานข้อมูลเก็บนอกโปรเจกต์ (ห้ามเก็บใน `storage/backups/` หรือใต้ htdocs)

### ความปลอดภัยฝั่งหน้าเว็บ (CSP)
- `script-src 'self'` — **ห้ามใส่ `<script>` inline หรือ `onclick="..."`** (เบราว์เซอร์จะไม่รัน)
- ปุ่มที่เรียกฟังก์ชัน global ให้ใช้ `data-call="ชื่อฟังก์ชัน"` และ `data-arg="ค่า"` (ถ้ามี)
  เช่น `<button data-call="dashboardPage.viewRestock" data-arg="${UI.escape(id)}">` — ตัวกระจาย event อยู่ที่
  `resources/assets/js/core/shell.js` (ฟังก์ชันต้องอยู่บน `window`)

### Session / การเข้าสู่ระบบ
- ทุก request ตรวจสถานะบัญชีจากฐานข้อมูล: ปิดใช้งาน / เปลี่ยนบทบาท / รีเซ็ตหรือเปลี่ยนรหัสผ่าน มีผลทันที
  (เปลี่ยนรหัสผ่าน = session อื่นของบัญชีนั้นหลุดทั้งหมด)
- ไม่มีการใช้งานเกิน `SESSION_IDLE_MINUTES` (ค่าเริ่มต้น 120) ต้องเข้าสู่ระบบใหม่; อายุสูงสุด 8 ชม.
- ลองรหัสผิด: 10 ครั้ง/บัญชี หรือ 30 ครั้ง/IP ใน 15 นาที → ล็อกชั่วคราว (429)
- การเปลี่ยนแปลงบัญชีถูกบันทึกในตาราง `refer_audit_log` (migration 014)

## การพัฒนา

### โครงสร้าง Backend
- **Controllers**: รับ Request, เรียก Service, ส่ง Response
- **Services**: Business Logic, Transaction Management
- **Models**: Database Operations (Active Record pattern)
- **Middleware**: Auth, CSRF, Role-based access

### โครงสร้าง Frontend
- **Core**: API Client, Auth, UI Utilities
- **Components**: Modal, Alert, Table
- **Pages**: Page-specific logic

### เพิ่มหน้าใหม่
1. สร้าง Controller ใน `app/Controllers/`
2. สร้าง View ใน `resources/views/pages/`
3. เพิ่ม Route ใน `routes/web.php`
4. เพิ่ม CSS/JS ตามต้องการ

## ยอดอุปกรณ์บนรถ / ตรวจเช็คตามเวร / เติมของ (migration 010)

ไม่มีคลังสต็อกกลางแล้ว (`/api/stock*` ถูกถอด; ตาราง `refer_stock_items`/`refer_stock_transactions` คงไว้แต่ไม่ถูกใช้)
ทุกการเปลี่ยนยอดผ่าน `App\Services\VehicleStockService` ใน transaction เดียวกับงานนั้นเสมอ

| ข้อมูล | ตาราง | หมายเหตุ |
|---|---|---|
| ประเภทอุปกรณ์ | `refer_items.item_type` | `box` กล่อง/Lot, `piece` รายชิ้น, `electric` เครื่องไฟฟ้า |
| ยอดของสิ้นเปลือง | `refer_item_lots` | แหล่งข้อมูลหลัก; `refer_items.current_qty` = SUM(Lot) (sync อัตโนมัติ) |
| เครื่องไฟฟ้า | `refer_item_units` | สถานะพร้อม/ไม่พร้อม + แบต 1-4 ขีด รายเครื่อง — ไม่ตัดสต๊อค |
| Ledger | `refer_stock_movements` | INIT/USE/REFILL/ADJUST/REVERSAL ทุก Lot ที่ถูกแตะ |
| รอบตรวจ | `refer_checks` | UNIQUE (รถ, `check_date`, `shift_code`) — เช้า 08-16 / บ่าย 16-24 / ดึก 00-08 |
| ประวัติแก้รอบตรวจ | `refer_check_history` | สำเนาก่อนแก้ทุก revision |
| การเติม 1 ครั้ง | `refer_refill_batches` | `client_token` UNIQUE กันกดซ้ำ |

- ตรวจเช็ค: กรอก "ใช้ไป" → ยืนยัน "ครบถ้วน" หรือกรอก "นับได้จริง" + หมายเหตุ (= ผิดปกติ); ตัดยอดแบบ FEFO
- แก้รอบเดิม: ย้อนผลจาก ledger แล้วคำนวณใหม่ → ไม่หักซ้ำ
- เติมของ: ต้องระบุวันหมดอายุทุก Lot, ห้ามของหมดอายุ, ปิดยอดใน "รายการรอเติม" ของรถคันนั้นอัตโนมัติ
- กระทบยอด: `VehicleStockService::reconcile()` (ถูกเรียกก่อน commit ทุกครั้ง — ไม่ตรงจะ rollback)
- ย้อนกลับ migration: `database/migrations/010-rollback.sql` (สำรองฐานข้อมูลก่อนเสมอ)

## API Endpoints

### Authentication
- `POST /api/auth/login` - เข้าสู่ระบบ
- `POST /api/auth/logout` - ออกจากระบบ
- `GET /api/auth/me` - ตรวจสอบ session

### Dashboard
- `GET /api/dashboard` - ข้อมูลสรุป
- `GET /api/dashboard/stats` - สถิติ

### Nurses
- `GET /api/nurses` - รายชื่อพยาบาล
- `POST /api/nurses` - เพิ่มพยาบาล
- `PUT /api/nurses/{id}` - แก้ไขพยาบาล
- `DELETE /api/nurses/{id}` - ปิดใช้งานพยาบาล

### Vehicles
- `GET /api/vehicles` - รายชื่อรถ
- `GET /api/vehicles/{id}` - รายละเอียดรถ + อุปกรณ์
- `PUT /api/items/{id}` - แก้ไขอุปกรณ์
- `POST /api/vehicles/{id}/items` - เพิ่มอุปกรณ์

### Inspections
- `GET /api/inspections` - ประวัติการตรวจ
- `GET /api/inspections/{id}` - รายละเอียดการตรวจ
- `POST /api/inspections/start` - เริ่มตรวจใหม่
- `PUT /api/inspections/draft` - บันทึกร่าง
- `POST /api/inspections/submit` - ส่งผลตรวจ

### Restock (เส้นทางปัจจุบันที่ใช้งานจริง — ดู routes/web.php)
- `GET /api/staff/restock` / `GET /api/staff/restock/my` - รายการคำขอ (ของเจ้าหน้าที่/ที่รับผิดชอบ)
- `GET /api/staff/restock/{id}` - รายละเอียดคำขอ
- `POST /api/staff/restock/{id}/start` - เริ่มดำเนินการ (PENDING → IN_PROGRESS)
- `POST /api/staff/restock/{id}/restock` - บันทึกจ่าย/เติม (ตัดคลังกลางจริง)
- `POST /api/staff/restock/{id}/complete` - ปิดงาน (→ COMPLETED)
- `POST /api/staff/restock/{id}/cancel` - ยกเลิกคำขอ
- `GET /api/admin/restock` / `GET /api/admin/restock/stats` - มุมมองแอดมิน
- `POST /api/admin/restock/{id}/assign` / `.../unassign` - มอบหมาย/ถอนมอบหมายเจ้าหน้าที่
- `GET /api/restock`, `GET /api/restock/{id}`, `POST /api/restock/{id}/cancel` - เส้นทางเก่า (ยังใช้ได้)
- **หมายเหตุ:** `POST /api/restock/draft|submit|issue|fill|fulfil` ที่เคยมีในเอกสารรุ่นก่อนถูกถอดออกจากโค้ดจริงแล้ว (ยิงแล้วได้ 500) — ไม่มี client เรียกใช้อีกต่อไป

### Stock (คลังสต็อกกลาง)
- `GET /api/stock` - ภาพรวมคลัง
- `GET /api/stock/transactions` - ประวัติความเคลื่อนไหว
- `POST /api/stock/{id}/receive` - รับของเข้าคลัง
- `POST /api/stock/{id}/adjust` - ปรับยอด/ขั้นต่ำ

### Departments (ทะเบียนวอร์ด)
- `GET /api/departments` - รายการวอร์ด (ทุก role ที่ login แล้ว)
- `POST /api/departments` / `PUT /api/departments/{id}` / `DELETE /api/departments/{id}` - จัดการวอร์ด (admin เท่านั้น)

### History
- `GET /api/history/inspections` - ประวัติการตรวจ (มี filter)
- `GET /api/history/restock` - ประวัติการเบิก-เติม (มี filter)

## การทดสอบ

> หมายเหตุ (2026-10-19): `tests/test-php.php`, `tests/test-restock.php`,
> `tests/mock-server.mjs` และไฟล์สนับสนุนของมัน (`check-build.mjs`, `inject-assets.mjs`,
> `index.template.html`) ถูกลบไปแล้ว — ไฟล์เหล่านี้ทดสอบ/จำลอง `public/api.php`
> (ระบบ flat-file state รุ่นเก่าที่ถูกแทนที่ด้วย `app/Controllers` + `app/Services` +
> `app/Models` ทั้งหมดแล้ว และตัว `public/api.php` ก็ถูกลบไปด้วย) ยังไม่มีชุดทดสอบอัตโนมัติ
> สำหรับโค้ดสถาปัตยกรรมปัจจุบัน — เป็นงานค้างที่ควรทำก่อนแก้ business logic ส่วน
> stock/restock ต่อไป (ดู Roadmap P2 ในรายงานตรวจสอบ)

## การ Deploy

### Production Checklist
- [ ] `APP_DEBUG=false` ใน .env
- [ ] ใช้ HTTPS เท่านั้น
- [ ] ตั้งค่า `APP_ORIGIN` ให้ถูกต้อง
- [ ] จำกัดสิทธิ์ไฟล์: `chmod 750 storage/ config/`
- [ ] ตั้งค่า PHP OPcache
- [ ] ตั้งค่า Database Connection Pooling
- [ ] Backup ฐานข้อมูลอัตโนมัติ

## Rollback Procedure

หากพบปัญหาหลัง Deploy:

1. **Code Rollback**: `git checkout <previous-tag>`
2. **Database Rollback**: 
   - ไม่ต้อง rollback migration (CREATE TABLE IF NOT EXISTS)
   - หากมีข้อมูลใหม่ ให้ export → import กลับ
3. **Config Rollback**: คืนค่า .env เดิม
4. **Clear Cache**: `rm -rf storage/cache/*` (ถ้ามี)

## License

Proprietary - สำหรับใช้งานภายในองค์กรเท่านั้น