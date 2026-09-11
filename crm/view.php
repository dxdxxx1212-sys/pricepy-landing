<?php
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$ST = crm_statuses(); $users = crm_users_map();
$id = (int)($_GET['id'] ?? 0);
$s=$db->prepare("SELECT * FROM leads WHERE id=?"); $s->execute([$id]); $L=$s->fetch();
if(!$L){ crm_head('Лид'); echo '<div class="card">Лид не найден. <a href="index.php">← к списку</a></div>'; crm_foot(); exit; }
$msg='';

if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='status'){
    $ns=$_POST['status']??$L['status'];
    if(isset($ST[$ns])){
      $assignee=$L['assignee_id'];
      if(!$assignee && $ns!=='new') $assignee=$me['id']; // взял в работу — авто-назначение
      $db->prepare("UPDATE leads SET status=?,assignee_id=?,updated_at=? WHERE id=?")->execute([$ns,$assignee,date('c'),$id]);
      if($ns!==$L['status']) crm_event($id,$me['id'],'статус',($ST[$L['status']]??$L['status']).' → '.($ST[$ns]??$ns));
    }
  } elseif($act==='contact'){
    $C=crm_contacts();
    $nc=$_POST['contact']??'';
    if($nc===''){ // сбросить все каналы
      if($L['call_status']!==''){
        $db->prepare("UPDATE leads SET call_status='',updated_at=? WHERE id=?")->execute([date('c'),$id]);
        crm_event($id,$me['id'],'контакт','сброшено');
      }
    } elseif(isset($C[$nc])){
      $old=(string)$L['call_status'];
      $new=crm_contact_toggle($old,$nc);
      if($new!==$old){
        $wasOn=in_array($nc,crm_contact_list($old),true); // сейчас снимаем канал или добавляем
        $db->prepare("UPDATE leads SET call_status=?,updated_at=? WHERE id=?")->execute([$new,date('c'),$id]);
        crm_event($id,$me['id'],'контакт',($wasOn?'убрано: ':'').crm_contact_label($nc));
        // Первый контакт по «новому» лиду → сам берём в работу и назначаем на оператора.
        if(!$wasOn && $L['status']==='new'){
          $as=$L['assignee_id']?:$me['id'];
          $db->prepare("UPDATE leads SET status='work',assignee_id=? WHERE id=?")->execute([$as,$id]);
          crm_event($id,$me['id'],'статус','Новый → В работе');
        }
        // Добавили «Не дозвонился» и нет напоминания → авто-перезвон через 2 часа, чтобы лид не потерялся.
        if(!$wasOn && $nc==='noanswer' && empty($L['next_action_at'])){
          $t=date('c',strtotime('+2 hours'));
          $db->prepare("UPDATE leads SET next_action_at=? WHERE id=?")->execute([$t,$id]);
          crm_event($id,$me['id'],'напоминание','перезвонить '.crm_dt($t));
        }
      }
    }
  } elseif($act==='remind'){
    $when=$_POST['when']??'';
    $ts=null;
    if($when==='clear'){ $ts=''; }
    elseif($when==='eve'){ $ts=date('c',strtotime('today 18:00')); }
    elseif($when==='tom'){ $ts=date('c',strtotime('tomorrow 10:00')); }
    elseif($when==='d3'){ $ts=date('c',strtotime('+3 days 10:00')); }
    elseif($when==='custom'){ $c=trim($_POST['dt']??''); $t=$c?strtotime($c):0; if($t) $ts=date('c',$t); }
    if($ts!==null){
      $db->prepare("UPDATE leads SET next_action_at=?,updated_at=? WHERE id=?")->execute([$ts,date('c'),$id]);
      crm_event($id,$me['id'],'напоминание',$ts?crm_dt($ts):'снято');
    }
  } elseif($act==='assign'){
    $uid=$_POST['uid']??'';
    if($uid===''){ // снять назначение
      $db->prepare("UPDATE leads SET assignee_id=NULL,updated_at=? WHERE id=?")->execute([date('c'),$id]);
      if($L['assignee_id']) crm_event($id,$me['id'],'назначение','снято');
    } else {
      $uid=(int)$uid;
      $chk=$db->prepare("SELECT name FROM users WHERE id=? AND active=1"); $chk->execute([$uid]); $nm=$chk->fetchColumn();
      if($nm && (int)$L['assignee_id']!==$uid){
        $db->prepare("UPDATE leads SET assignee_id=?,updated_at=? WHERE id=?")->execute([$uid,date('c'),$id]);
        crm_event($id,$me['id'],'назначение',$nm);
      }
    }
  } elseif($act==='comment'){
    $body=trim($_POST['body']??'');
    if($body!==''){ $db->prepare("INSERT INTO comments(lead_id,user_id,body,created_at) VALUES(?,?,?,?)")->execute([$id,$me['id'],$body,date('c')]); }
  } elseif($act==='delete'){
    if($me['role']!=='owner'){ http_response_code(403); exit('Удалять лиды может только владелец'); }
    crm_delete_lead($id);
    header('Location: index.php?deleted=1'); exit;
  }
  header('Location: view.php?id='.$id.'&ok=1'); exit; // PRG
}
if(isset($_GET['ok'])) $msg='Сохранено';

$comments=$db->prepare("SELECT c.*,u.name un FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE lead_id=? ORDER BY c.id DESC"); $comments->execute([$id]); $comments=$comments->fetchAll();
$events=$db->prepare("SELECT e.*,u.name un FROM events e LEFT JOIN users u ON u.id=e.user_id WHERE lead_id=? ORDER BY e.id DESC LIMIT 40"); $events->execute([$id]); $events=$events->fetchAll();
$related=[]; if(!empty($L['phone_norm'])){ $rs=$db->prepare("SELECT id,status FROM leads WHERE phone_norm=? AND id<>? ORDER BY id DESC LIMIT 20"); $rs->execute([$L['phone_norm'],$id]); $related=$rs->fetchAll(); }
$dig=crm_phone_digits($L['contact']);
$e164=crm_phone_e164($L['contact']); $digN=ltrim($e164,'+'); // +7XXXXXXXXXX и цифры для ссылок
$ch=$L['channel'];
$csrf=h(crm_csrf());
$activeUsers=$db->query("SELECT id,name,role FROM users WHERE active=1 ORDER BY role='owner' DESC, id")->fetchAll();
crm_head('Лид #'.$id); ?>
<style>
.lead-wrap{max-width:720px;margin:0 auto}
.statusrow{display:flex;flex-wrap:wrap;gap:8px;margin:0;align-items:center}
.spill{font-family:inherit;font-size:14px;padding:10px 15px;border-radius:22px;cursor:pointer;border:1px solid var(--line);background:transparent;color:var(--muted);transition:filter .1s}
.spill:hover{filter:brightness(1.25)}
.grouplbl{color:var(--muted);font-size:13px;margin-bottom:9px}
.gtag{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-right:2px}
.rowbreak{flex-basis:100%;height:0}
.clr{color:var(--muted);font-size:13px;background:none;border:0;cursor:pointer;text-decoration:underline;padding:4px 2px;font-family:inherit}
.remind-now{padding:8px 12px;border-radius:8px;font-size:14px;margin-bottom:12px}
.remind-set{background:#173a24;color:#8ff0b0}
.remind-over{background:#3a1717;color:#ffb0b0}
.dtin{padding:8px 10px}
.reqline{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:14px}
.reqline i{color:var(--muted);font-style:normal;margin-right:5px}
@media(max-width:760px){
  .lead-wrap .card{padding:14px}
  .lead-wrap h2{font-size:20px}
  .spill{font-size:15px;padding:11px 16px}            /* удобный тап */
  .statusrow{gap:9px}
  .dtin{flex:1 1 100%}                                 /* дата-время на всю ширину */
}
</style>
<div class="lead-wrap">
<p style="margin:0 0 14px"><a href="index.php" class="muted">← к списку</a></p>
<?php if($msg){ ?><div style="background:#173a24;color:#8ff0b0;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px"><?=h($msg)?></div><?php } ?>
<?php if($related){ ?><div style="background:#2a1e14;border:1px solid #ff8a5b;color:#ffbf94;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:13px">⚠ Повторный клиент — ещё <?=count($related)?> заявк<?=count($related)==1?'а':(count($related)<5?'и':'')?>: <?php foreach($related as $i=>$rl){ echo ($i?' · ':'').'<a href="view.php?id='.$rl['id'].'" style="color:#ffd6b0">#'.$rl['id'].'</a>'; } ?></div><?php } ?>

<!-- КОНТАКТ -->
<div class="card">
  <h2 style="margin:0 0 4px;font-size:22px"><?=h($L['name']?:'Без имени')?></h2>
  <div style="font-size:18px"><?=h($L['contact']?:'—')?><?php if($ch){ ?> <span class="muted" style="font-size:13px">· клиент выбрал: <b style="color:var(--ink)"><?=h(crm_channel_label($ch))?></b></span><?php } ?></div>
  <?php
    $waUrl = $digN ? 'https://wa.me/'.$digN : '';
    $tgUrl = preg_match('/@([A-Za-z0-9_]{4,})/u',$L['contact'],$m) ? 'https://t.me/'.$m[1] : ($digN ? 'tg://resolve?phone='.$digN : '');
    $hl = function($c) use($ch){ return crm_channel_norm($ch)===$c ? ' style="border-color:#f6871f;color:#f6871f;font-weight:700"' : ''; };
    $cj = h(json_encode($e164 ?: $L['contact'], JSON_UNESCAPED_UNICODE)); // копируем номер в +7XXXXXXXXXX (для МАКС), ник — как есть
  ?>
  <div class="statusrow" style="margin-top:12px">
    <?php if($dig){ ?><a class="spill" href="tel:<?=$e164?>">📞 Позвонить</a><?php } ?>
    <?php if($waUrl){ ?><a class="spill" href="<?=$waUrl?>" target="_blank" rel="noopener"<?=$hl('whatsapp')?>>WhatsApp</a><?php } ?>
    <?php if($tgUrl){ ?><a class="spill" href="<?=h($tgUrl)?>" target="_blank" rel="noopener"<?=$hl('telegram')?>>Telegram</a><?php } ?>
    <a class="spill" href="https://max.ru/" target="_blank" rel="noopener" onclick="crmCopy(<?=$cj?>)"<?=$hl('max')?> title="Откроет МАКС и скопирует номер — вставьте в поиск">МАКС</a>
    <button type="button" class="spill" onclick="crmCopy(<?=$cj?>);this.textContent='Скопировано ✓'">⧉ Копировать номер</button>
  </div>
</div>

<!-- ЗАПРОС (что нужно клиенту — чтобы собрать подборку) -->
<?php
$reqs=[['Назначение',$L['use_']],['Тип',$L['type']],['Грузоп.',$L['capacity']],['Бюджет',$L['budget']],['Сроки',$L['timing']],['Модели',$L['items']],['Источник',$L['source']]];
$reqs=array_filter($reqs, function($x){ return $x[1]!==''&&$x[1]!==null; });
if($reqs){ ?>
<div class="card">
  <div class="reqline"><?php foreach($reqs as $x){ ?><div><i><?=h($x[0])?></i><?=h($x[1])?></div><?php } ?></div>
</div>
<?php } ?>

<!-- КАК СВЯЗАЛИСЬ (канал) и СТАТУС СДЕЛКИ (воронка) — нажатие = сохранение -->
<?php $C=crm_contacts(); $ccList=crm_contact_list($L['call_status']); ?>
<div class="card">
  <div class="grouplbl">Как связались — можно отметить несколько:</div>
  <form method="post" class="statusrow" style="margin-bottom:16px">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="contact">
    <?php $lastG=''; foreach($C as $k=>$v){ if($v['g']!==$lastG){ if($lastG!=='') echo '<span class="rowbreak"></span>'; $lastG=$v['g']; ?><span class="gtag"><?=$v['g']==='msg'?'В мессенджере':'По телефону'?></span><?php } $active=in_array($k,$ccList,true); $col=crm_contact_color($k); ?>
      <button name="contact" value="<?=$k?>" class="spill"<?=$active?' style="background:'.$col.';color:#12181f;font-weight:800;border-color:'.$col.'"':''?> title="<?=$active?'нажми, чтобы убрать':'нажми, чтобы отметить'?>"><?=$active?'✓ ':''?><?=h($v['l'])?></button>
    <?php } if($ccList){ ?><button name="contact" value="" class="clr" title="сбросить все каналы">× сбросить всё</button><?php } ?>
  </form>
  <div class="grouplbl">Статус сделки — нажми:</div>
  <form method="post" class="statusrow">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="status">
    <?php foreach($ST as $k=>$v){ $active=$L['status']===$k; $col=crm_status_color($k); ?>
      <button name="status" value="<?=$k?>" class="spill"<?=$active?' style="background:'.$col.';color:#12181f;font-weight:800;border-color:'.$col.'"':''?>><?=h($v)?></button>
    <?php } ?>
  </form>

  <div class="grouplbl" style="margin-top:16px">Ответственный<?=$L['assignee_id']?'':' — не назначен'?>:</div>
  <form method="post" class="statusrow">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="assign">
    <?php foreach($activeUsers as $au){ $active=(int)$L['assignee_id']===(int)$au['id']; ?>
      <button name="uid" value="<?=$au['id']?>" class="spill"<?=$active?' style="background:#8b5cf6;color:#12181f;font-weight:800;border-color:#8b5cf6"':''?>><?=h($au['name'])?><?=$au['role']==='owner'?' ★':''?></button>
    <?php } ?>
    <?php if($L['assignee_id']){ ?><button name="uid" value="" class="clr" title="снять ответственного">× снять</button><?php } ?>
  </form>
</div>

<!-- НАПОМИНАНИЕ (следующий контакт) — чтобы лид не потерялся -->
<?php $na=$L['next_action_at']; $naTs=$na?strtotime($na):0; $overdue=$naTs && $naTs<time(); ?>
<div class="card">
  <div class="grouplbl">Следующий контакт:</div>
  <?php if($na){ ?><div class="remind-now <?=$overdue?'remind-over':'remind-set'?>"><?=$overdue?'⏰ Просрочено: ':'📅 Напомнить: '?><?=crm_dt($na)?><?=$overdue?' — пора связаться':''?></div><?php } ?>
  <form method="post" class="statusrow">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="remind">
    <button name="when" value="eve" class="spill">Сегодня вечером</button>
    <button name="when" value="tom" class="spill">Завтра</button>
    <button name="when" value="d3" class="spill">Через 3 дня</button>
    <input type="datetime-local" name="dt" class="dtin">
    <button name="when" value="custom" class="spill">Задать</button>
    <?php if($na){ ?><button name="when" value="clear" class="clr" title="убрать напоминание">× убрать</button><?php } ?>
  </form>
</div>

<!-- КОММЕНТАРИИ -->
<div class="card">
  <h3 style="margin:0 0 10px">Комментарии</h3>
  <form method="post" style="margin-bottom:8px">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="comment">
    <textarea name="body" rows="2" style="width:100%" placeholder="Что скинул, что ответил, договорённости…"></textarea>
    <div style="margin-top:8px"><button class="btn btn-b">Добавить</button></div>
  </form>
  <?php foreach($comments as $c){ ?><div class="cmt"><div><?=nl2br(h($c['body']))?></div><div class="m"><?=h($c['un']?:'?')?> · <?=crm_dt($c['created_at'])?></div></div><?php } ?>
  <?php if(!$comments){ ?><div class="muted" style="font-size:14px">Пока нет комментариев.</div><?php } ?>
</div>

<!-- ИСТОРИЯ (свёрнута) -->
<details style="margin-bottom:12px">
  <summary class="muted" style="cursor:pointer;font-size:13px;padding:4px 0">История изменений</summary>
  <div class="card" style="margin-top:8px">
    <?php foreach($events as $e){ ?><div class="cmt" style="padding:7px 0"><span class="pill"><?=h($e['type'])?></span> <?=h($e['detail'])?> <span class="m"> — <?=h($e['un']?:'?')?>, <?=crm_dt($e['created_at'])?></span></div><?php } ?>
    <?php if(!$events){ ?><div class="muted" style="font-size:14px">Действий ещё не было.</div><?php } ?>
  </div>
</details>

<div class="muted" style="font-size:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
  <span>ID <?=$L['id']?> · <?=crm_dt($L['created_at'])?> · IP <?=h($L['ip']?:'—')?></span>
  <?php if($me['role']==='owner'){ ?>
  <form method="post" onsubmit="return confirm('Удалить лид #<?=$id?> навсегда? Вместе с комментариями и историей.')" style="margin:0">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="delete">
    <button style="background:none;border:0;color:#c0392b;cursor:pointer;padding:0;font:inherit;font-size:12px;text-decoration:underline">удалить лид</button>
  </form>
  <?php } ?>
</div>
</div>
<?php crm_foot();
