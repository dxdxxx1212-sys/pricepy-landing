<?php
// CRM «Восток Прицеп» — общая библиотека: БД (SQLite), схема, авторизация, вёрстка.
// База лежит ВНЕ веб-корня (нельзя скачать через браузер) и не в git (переживает автодеплой).

define('CRM_DB_PATH', getenv('CRM_DB') ?: '/var/lib/pricepy-crm/leads.sqlite');
date_default_timezone_set('Europe/Moscow'); // все даты/время и KPI «сегодня» — по Москве

// ---- Справочники ----
// Два независимых поля под флоу оператора:
//  1) КАК СВЯЗАЛИСЬ (call_status) — сначала ищем в мессенджере, иначе звоним.
//  2) СТАТУС СДЕЛКИ (status) — воронка: новый → в работе → подборка → итог.
function crm_statuses(){ return [
  'new'  => 'Новый',
  'work' => 'В работе',
  'sent' => 'Отправил подборку',
  'won'  => 'Продажа',
  'lost' => 'Отказ',
];}
function crm_status_color($s){ return [
  'new'=>'#f5b301','work'=>'#3b82f6','sent'=>'#0ea5e9','won'=>'#1f9d55','lost'=>'#6b7280',
][$s] ?? '#9aa2ab'; }

// Канал связи: как оператор реально достучался до клиента. '' = ещё не связывались.
// g — группа (msg = в мессенджере, call = по телефону) для группировки в UI.
function crm_contacts(){ return [
  'wa'       => ['l'=>'WhatsApp',         'g'=>'msg'],
  'tg'       => ['l'=>'Telegram',         'g'=>'msg'],
  'max'      => ['l'=>'МАКС',             'g'=>'msg'],
  'nomsg'    => ['l'=>'Нет в мессенджере','g'=>'msg'],
  'called'   => ['l'=>'Дозвонился',       'g'=>'call'],
  'noanswer' => ['l'=>'Не дозвонился',    'g'=>'call'],
];}
function crm_contact_label($c){ $C=crm_contacts(); return $C[$c]['l'] ?? ''; }
function crm_contact_color($c){ return [
  'wa'=>'#22c55e','tg'=>'#0ea5e9','max'=>'#8b5cf6','nomsg'=>'#f59e0b','called'=>'#1f9d55','noanswer'=>'#9aa2ab',
][$c] ?? '#9aa2ab'; }
// С клиентом могут связаться в нескольких каналах (созвон + перевели в мессенджер),
// поэтому call_status хранит НЕСКОЛЬКО ключей через запятую: "called,max". '' = ещё не связывались.
function crm_contact_list($cs){ $out=[]; foreach(explode(',',(string)$cs) as $k){ $k=trim($k); if($k!==''&&!in_array($k,$out,true)) $out[]=$k; } return $out; }
function crm_contact_labels($cs){ return array_map('crm_contact_label', crm_contact_list($cs)); }
// Включить/выключить канал в наборе. Правила: «Дозвонился»/«Не дозвонился» — взаимоисключающие;
// «Нет в мессенджере» несовместимо с конкретным мессенджером (wa/tg/max) и наоборот.
function crm_contact_toggle($cs,$k){
  $C=crm_contacts(); if(!isset($C[$k])) return (string)$cs;
  $cur=crm_contact_list($cs);
  if(in_array($k,$cur,true)){
    $cur=array_values(array_filter($cur,function($x)use($k){ return $x!==$k; }));
  } else {
    if(($C[$k]['g']??'')==='call'){ $cur=array_values(array_filter($cur,function($x)use($C){ return ($C[$x]['g']??'')!=='call'; })); }
    if($k==='nomsg'){ $cur=array_values(array_filter($cur,function($x){ return !in_array($x,['wa','tg','max'],true); })); }
    elseif(in_array($k,['wa','tg','max'],true)){ $cur=array_values(array_filter($cur,function($x){ return $x!=='nomsg'; })); }
    $cur[]=$k;
  }
  $order=array_keys($C); // канонический порядок как в crm_contacts()
  usort($cur,function($a,$b)use($order){ return array_search($a,$order)-array_search($b,$order); });
  return implode(',',$cur);
}
// Канал, который клиент выбрал в квизе (поле channel) — куда он ждёт сообщение.
// Значение приходит по-разному (max/МАКС/макс, phone/Телефон, WhatsApp/whatsapp) из
// разных версий квиза и импорта — нормализуем к единому ключу whatsapp/telegram/max/phone.
function crm_channel_norm($ch){
  $c = mb_strtolower(trim((string)$ch), 'UTF-8');
  $map = [
    'whatsapp'=>'whatsapp','вотсап'=>'whatsapp','ватсап'=>'whatsapp','wa'=>'whatsapp',
    'telegram'=>'telegram','телеграм'=>'telegram','тг'=>'telegram','tg'=>'telegram',
    'max'=>'max','макс'=>'max',
    'phone'=>'phone','телефон'=>'phone','тел'=>'phone','звонок'=>'phone',
  ];
  return $map[$c] ?? '';
}
function crm_channel_label($ch){
  $l = ['whatsapp'=>'WhatsApp','telegram'=>'Telegram','max'=>'МАКС','phone'=>'Телефон'][crm_channel_norm($ch)] ?? '';
  return $l !== '' ? $l : trim((string)$ch);
}
function crm_channel_color($ch){
  return ['whatsapp'=>'#22c55e','telegram'=>'#0ea5e9','max'=>'#8b5cf6','phone'=>'#9aa2ab'][crm_channel_norm($ch)] ?? '#9aa2ab';
}

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
  crm_migrate($db);
  return $db;
}
// Лёгкие миграции для баз, созданных до появления колонок (ALTER + бэкофилл).
function crm_migrate($db){ static $done=false; if($done) return; $done=true;
  $cols = $db->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if(!in_array('phone_norm',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN phone_norm TEXT"); }
  if(!in_array('call_status',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN call_status TEXT DEFAULT ''"); }
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(phone_norm)");
  // Перенос старой плоской воронки в двухполевую модель (идемпотентно — после переноса таких строк нет).
  $legacy = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status IN('messaged','noanswer')")->fetchColumn();
  if($legacy){
    // «Не дозвонился» был этапом → теперь это канал связи, а сделка остаётся «в работе».
    $db->exec("UPDATE leads SET call_status='noanswer', status='work' WHERE status='noanswer'");
    // «Написал в мессенджере» → канал = мессенджер, который клиент выбрал в квизе (если знаем), иначе WhatsApp.
    $db->exec("UPDATE leads SET call_status=CASE channel WHEN 'telegram' THEN 'tg' WHEN 'max' THEN 'max' ELSE 'wa' END, status='work' WHERE status='messaged'");
  }
  // заполнить нормализованный телефон там, где ещё пусто (после ALTER или для старых строк)
  $need = $db->query("SELECT id,contact FROM leads WHERE (phone_norm IS NULL OR phone_norm='') AND contact<>''")->fetchAll();
  if($need){ $up=$db->prepare("UPDATE leads SET phone_norm=? WHERE id=?");
    foreach($need as $r){ $up->execute([crm_phone_digits($r['contact']), $r['id']]); } }
}
function crm_init_schema($db){ static $done=false; if($done) return; $done=true;
  $db->exec("CREATE TABLE IF NOT EXISTS leads(
    id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, source TEXT,
    name TEXT, contact TEXT, channel TEXT,
    use_ TEXT, capacity TEXT, type TEXT, budget TEXT, timing TEXT,
    utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
    gclid TEXT, yclid TEXT, items TEXT, phone_norm TEXT,
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
// $createdAt — необязательно: исторический момент заявки (для импорта из leads.log).
function crm_insert_lead($data, $raw, $createdAt=null){
  $g = function($k) use($data){ return isset($data[$k]) ? mb_substr((string)$data[$k],0,500) : ''; };
  $now = $createdAt ?: date('c');
  $st = crm_db()->prepare("INSERT INTO leads
    (created_at,source,name,contact,channel,use_,capacity,type,budget,timing,
     utm_source,utm_medium,utm_campaign,utm_content,utm_term,gclid,yclid,items,phone_norm,ip,ua,raw,status,updated_at)
    VALUES(:ca,:src,:nm,:ct,:ch,:us,:cp,:tp,:bg,:tm,:u1,:u2,:u3,:u4,:u5,:gc,:yc,:it,:pn,:ip,:ua,:raw,'new',:up)");
  $st->execute([
    ':ca'=>$now, ':up'=>$now, ':src'=>$g('source'), ':nm'=>$g('name'), ':ct'=>$g('contact'), ':ch'=>$g('channel'),
    ':us'=>$g('use'), ':cp'=>$g('capacity'), ':tp'=>$g('type'), ':bg'=>$g('budget'), ':tm'=>$g('timing'),
    ':u1'=>$g('utm_source'), ':u2'=>$g('utm_medium'), ':u3'=>$g('utm_campaign'), ':u4'=>$g('utm_content'), ':u5'=>$g('utm_term'),
    ':gc'=>$g('gclid'), ':yc'=>$g('yclid'), ':it'=>$g('items'), ':pn'=>crm_phone_digits($g('contact')),
    ':ip'=>($_SERVER['REMOTE_ADDR']??''), ':ua'=>mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,300), ':raw'=>$raw,
  ]);
  return crm_db()->lastInsertId();
}
// Полное удаление лида вместе с комментариями и историей (только владелец — проверка на странице).
function crm_delete_lead($id){ $db=crm_db(); $id=(int)$id;
  $db->prepare("DELETE FROM comments WHERE lead_id=?")->execute([$id]);
  $db->prepare("DELETE FROM events WHERE lead_id=?")->execute([$id]);
  $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
}

// ---- Авторизация (используется только страницами панели) ----
function crm_sess(){ if(session_status()!==PHP_SESSION_ACTIVE){ session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>true]); session_start(); } }
function crm_user(){ crm_sess(); if(empty($_SESSION['uid'])) return null; $s=crm_db()->prepare("SELECT * FROM users WHERE id=? AND active=1"); $s->execute([$_SESSION['uid']]); return $s->fetch() ?: null; }
function crm_require(){ $u=crm_user(); if(!$u){ header('Location: login.php'); exit; } return $u; }
function crm_require_owner(){ $u=crm_require(); if($u['role']!=='owner'){ http_response_code(403); exit('Только для владельца'); } return $u; }
function crm_login($login,$pass){ $s=crm_db()->prepare("SELECT * FROM users WHERE login=? COLLATE NOCASE AND active=1"); $s->execute([trim($login)]); $u=$s->fetch();
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
// Телефон в формате для набора/мессенджеров (МАКС, WhatsApp, звонилка): +7XXXXXXXXXX.
// Учитывает, что в базе номер может лежать как 79.., 89.., так и просто 9.. (без кода страны).
function crm_phone_e164($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '+7'.substr($d,1);
  if(strlen($d)===10 && $d[0]==='9') return '+7'.$d;
  if($d==='') return '';
  return '+'.$d; // нестандартный — отдаём как есть с плюсом
}
// Красивый вид телефона: +7 900 123-45-67. Ник/необычный формат — как есть.
function crm_phone_fmt($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(preg_match('/^[78](\d{3})(\d{3})(\d{2})(\d{2})$/',$d,$m)) return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
  if(preg_match('/^(9\d{2})(\d{3})(\d{2})(\d{2})$/',$d,$m))    return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
  return trim((string)$c);
}

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
.req{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qa{display:inline-block;padding:3px 7px;border:1px solid var(--line);border-radius:6px;font-size:12px;color:#9cc4ff;margin-right:3px}
.qa:hover{background:#1b232c;text-decoration:none}
.want{display:inline-block;padding:1px 7px;border-radius:6px;font-size:11px;background:transparent;color:var(--muted);border:1px solid var(--line)}
.badge-o{display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700;background:transparent;border:1px solid currentColor}
.lead-name{color:var(--ink)}.lead-name:hover{color:#fff;text-decoration:none}
.newtab{display:inline-block;margin-left:6px;color:var(--muted);font-size:13px;line-height:18px;border:1px solid var(--line);border-radius:6px;padding:0 6px;vertical-align:middle}
.newtab:hover{color:#9cc4ff;background:#1b232c;text-decoration:none}
.cphone{color:var(--muted);cursor:pointer;border-bottom:1px dashed var(--line)}
.cphone:hover{color:#9cc4ff}
.cphone.ok{color:#5fd08a;border-bottom-color:transparent}
#crmtoast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#173a24;color:#8ff0b0;padding:9px 16px;border-radius:22px;font-size:14px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.4);z-index:60;opacity:0;transition:opacity .18s;pointer-events:none;max-width:90vw;text-align:center}
#crmtoast.on{opacity:1}
@media(max-width:820px){.grid2{grid-template-columns:1fr}.top nav{gap:12px;font-size:14px}}
/* Мобильные карточки: таблица лидов превращается в стопку карточек, без гориз. скролла */
@media(max-width:760px){
  /* поля ≥16px — iOS иначе зумит страницу при фокусе */
  input,select,textarea{font-size:16px}
  .wrap{padding:14px 12px}
  /* верхняя панель: перенос и компактность на узком экране */
  .top{padding:10px 12px;gap:8px 12px;flex-wrap:wrap}
  .top .me{font-size:12px}
  /* KPI — по 2 в ряд */
  .kpi{grid-template-columns:repeat(2,1fr);gap:8px}
  .kpi .k{padding:12px}.kpi .k b{font-size:21px}
  /* фильтры на всю ширину — крупные тап-таргеты */
  .filters{gap:8px}
  .filters select{flex:1 1 46%;min-width:0}
  .filters input[name=q]{flex:1 1 100%;min-width:0}
  .filters .btn{flex:1 1 100%}
  table.leads thead{display:none}
  table.leads,table.leads tbody,table.leads tr,table.leads td{display:block;width:100%}
  table.leads tr{border:1px solid var(--line);border-radius:10px;margin-bottom:10px;padding:8px 12px;background:var(--panel)}
  table.leads tr:hover td{background:transparent}
  table.leads td{border:0;padding:5px 0}
  .req{max-width:none;white-space:normal}
  /* крупнее для пальца: быстрые действия и «открыть в новой вкладке» */
  .qa{padding:9px 13px;font-size:14px;margin-right:7px}
  .newtab{padding:3px 11px;font-size:15px;line-height:22px}
  .cphone{padding:2px 0;display:inline-block}
}
</style></head><body>
<div class="top"><span class="brand">Восток<span>Прицеп</span> · CRM</span>
<?php if($u){ ?><nav><a href="index.php">Лиды</a><?php if($u['role']==='owner'){ ?><a href="users.php">Операторы</a><?php } ?></nav>
<span class="sp"></span><span class="me"><?=h($u['name'])?> · <?=$u['role']==='owner'?'владелец':'оператор'?></span> <a href="logout.php" class="muted">выйти</a><?php } ?>
</div><div class="wrap"><?php }
function crm_foot(){ ?></div><div id="crmtoast"></div><script>
function crmToast(m){var t=document.getElementById('crmtoast');if(!t)return;t.textContent=m;t.classList.add('on');clearTimeout(window._crmtt);window._crmtt=setTimeout(function(){t.classList.remove('on');},1600);}
function crmCopy(x){var el=(x&&x.nodeType)?x:null;var v=el?(el.getAttribute('data-c')||el.textContent.trim()):String(x);
  var ok=function(){if(el)el.classList.add('ok');crmToast('Скопировано: '+v);if(el)setTimeout(function(){el.classList.remove('ok');},1200);};
  var fb=function(){try{var t=document.createElement('textarea');t.value=v;t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.focus();t.select();document.execCommand('copy');document.body.removeChild(t);ok();}catch(e){crmToast('Не удалось скопировать');}};
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v).then(ok,fb);}else{fb();}}
</script></body></html><?php }
