<?php
require __DIR__.'/lib.php';
if(crm_user()){ header('Location: index.php'); exit; }
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!crm_csrf_ok()){ $err='Сессия истекла, попробуйте снова'; }
  elseif(!crm_login_throttle()){ $err='Слишком много попыток. Подождите 15 минут.'; }
  elseif(crm_login($_POST['login']??'', $_POST['pass']??'')){ header('Location: index.php'); exit; }
  else { crm_login_fail(); $err='Неверный логин или пароль'; usleep(600000); }
}
crm_head('Вход'); ?>
<div style="max-width:340px;margin:8vh auto 0">
  <div class="card">
    <h2 style="margin:0 0 14px">Вход в CRM</h2>
    <?php if($err){ ?><div style="background:#3a1d1d;color:#ffb4b4;padding:9px 12px;border-radius:8px;margin-bottom:12px;font-size:14px"><?=h($err)?></div><?php } ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h(crm_csrf())?>">
      <div style="margin-bottom:10px"><input name="login" placeholder="Логин" style="width:100%" autofocus></div>
      <div style="margin-bottom:14px"><input name="pass" type="password" placeholder="Пароль" style="width:100%"></div>
      <button class="btn" style="width:100%">Войти</button>
    </form>
  </div>
</div>
<?php crm_foot();
