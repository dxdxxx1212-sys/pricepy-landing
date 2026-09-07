#!/usr/bin/env bash
# Поднимает v2 (простой лендинг-квиз) на поддомене podbor.восток-прицеп.рф.
# Тот же каталог файлов /var/www/pricepy, но главная = v2.html. HTTPS + рабочие заявки.
# Оптимизация: HTTP/2, WebP по Accept, долгий кэш статики, gzip для текстов.
# Запуск на сервере:
#   curl -fsSL https://dxdxxx1212-sys.github.io/pricepy-landing/subdomain-podbor.sh | bash
set -euo pipefail

SUB="xn--90af3acbk.xn----ctbklixakchgm2d.xn--p1ai"   # подбор.восток-прицеп.рф
WWW="/var/www/pricepy"
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

# --- WebP negotiation (http-контекст, отдельным файлом в conf.d) ---
# gzip НЕ трогаем — он уже включён в основном /etc/nginx/nginx.conf (иначе "duplicate").
echo "==> conf.d: WebP-negotiation..."
cat > /etc/nginx/conf.d/pricepy-webp.conf <<'WEBP'
# Если браузер шлёт Accept: image/webp — пробуем отдать <файл>.webp вместо оригинала.
map $http_accept $webp_suffix { default ""; "~*image/webp" ".webp"; }
WEBP

echo "==> Конфиг nginx для ${SUB} (главная = v2.html)..."
cat > /etc/nginx/sites-available/podbor <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SUB};
    root ${WWW};
    http2 on;

    # не отдавать наружу логи заявок (ПДн), базы и скрипты
    location ~* \.(log|sqlite|sqlite-wal|sqlite-shm|sh|md)\$ { deny all; }
    location ~ /\. { deny all; }

    location = / { try_files /v2.html =404; }

    # Картинки: если есть <файл>.webp и браузер его принимает — отдаём его. Долгий кэш.
    location ~* \.(jpg|jpeg|png)\$ {
        add_header Vary Accept;
        expires 30d;
        add_header Cache-Control "public";
        try_files \${uri}\${webp_suffix} \$uri =404;
    }
    # Прочая статика — долгий кэш.
    location ~* \.(webp|gif|ico|svg|css|js|woff2?)\$ {
        expires 30d;
        add_header Cache-Control "public";
        try_files \$uri =404;
    }

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
