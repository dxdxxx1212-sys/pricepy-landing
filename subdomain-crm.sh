#!/usr/bin/env bash
# Поднимает CRM-панель на crm.восток-прицеп.рф (root = /var/www/pricepy/crm, PHP, HTTPS).
# База SQLite лежит ВНЕ веб-корня: /var/lib/pricepy-crm/leads.sqlite (не скачать через браузер).
# Перед запуском: в DNS (sprinthost) добавить A-запись  crm → 45.150.39.174
# Запуск на сервере:
#   curl -fsSL https://dxdxxx1212-sys.github.io/pricepy-landing/subdomain-crm.sh | bash
set -euo pipefail

SUB="crm.xn----ctbklixakchgm2d.xn--p1ai"   # crm.восток-прицеп.рф
WWW="/var/www/pricepy/crm"
DBDIR="/var/lib/pricepy-crm"
PHP_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -n1)"
echo "PHP-FPM сокет: ${PHP_SOCK:-НЕ НАЙДЕН}"

echo "==> PHP-расширения для CRM (SQLite + mbstring) — без них база и кириллица не работают..."
if ! php -m 2>/dev/null | grep -qi pdo_sqlite || ! php -m 2>/dev/null | grep -qi mbstring; then
  apt-get update -qq && apt-get install -y php-sqlite3 php-mbstring
  # перезапуск PHP-FPM, чтобы веб-панель подхватила расширения
  for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | grep -o 'php[0-9.]*-fpm' | sort -u); do
    systemctl restart "$svc" || true
  done
fi

echo "==> Папка для базы (вне веб-корня), права www-data..."
mkdir -p "$DBDIR"
chown -R www-data:www-data "$DBDIR"
chmod 750 "$DBDIR"

echo "==> Конфиг nginx для ${SUB}..."
cat > /etc/nginx/sites-available/crm <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SUB};
    root ${WWW};
    index index.php login.php;

    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    # deny-правила ВЫШЕ обработчика php (regex-локейшены матчатся по порядку)
    location ~ /\. { deny all; }
    location ~* \.(sqlite|sqlite-wal|sqlite-shm|log|md|sh|sql|bak)\$ { deny all; }
    location = /lib.php { deny all; }
    location = /mkowner.php { deny all; }

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php\$ {
        try_files \$uri =404;                     # не исполнять несуществующие пути (path-info)
        include fastcgi_params;
        fastcgi_pass unix:${PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/crm /etc/nginx/sites-enabled/crm
nginx -t && systemctl reload nginx

echo "==> HTTPS (Let's Encrypt)..."
if certbot --nginx -d "${SUB}" --non-interactive --agree-tos -m "admin@jefwipwero.online" --redirect; then
  echo "    ✅ HTTPS включён"
else
  echo "    ⚠️ Серт пока не выпущен — проверь A-запись crm→45.150.39.174 и повтори:"
  echo "       certbot --nginx -d ${SUB} --redirect"
fi

echo "==> Ежедневный бэкап базы + чистка антибрутфорса (cron.daily, ротация 30 дней)..."
command -v sqlite3 >/dev/null 2>&1 || { apt-get update -qq && apt-get install -y sqlite3; }
mkdir -p /var/backups/pricepy-crm
chmod 750 /var/backups/pricepy-crm
cat > /etc/cron.daily/pricepy-crm-backup <<'CRON'
#!/bin/sh
# Резервная копия SQLite «на живую» (.backup — консистентно при WAL, в отличие от cp).
DB=/var/lib/pricepy-crm/leads.sqlite
[ -f "$DB" ] || exit 0
OUT="/var/backups/pricepy-crm/leads-$(date +%F).sqlite"
sqlite3 "$DB" ".backup '$OUT'" && gzip -f "$OUT"
# держим 30 дней
find /var/backups/pricepy-crm -name 'leads-*.sqlite.gz' -mtime +30 -delete
# подчищаем старые записи антибрутфорса, чтобы таблица не пухла
sqlite3 "$DB" "DELETE FROM login_fails WHERE ts < strftime('%s','now')-86400;" 2>/dev/null || true
CRON
chmod +x /etc/cron.daily/pricepy-crm-backup
echo "    ✅ Бэкап настроен. ВАЖНО: это копия на том же сервере — позже настрой выгрузку"
echo "       в российское объектное хранилище (Selectel/VK/Yandex), чтобы пережить отказ диска."

echo "==> Готово. Создай владельца из командной строки (пароль вводится СКРЫТО):"
echo "     php ${WWW}/mkowner.php"
echo "   Затем заходи: https://crm.восток-прицеп.рф/"
