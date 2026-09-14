<?php
// Фрагмент для поп-апа из списка лидов: история изменений + все комментарии (с фото).
// GET id=<lead> — вернуть фрагмент. POST (act=comment_edit|comment_delete, только владелец) — применить и вернуть обновлённый фрагмент.
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='comment_edit' || $act==='comment_delete'){ crm_process_comment_ops($me,$act); }
}

$Lr=$db->prepare("SELECT id,name FROM leads WHERE id=?"); $Lr->execute([$id]); $L=$Lr->fetch();
if(!$L){ http_response_code(404); header('Content-Type: text/html; charset=UTF-8'); exit('Лид не найден'); }

$comments=$db->prepare("SELECT c.*,u.name un FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE lead_id=? ORDER BY c.id DESC");
$comments->execute([$id]); $comments=$comments->fetchAll();
$events=$db->prepare("SELECT e.*,u.name un FROM events e LEFT JOIN users u ON u.id=e.user_id WHERE lead_id=? ORDER BY e.id DESC LIMIT 60");
$events->execute([$id]); $events=$events->fetchAll();
$csrf=h(crm_csrf()); $canManage=($me['role']==='owner');
header('Content-Type: text/html; charset=UTF-8');
?>
<div class="hist-head"><b><?=h($L['name']?:('Лид #'.$id))?></b> · <a href="view.php?id=<?=$id?>">открыть карточку →</a></div>
<div class="hist-sec">
  <div class="hist-lbl">💬 Комментарии</div>
  <?php if(!$comments){ ?><div class="muted" style="font-size:14px">Комментариев пока нет.</div><?php }
    foreach($comments as $c){ echo crm_comment_card_html($c, crm_comment_attachments($c['id']), $canManage, $csrf); } ?>
</div>
<div class="hist-sec">
  <div class="hist-lbl">🕘 История изменений</div>
  <?=crm_events_list_html($events)?>
</div>
