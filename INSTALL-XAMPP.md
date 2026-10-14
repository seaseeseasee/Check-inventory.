# คู่มือติดตั้งระบบตรวจรถรีเฟอร์ (Ambulance Refer Checklist) บน XAMPP

**เวอร์ชัน:** 2.0.0  
**วันที่อัปเดต:** 2025  
**รองรับ:** Windows 10/11, XAMPP (Apache + MariaDB/MySQL), phpMyAdmin

---

## 1. ความต้องการของระบบ (System Requirements)

| ส่วนประกอบ | เวอร์ชันขั้นต่ำ | เวอร์ชันแนะนำ |
|-------------|----------------|---------------|
| **OS** | Windows 10 64-bit | Windows 11 64-bit |
| **XAMPP** | 8.1.x | 8.2.x หรือใหม่กว่า |
| **PHP** | 8.1 | 8.2+ |
| **MariaDB/MySQL** | 10.5 / 8.0 | 10.6+ / 8.0+ |
| **phpMyAdmin** | 5.1+ | 5.2+ |
| **瀏覽器** | Chrome 90+, Firefox 88+, Edge 90+ | เวอร์ชันล่าสุด |

> ⚠️ **หมายเหตุ:** ระบบใช้ PHP 8.1+ features (match expression, readonly properties, enums) หากใช้ PHP 8.0 จะต้องปรับโค้ดบางส่วน

---

## 2. โครงสร้างไดเรกทอรีที่แนะนำ

```
C:\xampp\htdocs\refer-system\
│
├── app\                    # Application Logic (ไม่เข้าถึงผ่านเว็บได้)
│   ├── Controllers\
│   ├── Models\
│   ├── Services\
│   ├── Middleware\
│   └── Helpers\
│
├── config\                 # Configuration Files
│   ├── app.php
│   ├── database.php
│   └── env.php             # .env loader
│
├── database\               # Database Files
│   ├── schema.sql          # โครงสร้างฐานข้อมูล (นำเข้าใน phpMyAdmin)
│   ├── seed.sql            # ข้อมูลตัวอย่าง (นำเข้าใน phpMyAdmin)
│   └── migrations\         # Migration files (อ้างอิง)
│
├── public\                 # Web Document Root (เข้าถึงผ่านเว็บได้)
│   ├── index.php           # Entry Point
│   ├── .htaccess           # Apache Config
│   └── assets\             # CSS, JS, Images
│
├── resources\              # Views & Assets Source
│   ├── views\
│   └── assets\
│
├── routes\                 # Route Definitions
│   └── web.php
│
├── storage\                # Writable Directories
│   ├── logs\
│   └── backups\
│
├── tests\                  # Test Files
│
├── .env.example            # Template Environment Config
├── .env                    # สร้างจาก .env.example (ไม่ commit)
├── INSTALL-XAMPP.md        # ไฟล์นี้
└── README.md
```

---

## 3. ขั้นตอนการติดตั้ง (Step-by-Step)

### 3.1 เตรียม XAMPP

1. **ดาวน์โหลด XAMPP**  
   ไปที่ https://www.apachefriends.org/download.html  
   เลือกเวอร์ชัน PHP 8.2+ สำหรับ Windows 64-bit

2. **ติดตั้ง XAMPP**  
   - รัน installer เป็น Administrator
   - ติดตั้งที่ `C:\xampp` (ค่าเริ่มต้น)
   - เลือก Components: Apache, MySQL, phpMyAdmin, FileZilla (ถ้าต้องการ)

3. **เริ่มต้น Services**  
   เปิด **XAMPP Control Panel** → กด **Start** ตรงหน้า **Apache** และ **MySQL**  
   ต้องแสดงสถานะสีเขียวทั้งสอง

4. **ทดสอบการทำงาน**  
   เปิดเบราว์เซอร์ไปที่ `http://localhost/dashboard/`  
   ควรเห็นหน้า XAMPP Dashboard

---

### 3.2 วางไฟล์โปรเจกต์

1. **สร้างโฟลเดอร์**  
   `C:\xampp\htdocs\refer-system\`

2. **คัดลอกไฟล์ทั้งหมด**  
   วาง Source Code ทั้งหมดไว้ในโฟลเดอร์นี้  
   โครงสร้างต้องเหมือนในข้อ 2

3. **ตรวจสอบไฟล์สำคัญ**  
   ต้องมีไฟล์เหล่านี้:
   ```
   C:\xampp\htdocs\refer-system\.env.example
   C:\xampp\htdocs\refer-system\public\index.php
   C:\xampp\htdocs\refer-system\database\schema.sql
   C:\xampp\htdocs\refer-system\database\seed.sql
   ```

---

### 3.3 สร้างไฟล์ Environment Config

1. **คัดลอกไฟล์ตัวอย่าง**  
   ```cmd
   cd C:\xampp\htdocs\refer-system
   copy .env.example .env
   ```

2. **แก้ไข .env**  
   เปิดด้วย Notepad แก้ค่าต่อไปนี้:
   ```ini
   APP_URL=http://localhost/refer-system/public
   DB_DATABASE=refer_system
   DB_USERNAME=root
   DB_PASSWORD=
   ```
   > **XAMPP Default:** user `root` ไม่มีรหัสผ่าน  
   > หากตั้งรหัสผ่าน MySQL ให้ใส่ที่ `DB_PASSWORD`

3. **สร้าง PASSWORD_HASH**  
   รันคำสั่งนี้ใน Command Prompt:
   ```cmd
   php -r "echo password_hash('your-admin-password', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   คัดลอกผลลัพธ์ (ขึ้นต้นด้วย `$2y$...`) ใส่ใน `.env`:
   ```ini
   PASSWORD_HASH=$2y$10$xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   ```

---

### 3.4 สร้างฐานข้อมูลผ่าน phpMyAdmin

1. **เปิด phpMyAdmin**  
   เข้าไปที่ `http://localhost/phpmyadmin/`

2. **สร้างฐานข้อมูลใหม่**  
   - กดแท็บ **Databases** → **Create database**
   - ชื่อ: `refer_system`
   - Collation: `utf8mb4_unicode_ci` (หรือ `utf8mb4_general_ci`)
   - กด **Create**

3. **นำเข้า Schema**  
   - เลือกฐานข้อมูล `refer_system` ที่เพิ่งสร้าง
   - กดแท็บ **Import**
   - Choose File: `C:\xampp\htdocs\refer-system\database\schema.sql`
   - กด **Go** (รอจนเสร็จ)

4. **นำเข้า Seed Data (ถ้าต้องการข้อมูลทดสอบ)**  
   - ยังคงอยู่ในฐานข้อมูล `refer_system`
   - กด **Import** อีกครั้ง
   - Choose File: `C:\xampp\htdocs\refer-system\database\seed.sql`
   - กด **Go**

5. **ตรวจสอบตาราง**  
   ควรเห็นตารางประมาณ 14 ตาราง:
   - `refer_users`
   - `refer_nurses`
   - `refer_vehicles`
   - `refer_items`
   - `refer_checks`
   - `refer_check_items`
   - `refer_drafts`
   - `refer_draft_items`
   - `refer_restock_requests`
   - `refer_restock_items`
   - `refer_restock_actions`
   - `refer_metadata`
   - `refer_login_attempts`
   - `refer_revision`

---

### 3.5 ตั้งค่า Apache (สำคัญ!)

#### วิธีที่ 1: ใช้ Alias (แนะนำ - ง่ายที่สุด)

1. **แก้ไข httpd.conf**  
   เปิด `C:\xampp\apache\conf\httpd.conf`  
   หาบรรทัด `Include conf/extra/httpd-vhosts.conf` ให้ Uncomment (ลบ `#` นำหน้า)

2. **แก้ไข httpd-vhosts.conf**  
   เปิด `C:\xampp\apache\conf\extra\httpd-vhosts.conf`  
   เพิ่มในท้ายไฟล์:

   ```apache
   <VirtualHost *:80>
       DocumentRoot "C:/xampp/htdocs/refer-system/public"
       ServerName refer-system.local
       
       <Directory "C:/xampp/htdocs/refer-system/public">
           Options Indexes FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
       
       # Deny access to sensitive directories
       <Directory "C:/xampp/htdocs/refer-system/app">
           Require all denied
       </Directory>
       <Directory "C:/xampp/htdocs/refer-system/config">
           Require all denied
       </Directory>
       <Directory "C:/xampp/htdocs/refer-system/database">
           Require all denied
       </Directory>
       <Directory "C:/xampp/htdocs/refer-system/storage">
           Require all denied
       </Directory>
   </VirtualHost>
   ```

3. **แก้ไข hosts file**  
   เปิด `C:\Windows\System32\drivers\etc\hosts` (Run Notepad as Administrator)  
   เพิ่มบรรทัด:
   ```
   127.0.0.1   refer-system.local
   ```

4. **Restart Apache**  
   ใน XAMPP Control Panel: Stop → Start Apache

5. **ทดสอบ**  
   เปิดเบราว์เซอร์ไปที่ `http://refer-system.local/`

#### วิธีที่ 2: ใช้ Alias Directive (ไม่ต้องแก้ hosts)

ใน `httpd.conf` หรือ `httpd-vhosts.conf` เพิ่ม:

```apache
Alias /refer-system "C:/xampp/htdocs/refer-system/public"

<Directory "C:/xampp/htdocs/refer-system/public">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
    
    # SPA Fallback
    FallbackResource /refer-system/index.php
</Directory>

# Deny access to sensitive directories
<Directory "C:/xampp/htdocs/refer-system/app">
    Require all denied
</Directory>
<Directory "C:/xampp/htdocs/refer-system/config">
    Require all denied
</Directory>
<Directory "C:/xampp/htdocs/refer-system/database">
    Require all denied
</Directory>
<Directory "C:/xampp/htdocs/refer-system/storage">
    Require all denied
</Directory>
```

Restart Apache แล้วเข้า `http://localhost/refer-system/`

#### วิธีที่ 3: ย้าย public ไปเป็น Document Root (ง่ายแต่อาจกระทบโปรเจกต์อื่น)

แก้ `httpd.conf`:
```apache
DocumentRoot "C:/xampp/htdocs/refer-system/public"
<Directory "C:/xampp/htdocs/refer-system/public">
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```
Restart Apache → เข้า `http://localhost/`

> ⚠️ **แนะนำวิธีที่ 1 หรือ 2** เพื่อไม่ให้กระทบโปรเจกต์อื่นใน htdocs

---

### 3.6 สร้างบัญชี Administrator แรก

หลังจากติดตั้งฐานข้อมูลแล้ว ให้สร้างบัญชี Admin ผ่านเว็บ:

1. เข้า `http://refer-system.local/setup-admin.php` (หรือ `http://localhost/refer-system/setup-admin.php`)
2. กรอกข้อมูล:
   - **Username:** `admin` (หรือที่ต้องการ)
   - **Password:** รหัสผ่านที่แข็งแกร่ง (อย่างน้อย 8 ตัวอักษร)
   - **Full Name:** ชื่อผู้ดูแลระบบ
   - **Role:** `admin`
3. กด **Create Admin**
3. **ลบไฟล์ setup-admin.php** ทันทีหลังใช้งานเสร็จ

> ⚠️ **สำคัญ:** ต้องลบ `setup-admin.php` หลังใช้งาน เพื่อความปลอดภัย

---

## 4. URL สำคัญ

| หน้า | URL (Virtual Host) | URL (Alias) |
|------|-------------------|-------------|
| **Login** | `http://refer-system.local/` | `http://localhost/refer-system/` |
| **Admin Dashboard** | `http://refer-system.local/` (หลัง login admin) | `http://localhost/refer-system/` |
| **Staff Dashboard** | `http://refer-system.local/` (หลัง login staff) | `http://localhost/refer-system/` |
| **phpMyAdmin** | `http://localhost/phpmyadmin/` | `http://localhost/phpmyadmin/` |
| **Setup Admin** | `http://refer-system.local/setup-admin.php` | `http://localhost/refer-system/setup-admin.php` |

---

## 5. Checklist การทดสอบหลังติดตั้ง

- [ ] Apache รันได้ (สีเขียวใน XAMPP Control Panel)
- [ ] MySQL/MariaDB รันได้ (สีเขียวใน XAMPP Control Panel)
- [ ] phpMyAdmin เข้าได้ที่ `http://localhost/phpmyadmin/`
- [ ] ฐานข้อมูล `refer_system` มี 14 ตาราง
- [ ] เปิดหน้า Login ได้ (`http://refer-system.local/`)
- [ ] Login ด้วย Admin account สำเร็จ
- [ ] Admin เห็นเมนู: Dashboard, จัดการพยาบาล, จัดการรถ/อุปกรณ์, จัดการผู้ใช้, ประวัติ
- [ ] Staff เห็นเมนู: Dashboard, ตรวจรถ, เติมอุปกรณ์, ประวัติ
- [ ] Admin เพิ่ม/แก้ไข/ปิดใช้งาน พยาบาลได้
- [ ] Staff ตรวจรถและบันทึกผลได้
- [ ] ระบบสร้างรายการเติมอุปกรณ์อัตโนมัติเมื่อตรวจพบของขาด
- [ ] Staff บันทึกจำนวนที่เติมจริงได้ (ไม่ต้องผ่านอนุมัติ)
- [ ] CSS, JS, รูปภาพ โหลดได้ครบ (ไม่มี 404 ใน Developer Tools)
- [ ] ไม่มี Error 500 ใน Apache Error Log (`C:\xampp\apache\logs\error.log`)

---

## 6. การแก้ไขปัญหา (Troubleshooting)

### Apache ไม่ Start
- ตรวจสอบ Port 80 ถูกใช้งาน: `netstat -ano | findstr :80`
- ปิด Skype / IIS / โปรแกรมอื่นที่ใช้ Port 80
- หรือแก้ Listen port ใน `httpd.conf`: `Listen 8080`

### MySQL ไม่ Start
- ตรวจสอบ Port 3306: `netstat -ano | findstr :3306`
- ลบ `ib_logfile*` ใน `C:\xampp\mysql\data\` แล้ว restart

### หน้าเว็บแสดง Error 500
1. ตรวจสอบ `.env` มีค่าครบถ้วน
2. ตรวจสอบ `storage/logs/` มีสิทธิ์เขียน
3. ดู Apache Error Log: `C:\xampp\apache\logs\error.log`
4. เปิด `APP_DEBUG=true` ใน `.env` ชั่วคราวเพื่อดูรายละเอียด

### CSS/JS ไม่โหลด (404)
- ตรวจสอบ `APP_URL` ใน `.env` ตรงกับ URL ที่ใช้เข้าจริง
- ตรวจสอบ `RewriteBase` ใน `public/.htaccess`
- Hard Refresh: `Ctrl+F5`

### ไม่สามารถ Login ได้
- ตรวจสอบ `PASSWORD_HASH` ใน `.env` ตรงกับรหัสผ่านที่ตั้ง
- รัน `setup-admin.php` ใหม่เพื่อสร้าง admin ใหม่
- ตรวจสอบตาราง `refer_users` ใน phpMyAdmin

### ไม่สามารถเชื่อมต่อฐานข้อมูล
- ตรวจสอบ `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` ใน `.env`
- ทดสอบเชื่อมต่อใน phpMyAdmin
- ตรวจสอบ firewall/antivirus ไม่บล็อก Port 3306

---

## 7. สิทธิ์ไฟล์และโฟลเดอร์ (Windows)

ใน Windows ไม่ต้อง `chmod` แต่ต้องให้สิทธิ์เขียน:
- `C:\xampp\htdocs\refer-system\storage\logs\` → **Write**
- `C:\xampp\htdocs\refer-system\storage\backups\` → **Write**

คลิกขวาโฟลเดอร์ → Properties → Security → Edit → Users → **Write** ✓

---

## 8. การ Backup และ Restore

### Backup ฐานข้อมูล
```cmd
cd C:\xampp\mysql\bin
mysqldump -u root refer_system > C:\backups\refer_system_%date:~-4,4%%date:~-7,2%%date:~-10,2%.sql
```

### Restore ฐานข้อมูล
```cmd
cd C:\xampp\mysql\bin
mysql -u root refer_system < C:\backups\refer_system_20250115.sql
```

### Backup ไฟล์โปรเจกต์
คัดลอกโฟลเดอร์ `C:\xampp\htdocs\refer-system\` ไปเก็บที่ปลอดภัย

---

## 9. ข้อจำกัดและคำเตือน

| ข้อจำกัด | รายละเอียด |
|-----------|------------|
| **Shared Password** | ระบบรองรับ shared password ผ่าน `PASSWORD_HASH` ใน `.env` สำหรับ backward compatibility |
| **Nurse ≠ User** | พยาบาล (`refer_nurses`) และผู้ใช้ระบบ (`refer_users`) แยกกัน ผูกผ่าน `nurse_id` |
| **No Stock Management** | ไม่มีระบบคลัง/ยอดคงเหลือ (ตาม requirement) |
| **Single-page App** | ใช้ vanilla JS ไม่มี framework |
| **HTTPS** | บน XAMPP ใช้ HTTP สำหรับพัฒนา Production ต้องใช้ HTTPS |
| **Timezone** | ตั้ง `date.timezone = Asia/Bangkok` ใน `php.ini` |

---

## 10. ไฟล์ที่สร้าง/แก้ไขสำหรับ XAMPP

### ไฟล์ใหม่ (New Files)
- `config/env.php` - .env loader แบบไม่ต้อง Composer
- `database/schema.sql` - โครงสร้างฐานข้อมูลสำหรับ phpMyAdmin
- `database/seed.sql` - ข้อมูลตัวอย่างสำหรับ phpMyAdmin
- `public/setup-admin.php` - สคริปต์สร้าง Admin แรก
- `INSTALL-XAMPP.md` - คู่มือนี้
- `vendor/autoload.php` - Simple autoloader (ไม่ต้อง Composer)

### ไฟล์ที่แก้ไข (Modified Files)
- `public/index.php` - รองรับ XAMPP paths และ fallback routing
- `public/.htaccess` - Apache config สำหรับ XAMPP
- `config/app.php` - ใช้ค่าจาก .env
- `config/database.php` - ใช้ค่าจาก .env
- `config/bootstrap.php` - โหลด .env อัตโนมัติ
- `app/Config/App.php` - อ่าน config จาก environment
- `app/Config/Database.php` - เชื่อมต่อ DB จาก environment

---

## 11. การอัปเกรดจาก v1 (ถ้ามี)

หากมีระบบ v1 อยู่แล้ว:
1. Backup ฐานข้อมูลเก่า
2. รัน migration ใหม่: `database/migrations/003-users-and-roles.sql`
3. สร้าง admin user ใหม่ผ่าน `setup-admin.php`
4. ทดสอบฟังก์ชันทั้งหมด

---

## 12. การติดต่อและสนับสนุน

หากพบปัญหาในการติดตั้ง:
1. ตรวจสอบ Apache Error Log: `C:\xampp\apache\logs\error.log`
2. ตรวจสอบ PHP Error Log: `C:\xampp\php\logs\php_error_log`
3. ดู XAMPP Forum: https://community.apachefriends.org/
4. ตรวจสอบ PHP version: `php -v` ใน Command Prompt

---

**สรุป:** วางโปรเจกต์ที่ `C:\xampp\htdocs\refer-system\`, ตั้งค่า `.env`, นำเข้า SQL 2 ไฟล์ใน phpMyAdmin, ตั้งค่า Apache Virtual Host, สร้าง Admin ผ่าน `setup-admin.php`, ลบ `setup-admin.php`, เริ่มใช้งานได้เลย 🎉