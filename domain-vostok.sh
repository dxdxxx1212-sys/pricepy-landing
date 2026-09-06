#!/usr/bin/env bash
# Подключает домен восток-прицеп.рф к лендингу (отдельный nginx-блок + HTTPS).
# Не трогает конфиг jefwipwero.online. Запуск на сервере:
#   curl -fsSL https://dxdxxx1212-sys.github.io/pricepy-landing/domain-vostok.sh | bash
set -euo pipefail

PUNY="xn----ctbklixakchgm2d.xn--p1ai"   # восток-прицеп.рф
WWW="/var/www/pricepy"
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

echo "==> Папка для логов/базы вне веб-корня (нельзя скачать из браузера)..."
mkdir -p /var/lib/pricepy-crm
chown -R www-data:www-data /var/lib/pricepy-crm
chmod 750 /var/lib/pricepy-crm

echo "==> Конфиг nginx для восток-прицеп.рф..."
cat > /etc/nginx/sites-available/vostok <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${PUNY} www.${PUNY};
    root ${WWW};
    index index.html;

    # не отдавать наружу логи заявок (ПДн), базы и скрипты
    location ~* \.(log|sqlite|sqlite-wal|sqlite-shm|sh|md)\$ { deny all; }
    location ~ /\. { deny all; }

    location / { try_files \$uri \$uri/ =404; }

    location = /api/lead {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME ${WWW}/api/lead.php;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/vostok /etc/nginx/sites-enabled/vostok
nginx -t && systemctl reload nginx

echo "==> HTTPS (Let's Encrypt)..."
if certbot --nginx -d "${PUNY}" -d "www.${PUNY}" --non-interactive --agree-tos -m "admin@jefwipwero.online" --redirect; then
  echo "    ✅ HTTPS включён"
else
  echo "    ⚠️ Сертификат пока не выпущен (обычно DNS ещё не разошёлся)."
  echo "       Сайт уже работает по http. Позже: certbot --nginx -d ${PUNY} -d www.${PUNY} --redirect"
fi

echo "==> Готово! Открой: https://восток-прицеп.рф"
