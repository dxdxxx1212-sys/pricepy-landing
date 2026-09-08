<?php
// Импорт лидов из выгрузки Telegram (полный список за всё время) в базу CRM.
// ТОЛЬКО из консоли сервера. Дедуп по телефону/нику — дублей не создаёт.
// Тесты и мусор отсеиваются. В Telegram ничего не отправляет.
// Загрузка файла на сервер (с Mac):  scp ~/Desktop/message.txt root@СЕРВЕР:/root/message.txt
// Запуск:
//   php /var/www/pricepy/crm/import-tg.php dry /root/message.txt   ← предпросмотр
//   php /var/www/pricepy/crm/import-tg.php /root/message.txt        ← импорт
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Доступно только из консоли сервера.'); }
require __DIR__.'/lib.php';

$dry = false; $path = '';
foreach (array_slice($argv, 1) as $a) {
  if ($a === 'dry' || $a === '--dry') $dry = true;
  elseif ($path === '') $path = $a;
}
if ($path === '') $path = '/root/message.txt';
if (!is_file($path)) { fwrite(STDERR, "Файл не найден: $path\nСначала загрузите выгрузку: scp message.txt root@СЕРВЕР:/root/message.txt\n"); exit(1); }

function tg_valid_contact($c){
  $d = preg_replace('/\D+/', '', (string)$c);
  if (preg_match('/^[78]9\d{9}$/', $d)) return true;                 // 11 цифр 7/8 9XX...
  if (preg_match('/^9\d{9}$/', $d)) return true;                     // 10 цифр 9XX...
  if (preg_match('/@[A-Za-z0-9_]{4,}/u', (string)$c)) return true;   // @ник
  return false;
}
function tg_is_test($name){
  return (bool)preg_match('/тест|test|проверк|claude|финал|домен|лендинг|подбор-тест/iu', (string)$name);
}

// Разбор выгрузки на блоки-заявки. Блок = строка "[дата время] Имя: 🆕 Новая заявка" + пары key: value.
$lines = file($path);
$blocks = []; $cur = null;
foreach ($lines as $raw) {
  $line = rtrim($raw, "\r\n");
  if (preg_match('/^\[(\d{2}\.\d{2}\.\d{2})\s+(\d{2}:\d{2})\]/u', $line, $m)) {
    if ($cur) { $blocks[] = $cur; $cur = null; }
    if (mb_strpos($line, 'Новая заявка') !== false) $cur = ['ts' => $m[1].' '.$m[2], 'd' => []];
    continue;
  }
  if ($cur !== null && preg_match('/^([a-zA-Z_]+):\s?(.*)$/u', $line, $mm)) {
    $cur['d'][strtolower(trim($mm[1]))] = trim($mm[2]);
  }
}
if ($cur) $blocks[] = $cur;

if ($dry) echo "=== РЕЖИМ ПРЕДПРОСМОТРА: ничего не записывается ===\n";
echo "Найдено блоков-заявок в файле: ".count($blocks)."\n";

$db = crm_db();
$byPhone   = $db->prepare("SELECT 1 FROM leads WHERE phone_norm=? LIMIT 1");
$byContact = $db->prepare("SELECT 1 FROM leads WHERE contact=? COLLATE NOCASE LIMIT 1");
$chMap = ['whatsapp'=>'whatsapp','telegram'=>'telegram','max'=>'max','макс'=>'max','телефон'=>'phone','phone'=>'phone'];

$imported=0; $dupes=0; $junk=0; $tests=0; $seen=[];
foreach ($blocks as $b) {
  $d = $b['d'];
  $name = trim($d['name'] ?? ''); $contact = trim($d['contact'] ?? '');
  if ($contact === '') { $junk++; continue; }
  if (tg_is_test($name)) { $tests++; continue; }
  if (!tg_valid_contact($contact)) { $junk++; continue; }

  $phone = crm_phone_digits($contact);
  $key = $phone ? 'p:'.$phone : 'c:'.mb_strtolower($contact);
  if (isset($seen[$key])) { $dupes++; continue; }   // повтор внутри файла
  $seen[$key] = 1;
  if ($phone) { $byPhone->execute([$phone]); $ex = $byPhone->fetchColumn(); }
  else        { $byContact->execute([$contact]); $ex = $byContact->fetchColumn(); }
  if ($ex) { $dupes++; continue; }                  // уже есть в базе

  $dt = DateTime::createFromFormat('d.m.y H:i', $b['ts'], new DateTimeZone('Europe/Moscow'));
  $ca = $dt ? $dt->format('c') : date('c');
  $chl = strtolower($d['channel'] ?? '');
  $data = [
    'source'   => $d['source'] ?? 'tg-import',
    'use'      => $d['use'] ?? '',      'capacity' => $d['capacity'] ?? '',
    'type'     => $d['type'] ?? '',     'budget'   => $d['budget'] ?? '',
    'timing'   => $d['timing'] ?? '',   'name'     => $name,
    'channel'  => ($chMap[$chl] ?? $chl), 'contact' => $contact,
  ];
  if ($dry) echo sprintf("  + %s | %s | %s | %s\n", $dt ? $dt->format('d.m.Y H:i') : '?', $name ?: '(без имени)', $contact, crm_channel_label($data['channel']));
  else crm_insert_lead($data, json_encode($data, JSON_UNESCAPED_UNICODE), $ca);
  $imported++;
}

$word = $dry ? "БУДЕТ импортировано" : "Импортировано";
echo "\nГотово. $word: $imported · пропущено дублей (уже в базе / повтор номера): $dupes · тестов: $tests · мусора: $junk\n";
if ($dry && $imported) echo "Если список устраивает — запусти ту же команду без 'dry'.\n";
