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
$ch=$L['channel'];
$chName=['whatsapp'=>'WhatsApp','telegram'=>'Telegram','max'=>'МАКС','phone'=>'по телефону'];
$csrf=h(crm_csrf());
crm_head('Лид #'.$id); ?>
<style>
.lead-wrap{max-width:720px;margin:0 auto}
.statusrow{display:flex;flex-wrap:wrap;gap:8px;margin:0}
.spill{font-family:inherit;font-size:14px;padding:10px 15px;border-radius:22px;cursor:pointer;border:1px solid var(--line);background:transparent;color:var(--muted);transition:filter .1s}
.spill:hover{filter:brightness(1.25)}
.reqline{display:flex;flex-wrap:wrap;gap:6px 18px;font-size:14px}
.reqline i{color:var(--muted);font-style:normal;margin-right:5px}
</style>
<div class="lead-wrap">
<p style="margin:0 0 14px"><a href="index.php" class="muted">← к списку</a></p>
<?php if($msg){ ?><div style="background:#173a24;color:#8ff0b0;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px"><?=h($msg)?></div><?php } ?>
<?php if($related){ ?><div style="background:#2a1e14;border:1px solid #ff8a5b;color:#ffbf94;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:13px">⚠ Повторный клиент — ещё <?=count($related)?> заявк<?=count($related)==1?'а':(count($related)<5?'и':'')?>: <?php foreach($related as $i=>$rl){ echo ($i?' · ':'').'<a href="view.php?id='.$rl['id'].'" style="color:#ffd6b0">#'.$rl['id'].'</a>'; } ?></div><?php } ?>

<!-- КОНТАКТ -->
<div class="card">
  <h2 style="margin:0 0 4px;font-size:22px"><?=h($L['name']?:'Без имени')?></h2>
  <div style="font-size:18px;margin-bottom:12px"><?=h($L['contact']?:'—')?><?php if($ch){ ?> <span class="muted" style="font-size:13px">· выбрал: <?=h($chName[$ch]??$ch)?></span><?php } ?></div>
  <?php if($dig){ ?>
  <div style="display:flex;flex-wrap:wrap;gap:8px">
    <a class="btn btn-sec" href="tel:+<?=$dig?>">📞 Позвонить</a>
    <a class="btn btn-sec" href="https://wa.me/<?=$dig?>" target="_blank">WhatsApp</a>
    <a class="btn btn-sec" href="https://t.me/+<?=$dig?>" target="_blank">Telegram</a>
  </div>
  <?php } ?>
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

<!-- СТАТУС (одно действие, нажатие = сохранение) -->
<div class="card">
  <div class="muted" style="font-size:13px;margin-bottom:9px">Статус — нажми, чтобы сменить:</div>
  <form method="post" class="statusrow">
    <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="status">
    <?php foreach($ST as $k=>$v){ $active=$L['status']===$k; $col=crm_status_color($k); ?>
      <button name="status" value="<?=$k?>" class="spill"<?=$active?' style="background:'.$col.';color:#12181f;font-weight:800;border-color:'.$col.'"':''?>><?=h($v)?></button>
    <?php } ?>
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
