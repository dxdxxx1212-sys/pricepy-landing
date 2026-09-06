<?php
require __DIR__.'/lib.php';
$me = crm_require_owner();
$db = crm_db();
$msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='add'){
    $login=trim($_POST['login']??''); $name=trim($_POST['name']??'')?:$login; $pass=$_POST['pass']??''; $role=($_POST['role']??'operator')==='owner'?'owner':'operator';
    if(mb_strlen($login)<3||mb_strlen($pass)<8){ $err='Логин ≥3 и пароль ≥8 символов'; }
    else{ try{ $db->prepare("INSERT INTO users(login,pass_hash,name,role,active,created_at) VALUES(?,?,?,?,1,?)")
      ->execute([$login,password_hash($pass,PASSWORD_DEFAULT),$name,$role,date('c')]); $msg='Пользователь добавлен'; }
      catch(Throwable $e){ $err='Такой логин уже есть'; } }
  } elseif($act==='toggle'){
    $uid=(int)$_POST['uid'];
    if($uid===$me['id']){ $err='Нельзя менять свой статус'; }
    else{
      $t=$db->prepare("SELECT role,active FROM users WHERE id=?"); $t->execute([$uid]); $t=$t->fetch();
      $owners=(int)$db->query("SELECT COUNT(*) c FROM users WHERE role='owner' AND active=1")->fetch()['c'];
      if($t && $t['role']==='owner' && $t['active'] && $owners<=1){ $err='Нельзя отключить последнего владельца'; }
      else{ $db->prepare("UPDATE users SET active=1-active WHERE id=?")->execute([$uid]); $msg='Готово'; }
    }
  }
}
$list=$db->query("SELECT * FROM users ORDER BY id")->fetchAll();
$csrf=h(crm_csrf());
crm_head('Операторы'); ?>
<div class="grid2">
  <div class="card" style="padding:0;overflow-x:auto">
    <table><thead><tr><th>#</th><th>Имя</th><th>Логин</th><th>Роль</th><th>Статус</th><th></th></tr></thead><tbody>
    <?php foreach($list as $u){ ?><tr>
      <td class="muted"><?=$u['id']?></td><td><?=h($u['name'])?></td><td class="muted"><?=h($u['login'])?></td>
      <td><?=$u['role']==='owner'?'владелец':'оператор'?></td>
      <td><?=$u['active']?'<span style="color:#5fd08a">активен</span>':'<span class="muted">отключён</span>'?></td>
      <td class="right"><?php if($u['id']!==$me['id']){ ?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="uid" value="<?=$u['id']?>"><button class="btn btn-sec" style="padding:5px 10px"><?=$u['active']?'отключить':'включить'?></button></form><?php } ?></td>
    </tr><?php } ?></tbody></table>
  </div>
  <div class="card">
    <h3 style="margin:0 0 10px">Добавить оператора</h3>
    <?php if($err){ ?><div style="background:#3a1d1d;color:#ffb4b4;padding:8px 11px;border-radius:8px;margin-bottom:10px;font-size:13px"><?=h($err)?></div><?php } ?>
    <?php if($msg){ ?><div style="background:#173a24;color:#8ff0b0;padding:8px 11px;border-radius:8px;margin-bottom:10px;font-size:13px"><?=h($msg)?></div><?php } ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="add">
      <div style="margin-bottom:9px"><input name="name" placeholder="Имя" style="width:100%"></div>
      <div style="margin-bottom:9px"><input name="login" placeholder="Логин" style="width:100%" required></div>
      <div style="margin-bottom:9px"><input name="pass" type="password" placeholder="Пароль (≥8)" style="width:100%" required></div>
      <div style="margin-bottom:12px"><select name="role" style="width:100%"><option value="operator">Оператор</option><option value="owner">Владелец</option></select></div>
      <button class="btn">Добавить</button>
    </form>
  </div>
</div>
<?php crm_foot();
