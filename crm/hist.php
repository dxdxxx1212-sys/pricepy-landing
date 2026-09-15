<?php
// Фрагмент для поп-апа из списка лидов: ТОЛЬКО комментарии с фото (быстрый просмотр).
// Действия (статус/канал/перезвон/назначение) и история — в карточке лида (view.php), чтобы не дублировать.
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='comment_edit' || $act==='comment_delete'){ crm_process_comment_ops($me,$act); } // правка/удаление — только владелец (проверка внутри)
}

$Lr=$db->prepare("SELECT id,name FROM leads WHERE id=?"); $Lr->execute([$id]); $L=$Lr->fetch();
if(!$L){ http_response_code(404); header('Content-Type: text/html; charset=UTF-8'); exit('Лид не найден'); }

$comments=$db->prepare("SELECT c.*,u.name un FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE lead_id=? ORDER BY c.id DESC");
$comments->execute([$id]); $comments=$comments->fetchAll();
$csrf=h(crm_csrf()); $canManage=($me['role']==='owner');
header('Content-Type: text/html; charset=UTF-8');
?>
<div class="hist-head"><b><?=h($L['name']?:('Лид #'.$id))?></b> · <a href="view.php?id=<?=$id?>">открыть карточку →</a></div>
<div class="hist-sec">
  <div class="hist-lbl">💬 Комментарии и фото</div>
  <?php if(!$comments){ ?><div class="muted" style="font-size:14px">Комментариев и фото пока нет. <a href="view.php?id=<?=$id?>">Добавить в карточке →</a></div><?php }
    foreach($comments as $c){ echo crm_comment_card_html($c, crm_comment_attachments($c['id']), $canManage, $csrf); } ?>
</div>
