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
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); echo '{"ok":false}'; exit; }

// 1) копия на сервере в РФ (локализация персональных данных, 152-ФЗ)
@file_put_contents(__DIR__ . '/../leads.log', date('c') . ' | ' . $raw . "\n", FILE_APPEND | LOCK_EX);

// 2) доставка в Telegram через Worker
$ch = curl_init($WORKER_URL);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_POSTFIELDS     => $raw,
  CURLOPT_TIMEOUT        => 12,
]);
curl_exec($ch);
curl_close($ch);

echo '{"ok":true}';
