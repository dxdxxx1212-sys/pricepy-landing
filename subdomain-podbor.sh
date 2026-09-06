#!/usr/bin/env bash
# Поднимает v2 (простой лендинг-квиз) на поддомене podbor.восток-прицеп.рф.
# Тот же каталог файлов /var/www/pricepy, но главная = v2.html. HTTPS + рабочие заявки.
# Запуск на сервере:
#   curl -fsSL https://dxdxxx1212-sys.github.io/pricepy-landing/subdomain-podbor.sh | bash
set -euo pipefail

SUB="xn--90af3acbk.xn----ctbklixakchgm2d.xn--p1ai"   # подбор.восток-прицеп.рф
WWW="/var/www/pricepy"
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

echo "==> Конфиг nginx для ${SUB} (главная = v2.html)..."
cat > /etc/nginx/sites-available/podbor <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SUB};
    root ${WWW};

    # не отдавать наружу логи заявок (ПДн), базы и скрипты
    location ~* \.(log|sqlite|sqlite-wal|sqlite-shm|sh|md)\$ { deny all; }
    location ~ /\. { deny all; }

    location = / { try_files /v2.html =404; }
    location / { try_files \$uri \$uri/ =404; }

    location = /api/lead {
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME ${WWW}/api/lead.php;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/podbor /etc/nginx/sites-enabled/podbor
nginx -t && systemctl reload nginx

echo "==> HTTPS (Let's Encrypt)..."
if certbot --nginx -d "${SUB}" --non-interactive --agree-tos -m "admin@jefwipwero.online" --redirect; then
  echo "    ✅ HTTPS включён"
else
  echo "    ⚠️ Серт пока не выпущен — повтори: certbot --nginx -d ${SUB} --redirect"
  echo "       (иногда с 2-3 попытки из-за капризной сети РФ↔Let's Encrypt)"
fi

echo "==> Готово! Открой: https://подбор.восток-прицеп.рф"
