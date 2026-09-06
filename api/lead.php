<?php
// Приём заявок: сохраняет копию на сервере (РФ) и доставляет в Telegram
// через Cloudflare Worker (прямой доступ к api.telegram.org с РФ-сервера закрыт).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

// Worker-релей (токен и chat_id хранятся в нём как секреты)
$WORKER_URL = 'https://throbbing-union-7326pricepy-leads.dxdxxx1212.workers.dev';

$raw  = file_get_contents('php://input');

// защита от переполнения диска мусорным телом (нормальная заявка < 4 КБ)
if (strlen($raw) > 16384) { http_response_code(413); echo '{"ok":false}'; exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

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

// 1) копия на сервере в РФ (локализация персональных данных, 152-ФЗ)
@file_put_contents($LOG_DIR . '/leads.log', date('c') . ' | ' . $raw . "\n", FILE_APPEND | LOCK_EX);

// 2) доставка в Telegram через Worker — с повтором и проверкой ответа
$ok = false; $lastErr = '';
for ($i = 0; $i < 3; $i++) {
  $ch = curl_init($WORKER_URL);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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
