# syntax=docker/dockerfile:1
#
# emer — frontend container: nginx serving the UI's static assets and
# reverse-proxying the app shell + API to the backend (see nginx.conf).
# Build context is the project root (compose.yaml) so resources/assets is
# reachable. Rebuild together with the backend when assets change — the
# backend's asset() helper computes the ?v= cache-bust from its own copy.
FROM nginx:1.27.5-alpine

COPY docker/frontend/nginx.conf /etc/nginx/conf.d/default.conf

# Served at /assets/* — mirrors public/index.php's resources/assets fallback
COPY resources/assets /usr/share/nginx/html/assets

EXPOSE 80
