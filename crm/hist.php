<?php
// Фрагмент для поп-апа из списка лидов: быстрые действия + комментарии (с фото) + история изменений.
// GET id=<lead> — вернуть фрагмент. POST (act=...) — применить действие и вернуть обновлённый фрагмент.
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='comment_edit' || $act==='comment_delete'){ crm_process_comment_ops($me,$act); }   // только владелец (внутри)
  else { crm_process_lead_action($me,$id,$act); }                                              // статус/канал/перезвон/назначение/коммент
}

$Lr=$db->prepare("SELECT * FROM leads WHERE id=?"); $Lr->execute([$id]); $L=$Lr->fetch();
if(!$L){ http_response_code(404); header('Content-Type: text/html; charset=UTF-8'); exit('Лид не найден'); }

$ST=crm_statuses(); $C=crm_contacts(); $users=crm_users_map();
$ccList=crm_contact_list($L['call_status']);
$dig=crm_phone_digits($L['contact']); $e164=crm_phone_e164($L['contact']); $digN=ltrim($e164,'+');
$aid=(int)$L['assignee_id']; $na=$L['next_action_at'];
$comments=$db->prepare("SELECT c.*,u.name un FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE lead_id=? ORDER BY c.id DESC");
$comments->execute([$id]); $comments=$comments->fetchAll();
$events=$db->prepare("SELECT e.*,u.name un FROM events e LEFT JOIN users u ON u.id=e.user_id WHERE lead_id=? ORDER BY e.id DESC LIMIT 60");
$events->execute([$id]); $events=$events->fetchAll();
$csrf=h(crm_csrf()); $canManage=($me['role']==='owner');
header('Content-Type: text/html; charset=UTF-8');
?>
<div class="hist-head"><b><?=h($L['name']?:('Лид #'.$id))?></b> · <a href="view.php?id=<?=$id?>">открыть карточку →</a></div>

<div class="hist-quick">
  <div class="hq-contacts">
    <?php if($dig){ ?><a class="spill" href="tel:<?=$e164?>" title="Позвонить">📞</a><a class="spill" href="https://wa.me/<?=$digN?>" target="_blank" rel="noopener">WA</a><a class="spill" href="tg://resolve?phone=<?=$digN?>">TG</a><?php } ?>
    <?php if($dig){ ?><span class="cphone" data-c="<?=$e164?>" onclick="crmCopy(this)" title="Скопировать номер"><?=h(crm_phone_fmt($L['contact']))?></span><?php }else{ ?><span class="muted"><?=h($L['contact']?:'—')?></span><?php } ?>
  </div>

  <div class="hq-lbl">Как связались</div>
  <form method="post" class="hq-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="act" value="contact">
    <?php foreach($C as $k=>$v){ $on=in_array($k,$ccList,true); ?><button name="contact" value="<?=$k?>" class="spill<?=$on?' on':''?>"><?=$on?'✓ ':''?><?=h($v['l'])?></button><?php } ?>
    <?php if($ccList){ ?><button name="contact" value="" class="spill hq-clr">× сбросить</button><?php } ?>
  </form>

  <div class="hq-lbl">Статус</div>
  <form method="post" class="hq-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="act" value="status">
    <?php foreach($ST as $k=>$v){ $on=$L['status']===$k; ?><button name="status" value="<?=$k?>" class="spill<?=$on?' on':''?>"><?=h($v)?></button><?php } ?>
  </form>

  <div class="hq-lbl">Ответственный: <b><?=$aid && isset($users[$aid]) ? h($users[$aid]) : 'не назначен'?></b></div>
  <form method="post" class="hq-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="act" value="assign">
    <?php if($aid!==(int)$me['id']){ ?><button name="uid" value="<?=(int)$me['id']?>" class="spill">Взять себе</button><?php } ?>
    <?php if($aid){ ?><button name="uid" value="" class="spill hq-clr">× снять</button><?php } ?>
  </form>

  <div class="hq-lbl">Перезвон<?=$na?': <b>'.crm_dt($na).'</b>':''?></div>
  <form method="post" class="hq-form"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="act" value="remind">
    <button name="when" value="eve" class="spill">вечером</button><button name="when" value="tom" class="spill">завтра</button><button name="when" value="d3" class="spill">+3 дня</button>
    <?php if($na){ ?><button name="when" value="clear" class="spill hq-clr">× убрать</button><?php } ?>
  </form>

  <form method="post" class="hq-form hq-comment"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="act" value="comment">
    <textarea name="body" rows="2" placeholder="Быстрый комментарий…"></textarea>
    <button class="btn btn-b">Добавить</button>
  </form>
</div>

<div class="hist-sec">
  <div class="hist-lbl">💬 Комментарии</div>
  <?php if(!$comments){ ?><div class="muted" style="font-size:14px">Комментариев пока нет.</div><?php }
    foreach($comments as $c){ echo crm_comment_card_html($c, crm_comment_attachments($c['id']), $canManage, $csrf); } ?>
</div>
<div class="hist-sec">
  <div class="hist-lbl">🕘 История изменений</div>
  <?=crm_events_list_html($events)?>
</div>
