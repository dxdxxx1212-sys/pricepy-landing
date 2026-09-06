<?php
// Лёгкий JSON-эндпоинт для сигнала о новом лиде без F5 (опрос из index.php).
// Возвращает максимальный id лида и число новых. Без авторизации отдаёт {ok:false}.
require __DIR__.'/lib.php';
header('Content-Type: application/json; charset=utf-8');
if(!crm_user()){ echo '{"ok":false}'; exit; }
$db=crm_db();
$max=(int)$db->query("SELECT COALESCE(MAX(id),0) m FROM leads")->fetch()['m'];
$new=(int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='new'")->fetch()['c'];
echo json_encode(['ok'=>true,'max'=>$max,'new'=>$new]);
