#!/usr/bin/env bash
# Production deploy for 1daa.ru/tochka/ (docker-compose v1)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOMAIN="${DOMAIN:-1daa.ru}"
APP_PATH="${APP_PATH:-/tochka}"
COMPOSE="docker-compose -f docker-compose.production.yml"

cd "$ROOT"

if [[ ! -f .env ]]; then
  echo "Нет .env" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1091
source .env
set +a

echo "==> Build & start containers..."
$COMPOSE up -d --build

echo "==> Wait for API..."
for i in $(seq 1 60); do
  if docker exec tochka-api python -c "print('ok')" 2>/dev/null; then break; fi
  sleep 3
done

echo "==> Migrate (skip if schema-production.sql already loaded)..."
if docker exec tochka-db psql -U "$DB_USER" -d "$DB_NAME" -tAc "SELECT COUNT(*) FROM django_migrations" 2>/dev/null | grep -qx '0'; then
  docker exec -i tochka-db psql -U "$DB_USER" -d "$DB_NAME" < infrastructure/database/schema-production.sql
fi
docker exec tochka-api python manage.py migrate --noinput 2>/dev/null || true

FRONTEND_PORT="${TOCHKA_FRONTEND_PORT:-3010}"
API_PORT="${TOCHKA_API_PORT:-8010}"

mkdir -p /etc/nginx/snippets
cat > /etc/nginx/snippets/1daa-tochka.conf <<EOF
location ${APP_PATH}/api/ {
    proxy_pass http://127.0.0.1:${API_PORT}/api/;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
}
location ${APP_PATH}/ {
    proxy_pass http://127.0.0.1:${FRONTEND_PORT}${APP_PATH}/;
    proxy_http_version 1.1;
    proxy_set_header Host \$host;
    proxy_set_header X-Real-IP \$remote_addr;
    proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto \$scheme;
    proxy_set_header Upgrade \$http_upgrade;
    proxy_set_header Connection "upgrade";
}
EOF

NGINX_SITE="/etc/nginx/sites-available/1daa.ru"
if [[ ! -f "$NGINX_SITE" ]]; then
  cat > "$NGINX_SITE" <<'NGINX'
server {
    listen 80;
    listen [::]:80;
    server_name 1daa.ru www.1daa.ru;
    root /var/www/1daa;
    index index.html;
    include snippets/1daa-tochka.conf;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
server {
    listen 127.0.0.1:7443 ssl;
    server_name 1daa.ru www.1daa.ru;
    root /var/www/1daa;
    index index.html;
    ssl_certificate /etc/letsencrypt/live/1daa.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/1daa.ru/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
    include snippets/1daa-tochka.conf;
    location / {
        try_files $uri $uri/ /index.html;
    }
}
NGINX
  ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/1daa.ru
  mkdir -p /var/www/1daa
  echo '<!DOCTYPE html><html><body><h1>1DAA</h1><p><a href="/tochka/">Точка Банк. Интеграция</a></p></body></html>' > /var/www/1daa/index.html
fi

if [[ ! -f /etc/letsencrypt/live/1daa.ru/fullchain.pem ]]; then
  echo "==> SSL certbot for 1daa.ru..."
  certbot certonly --nginx -d 1daa.ru -d www.1daa.ru --non-interactive --agree-tos -m degtyaryov@inbox.ru || true
fi

# nginx.org package ignores sites-enabled unless explicitly included
if ! grep -q 'sites-enabled' /etc/nginx/nginx.conf; then
  sed -i '/include \/etc\/nginx\/conf.d\/\*.conf;/a\    include /etc/nginx/sites-enabled/*;' /etc/nginx/nginx.conf
fi

nginx -t && systemctl enable nginx && systemctl reload nginx

echo "==> Caddy TLS (Chrome post-quantum / bad key share workaround)..."
mkdir -p /opt/caddy
cp "$ROOT/infrastructure/caddy/1daa.ru.Caddyfile" /opt/caddy/Caddyfile
docker rm -f caddy-1daa 2>/dev/null || true
docker run -d --name caddy-1daa --restart unless-stopped --network host \
  -v /etc/letsencrypt:/etc/letsencrypt:ro \
  -v /var/www/1daa:/var/www/1daa:ro \
  -v /opt/caddy/Caddyfile:/etc/caddy/Caddyfile:ro \
  caddy:2-alpine

echo "==> HAProxy SNI route 1daa.ru -> Caddy :7444..."
HAPROXY_CFG=/etc/haproxy/haproxy.cfg
if ! grep -q 'caddy_1daa' "$HAPROXY_CFG"; then
  sed -i '/use_backend reality_tcp/i\    use_backend caddy_1daa if { req.ssl_sni -i 1daa.ru } || { req.ssl_sni -i www.1daa.ru }' "$HAPROXY_CFG"
  sed -i '/^backend reality_tcp/i\
backend caddy_1daa\n    server caddy 127.0.0.1:7444 check\n' "$HAPROXY_CFG"
  haproxy -c -f "$HAPROXY_CFG" && systemctl reload haproxy
fi

echo "=== Done ==="
echo "https://${DOMAIN}${APP_PATH}/"
$COMPOSE ps
