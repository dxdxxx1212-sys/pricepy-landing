<?php
// CRM «Восток Прицеп» — общая библиотека: БД (SQLite), схема, авторизация, вёрстка.
// База лежит ВНЕ веб-корня (нельзя скачать через браузер) и не в git (переживает автодеплой).

define('CRM_DB_PATH', getenv('CRM_DB') ?: '/var/lib/pricepy-crm/leads.sqlite');
date_default_timezone_set('Europe/Moscow'); // все даты/время и KPI «сегодня» — по Москве

// ---- Справочники ----
function crm_statuses(){ return [
  'new'=>'Новый','work'=>'В работе','reached'=>'Дозвонились',
  'qualified'=>'Квалифицирован','deal'=>'Оформление','won'=>'Продажа','lost'=>'Отказ',
];}
function crm_call_statuses(){ return [
  ''=>'—','answered'=>'Дозвон','noanswer'=>'Не дозвон','callback'=>'Перезвонить',
];}
function crm_status_color($s){ return [
  'new'=>'#f5b301','work'=>'#3b82f6','reached'=>'#6366f1','qualified'=>'#8b5cf6',
  'deal'=>'#0ea5e9','won'=>'#1f9d55','lost'=>'#9aa2ab',
][$s] ?? '#9aa2ab'; }

// ---- База ----
function crm_db(){
  static $db=null;
  if($db) return $db;
  $db = new PDO('sqlite:'.CRM_DB_PATH);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $db->exec('PRAGMA journal_mode=WAL;');
  $db->exec('PRAGMA busy_timeout=3000;');
  crm_init_schema($db);
  return $db;
}
function crm_init_schema($db){ static $done=false; if($done) return; $done=true;
  $db->exec("CREATE TABLE IF NOT EXISTS leads(
    id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, source TEXT,
    name TEXT, contact TEXT, channel TEXT,
    use_ TEXT, capacity TEXT, type TEXT, budget TEXT, timing TEXT,
    utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
    gclid TEXT, yclid TEXT, items TEXT,
    ip TEXT, ua TEXT, raw TEXT,
    status TEXT DEFAULT 'new', call_status TEXT DEFAULT '', assignee_id INTEGER,
    next_action_at TEXT, sale_amount TEXT, model TEXT, reject_reason TEXT, updated_at TEXT)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at)");
  $db->exec("CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT UNIQUE, pass_hash TEXT,
    name TEXT, role TEXT DEFAULT 'operator', active INTEGER DEFAULT 1, created_at TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS comments(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS events(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, type TEXT, detail TEXT, created_at TEXT)");
}

// Вставка лида (вызывается из api/lead.php). Возвращает id или бросает исключение.
function crm_insert_lead($data, $raw){
  $g = function($k) use($data){ return isset($data[$k]) ? mb_substr((string)$data[$k],0,500) : ''; };
  $now = date('c');
  $st = crm_db()->prepare("INSERT INTO leads
    (created_at,source,name,contact,channel,use_,capacity,type,budget,timing,
     utm_source,utm_medium,utm_campaign,utm_content,utm_term,gclid,yclid,items,ip,ua,raw,status,updated_at)
    VALUES(:ca,:src,:nm,:ct,:ch,:us,:cp,:tp,:bg,:tm,:u1,:u2,:u3,:u4,:u5,:gc,:yc,:it,:ip,:ua,:raw,'new',:up)");
  $st->execute([
    ':ca'=>$now, ':up'=>$now, ':src'=>$g('source'), ':nm'=>$g('name'), ':ct'=>$g('contact'), ':ch'=>$g('channel'),
    ':us'=>$g('use'), ':cp'=>$g('capacity'), ':tp'=>$g('type'), ':bg'=>$g('budget'), ':tm'=>$g('timing'),
    ':u1'=>$g('utm_source'), ':u2'=>$g('utm_medium'), ':u3'=>$g('utm_campaign'), ':u4'=>$g('utm_content'), ':u5'=>$g('utm_term'),
    ':gc'=>$g('gclid'), ':yc'=>$g('yclid'), ':it'=>$g('items'),
    ':ip'=>($_SERVER['REMOTE_ADDR']??''), ':ua'=>mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,300), ':raw'=>$raw,
  ]);
  return crm_db()->lastInsertId();
}

// ---- Авторизация (используется только страницами панели) ----
function crm_sess(){ if(session_status()!==PHP_SESSION_ACTIVE){ session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>true]); session_start(); } }
function crm_user(){ crm_sess(); if(empty($_SESSION['uid'])) return null; $s=crm_db()->prepare("SELECT * FROM users WHERE id=? AND active=1"); $s->execute([$_SESSION['uid']]); return $s->fetch() ?: null; }
function crm_require(){ $u=crm_user(); if(!$u){ header('Location: login.php'); exit; } return $u; }
function crm_require_owner(){ $u=crm_require(); if($u['role']!=='owner'){ http_response_code(403); exit('Только для владельца'); } return $u; }
function crm_login($login,$pass){ $s=crm_db()->prepare("SELECT * FROM users WHERE login=? AND active=1"); $s->execute([trim($login)]); $u=$s->fetch();
  if($u && password_verify($pass,$u['pass_hash'])){ crm_sess(); session_regenerate_id(true); $_SESSION['uid']=$u['id']; return true; } return false; }
function crm_logout(){ crm_sess(); $_SESSION=[]; session_destroy(); }
// антибрутфорс логина: не более 10 неудач с одного IP за 15 минут
function crm_login_throttle(){ $db=crm_db(); $db->exec("CREATE TABLE IF NOT EXISTS login_fails(ip TEXT, ts INTEGER)"); $s=$db->prepare("SELECT COUNT(*) c FROM login_fails WHERE ip=? AND ts>?"); $s->execute([$_SERVER['REMOTE_ADDR']??'', time()-900]); return ((int)$s->fetch()['c']) < 10; }
function crm_login_fail(){ $db=crm_db(); $db->exec("CREATE TABLE IF NOT EXISTS login_fails(ip TEXT, ts INTEGER)"); $db->prepare("INSERT INTO login_fails(ip,ts) VALUES(?,?)")->execute([$_SERVER['REMOTE_ADDR']??'', time()]); }
function crm_csrf(){ crm_sess(); if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function crm_csrf_ok(){ crm_sess(); return isset($_POST['csrf']) && hash_equals($_SESSION['csrf']??'',$_POST['csrf']); }
function crm_event($lead_id,$user_id,$type,$detail=''){ $s=crm_db()->prepare("INSERT INTO events(lead_id,user_id,type,detail,created_at) VALUES(?,?,?,?,?)"); $s->execute([$lead_id,$user_id,$type,$detail,date('c')]); }
function crm_users_map(){ $m=[]; foreach(crm_db()->query("SELECT id,name FROM users") as $r){ $m[$r['id']]=$r['name']; } return $m; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function crm_dt($iso){ if(!$iso) return '—'; $t=strtotime($iso); return $t? date('d.m.Y H:i',$t):h($iso); }
function crm_phone_digits($c){ return preg_replace('/\D+/','',$c); }

// ---- Вёрстка ----
function crm_head($title){ $u=crm_user(); ?><!DOCTYPE html><html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?=h($title)?> · CRM Восток Прицеп</title>
<style>
:root{--bg:#0f141a;--panel:#171e26;--line:#26313d;--ink:#e7edf3;--muted:#8a97a5;--acc:#f5b301;--acc2:#3b82f6}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px}
a{color:#9cc4ff;text-decoration:none}a:hover{text-decoration:underline}
.top{background:var(--panel);border-bottom:1px solid var(--line);padding:12px 18px;display:flex;align-items:center;gap:18px;position:sticky;top:0;z-index:10}
.top .brand{font-weight:800;color:var(--acc)}.top .brand span{color:#fff}
.top nav{display:flex;gap:16px}.top nav a{color:var(--muted);font-weight:600}.top nav a.on{color:#fff}
.top .sp{flex:1}.top .me{color:var(--muted);font-size:13px}
.wrap{max-width:1200px;margin:0 auto;padding:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}
.btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:700;border-radius:8px;background:var(--acc);color:#1a1a1a;padding:9px 16px;font-size:14px}
.btn:hover{filter:brightness(1.05);text-decoration:none}.btn-sec{background:#2a3542;color:var(--ink)}.btn-b{background:var(--acc2);color:#fff}
input,select,textarea{font-family:inherit;font-size:14px;background:#0f151c;border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:9px 11px}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc)}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
tr:hover td{background:#1b232c}
.badge{display:inline-block;padding:3px 9px;border-radius:20px;font-size:12px;font-weight:700;color:#12181f}
.pill{display:inline-block;padding:2px 8px;border-radius:6px;background:#232e39;color:var(--muted);font-size:12px;margin:1px}
.muted{color:var(--muted)}.right{text-align:right}
.grid2{display:grid;grid-template-columns:1fr 340px;gap:16px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px}
.kpi .k{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:14px}
.kpi .k b{font-size:24px;display:block}.kpi .k span{color:var(--muted);font-size:12px}
.dl{display:grid;grid-template-columns:130px 1fr;gap:6px 10px;font-size:14px}.dl dt{color:var(--muted)}.dl dd{margin:0}
.cmt{border-top:1px solid var(--line);padding:10px 0}.cmt .m{color:var(--muted);font-size:12px}
@media(max-width:820px){.grid2{grid-template-columns:1fr}.top nav{display:none}}
</style></head><body>
<div class="top"><span class="brand">Восток<span>Прицеп</span> · CRM</span>
<?php if($u){ ?><nav><a href="index.php">Лиды</a><?php if($u['role']==='owner'){ ?><a href="users.php">Операторы</a><?php } ?></nav>
<span class="sp"></span><span class="me"><?=h($u['name'])?> · <?=$u['role']==='owner'?'владелец':'оператор'?></span> <a href="logout.php" class="muted">выйти</a><?php } ?>
</div><div class="wrap"><?php }
function crm_foot(){ echo '</div></body></html>'; }
