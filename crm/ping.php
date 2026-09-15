<?php
// Лёгкий JSON-эндпоинт для сигнала о новом лиде без F5 (опрос из index.php).
// Возвращает максимальный id лида и число новых. Без авторизации отдаёт {ok:false}.
require __DIR__.'/lib.php';
header('Content-Type: application/json; charset=utf-8');
$me=crm_user(); if(!$me){ echo '{"ok":false}'; exit; }
$db=crm_db();
$scope=crm_lead_scope_sql($me); // оператор считает только свои лиды (иначе баннер «новый лид» ложно срабатывает)
$max=(int)$db->query("SELECT COALESCE(MAX(id),0) m FROM leads WHERE $scope")->fetch()['m'];
$new=(int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='new' AND ($scope)")->fetch()['c'];
echo json_encode(['ok'=>true,'max'=>$max,'new'=>$new]);
