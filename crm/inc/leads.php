<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Лиды: действия, приём, распределение, удаление, история ----
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
      if($nm && (int)$L['assignee_id']!==$uid){ $db->prepare("UPDATE leads SET assignee_id=?,updated_at=? WHERE id=?")->execute([$uid,date('c'),$id]); crm_event($id,$me['id'],'назначение',$nm); crm_notify_operator($uid,$id); } }
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
    if($upd->rowCount()>0){ crm_event($leadId,0,'назначение',$wname.' (авто-подача)'); crm_notify_operator($winner,$leadId); return $winner; }
    return null;
  }catch(Throwable $e){ return null; } // подача необязательна — заявку не трогаем
}
// Массовая передача лидов оператору (или снятие назначения при $uid пустом). ТОЛЬКО владелец.
// Возвращает число реально изменённых лидов. Оператору шлётся ОДНО суммарное уведомление.
function crm_bulk_assign($me,$ids,$uid){
  if(($me['role']??'')!=='owner') return 0;
  $db=crm_db();
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)$ids), function($x){ return $x>0; })));
  if(!$ids) return 0;
  if(count($ids)>500) $ids=array_slice($ids,0,500); // предохранитель
  $uid=trim((string)$uid);
  if($uid===''){ return 0; }                          // оператор не выбран — ничего не трогаем (защита от случайного снятия)
  $target=($uid==='unassign')?0:(int)$uid;            // явное снятие — только через пункт «снять назначение»
  $tname=''; $chat='';
  if($target>0){ $chk=$db->prepare("SELECT name,tg_chat_id FROM users WHERE id=? AND active=1"); $chk->execute([$target]); $t=$chk->fetch();
    if(!$t) return 0; $tname=$t['name']; $chat=(string)$t['tg_chat_id']; } // цель — только активный пользователь
  $in=implode(',',array_fill(0,count($ids),'?'));
  // берём только реально существующие лиды, у которых назначение МЕНЯЕТСЯ (чтобы не мусорить историю)
  $sel=$db->prepare("SELECT id FROM leads WHERE id IN($in) AND COALESCE(assignee_id,0)<>?"); $sel->execute(array_merge($ids,[$target]));
  $changed=array_map('intval',$sel->fetchAll(PDO::FETCH_COLUMN));
  if(!$changed) return 0;
  $now=date('c'); $cin=implode(',',array_fill(0,count($changed),'?'));
  $detail=$target>0?($tname.' (массово)'):'снято (массово)';
  // атомарно: назначение + события одной транзакцией (и быстрее при пачке до 500)
  try{
    $db->beginTransaction();
    $db->prepare("UPDATE leads SET assignee_id=?,updated_at=? WHERE id IN($cin)")->execute(array_merge([$target>0?$target:null,$now],$changed));
    $ev=$db->prepare("INSERT INTO events(lead_id,user_id,type,detail,created_at) VALUES(?,?,?,?,?)");
    foreach($changed as $lid){ $ev->execute([$lid,$me['id'],'назначение',$detail,$now]); }
    $db->commit();
  }catch(Throwable $e){ if($db->inTransaction()) $db->rollBack(); return 0; }
  if($target>0 && trim($chat)!==''){ $n=count($changed); // суммарное уведомление — после commit
    crm_tg_send($chat, "🔔 <b>Вам передано ".$n." ".crm_plural_lead($n)."</b>\n".CRM_BASE_URL."/index.php"); }
  return count($changed);
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
function crm_event($lead_id,$user_id,$type,$detail=''){ $s=crm_db()->prepare("INSERT INTO events(lead_id,user_id,type,detail,created_at) VALUES(?,?,?,?,?)"); $s->execute([$lead_id,$user_id,$type,$detail,date('c')]); }
function crm_users_map(){ $m=[]; foreach(crm_db()->query("SELECT id,name FROM users") as $r){ $m[$r['id']]=$r['name']; } return $m; }
