<?php
// Отдача вложения (фото/скрин комментария) — ТОЛЬКО авторизованным (внутри могут быть ПДн клиента).
// Файлы лежат вне веб-корня (CRM_UPLOAD_DIR), путь берём из БД по id — пользовательский ввод в путь не попадает.
require __DIR__.'/lib.php';
crm_require();

$id = (int)($_GET['id'] ?? 0);
$s = crm_db()->prepare("SELECT * FROM attachments WHERE id=?"); $s->execute([$id]); $a = $s->fetch();
if(!$a){ http_response_code(404); exit('Вложение не найдено'); }

$full = CRM_UPLOAD_DIR.'/'.$a['path'];
$real = realpath($full);
// защита от выхода за пределы каталога вложений
if($real===false || strpos($real, realpath(CRM_UPLOAD_DIR).DIRECTORY_SEPARATOR)!==0 || !is_file($real)){ http_response_code(404); exit('Файл недоступен'); }

$mime = $a['mime'] ?: 'application/octet-stream';
$dl   = isset($_GET['dl']);
$name = $a['orig_name'] ?: ('attachment-'.$a['id']);

header('Content-Type: '.$mime);
header('Content-Length: '.filesize($real));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: '.($dl?'attachment':'inline').'; filename="'.rawurlencode($name).'"');
readfile($real);
