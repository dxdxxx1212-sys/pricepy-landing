<?php
require __DIR__.'/lib.php';
$me = crm_require_owner();
$db = crm_db();
$msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && crm_csrf_ok()){
  $act=$_POST['act']??'';
  if($act==='add'){
    $login=trim($_POST['login']??''); $name=trim($_POST['name']??'')?:$login; $pass=$_POST['pass']??''; $role=($_POST['role']??'operator')==='owner'?'owner':'operator';
    if(mb_strlen($login)<3||mb_strlen($pass)<10){ $err='Логин ≥3 и пароль ≥10 символов'; }
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
      else{
        $wasActive=(int)($t['active']??0);
        $db->prepare("UPDATE users SET active=1-active WHERE id=?")->execute([$uid]);
        if($wasActive){ $db->prepare("UPDATE leads SET assignee_id=NULL WHERE assignee_id=?")->execute([$uid]); $msg='Оператор отключён, его лиды сняты с назначения (в «Нераспределённые»)'; }
        else{ $msg='Оператор включён'; }
      }
    }
  } elseif($act==='feed'){
    $uid=(int)$_POST['uid'];
    $t=$db->prepare("SELECT role FROM users WHERE id=?"); $t->execute([$uid]); $role=$t->fetchColumn();
    if($role!=='operator'){ $err='Подача настраивается только для операторов'; }
    else{
      $share=max(0,min(100,(int)($_POST['share']??0)));      // доля потока 0..100
      $active=$share>0?1:0;                                   // подача включена, если доля > 0 (0 = выключено)
      $db->prepare("UPDATE users SET feed_share=?,feed_active=? WHERE id=?")->execute([$share,$active,$uid]);
      $msg=$share>0?('Подача обновлена: '.$share.'%'):'Подача выключена';
    }
  }
}
$list=$db->query("SELECT * FROM users ORDER BY id")->fetchAll();
// сколько потока уходит операторам автоматически (сумма долей активных операторов с включённой подачей, потолок 100)
$feedSum=0; foreach($list as $u){ if($u['role']==='operator' && $u['active'] && (int)($u['feed_active']??0)===1) $feedSum+=(int)($u['feed_share']??0); }
$feedSum=min(100,$feedSum); $manual=100-$feedSum;
$csrf=h(crm_csrf());
crm_head('Операторы'); ?>
<div class="card" style="margin-bottom:14px">
  <div style="font-weight:700;margin-bottom:4px">Авто-подача новых лидов</div>
  <div style="font-size:14px;color:var(--muted2)">Из каждых 100 заявок с сайта: <b style="color:var(--ink)"><?=$feedSum?>%</b> уходит операторам автоматически по долям ниже, <b style="color:var(--ink)"><?=$manual?>%</b> остаётся вам в «Нераспределённых» (раздаёте вручную). Меняется в лайве — действует со следующей заявки.</div>
</div>
<div class="grid2">
  <div class="card" style="padding:0;overflow-x:auto">
    <table><thead><tr><th>#</th><th>Имя</th><th>Логин</th><th>Роль</th><th>Статус</th><th>Подача лидов</th><th></th></tr></thead><tbody>
    <?php foreach($list as $u){ ?><tr>
      <td class="muted"><?=$u['id']?></td><td><?=h($u['name'])?></td><td class="muted"><?=h($u['login'])?></td>
      <td><?=$u['role']==='owner'?'владелец':'оператор'?></td>
      <td><?=$u['active']?'<span style="color:#5fd08a">активен</span>':'<span class="muted">отключён</span>'?></td>
      <td><?php if($u['role']==='operator'){ ?><form method="post" style="display:flex;align-items:center;gap:6px;margin:0">
          <input type="hidden" name="csrf" value="<?=$csrf?>"><input type="hidden" name="act" value="feed"><input type="hidden" name="uid" value="<?=$u['id']?>">
          <input type="number" name="share" min="0" max="100" value="<?=(int)($u['feed_share']??0)?>" style="width:62px;padding:6px 8px" title="Доля потока новых лидов в % (0 = выключено)"><span class="muted" style="font-size:13px">%</span>
          <button class="btn btn-sec" style="padding:5px 10px">OK</button>
        </form><?php }else{ ?><span class="muted" title="Владелец получает нераспределённый остаток">—</span><?php } ?></td>
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
      <div style="margin-bottom:9px"><input name="login" placeholder="Логин (латиницей, без пробелов)" style="width:100%" required autocapitalize="off" autocorrect="off" spellcheck="false"></div>
      <div style="margin-bottom:9px"><input name="pass" type="password" placeholder="Пароль (≥10)" style="width:100%" required></div>
      <div style="margin-bottom:12px"><select name="role" style="width:100%"><option value="operator">Оператор</option><option value="owner">Владелец</option></select></div>
      <button class="btn">Добавить</button>
    </form>
  </div>
</div>
<?php crm_foot();
