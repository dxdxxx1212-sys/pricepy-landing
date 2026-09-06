<?php
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$ST = crm_statuses(); $CS = crm_call_statuses(); $users = crm_users_map();
$id = (int)($_GET['id'] ?? 0);
$get = function() use($db,$id){ $s=$db->prepare("SELECT * FROM leads WHERE id=?"); $s->execute([$id]); return $s->fetch(); };
$L = $get();
if(!$L){ crm_head('Лид'); echo '<div class="card">Лид не найден. <a href="index.php">← к списку</a></div>'; crm_foot(); exit; }
$msg='';

if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='save'){
    $new=[
      'status'=>$_POST['status']??$L['status'],
      'call_status'=>$_POST['call_status']??$L['call_status'],
      'assignee_id'=>($_POST['assignee_id']??'')!==''?(int)$_POST['assignee_id']:null,
      'next_action_at'=>trim($_POST['next_action_at']??''),
      'model'=>trim($_POST['model']??''),
      'sale_amount'=>trim($_POST['sale_amount']??''),
      'reject_reason'=>trim($_POST['reject_reason']??''),
    ];
    $db->prepare("UPDATE leads SET status=?,call_status=?,assignee_id=?,next_action_at=?,model=?,sale_amount=?,reject_reason=?,updated_at=? WHERE id=?")
       ->execute([$new['status'],$new['call_status'],$new['assignee_id'],$new['next_action_at'],$new['model'],$new['sale_amount'],$new['reject_reason'],date('c'),$id]);
    if($new['status']!==$L['status']) crm_event($id,$me['id'],'status',($ST[$L['status']]??$L['status']).' → '.($ST[$new['status']]??$new['status']));
    if($new['call_status']!==$L['call_status']) crm_event($id,$me['id'],'call',$CS[$new['call_status']]??$new['call_status']);
  } elseif($act==='comment'){
    $body=trim($_POST['body']??'');
    if($body!==''){ $db->prepare("INSERT INTO comments(lead_id,user_id,body,created_at) VALUES(?,?,?,?)")->execute([$id,$me['id'],$body,date('c')]); }
  }
  header('Location: view.php?id='.$id.'&ok=1'); exit; // PRG: защита от повторной отправки по F5
}
if(isset($_GET['ok'])) $msg='Сохранено';

$comments=$db->prepare("SELECT c.*,u.name un FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE lead_id=? ORDER BY c.id DESC"); $comments->execute([$id]); $comments=$comments->fetchAll();
$events=$db->prepare("SELECT e.*,u.name un FROM events e LEFT JOIN users u ON u.id=e.user_id WHERE lead_id=? ORDER BY e.id DESC LIMIT 40"); $events->execute([$id]); $events=$events->fetchAll();
$dig=crm_phone_digits($L['contact']);
$csrf=h(crm_csrf());
crm_head('Лид #'.$id); ?>
<p style="margin:0 0 14px"><a href="index.php" class="muted">← к списку</a></p>
<?php if($msg){ ?><div style="background:#173a24;color:#8ff0b0;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px"><?=h($msg)?></div><?php } ?>

<div class="grid2">
  <div>
    <!-- обработка -->
    <div class="card">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <span class="badge" style="background:<?=crm_status_color($L['status'])?>;font-size:14px"><?=h($ST[$L['status']]??$L['status'])?></span>
        <h2 style="margin:0;font-size:20px"><?=h($L['name']?:'Без имени')?></h2>
        <span class="sp" style="flex:1"></span>
        <?php if($dig){ ?><a class="btn btn-sec" href="tel:+<?=$dig?>">📞 Позвонить</a> <a class="btn btn-sec" href="https://wa.me/<?=$dig?>" target="_blank">WhatsApp</a><?php } ?>
      </div>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="save">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <label>Этап<br><select name="status" style="width:100%"><?php foreach($ST as $k=>$v){ ?><option value="<?=$k?>" <?=$L['status']===$k?'selected':''?>><?=h($v)?></option><?php } ?></select></label>
          <label>Звонок<br><select name="call_status" style="width:100%"><?php foreach($CS as $k=>$v){ ?><option value="<?=$k?>" <?=$L['call_status']===$k?'selected':''?>><?=h($v)?></option><?php } ?></select></label>
          <label>Оператор<br><select name="assignee_id" style="width:100%"><option value="">— не назначен</option><?php foreach($users as $uid=>$un){ ?><option value="<?=$uid?>" <?=$L['assignee_id']==$uid?'selected':''?>><?=h($un)?></option><?php } ?></select></label>
          <label>Перезвонить (дата/время)<br><input type="datetime-local" name="next_action_at" value="<?=h($L['next_action_at'])?>" style="width:100%"></label>
          <label>Модель прицепа<br><input name="model" value="<?=h($L['model'])?>" placeholder="если выбрали" style="width:100%"></label>
          <label>Сумма сделки, ₽<br><input name="sale_amount" value="<?=h($L['sale_amount'])?>" placeholder="если продали" style="width:100%"></label>
        </div>
        <label style="display:block;margin-top:12px">Причина отказа (если «Отказ»)<br><input name="reject_reason" value="<?=h($L['reject_reason'])?>" placeholder="дорого / передумал / нет в наличии…" style="width:100%"></label>
        <div style="margin-top:14px"><button class="btn">Сохранить</button></div>
      </form>
    </div>

    <!-- комментарии -->
    <div class="card">
      <h3 style="margin:0 0 10px">Комментарии</h3>
      <form method="post" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="comment">
        <textarea name="body" rows="2" style="width:100%" placeholder="Что обсудили, договорённости…"></textarea>
        <div style="margin-top:8px"><button class="btn btn-b">Добавить</button></div>
      </form>
      <?php foreach($comments as $c){ ?><div class="cmt"><div><?=nl2br(h($c['body']))?></div><div class="m"><?=h($c['un']?:'?')?> · <?=crm_dt($c['created_at'])?></div></div><?php } ?>
      <?php if(!$comments){ ?><div class="muted" style="font-size:14px">Пока нет комментариев.</div><?php } ?>
    </div>

    <!-- история -->
    <div class="card">
      <h3 style="margin:0 0 10px">История</h3>
      <?php foreach($events as $e){ ?><div class="cmt" style="padding:7px 0"><span class="pill"><?=h($e['type'])?></span> <?=h($e['detail'])?> <span class="m"> — <?=h($e['un']?:'?')?>, <?=crm_dt($e['created_at'])?></span></div><?php } ?>
      <?php if(!$events){ ?><div class="muted" style="font-size:14px">Действий ещё не было.</div><?php } ?>
    </div>
  </div>

  <!-- сайдбар: данные лида -->
  <div>
    <div class="card">
      <h3 style="margin:0 0 12px">Заявка</h3>
      <dl class="dl">
        <dt>Контакт</dt><dd><?=h($L['contact']?:'—')?></dd>
        <dt>Канал</dt><dd><?=h($L['channel']?:'—')?></dd>
        <dt>Назначение</dt><dd><?=h($L['use_']?:'—')?></dd>
        <dt>Грузоп.</dt><dd><?=h($L['capacity']?:'—')?></dd>
        <dt>Тип</dt><dd><?=h($L['type']?:'—')?></dd>
        <dt>Бюджет</dt><dd><?=h($L['budget']?:'—')?></dd>
        <dt>Сроки</dt><dd><?=h($L['timing']?:'—')?></dd>
        <?php if($L['items']){ ?><dt>Модели</dt><dd><?=h($L['items'])?></dd><?php } ?>
        <dt>Источник</dt><dd><span class="pill"><?=h($L['source']?:'—')?></span></dd>
        <dt>Создан</dt><dd><?=crm_dt($L['created_at'])?></dd>
      </dl>
    </div>
    <?php if($L['utm_source']||$L['utm_campaign']||$L['gclid']||$L['yclid']){ ?>
    <div class="card"><h3 style="margin:0 0 12px">Реклама (UTM)</h3><dl class="dl">
      <dt>source</dt><dd><?=h($L['utm_source']?:'—')?></dd>
      <dt>medium</dt><dd><?=h($L['utm_medium']?:'—')?></dd>
      <dt>campaign</dt><dd><?=h($L['utm_campaign']?:'—')?></dd>
      <dt>content</dt><dd><?=h($L['utm_content']?:'—')?></dd>
      <dt>term</dt><dd><?=h($L['utm_term']?:'—')?></dd>
      <?php if($L['gclid']){ ?><dt>gclid</dt><dd><?=h($L['gclid'])?></dd><?php } ?>
      <?php if($L['yclid']){ ?><dt>yclid</dt><dd><?=h($L['yclid'])?></dd><?php } ?>
    </dl></div><?php } ?>
    <div class="card"><span class="muted" style="font-size:12px">ID <?=$L['id']?> · IP <?=h($L['ip']?:'—')?></span></div>
  </div>
</div>
<?php crm_foot();
