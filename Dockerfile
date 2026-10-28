# syntax=docker/dockerfile:1
#
# emer — Ambulance Refer Checklist System
# Base image: PHP 8.5 (upgraded from 8.2; whole codebase lints clean on 8.5
# with E_ALL). App code still stays 8.2-compatible for the XAMPP dev machine.
# Pin ถึง patch version (เดิม 8.5-apache ลอยตามรุ่นใหม่เมื่อ build ซ้ำ) — อัปเดตตั้งใจเมื่อมี security release
FROM php:8.5.12-apache

# ---- PHP extensions actually used by app/ (verified by grep, not assumed) ----
# pdo + pdo_mysql : app/Config/Database.php (`new PDO(...)`, mysql DSN)
# mbstring        : app/Services/StockService.php (mb_substr), app/Helpers (mb_strlen/mb_strtolower)
# upgrade: รับแพตช์ความปลอดภัยของ Debian ที่ออกหลัง base image ถูก build (เช่น libbrotli1 CVE-2026-111497)
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends libonig-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql mbstring

# ---- Apache modules required by public/.htaccess (verified by reading the file) ----
# mod_rewrite : SPA/API routing fallback to index.php
# mod_headers : security headers (CSP, X-Frame-Options, ...)
# mod_expires : static asset cache headers
# mod_deflate : gzip compression
# mod_remoteip: real client IP behind the frontend nginx (docker/apache/remoteip.conf)
RUN a2enmod rewrite headers expires deflate remoteip

COPY docker/apache/remoteip.conf /etc/apache2/conf-enabled/remoteip.conf

# ซ่อนเวอร์ชัน Apache/PHP (zz- = โหลดหลัง security.conf เดิมของ Debian จึงทับค่าได้)
COPY docker/apache/security.conf /etc/apache2/conf-enabled/zz-security.conf
COPY docker/php/security.ini /usr/local/etc/php/conf.d/zz-security.ini

# Document Root -> public/ only, so config/, app/, database/, storage/ are
# never inside the web-servable tree at all (stronger than relying on the
# .htaccess deny-rule for those folders, which assumes a different docroot).
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

# Copy application code. .dockerignore keeps .git, .env, local backups, and
# dev-only test/debug files out of the image.
COPY . /var/www/html

# storage/ is the only folder the app is designed to write to (logs/backups,
# currently unused by app code but kept writable for forward-compat).
# Everything else stays owned by www-data with normal read perms.
RUN chown -R www-data:www-data /var/www/html/storage \
    && find /var/www/html/storage -type d -exec chmod 775 {} \;

EXPOSE 80
