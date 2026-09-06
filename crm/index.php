<?php
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$ST = crm_statuses(); $CS = crm_call_statuses(); $users = crm_users_map();

// фильтры
$fStatus = $_GET['status'] ?? '';
$fSource = $_GET['source'] ?? '';
$fAssignee = $_GET['assignee'] ?? '';
$q = trim($_GET['q'] ?? '');
$fDue = $_GET['due'] ?? '';
$where=[]; $args=[];
if($fStatus!==''){ $where[]='status=?'; $args[]=$fStatus; }
if($fSource!==''){ $where[]='source=?'; $args[]=$fSource; }
if($fAssignee!==''){ $where[]='assignee_id=?'; $args[]=(int)$fAssignee; }
if($fDue==='today'){ $where[]="(next_action_at<>'' AND substr(next_action_at,1,10)<=? AND status NOT IN('won','lost'))"; $args[]=date('Y-m-d'); }
if($q!==''){ $where[]='(name LIKE ? OR contact LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
$st=$db->prepare("SELECT * FROM leads $wsql ORDER BY id DESC LIMIT 300");
$st->execute($args); $rows=$st->fetchAll();
$tc=$db->prepare("SELECT COUNT(*) c FROM leads $wsql"); $tc->execute($args); $total=(int)$tc->fetch()['c'];

// KPI
$today = date('Y-m-d');
$k_today = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE created_at LIKE '".$today."%'")->fetch()['c'];
$kw = $db->prepare("SELECT COUNT(*) c FROM leads WHERE created_at>=?");
$kw->execute([date('c',strtotime('-7 days'))]); $k_week=(int)$kw->fetch()['c'];
$k_new   = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='new'")->fetch()['c'];
$k_won   = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='won'")->fetch()['c'];
$k_total = (int)$db->query("SELECT COUNT(*) c FROM leads")->fetch()['c'];
$k_due   = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE next_action_at<>'' AND substr(next_action_at,1,10)<='".date('Y-m-d')."' AND status NOT IN('won','lost')")->fetch()['c'];
$sources = $db->query("SELECT DISTINCT source FROM leads WHERE source<>'' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

crm_head('Лиды'); ?>
<div class="kpi">
  <div class="k"><b><?=$k_today?></b><span>сегодня</span></div>
  <div class="k"><b><?=$k_week?></b><span>за 7 дней</span></div>
  <div class="k"><b style="color:var(--acc)"><?=$k_new?></b><span>новых (не в работе)</span></div>
  <a class="k" href="?due=today" style="text-decoration:none"><b style="color:#ff8a5b"><?=$k_due?></b><span>перезвонить сегодня</span></a>
  <div class="k"><b style="color:#5fd08a"><?=$k_won?></b><span>продажи</span></div>
  <div class="k"><b><?=$k_total?></b><span>всего</span></div>
</div>

<form class="filters" method="get">
  <select name="status" onchange="this.form.submit()"><option value="">Все статусы</option>
    <?php foreach($ST as $k=>$v){ ?><option value="<?=$k?>" <?=$fStatus===$k?'selected':''?>><?=h($v)?></option><?php } ?></select>
  <select name="source" onchange="this.form.submit()"><option value="">Все источники</option>
    <?php foreach($sources as $s){ ?><option value="<?=h($s)?>" <?=$fSource===$s?'selected':''?>><?=h($s)?></option><?php } ?></select>
  <select name="assignee" onchange="this.form.submit()"><option value="">Все операторы</option>
    <?php foreach($users as $id=>$nm){ ?><option value="<?=$id?>" <?=$fAssignee==$id?'selected':''?>><?=h($nm)?></option><?php } ?></select>
  <?php if($fDue==='today'){ ?><input type="hidden" name="due" value="today"><span class="pill" style="background:#ff8a5b;color:#12181f">перезвонить сегодня</span><?php } ?>
  <input name="q" value="<?=h($q)?>" placeholder="Поиск: имя или телефон" style="min-width:200px">
  <button class="btn btn-sec">Найти</button>
  <?php if($fStatus||$fSource||$fAssignee||$q||$fDue){ ?><a href="index.php" class="muted">сбросить</a><?php } ?>
  <span class="sp" style="flex:1"></span>
  <span class="muted"><?=$total?> шт.<?=$total>300?' (показаны 300)':''?></span>
</form>

<div class="card" style="padding:0;overflow-x:auto">
<table>
<thead><tr><th>#</th><th>Дата</th><th>Имя / контакт</th><th>Запрос</th><th>Источник</th><th>Статус</th><th>Звонок</th><th>Оператор</th></tr></thead>
<tbody>
<?php foreach($rows as $r){ $d=crm_phone_digits($r['contact']); ?>
<tr onclick="location='view.php?id=<?=$r['id']?>'" style="cursor:pointer">
  <td class="muted"><?=$r['id']?></td>
  <td class="muted" style="white-space:nowrap"><?=crm_dt($r['created_at'])?></td>
  <td><b><?=h($r['name']?:'—')?></b><br><span class="muted"><?=h($r['contact'])?></span>
    <?php if($d){ ?> <a href="tel:+<?=$d?>" onclick="event.stopPropagation()">📞</a> <a href="https://wa.me/<?=$d?>" target="_blank" onclick="event.stopPropagation()">wa</a><?php } ?>
  </td>
  <td class="muted" style="max-width:220px"><?php $req=array_filter([$r['use_'],$r['type'],$r['capacity'],$r['budget'],$r['items']]); echo h(implode(' · ',$req)); ?></td>
  <td><span class="pill"><?=h($r['source']?:'—')?></span></td>
  <td><span class="badge" style="background:<?=crm_status_color($r['status'])?>"><?=h($ST[$r['status']]??$r['status'])?></span></td>
  <td class="muted"><?=h($CS[$r['call_status']]??'')?></td>
  <td class="muted"><?=h($r['assignee_id']?($users[$r['assignee_id']]??'?'):'—')?></td>
</tr>
<?php } if(!$rows){ ?><tr><td colspan="8" class="muted" style="padding:24px;text-align:center">Лидов пока нет. Как только придёт заявка с сайта — появится здесь.</td></tr><?php } ?>
</tbody></table>
</div>
<?php crm_foot();
