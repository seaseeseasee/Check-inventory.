<?php
/**
 * ทางเข้าสำหรับเซิร์ฟเวอร์ที่ชี้ URL มาที่โฟลเดอร์โปรเจกต์ (ไม่ใช่ public/) และไม่มี URL rewrite
 * ตั้ง APP_PATH_INFO=true ใน .env แล้วทุก URL จะอยู่ในรูป /<path>/index.php/api/... และ /<path>/index.php/assets/...
 * Docker / Virtual Host ที่ชี้ไปที่ public/ ไม่ใช้ไฟล์นี้
 */
require __DIR__ . '/public/index.php';
