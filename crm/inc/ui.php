<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Вёрстка: шапка/подвал страницы, общие фрагменты ----
// Версия статики для сброса кэша браузера: mtime файла меняется при каждом деплое (git reset), этого достаточно.
function crm_asset_v($name){ return (int)@filemtime(__DIR__.'/../assets/'.$name); }
// Плашка-сообщение: $kind = ok | warn | err. Пустой текст — ничего не выводит.
function crm_flash($kind,$text){ return $text==='' ? '' : '<div class="flash '.$kind.'">'.h($text).'</div>'; }
// Скрытые поля формы-действия: csrf + act (+ произвольные name=>value). Экранирует всё само.
function crm_act_fields($act,$extra=[]){
  $h='<input type="hidden" name="csrf" value="'.h(crm_csrf()).'"><input type="hidden" name="act" value="'.h($act).'">';
  foreach($extra as $k=>$v) $h.='<input type="hidden" name="'.h($k).'" value="'.h($v).'">';
  return $h;
}
function crm_head($title){ $u=crm_user();
  // X-Frame-Options (DENY) и nosniff уже ставит nginx на весь поддомен — здесь не дублируем,
  // иначе в ответе два разных X-Frame-Options. Добавляем только то, чего у nginx нет:
  // no-referrer — в URL карточки есть id лида, а из неё уходят ссылки на wa.me / t.me / max.ru.
  if(!headers_sent()){ header('Referrer-Policy: no-referrer'); }
  ?><!DOCTYPE html><html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?=h($title)?> · CRM Восток Прицеп</title>
<link rel="stylesheet" href="assets/crm.css?v=<?=crm_asset_v('crm.css')?>"></head><body>
<div class="top"><span class="brand">Восток<span>Прицеп</span> · CRM</span>
<?php if($u){ ?><nav><a href="index.php">Лиды</a><?php if($u['role']==='owner'){ ?><a href="users.php">Операторы</a><?php } ?></nav>
<span class="sp"></span><span class="me"><?=h($u['name'])?> · <?=$u['role']==='owner'?'владелец':'оператор'?></span> <a href="logout.php" class="muted">выйти</a><?php } ?>
</div><div class="wrap"><?php }
function crm_foot(){ ?></div><div id="crmtoast"></div><script src="assets/crm.js?v=<?=crm_asset_v('crm.js')?>"></script></body></html><?php }
