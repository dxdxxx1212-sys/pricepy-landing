#!/usr/bin/env bash
# CRM: фото в комментариях больше 1 МБ получали 413 от nginx (client_max_body_size не задан → дефолт 1 МБ),
# а PHP на дефолтах режет файл на 2 МБ. В коде лимит 15 МБ на фото. Плюс второй слой защиты из ревизии.
#   1) php-fpm: лимиты загрузки + display_errors/expose_php выключены явно + строгий режим сессий;
#   2) nginx crm: client_max_body_size 64m; запрет /inc/ и import-*.php (сейчас их держит только проверка в PHP).
# Идемпотентно — повторный запуск ничего не ломает.
# Запуск на сервере (root):
#   curl -fsSL https://raw.githubusercontent.com/dxdxxx1212-sys/pricepy-landing/<SHA>/harden-crm-uploads.sh | bash
set -euo pipefail

echo "==> php-fpm: лимиты загрузки"
changed_php=0
for d in /etc/php/*/fpm/conf.d; do
  [ -d "$d" ] || continue
  f="$d/99-pricepy.ini"
  want='; Восток Прицеп (harden-crm-uploads.sh)
upload_max_filesize = 16M
post_max_size = 64M
max_file_uploads = 10
display_errors = Off
expose_php = Off
session.use_strict_mode = 1'
  if [ -f "$f" ] && [ "$(cat "$f")" = "$want" ]; then echo "   = $f уже настроен"; continue; fi
  printf '%s\n' "$want" > "$f"; echo "   + $f"; changed_php=1
done

echo "==> nginx crm"
f=/etc/nginx/sites-available/crm
[ -f "$f" ] || { echo "Нет $f — CRM на этом сервере не настроена"; exit 1; }
cp -n "$f" "$f.bak-$(date +%F)" || true
changed_ng=0
if ! grep -q 'client_max_body_size' "$f"; then
  sed -i '/^\s*index /a\    client_max_body_size 64m;   # фото в комментариях (в коде лимит 15 МБ на файл)' "$f"
  echo "   + client_max_body_size 64m"; changed_ng=1
else echo "   = client_max_body_size уже задан"; fi
if ! grep -q 'location \^~ /inc/' "$f"; then
  sed -i '/location = \/mkowner.php/a\    location ^~ /inc/ { deny all; }             # модули CRM — только через lib.php\
    location ~ ^/import-.*\\.php$ { deny all; }  # консольные импорты — не из браузера' "$f"
  echo "   + запрет /inc/ и import-*.php"; changed_ng=1
else echo "   = запрет /inc/ уже есть"; fi

nginx -t
[ "$changed_ng" = 1 ] && systemctl reload nginx && echo "   nginx перезагружен"
if [ "$changed_php" = 1 ]; then
  for svc in $(systemctl list-units --type=service --no-legend 2>/dev/null | grep -o 'php[0-9.]*-fpm' | sort -u); do
    systemctl reload "$svc" && echo "   $svc перезагружен"
  done
fi

echo "==> Проверка снаружи"
H=https://crm.xn----ctbklixakchgm2d.xn--p1ai
code3=$(head -c 3000000 /dev/zero | tr '\0' x | curl -s -o /dev/null -w '%{http_code}' --max-time 30 -X POST --data-binary @- "$H/login.php")
printf '   запрос 3 МБ на CRM: %s (было 413, ждём 200)\n' "$code3"
printf '   /inc/db.php:        %s (ждём 403)\n' "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$H/inc/db.php")"
printf '   /import-tg.php:     %s (ждём 403)\n' "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$H/import-tg.php")"
printf '   /login.php:         %s (ждём 200)\n' "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$H/login.php")"
