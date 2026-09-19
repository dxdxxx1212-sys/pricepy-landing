<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
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
