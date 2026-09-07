<?php
// Приём заявок: сохраняет копию на сервере (РФ) и доставляет в Telegram
// через Cloudflare Worker (прямой доступ к api.telegram.org с РФ-сервера закрыт).
header('Content-Type: application/json; charset=utf-8');
// CORS — только для наших доменов. Реальные заявки идут тем же origin (relative /api/lead),
// им CORS вообще не нужен; wildcard '*' только открывал эндпоинт для чужих сайтов.
$ALLOWED_ORIGINS = [
  'https://xn----ctbklixakchgm2d.xn--p1ai',                 // восток-прицеп.рф
  'https://www.xn----ctbklixakchgm2d.xn--p1ai',
  'https://xn--90af3acbk.xn----ctbklixakchgm2d.xn--p1ai',   // подбор.восток-прицеп.рф
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOWED_ORIGINS, true)) {
  header('Access-Control-Allow-Origin: ' . $origin);
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

// Worker-релей (токен и chat_id хранятся в нём как секреты).
$WORKER_URL = 'https://throbbing-union-7326pricepy-leads.dxdxxx1212.workers.dev';
// Общий секрет для аутентификации у воркера (из api/config.php вне git). Пусто = воркер
// пока не требует секрета — доставка не ломается до включения проверки в Cloudflare.
$LEAD_SECRET = '';
if (is_file(__DIR__ . '/config.php')) { include __DIR__ . '/config.php'; } // задаёт $LEAD_SECRET (+ legacy $BOT_TOKEN/$CHAT_ID)

$raw  = file_get_contents('php://input');

// защита от переполнения диска мусорным телом (нормальная заявка < 4 КБ)
if (strlen($raw) > 16384) { http_response_code(413); echo '{"ok":false}'; exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

// honeypot: скрытое поле hp заполняют только боты. Тихо «принимаем» и выкидываем.
if (!empty($data['hp'])) { echo '{"ok":true}'; exit; }

// минимальная валидация: должно быть имя ИЛИ контакт — режем пустые {} и мусор,
// не рискуя реальными заявками (контакт может быть телефоном или ником мессенджера).
$vName = trim((string)($data['name'] ?? ''));
$vContact = trim((string)($data['contact'] ?? ''));
if ($vName === '' && mb_strlen($vContact) < 4) { http_response_code(422); echo '{"ok":false}'; exit; }

// логи пишем ВНЕ веб-корня, если папка CRM уже создана (её нельзя скачать из браузера);
// иначе — рядом с сайтом (nginx закрывает *.log правилом deny). Так копия лида не теряется.
$LOG_DIR = (is_dir('/var/lib/pricepy-crm') && is_writable('/var/lib/pricepy-crm'))
  ? '/var/lib/pricepy-crm' : (__DIR__ . '/..');

// мягкий анти-флуд: не более 30 заявок с одного IP за 60 сек (режем ботов, людям не мешает).
// fail-open: любая ошибка троттлинга не блокирует лид.
try {
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $rlFile = $LOG_DIR . '/.rl_' . md5($ip);
  $now = time(); $hits = [];
  if (is_file($rlFile)) { foreach (explode(',', (string)@file_get_contents($rlFile)) as $t) { if ((int)$t > $now - 60) $hits[] = (int)$t; } }
  if (count($hits) >= 30) { http_response_code(429); echo '{"ok":false}'; exit; }
  $hits[] = $now;
  @file_put_contents($rlFile, implode(',', $hits), LOCK_EX);
} catch (Throwable $e) { /* пропускаем лид */ }

// 1) копия на сервере в РФ (локализация персональных данных, 152-ФЗ).
//    Ротация по размеру: > 5 МБ — отправляем в .1, чтобы лог не рос бесконечно.
$logFile = $LOG_DIR . '/leads.log';
if (is_file($logFile) && filesize($logFile) > 5 * 1024 * 1024) { @rename($logFile, $logFile . '.1'); }
@file_put_contents($logFile, date('c') . ' | ' . $raw . "\n", FILE_APPEND | LOCK_EX);

// 2) доставка в Telegram через Worker — с повтором и проверкой ответа
$ok = false; $lastErr = '';
for ($i = 0; $i < 3; $i++) {
  $ch = curl_init($WORKER_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Lead-Secret: ' . $LEAD_SECRET],
    CURLOPT_POSTFIELDS     => $raw,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp !== false && $code >= 200 && $code < 300) { $ok = true; break; }
  $lastErr = $err !== '' ? $err : ('HTTP ' . $code);
  usleep(700000); // 0.7s перед повтором
}

// если доставить не удалось — фиксируем в отдельный лог (лид не теряется, видно причину)
if (!$ok) {
  @file_put_contents($LOG_DIR . '/leads-errors.log', date('c') . ' | FAILED(' . $lastErr . ') | ' . $raw . "\n", FILE_APPEND | LOCK_EX);
}

// 3) запись в CRM-базу — ПОСЛЕ доставки в Telegram, чтобы код БД физически не мог
//    задержать или сорвать доставку. Любой сбой БД логируется и на лид не влияет
//    (leads.log + Telegram уже отработали выше).
try {
  require_once __DIR__ . '/../crm/lib.php';
  crm_insert_lead($data, $raw);
} catch (Throwable $e) {
  @file_put_contents($LOG_DIR . '/leads-errors.log', date('c') . ' | CRM_DB_FAIL(' . $e->getMessage() . ') | ' . $raw . "\n", FILE_APPEND | LOCK_EX);
}

echo $ok ? '{"ok":true}' : '{"ok":false}';
