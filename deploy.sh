#!/usr/bin/env bash
# Разворачивает лендинг на чистом Ubuntu (nginx + PHP приёмник заявок + HTTPS).
# Запуск на сервере:
#   export DOMAIN=jefwipwero.online
#   export BOT_TOKEN='токен_бота'
#   export CHAT_ID='8215511350'
#   curl -fsSL https://dxdxxx1212-sys.github.io/pricepy-landing/deploy.sh | bash
set -euo pipefail

: "${DOMAIN:?Укажи DOMAIN, напр.: export DOMAIN=jefwipwero.online}"
: "${BOT_TOKEN:?Укажи BOT_TOKEN (токен бота от @BotFather)}"
: "${CHAT_ID:?Укажи CHAT_ID (напр.: export CHAT_ID=8215511350)}"

REPO="https://github.com/dxdxxx1212-sys/pricepy-landing.git"
WWW="/var/www/pricepy"

echo "==> [1/6] Установка пакетов..."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y nginx php-fpm php-curl certbot python3-certbot-nginx git curl

echo "==> [2/6] Загрузка сайта..."
rm -rf "$WWW"
git clone --depth 1 "$REPO" "$WWW"

echo "==> [3/6] Настройка приёмника заявок..."
cat > "$WWW/api/config.php" <<PHP
<?php
\$BOT_TOKEN = '${BOT_TOKEN}';
\$CHAT_ID   = '${CHAT_ID}';
PHP
touch "$WWW/leads.log"
chown -R www-data:www-data "$WWW"

PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "    PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

echo "==> [4/6] Конфиг nginx..."
cat > /etc/nginx/sites-available/pricepy <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN} www.${DOMAIN};
    root ${WWW};
    index index.html;

    location / { try_files \$uri \$uri/ =404; }

    location = /api/lead {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME ${WWW}/api/lead.php;
    }

    # не отдавать .git и прочие скрытые файлы
    location ~ /\. { deny all; }
}
NGINX
ln -sf /etc/nginx/sites-available/pricepy /etc/nginx/sites-enabled/pricepy
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx

echo "==> [5/6] HTTPS (Let's Encrypt)..."
if certbot --nginx -d "${DOMAIN}" -d "www.${DOMAIN}" --non-interactive --agree-tos -m "admin@${DOMAIN}" --redirect; then
  echo "    ✅ HTTPS включён"
else
  echo "    ⚠️ Сертификат пока не выпущен — обычно из-за того, что DNS ещё не указывает на сервер."
  echo "       Сайт уже работает по http://${DOMAIN}"
  echo "       Когда DNS заработает, выполни:"
  echo "       certbot --nginx -d ${DOMAIN} -d www.${DOMAIN} --redirect"
fi

echo "==> [6/6] Готово!  Открой: https://${DOMAIN}"
