<?php
// Импорт исторических лидов из leads.log(ов) в базу CRM (заявки до запуска CRM).
// ТОЛЬКО из консоли сервера. Идемпотентно: дедуп по дате+телефону, повтор не плодит дубли.
// Ничего не отправляет в Telegram — только пишет в базу.
// Запуск на сервере:
//   php /var/www/pricepy/crm/import-log.php dry   ← предпросмотр (ничего не пишет)
//   php /var/www/pricepy/crm/import-log.php        ← реальный импорт
//   php /var/www/pricepy/crm/import-log.php dry /путь/к/leads.log  ← конкретный файл
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Доступно только из консоли сервера.'); }
require __DIR__.'/lib.php';

// Аргументы: флаг dry (предпросмотр) и/или пути к логам.
$dry = false; $argPaths = [];
foreach (array_slice($argv, 1) as $a) {
  if ($a === 'dry' || $a === '--dry') $dry = true;
  else $argPaths[] = $a;
}

// Если пути не заданы — читаем ОБА стандартных места: новое (после CRM) и старое (до CRM).
$candidates = $argPaths ?: ['/var/lib/pricepy-crm/leads.log', __DIR__.'/../leads.log'];
$files = [];
foreach ($candidates as $p) { if (is_file($p) && !in_array(realpath($p), array_map('realpath',$files), true)) $files[] = $p; }
if (!$files) { fwrite(STDERR, "leads.log не найден. Искал: ".implode(', ', $candidates)."\n"); exit(1); }

// Похоже ли contact на настоящий контакт (иначе — тест/мусор, не тащим).
function crm_valid_contact($contact){
  $d = preg_replace('/\D+/', '', (string)$contact);
  if (preg_match('/^[78]9\d{9}$/', $d)) return true;             // 8/7 9XX XXXXXXX (11 цифр)
  if (preg_match('/^9\d{9}$/', $d)) return true;                 // 9XX XXXXXXX без кода (10 цифр)
  if (preg_match('/@[A-Za-z0-9_]{4,}/u', (string)$contact)) return true; // телеграм-ник @name
  return false;
}

if ($dry) echo "=== РЕЖИМ ПРЕДПРОСМОТРА: ничего не записывается ===\n";
$db = crm_db();
$check = $db->prepare("SELECT 1 FROM leads WHERE created_at=? AND phone_norm=? LIMIT 1");

$imported=0; $dupes=0; $empty=0; $junk=0; $bad=0; $ln=0; $seen=[]; $junkList=[];
foreach ($files as $log) {
  echo "Читаю: $log\n";
  $fh = fopen($log, 'r');
  while (($line = fgets($fh)) !== false) {
    $line = trim($line); if ($line === '') continue; $ln++;
    $pos = strpos($line, ' | '); if ($pos === false) { $bad++; continue; }
    $ts = trim(substr($line, 0, $pos)); $json = substr($line, $pos + 3);
    $data = json_decode($json, true); if (!is_array($data)) { $bad++; continue; }

    if (!empty($data['hp'])) { $empty++; continue; }                 // honeypot
    $name = trim((string)($data['name'] ?? ''));
    $contact = trim((string)($data['contact'] ?? ''));
    if ($name === '' && mb_strlen($contact) < 4) { $empty++; continue; } // пусто
    if (!crm_valid_contact($contact)) { $junk++; $junkList[] = ($name?:'(без имени)')." | ".$contact; continue; } // тест/мусор

    $t = strtotime($ts); $ca = $t ? date('c', $t) : date('c');
    $phone = crm_phone_digits($contact);
    $key = $ca.'|'.$phone;
    if (isset($seen[$key])) { $dupes++; continue; }                  // дубль внутри логов
    $seen[$key] = 1;
    $check->execute([$ca, $phone]);
    if ($check->fetchColumn()) { $dupes++; continue; }               // уже есть в базе

    if ($dry) echo sprintf("  + %s | %s | %s | %s\n", crm_dt($ca), $name ?: '(без имени)', $contact, crm_channel_label($data['channel'] ?? ''));
    else crm_insert_lead($data, $json, $ca);
    $imported++;
  }
  fclose($fh);
}

if ($junkList) { echo "\nПропущено как тест/мусор (кривой телефон):\n"; foreach ($junkList as $j) echo "  – $j\n"; }
$word = $dry ? "БУДЕТ импортировано" : "Импортировано";
echo "\nГотово. $word: $imported · дубли: $dupes · пустые/боты: $empty · тест-мусор: $junk · нечитаемых строк: $bad · всего строк: $ln\n";
if ($dry && $imported) echo "Если список выше устраивает — запусти ту же команду без 'dry'.\n";
