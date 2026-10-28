# syntax=docker/dockerfile:1
#
# emer — frontend container: nginx serving the UI's static assets and
# reverse-proxying the app shell + API to the backend (see nginx.conf).
# Build context is the project root (compose.yaml) so resources/assets is
# reachable. Rebuild together with the backend when assets change — the
# backend's asset() helper computes the ?v= cache-bust from its own copy.
# nginx stable (1.30) — เดิม 1.27.5 บน Alpine 3.21 มี CVE ระดับ HIGH/CRITICAL ที่มีแพตช์แล้ว 60 รายการ
FROM nginx:1.30.6-alpine

# รับแพตช์ความปลอดภัยของ Alpine ที่ออกหลัง base image ถูก build (เช่น brotli / curl)
RUN apk upgrade --no-cache

COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf

# Served at /assets/* — mirrors public/index.php's resources/assets fallback
COPY resources/assets /usr/share/nginx/html/assets

EXPOSE 80
