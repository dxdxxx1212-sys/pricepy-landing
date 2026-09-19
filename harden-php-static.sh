#!/usr/bin/env bash
# СРОЧНОЕ ЗАКРЫТИЕ ДЫРЫ: на доменах лендинга nginx отдаёт *.php как обычные файлы (исходники и
# api/config.php с секретом читаются браузером). PHP там исполняется только для /api/lead
# (exact-location — он приоритетнее regex и продолжит работать).
# Добавляет во все сайты лендинга правило «любой .php → 403» и перезагружает nginx.
# Запуск на сервере (root):
#   curl -fsSL https://raw.githubusercontent.com/dxdxxx1212-sys/pricepy-landing/<SHA>/harden-php-static.sh | bash
set -euo pipefail

RULE='    location ~* \\.(php|phtml|phar|inc)$ { deny all; }   # исходники и config.php — не отдавать как файлы'
changed=0
for site in pricepy vostok podbor pricep; do
  f="/etc/nginx/sites-available/$site"
  [ -f "$f" ] || { echo "— $site: файла нет, пропускаю"; continue; }
  if grep -q 'location ~\* \\\.(php' "$f"; then echo "= $site: правило уже есть"; continue; fi
  # вставляем сразу после запрета скрытых файлов (он есть во всех конфигах), иначе — после root
  if grep -q 'location ~ /\\\. { deny all; }' "$f"; then
    sed -i "/location ~ \/\\\\\. { deny all; }/a\\
$RULE" "$f"
  else
    sed -i "/^\s*root /a\\
$RULE" "$f"
  fi
  echo "+ $site: правило добавлено"; changed=1
done

nginx -t
if [ "$changed" = 1 ]; then systemctl reload nginx; echo "nginx перезагружен"; fi

echo "==> Проверка снаружи (ожидаю 403 на config.php и 200/405 на /api/lead):"
for h in xn----ctbklixakchgm2d.xn--p1ai xn--90af3acbk.xn----ctbklixakchgm2d.xn--p1ai; do
  printf '   %-55s config.php: %s   /api/lead: %s\n' "$h" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "https://$h/api/config.php")" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "https://$h/api/lead")"
done
cat <<'TXT'

ВАЖНО: файл api/config.php был доступен публично — считай LEAD_SECRET скомпрометированным.
Смени его: 1) новый секрет в Cloudflare → Workers → throbbing-union-7326pricepy-leads → Settings → Variables (LEAD_SECRET);
           2) тот же секрет в /var/www/pricepy/api/config.php ($LEAD_SECRET = '...';).
Если в config.php ещё лежит $BOT_TOKEN (старый формат) — отзови токен у @BotFather и убери строку из файла.
TXT
