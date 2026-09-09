#!/usr/bin/env bash
# Поднимает v3 (полный лендинг) на поддомене прицеп.восток-прицеп.рф.
# Тот же каталог файлов /var/www/pricepy, но главная = v3.html. HTTPS + рабочие заявки.
# Оптимизация: HTTP/2, WebP по Accept, долгий кэш статики.
# Запуск на сервере (raw по SHA — чтобы не поймать кэш github.io):
#   curl -fsSL https://raw.githubusercontent.com/dxdxxx1212-sys/pricepy-landing/main/subdomain-pricep.sh | bash
set -euo pipefail

SUB="xn--e1afucc1b.xn----ctbklixakchgm2d.xn--p1ai"   # прицеп.восток-прицеп.рф
WWW="/var/www/pricepy"
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

# --- WebP negotiation (http-контекст, отдельным файлом в conf.d) ---
# Идемпотентно: если файл уже создан podbor-скриптом — просто перезапишется тем же.
echo "==> conf.d: WebP-negotiation..."
cat > /etc/nginx/conf.d/pricepy-webp.conf <<'WEBP'
# Если браузер шлёт Accept: image/webp — пробуем отдать <файл>.webp вместо оригинала.
map $http_accept $webp_suffix { default ""; "~*image/webp" ".webp"; }
WEBP

echo "==> Конфиг nginx для ${SUB} (главная = v3.html)..."
cat > /etc/nginx/sites-available/pricep <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SUB};
    root ${WWW};

    # не отдавать наружу логи заявок (ПДн), базы и скрипты
    location ~* \.(log|sqlite|sqlite-wal|sqlite-shm|sh|md)\$ { deny all; }
    location ~ /\. { deny all; }

    location = / { try_files /v3.html =404; }

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
ln -sf /etc/nginx/sites-available/pricep /etc/nginx/sites-enabled/pricep
nginx -t && systemctl reload nginx

echo "==> HTTPS (Let's Encrypt)..."
if certbot --nginx -d "${SUB}" --non-interactive --agree-tos -m "admin@jefwipwero.online" --redirect; then
  echo "    ✅ HTTPS включён"
  # HTTP/2 для nginx 1.24: параметр в строке listen 443 (директивы http2 on; тут ещё нет).
  sed -i 's/listen 443 ssl;/listen 443 ssl http2;/g; s/listen \[::\]:443 ssl;/listen [::]:443 ssl http2;/g' /etc/nginx/sites-available/pricep
  if nginx -t 2>/dev/null; then systemctl reload nginx; echo "    ✅ HTTP/2 включён"; else echo "    ⚠️ HTTP/2 не применился — проверь: nginx -t"; fi
else
  echo "    ⚠️ Серт пока не выпущен — повтори: certbot --nginx -d ${SUB} --redirect"
  echo "       (частая причина — DNS ещё не прописан/не разошёлся; проверь A-запись прицеп → 45.150.39.174)"
fi

echo "==> Готово! Открой: https://прицеп.восток-прицеп.рф"
