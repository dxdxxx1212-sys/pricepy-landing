<?php
// CRM «Восток Прицеп» — общая библиотека: БД (SQLite), схема, авторизация, вёрстка.
// База лежит ВНЕ веб-корня (нельзя скачать через браузер) и не в git (переживает автодеплой).

define('CRM_DB_PATH', getenv('CRM_DB') ?: '/var/lib/pricepy-crm/leads.sqlite');
// Вложения к комментариям (фото/скрины) — рядом с базой: вне веб-корня и вне git,
// значит переживают автодеплой и недоступны напрямую по URL (отдаём только через att.php за авторизацией).
define('CRM_UPLOAD_DIR', getenv('CRM_UPLOAD') ?: dirname(CRM_DB_PATH).'/uploads');
define('CRM_SCHEMA_VERSION', 3); // версия схемы (PRAGMA user_version): миграции гоняются только когда БД отстаёт
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
  'new'=>'#eab308','work'=>'#3b82f6','sent'=>'#38bdf8','won'=>'#22a06b','lost'=>'#64748b',
][$s] ?? '#64748b'; }
// цвет текста на бейдже статуса (светлый фон → тёмный текст, насыщенный → белый)
function crm_status_ink($s){ return in_array($s,['new','sent'],true) ? '#12181f' : '#fff'; }
// Моно-SVG иконка (currentColor) вместо эмодзи — стабильный вид на всех ОС.
function crm_icon($n,$cls='icn'){
  $p=[
    'phone'=>'<path d="M4 4.5c0 6 5.5 11.5 11.5 11.5l.6-3-3.6-1.2-1.4 1.4C8.6 11.7 8 11 6.3 8.3l1.4-1.4L6.5 3.3z"/>',
    'person'=>'<circle cx="8" cy="5" r="3"/><path d="M2.5 14c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/>',
    'clock'=>'<circle cx="8" cy="8" r="6.2"/><path d="M8 4.6V8l2.4 1.5"/>',
    'cal'=>'<rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/>',
    'ext'=>'<path d="M6 3h7v7M13 3l-8 8"/>',
  ];
  return '<svg class="'.$cls.'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'.($p[$n]??'').'</svg>';
}

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
  $ver = (int)$db->query("PRAGMA user_version")->fetchColumn();
  if($ver >= CRM_SCHEMA_VERSION) return; // схема актуальна — тяжёлые проверки/бэкофиллы не гоняем на каждый запрос
  $cols = $db->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if(!in_array('phone_norm',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN phone_norm TEXT"); }
  if(!in_array('call_status',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN call_status TEXT DEFAULT ''"); }
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(phone_norm)");
  // комментарии: колонка edited_at (для пометки «изменён» владельцем)
  $ccols = $db->query("PRAGMA table_info(comments)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if($ccols && !in_array('edited_at',$ccols,true)){ $db->exec("ALTER TABLE comments ADD COLUMN edited_at TEXT"); }
  // v3: авто-подача лидов операторам (доля потока + тумблер) и tg-чат оператора (для Этапа 3)
  $ucols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if($ucols && !in_array('feed_share',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN feed_share INTEGER DEFAULT 0"); }
  if($ucols && !in_array('feed_active',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN feed_active INTEGER DEFAULT 0"); }
  if($ucols && !in_array('tg_chat_id',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN tg_chat_id TEXT"); }
  // Перенос старой плоской воронки в двухполевую модель (идемпотентно — после переноса таких строк нет).
  $legacy = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status IN('messaged','noanswer')")->fetchColumn();
  if($legacy){
    // «Не дозвонился» был этапом → теперь это канал связи, а сделка остаётся «в работе».
    $db->exec("UPDATE leads SET call_status='noanswer', status='work' WHERE status='noanswer'");
    // «Написал в мессенджере» → канал = мессенджер, который клиент выбрал в квизе (если знаем), иначе WhatsApp.
    $db->exec("UPDATE leads SET call_status=CASE channel WHEN 'telegram' THEN 'tg' WHEN 'max' THEN 'max' ELSE 'wa' END, status='work' WHERE status='messaged'");
  }
  // Пересчитать нормализованный телефон для ВСЕХ строк (канонизация 8→7, 10-значный 9XX→7 9XX) —
  // чтобы дедуп/поиск/«повторный клиент» ловили один номер в любом формате.
  $need = $db->query("SELECT id,contact FROM leads WHERE contact<>''")->fetchAll();
  if($need){ $up=$db->prepare("UPDATE leads SET phone_norm=? WHERE id=?");
    foreach($need as $r){ $up->execute([crm_phone_norm($r['contact']), $r['id']]); } }
  $db->exec("PRAGMA user_version=".CRM_SCHEMA_VERSION);
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
    name TEXT, role TEXT DEFAULT 'operator', active INTEGER DEFAULT 1, created_at TEXT,
    feed_share INTEGER DEFAULT 0, feed_active INTEGER DEFAULT 0, tg_chat_id TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS comments(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT, edited_at TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS events(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, type TEXT, detail TEXT, created_at TEXT)");
  // Вложения к комментариям (фото/скрины). path — имя файла внутри CRM_UPLOAD_DIR (генерим сами, не из ввода).
  $db->exec("CREATE TABLE IF NOT EXISTS attachments(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, comment_id INTEGER, user_id INTEGER,
    path TEXT, orig_name TEXT, mime TEXT, size INTEGER, created_at TEXT)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_att_lead ON attachments(lead_id)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_att_comment ON attachments(comment_id)");
}

// ---- Вложения ----
function crm_att_types(){ return ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif']; }
function crm_upload_dir(){ if(!is_dir(CRM_UPLOAD_DIR)) @mkdir(CRM_UPLOAD_DIR,0770,true); return CRM_UPLOAD_DIR; }
// Сохранить одну картинку из $_FILES-элемента ['name','tmp_name','error','size',...]. Вернёт id или null.
function crm_attach_save($lead_id,$comment_id,$user_id,$f){
  if(!is_array($f) || (int)($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) return null;
  if(empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) return null;
  if(($f['size']??0)<=0 || $f['size']>15*1024*1024) return null;      // до 15 МБ
  $info = @getimagesize($f['tmp_name']);                              // валидирует, что это реально картинка
  $mime = $info['mime'] ?? '';
  $types = crm_att_types();
  if(!isset($types[$mime])) return null;
  $rel = date('Y/m').'/'.bin2hex(random_bytes(16)).'.'.$types[$mime];
  $full = crm_upload_dir().'/'.$rel;
  if(!is_dir(dirname($full))) @mkdir(dirname($full),0770,true);
  if(!move_uploaded_file($f['tmp_name'],$full)) return null;
  @chmod($full,0640);
  $orig = mb_substr(preg_replace('/[\r\n\t]/',' ',(string)($f['name']??'')),0,160);
  $st=crm_db()->prepare("INSERT INTO attachments(lead_id,comment_id,user_id,path,orig_name,mime,size,created_at) VALUES(?,?,?,?,?,?,?,?)");
  $st->execute([(int)$lead_id,(int)$comment_id,(int)$user_id,$rel,$orig,$mime,(int)$f['size'],date('c')]);
  return (int)crm_db()->lastInsertId();
}
function crm_comment_attachments($comment_id){ $s=crm_db()->prepare("SELECT * FROM attachments WHERE comment_id=? ORDER BY id"); $s->execute([(int)$comment_id]); return $s->fetchAll(); }
function crm_comment_get($cid){ $s=crm_db()->prepare("SELECT * FROM comments WHERE id=?"); $s->execute([(int)$cid]); return $s->fetch() ?: null; }
// Удалить комментарий вместе с прикреплёнными файлами (с диска) и их записями.
function crm_comment_delete($cid){ $db=crm_db(); $cid=(int)$cid;
  $att=$db->prepare("SELECT path FROM attachments WHERE comment_id=?"); $att->execute([$cid]);
  foreach($att->fetchAll(PDO::FETCH_COLUMN) as $p){ if($p){ $full=CRM_UPLOAD_DIR.'/'.$p; if(is_file($full)) @unlink($full); } }
  $db->prepare("DELETE FROM attachments WHERE comment_id=?")->execute([$cid]);
  $db->prepare("DELETE FROM comments WHERE id=?")->execute([$cid]);
}
// Обработка POST-операций над комментарием. ТОЛЬКО владелец. Возвращает lead_id при успехе, иначе null.
function crm_process_comment_ops($me,$act){
  if(($me['role']??'')!=='owner') return null;                 // операторам нельзя
  $cid=(int)($_POST['cid']??0); if(!$cid) return null;
  $c=crm_comment_get($cid); if(!$c) return null;
  if($act==='comment_delete'){ crm_comment_delete($cid); crm_event((int)$c['lead_id'],$me['id'],'комментарий','удалён'); return (int)$c['lead_id']; }
  if($act==='comment_edit'){
    $body=trim($_POST['body']??'');
    crm_db()->prepare("UPDATE comments SET body=?,edited_at=? WHERE id=?")->execute([$body,date('c'),$cid]);
    crm_event((int)$c['lead_id'],$me['id'],'комментарий','изменён');
    return (int)$c['lead_id'];
  }
  return null;
}
// Обработка действий над лидом (общая для карточки и поп-апа): статус, канал связи, напоминание, назначение, комментарий(+фото).
// Возвращает true, если act обработан. CSRF должен быть проверен вызывающим.
function crm_process_lead_action($me,$id,$act){
  $db=crm_db(); $id=(int)$id;
  $s=$db->prepare("SELECT * FROM leads WHERE id=?"); $s->execute([$id]); $L=$s->fetch();
  if(!$L) return false;
  $ST=crm_statuses();
  if($act==='status'){
    $ns=$_POST['status']??$L['status'];
    if(isset($ST[$ns])){
      // Ответственного НЕ трогаем: распределение лидов — только через явное назначение владельцем (owner-only ниже).
      $db->prepare("UPDATE leads SET status=?,updated_at=? WHERE id=?")->execute([$ns,date('c'),$id]);
      if(in_array($ns,['won','lost'],true) && !empty($L['next_action_at'])){ $db->prepare("UPDATE leads SET next_action_at='' WHERE id=?")->execute([$id]); }
      if($ns!==$L['status']) crm_event($id,$me['id'],'статус',($ST[$L['status']]??$L['status']).' → '.($ST[$ns]??$ns));
    }
  } elseif($act==='contact'){
    $C=crm_contacts(); $nc=$_POST['contact']??'';
    if($nc===''){
      if($L['call_status']!==''){ $db->prepare("UPDATE leads SET call_status='',updated_at=? WHERE id=?")->execute([date('c'),$id]); crm_event($id,$me['id'],'контакт','сброшено'); }
    } elseif(isset($C[$nc])){
      $old=(string)$L['call_status']; $new=crm_contact_toggle($old,$nc);
      if($new!==$old){
        $wasOn=in_array($nc,crm_contact_list($old),true);
        $db->prepare("UPDATE leads SET call_status=?,updated_at=? WHERE id=?")->execute([$new,date('c'),$id]);
        crm_event($id,$me['id'],'контакт',($wasOn?'убрано: ':'').crm_contact_label($nc));
        if(!$wasOn && $L['status']==='new'){ $db->prepare("UPDATE leads SET status='work' WHERE id=?")->execute([$id]); crm_event($id,$me['id'],'статус','Новый → В работе'); } // статус двигаем, ответственного не присваиваем
        if(!$wasOn && $nc==='noanswer' && empty($L['next_action_at'])){ $t=date('c',strtotime('+2 hours')); $db->prepare("UPDATE leads SET next_action_at=? WHERE id=?")->execute([$t,$id]); crm_event($id,$me['id'],'напоминание','перезвонить '.crm_dt($t)); }
      }
    }
  } elseif($act==='remind'){
    $when=$_POST['when']??''; $ts=null;
    if($when==='clear'){ $ts=''; }
    elseif($when==='eve'){ $ts=date('c',strtotime('today 18:00')); }
    elseif($when==='tom'){ $ts=date('c',strtotime('tomorrow 10:00')); }
    elseif($when==='d3'){ $ts=date('c',strtotime('+3 days 10:00')); }
    elseif($when==='custom'){ $cv=trim($_POST['dt']??''); $t=$cv?strtotime($cv):0; if($t) $ts=date('c',$t); }
    if($ts!==null){ $db->prepare("UPDATE leads SET next_action_at=?,updated_at=? WHERE id=?")->execute([$ts,date('c'),$id]); crm_event($id,$me['id'],'напоминание',$ts?crm_dt($ts):'снято'); }
  } elseif($act==='assign'){
    if(($me['role']??'')!=='owner') return false;        // распределяет лидов только владелец
    $uid=$_POST['uid']??'';
    if($uid===''){ $db->prepare("UPDATE leads SET assignee_id=NULL,updated_at=? WHERE id=?")->execute([date('c'),$id]); if($L['assignee_id']) crm_event($id,$me['id'],'назначение','снято'); }
    else { $uid=(int)$uid; $chk=$db->prepare("SELECT name FROM users WHERE id=? AND active=1"); $chk->execute([$uid]); $nm=$chk->fetchColumn();
      if($nm && (int)$L['assignee_id']!==$uid){ $db->prepare("UPDATE leads SET assignee_id=?,updated_at=? WHERE id=?")->execute([$uid,date('c'),$id]); crm_event($id,$me['id'],'назначение',$nm); } }
  } elseif($act==='comment'){
    $body=trim($_POST['body']??''); $F=$_FILES['att']??null;
    $hasFiles = is_array($F) && isset($F['name']) && is_array($F['name']) && array_filter($F['name'], function($n){ return $n!==''; });
    if($body!=='' || $hasFiles){
      $db->prepare("INSERT INTO comments(lead_id,user_id,body,created_at) VALUES(?,?,?,?)")->execute([$id,$me['id'],$body,date('c')]);
      $cid=(int)$db->lastInsertId(); $saved=0;
      if($hasFiles){ $n=count($F['name']);
        for($i=0;$i<$n && $saved<10;$i++){ if((int)($F['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) continue;
          $one=['name'=>$F['name'][$i],'tmp_name'=>$F['tmp_name'][$i],'error'=>$F['error'][$i],'size'=>$F['size'][$i]];
          if(crm_attach_save($id,$cid,$me['id'],$one)) $saved++; } }
      if($saved) crm_event($id,$me['id'],'вложение',$saved.' фото');
    }
  } else { return false; }
  return true;
}
// HTML одной карточки комментария (общий для карточки лида и поп-апа). $canManage — показать правку/удаление (владелец).
function crm_comment_card_html($c,$atts,$canManage,$csrf){
  $h='<div class="cmt" data-cid="'.(int)$c['id'].'"><div class="cmt-view">';
  if(($c['body']??'')!=='') $h.='<div class="cmt-body">'.nl2br(h($c['body'])).'</div>';
  if($atts){ $h.='<div class="att-grid">'; foreach($atts as $a){ $h.='<a class="att-th" href="att.php?id='.(int)$a['id'].'" target="_blank" rel="noopener" title="Открыть в полном размере"><img src="att.php?id='.(int)$a['id'].'" loading="lazy" alt=""></a>'; } $h.='</div>'; }
  $h.='<div class="m">'.h($c['un']?:'?').' · '.crm_dt($c['created_at']).(!empty($c['edited_at'])?' · <span title="отредактировано">изм.</span>':'').'</div>';
  if($canManage){
    $h.='<div class="cmt-tools">'
      .'<button type="button" class="cmt-edit-btn">изменить</button>'
      .'<form method="post" class="cmt-act cmt-del" onsubmit="return confirm(\'Удалить комментарий? Вместе с прикреплёнными фото.\')" style="display:inline">'
      .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="act" value="comment_delete"><input type="hidden" name="cid" value="'.(int)$c['id'].'">'
      .'<button type="submit" class="cmt-del-btn">удалить</button></form></div>';
  }
  $h.='</div>'; // .cmt-view
  if($canManage){
    $h.='<form method="post" class="cmt-act cmt-editform" style="display:none">'
      .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="act" value="comment_edit"><input type="hidden" name="cid" value="'.(int)$c['id'].'">'
      .'<textarea name="body" rows="3" style="width:100%">'.h($c['body']).'</textarea>'
      .'<div style="margin-top:6px"><button type="submit" class="btn btn-b">Сохранить</button> <button type="button" class="cmt-cancel clr">отмена</button></div></form>';
  }
  return $h.'</div>';
}
function crm_events_list_html($events){
  if(!$events) return '<div class="muted" style="font-size:14px">Действий ещё не было.</div>';
  $h=''; foreach($events as $e){ $h.='<div class="cmt" style="padding:7px 0"><span class="pill">'.h($e['type']).'</span> '.h($e['detail']).' <span class="m"> — '.h($e['un']?:'?').', '.crm_dt($e['created_at']).'</span></div>'; }
  return $h;
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
    ':gc'=>$g('gclid'), ':yc'=>$g('yclid'), ':it'=>$g('items'), ':pn'=>crm_phone_norm($g('contact')),
    ':ip'=>($_SERVER['REMOTE_ADDR']??''), ':ua'=>mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,300), ':raw'=>$raw,
  ]);
  return crm_db()->lastInsertId();
}
// Авто-подача: распределить новый лид между операторами по их долям (feed_share, 0..100).
// Взвешенный жребий БЕЗ общего состояния (нет счётчиков/курсоров → нет гонок в SQLite).
// Остаток до 100% (r > суммы долей) — лид остаётся нераспределённым (ручной пул владельца).
// Полностью fail-safe: любая ошибка глушится — распределение не должно влиять на приём заявки.
function crm_autoassign_new_lead($leadId){
  try{
    $leadId=(int)$leadId; if($leadId<=0) return null;
    $db=crm_db();
    // кандидаты: активные операторы с включённой подачей и положительной долей
    $cands=$db->query("SELECT id,name,feed_share FROM users WHERE role='operator' AND active=1 AND feed_active=1 AND feed_share>0 ORDER BY id")->fetchAll();
    if(!$cands) return null;
    $r=random_int(1,100); $cum=0; $winner=0; $wname='';
    foreach($cands as $c){ $cum+=(int)$c['feed_share']; if($cum>100) $cum=100; if($r<=$cum){ $winner=(int)$c['id']; $wname=$c['name']; break; } }
    if(!$winner) return null; // жребий попал в «остаток» — лид владельцу (нераспределённым)
    // идемпотентно: назначаем только если ещё никто не назначен (ручное назначение не перетираем)
    $upd=$db->prepare("UPDATE leads SET assignee_id=?,updated_at=? WHERE id=? AND (assignee_id IS NULL OR assignee_id=0)");
    $upd->execute([$winner,date('c'),$leadId]);
    if($upd->rowCount()>0){ crm_event($leadId,0,'назначение',$wname.' (авто-подача)'); return $winner; }
    return null;
  }catch(Throwable $e){ return null; } // подача необязательна — заявку не трогаем
}
// Полное удаление лида вместе с комментариями и историей (только владелец — проверка на странице).
function crm_delete_lead($id){ $db=crm_db(); $id=(int)$id;
  // сначала удалить файлы вложений с диска
  $att=$db->prepare("SELECT path FROM attachments WHERE lead_id=?"); $att->execute([$id]);
  foreach($att->fetchAll(PDO::FETCH_COLUMN) as $p){ if($p){ $full=CRM_UPLOAD_DIR.'/'.$p; if(is_file($full)) @unlink($full); } }
  $db->prepare("DELETE FROM attachments WHERE lead_id=?")->execute([$id]);
  $db->prepare("DELETE FROM comments WHERE lead_id=?")->execute([$id]);
  $db->prepare("DELETE FROM events WHERE lead_id=?")->execute([$id]);
  $db->prepare("DELETE FROM leads WHERE id=?")->execute([$id]);
}

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
function crm_login_throttle(){ $db=crm_db(); $db->exec("CREATE TABLE IF NOT EXISTS login_fails(ip TEXT, ts INTEGER)"); $s=$db->prepare("SELECT COUNT(*) c FROM login_fails WHERE ip=? AND ts>?"); $s->execute([$_SERVER['REMOTE_ADDR']??'', time()-900]); return ((int)$s->fetch()['c']) < 10; }
function crm_login_fail(){ $db=crm_db(); $db->exec("CREATE TABLE IF NOT EXISTS login_fails(ip TEXT, ts INTEGER)"); $db->exec("CREATE INDEX IF NOT EXISTS idx_login_fails ON login_fails(ip,ts)");
  $db->prepare("INSERT INTO login_fails(ip,ts) VALUES(?,?)")->execute([$_SERVER['REMOTE_ADDR']??'', time()]);
  $db->prepare("DELETE FROM login_fails WHERE ts < ?")->execute([time()-86400]); } // чистим старше суток, чтобы не рос
function crm_csrf(){ crm_sess(); if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function crm_csrf_ok(){ crm_sess(); return isset($_POST['csrf']) && hash_equals($_SESSION['csrf']??'',$_POST['csrf']); }
function crm_event($lead_id,$user_id,$type,$detail=''){ $s=crm_db()->prepare("INSERT INTO events(lead_id,user_id,type,detail,created_at) VALUES(?,?,?,?,?)"); $s->execute([$lead_id,$user_id,$type,$detail,date('c')]); }
function crm_users_map(){ $m=[]; foreach(crm_db()->query("SELECT id,name FROM users") as $r){ $m[$r['id']]=$r['name']; } return $m; }

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function crm_dt($iso){ if(!$iso) return '—'; $t=strtotime($iso); return $t? date('d.m.Y H:i',$t):h($iso); }
function crm_phone_digits($c){ return preg_replace('/\D+/','',$c); }
// Канонический ключ телефона для дедупа/поиска/связки: РФ-номер → 7XXXXXXXXXX (8→7, 10-значный 9XX→7 9XX).
// Ник/не-телефон — как цифры (обычно ''). Совпадает по логике с crm_phone_e164 (только без «+»).
function crm_phone_norm($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '7'.substr($d,1);
  if(strlen($d)===10 && $d[0]==='9') return '7'.$d;
  return $d;
}
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
:root{--bg:#0f141a;--panel:#171e26;--line:#262f3a;--ink:#e7edf3;--muted:#93a0ae;--muted2:#aeb9c5;--acc:#f5b301;--acc2:#3b82f6;
  --ph:#c9d3dd;                                   /* телефон/имя — заметнее */
  --chip-bg:#1f2731;--chip-ink:#c4ccd6;--chip-line:#2a3540;  /* нейтральная плашка */
  --alert:#f2843c;                               /* единый тон срочности */
  --ok-bg:#173a24;--ok-ink:#8ff0b0;--warn-bg:#3a2417;--warn-ink:#f0a86a;--warn-line:#5a4433;--danger-bg:#3a1717;--danger-ink:#ffb0b0}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px}
a{color:#9cc4ff;text-decoration:none}a:hover{text-decoration:underline}
:focus-visible{outline:2px solid var(--acc2);outline-offset:2px}
.icn{width:15px;height:15px;display:inline-block;vertical-align:-2px;flex:none}
.top{background:var(--panel);border-bottom:1px solid var(--line);padding:12px 18px;display:flex;align-items:center;gap:18px;position:sticky;top:0;z-index:10}
.top .brand{font-weight:800;color:var(--acc)}.top .brand span{color:#fff}
.top nav{display:flex;gap:16px}.top nav a{color:var(--muted);font-weight:600}.top nav a.on{color:#fff}
.top .sp{flex:1}.top .me{color:var(--muted);font-size:13px}
.wrap{max-width:1200px;margin:0 auto;padding:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:16px}
.btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:700;border-radius:8px;background:var(--acc);color:#1a1a1a;padding:9px 16px;font-size:14px}
.btn:hover{filter:brightness(1.05);text-decoration:none}.btn-sec{background:#2a3542;color:var(--ink)}.btn-b{background:var(--acc2);color:#fff}
input,select,textarea{font-family:inherit;font-size:14px;background:#0f151c;border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:9px 11px}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc)}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
tr:hover td{background:#1b232c}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#12181f}
/* нейтральная плашка — каналы, «хочет», менеджер, типы событий */
.chip{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);line-height:1.5}
.chip.warn{background:var(--warn-bg);color:var(--warn-ink);border-color:var(--warn-line)}
.ch-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex:none}
.mgr .icn{width:12px;height:12px;color:var(--muted)}
.qa .icn{width:15px;height:15px}
.pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);font-size:12px;margin:1px}
.muted{color:var(--muted)}.right{text-align:right}
.grid2{display:grid;grid-template-columns:1fr 340px;gap:16px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px}
.kpi .k{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px}
.kpi .k b{font-size:24px;display:block}.kpi .k span{color:var(--muted);font-size:12px}
.dl{display:grid;grid-template-columns:130px 1fr;gap:6px 10px;font-size:14px}.dl dt{color:var(--muted)}.dl dd{margin:0}
.cmt{border-top:1px solid var(--line);padding:10px 0}.cmt .m{color:var(--muted);font-size:12px}
.req{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qa{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:28px;padding:0 8px;border:1px solid var(--chip-line);border-radius:8px;font-size:12px;font-weight:600;color:var(--chip-ink);margin-right:4px;vertical-align:middle}
.qa:hover{background:#1b232c;text-decoration:none;color:#fff}
.qa.want-ch{border-color:var(--warn-line);color:var(--warn-ink)}   /* канал, который клиент выбрал */
.want{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line)}
.lead-name{color:#fff;font-weight:700}.lead-name:hover{color:#fff;text-decoration:none}
.newtab{display:inline-block;margin-left:5px;color:#5a6777;font-size:13px;line-height:1;vertical-align:middle}
.newtab:hover{color:#9cc4ff;text-decoration:none}
.cphone{color:var(--ph);font-weight:500;cursor:pointer;border-bottom:1px dashed #3a4653}
.cphone:hover{color:#fff}
.mgr{display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);border-radius:6px;padding:1px 8px}
.mgr-none{display:inline-block;margin-top:6px;color:var(--muted);padding:1px 8px;border:1px dashed var(--line);border-radius:6px;font-size:12px}
/* строка последнего комментария + превью вложения в списке лидов */
.lc{display:flex;align-items:center;gap:8px;margin-top:8px}
.lc-thumb{flex:none;width:40px;height:40px;border-radius:7px;overflow:hidden;border:1px solid var(--line);display:block;background:#0f151c}
.lc-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.lc-txt{color:var(--muted);font-size:12.5px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:270px}
.lc-more{color:#7f8a96;font-size:11px}
/* колонка «Коммент» в списке: клик открывает поп-ап */
.cmt-col .lc{cursor:pointer;margin-top:0}
.cmt-col .lc:hover .lc-txt{color:#c9d3dd}
.cmt-col .lc-txt{max-width:200px}
/* карточка комментария: инструменты владельца, форма правки, сетка фото */
.att-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.att-th{display:block;width:96px;height:96px;border-radius:8px;overflow:hidden;border:1px solid var(--line);background:#0f151c}
.att-th img{width:100%;height:100%;object-fit:cover;display:block}
.att-th:hover{border-color:var(--acc)}
.cmt-body{white-space:pre-wrap}
.cmt-tools{margin-top:5px;display:flex;gap:14px}
.cmt-tools button{background:none;border:0;color:#9cc4ff;cursor:pointer;font:inherit;font-size:12px;text-decoration:underline;padding:0}
.cmt-del-btn{color:#e0796b}
.cmt-cancel{background:none;border:0;color:var(--muted);cursor:pointer;font:inherit;font-size:13px;text-decoration:underline;padding:4px 2px}
.cmt-editform textarea{background:#0f151c;border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:8px;font-family:inherit;font-size:14px}
/* поп-ап истории + комментариев (страница списка) */
.hm{position:fixed;inset:0;z-index:100;display:flex;align-items:flex-start;justify-content:center}
.hm[hidden]{display:none}
.hm-back{position:absolute;inset:0;background:rgba(0,0,0,.62)}
.hm-box{position:relative;z-index:1;background:var(--panel);border:1px solid var(--line);border-radius:12px;max-width:560px;width:calc(100% - 28px);margin:5vh 0;max-height:90vh;overflow:auto;padding:18px 18px 22px}
.hm-x{position:absolute;top:6px;right:10px;background:none;border:0;color:var(--muted);font-size:26px;line-height:1;cursor:pointer}
.hist-head{font-size:16px;margin:0 0 6px;padding-right:26px}
.hist-sec{margin-top:16px}
.hist-lbl{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
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
  .lc-txt{max-width:none}
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
// правка комментария: показать/скрыть форму (владелец). Делегировано — работает и в карточке, и в поп-апе.
document.addEventListener('click',function(e){
  var eb=e.target.closest && e.target.closest('.cmt-edit-btn');
  if(eb){ var c=eb.closest('.cmt'); if(c){ var v=c.querySelector('.cmt-view'), f=c.querySelector('.cmt-editform'); if(v&&f){ v.style.display='none'; f.style.display='block'; var t=f.querySelector('textarea'); if(t){t.focus();} } } return; }
  var cc=e.target.closest && e.target.closest('.cmt-cancel');
  if(cc){ var c2=cc.closest('.cmt'); if(c2){ var v2=c2.querySelector('.cmt-view'), f2=c2.querySelector('.cmt-editform'); if(v2&&f2){ f2.style.display='none'; v2.style.display=''; } } }
});
</script></body></html><?php }
