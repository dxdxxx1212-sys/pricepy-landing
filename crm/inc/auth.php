<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Авторизация (используется только страницами панели) ----
function crm_sess(){ if(session_status()!==PHP_SESSION_ACTIVE){ session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>true]); session_start(); } }
function crm_user(){ crm_sess(); if(empty($_SESSION['uid'])) return null; $s=crm_db()->prepare("SELECT * FROM users WHERE id=? AND active=1"); $s->execute([$_SESSION['uid']]); return $s->fetch() ?: null; }
function crm_require(){ $u=crm_user(); if(!$u){ header('Location: login.php'); exit; } return $u; }
function crm_require_owner(){ $u=crm_require(); if($u['role']!=='owner'){ http_response_code(403); exit('Только для владельца'); } return $u; }
// Ограничение видимости лидов: владелец видит все, оператор — только назначенные ему.
// Возвращает готовый SQL-фрагмент для WHERE (id приведён к int — безопасно для встраивания).
function crm_lead_scope_sql($me){ return (($me['role']??'')==='owner') ? '1=1' : ('assignee_id='.(int)($me['id']??0)); }
// Может ли пользователь открывать конкретный лид (для карточки/поп-апа/вложений).
function crm_can_see_lead($me,$lead){ return (($me['role']??'')==='owner') || ((int)($lead['assignee_id']??0)===(int)($me['id']??0)); }
function crm_login($login,$pass){ $s=crm_db()->prepare("SELECT * FROM users WHERE login=? COLLATE NOCASE AND active=1"); $s->execute([trim($login)]); $u=$s->fetch();
  if($u && password_verify($pass,$u['pass_hash'])){ crm_sess(); session_regenerate_id(true); $_SESSION['uid']=$u['id']; return true; } return false; }
function crm_logout(){ crm_sess(); $_SESSION=[]; session_destroy(); }
// антибрутфорс логина: не более 10 неудач с одного IP за 15 минут
function crm_login_throttle(){ $s=crm_db()->prepare("SELECT COUNT(*) c FROM login_fails WHERE ip=? AND ts>?"); $s->execute([$_SERVER['REMOTE_ADDR']??'', time()-900]); return ((int)$s->fetch()['c']) < 10; }
function crm_login_fail(){ $db=crm_db();
  $db->prepare("INSERT INTO login_fails(ip,ts) VALUES(?,?)")->execute([$_SERVER['REMOTE_ADDR']??'', time()]);
  $db->prepare("DELETE FROM login_fails WHERE ts < ?")->execute([time()-86400]); } // чистим старше суток, чтобы не рос
function crm_csrf(){ crm_sess(); if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
// Проверка CSRF. Токен из формы приводим к строке: если прислать csrf[]=x (массив),
// hash_equals бросил бы TypeError и страница падала бы 500-й вместо честного отказа.
function crm_csrf_ok(){ crm_sess(); $t=$_POST['csrf']??''; return is_string($t) && $t!=='' && hash_equals((string)($_SESSION['csrf']??''),$t); }
