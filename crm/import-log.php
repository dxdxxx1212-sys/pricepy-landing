<?php
// Импорт исторических лидов из leads.log в базу CRM (те, что пришли до запуска CRM).
// ТОЛЬКО из консоли сервера. Идемпотентно: повторный запуск не создаёт дублей.
// Ничего не отправляет в Telegram — только пишет в базу.
// Запуск на сервере:
//   php /var/www/pricepy/crm/import-log.php dry   ← предпросмотр (ничего не пишет)
//   php /var/www/pricepy/crm/import-log.php        ← реальный импорт
//   (можно указать путь к логу: php .../import-log.php /var/lib/pricepy-crm/leads.log)
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Доступно только из консоли сервера.'); }
require __DIR__.'/lib.php';

// Разбор аргументов: флаг dry (предпросмотр) и/или путь к логу.
$dry = false; $paths = [];
foreach (array_slice($argv, 1) as $a) {
  if ($a === 'dry' || $a === '--dry') { $dry = true; }
  else { $paths[] = $a; }
}
if ($dry) echo "=== РЕЖИМ ПРЕДПРОСМОТРА: ничего не записывается ===\n";
$paths[] = '/var/lib/pricepy-crm/leads.log';
$paths[] = __DIR__.'/../leads.log';
$log = '';
foreach ($paths as $p) { if (is_file($p)) { $log = $p; break; } }
if (!$log) { fwrite(STDERR, "leads.log не найден. Искал: ".implode(', ', $paths)."\n"); exit(1); }
echo "Читаю: $log\n";

$db = crm_db();
$check = $db->prepare("SELECT 1 FROM leads WHERE created_at=? AND phone_norm=? LIMIT 1");

$imported = 0; $skipped = 0; $bad = 0; $ln = 0;
$fh = fopen($log, 'r');
while (($line = fgets($fh)) !== false) {
  $line = trim($line);
  if ($line === '') continue;
  $ln++;
  // Формат строки лога: "2026-08-01T12:00:00+03:00 | {json}"
  $pos = strpos($line, ' | ');
  if ($pos === false) { $bad++; continue; }
  $ts   = trim(substr($line, 0, $pos));
  $json = substr($line, $pos + 3);
  $data = json_decode($json, true);
  if (!is_array($data)) { $bad++; continue; }

  // honeypot и пустышки не тащим
  if (!empty($data['hp'])) { $skipped++; continue; }
  $name    = trim((string)($data['name'] ?? ''));
  $contact = trim((string)($data['contact'] ?? ''));
  if ($name === '' && mb_strlen($contact) < 4) { $skipped++; continue; }

  $t  = strtotime($ts);
  $ca = $t ? date('c', $t) : date('c');
  $phone = crm_phone_digits($contact);

  // дедуп: уже есть лид с тем же временем и телефоном → пропускаем
  $check->execute([$ca, $phone]);
  if ($check->fetchColumn()) { $skipped++; continue; }

  if ($dry) {
    echo sprintf("  + %s | %s | %s | %s\n", crm_dt($ca), $name ?: '(без имени)', $contact ?: '(нет контакта)', crm_channel_label($data['channel'] ?? ''));
  } else {
    crm_insert_lead($data, $json, $ca);
  }
  $imported++;
}
fclose($fh);

$word = $dry ? "БУДЕТ импортировано" : "Импортировано";
echo "\nГотово. $word: $imported · пропущено (дубли/пустые): $skipped · нечитаемых строк: $bad · всего строк: $ln\n";
if ($dry && $imported) echo "Если список выше устраивает — запусти без 'dry' для реального импорта.\n";
