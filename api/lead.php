<?php
// Приём заявок: сохраняет копию на сервере (РФ) и шлёт в Telegram.
// Токен и chat_id берутся из config.php (создаётся при деплое, в git не попадает).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }

$BOT_TOKEN = ''; $CHAT_ID = '';
$cfg = __DIR__ . '/config.php';
if (file_exists($cfg)) { require $cfg; }

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

// сообщение
$lines = [];
foreach ($data as $k => $v) {
  if (is_array($v)) { $v = implode(', ', $v); }
  $lines[] = $k . ': ' . $v;
}
$text = "🆕 Новая заявка с сайта\n\n" . implode("\n", $lines);

// 1) копия на сервере в РФ (локализация персональных данных, 152-ФЗ)
@file_put_contents(__DIR__ . '/../leads.log', date('c') . ' | ' . $raw . "\n", FILE_APPEND | LOCK_EX);

// 2) отправка в Telegram
if ($BOT_TOKEN && $CHAT_ID) {
  $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage");
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['chat_id' => $CHAT_ID, 'text' => $text], JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT        => 10,
  ]);
  curl_exec($ch);
  curl_close($ch);
}

echo '{"ok":true}';
