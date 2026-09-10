<?php
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$ST = crm_statuses(); $users = crm_users_map();

// фильтры
$fStatus = $_GET['status'] ?? '';
$fCall = $_GET['call'] ?? '';
$fSource = $_GET['source'] ?? '';
$fDue = isset($_GET['due']);
$q = trim($_GET['q'] ?? '');
$fMine = isset($_GET['mine']);
$fUnassigned = isset($_GET['unassigned']);
$tomorrow = date('c', strtotime('tomorrow')); // граница «на сегодня» = всё, что до начала завтра
$where=[]; $args=[];
if($fStatus!==''){ $where[]='status=?'; $args[]=$fStatus; }
if($fCall!==''){ $where[]="(','||call_status||',') LIKE ?"; $args[]='%,'.$fCall.',%'; } // членство в наборе каналов
if($fSource!==''){ $where[]='source=?'; $args[]=$fSource; }
if($fDue){ $where[]="next_action_at<>'' AND next_action_at<? AND status NOT IN('won','lost')"; $args[]=$tomorrow; }
if($fMine){ $where[]='assignee_id=?'; $args[]=(int)$me['id']; }
if($fUnassigned){ $where[]='(assignee_id IS NULL OR assignee_id=0)'; }
if($q!==''){
  $qd=preg_replace('/\D+/','',$q); // цифры номера — поиск по телефону в любом формате
  if($qd!==''){ $where[]='(name LIKE ? OR contact LIKE ? OR phone_norm LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$qd%"; }
  else { $where[]='(name LIKE ? OR contact LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
}
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
$tc=$db->prepare("SELECT COUNT(*) c FROM leads $wsql"); $tc->execute($args); $total=(int)$tc->fetch()['c'];
$per=100; $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)($_GET['page']??1))); $off=($page-1)*$per;
$order = $fDue ? 'next_action_at ASC' : 'created_at DESC, id DESC'; // по дате заявки (не по порядку добавления — иначе импорт «прыгает»)
$st=$db->prepare("SELECT * FROM leads $wsql ORDER BY $order LIMIT $per OFFSET $off");
$st->execute($args); $rows=$st->fetchAll();
$maxId=(int)$db->query("SELECT COALESCE(MAX(id),0) m FROM leads")->fetch()['m']; // для сигнала о новом лиде

// KPI — минимум для работы
$k_new      = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='new'")->fetch()['c'];
$kd=$db->prepare("SELECT COUNT(*) c FROM leads WHERE next_action_at<>'' AND next_action_at<? AND status NOT IN('won','lost')"); $kd->execute([$tomorrow]); $k_due=(int)$kd->fetch()['c'];
$k_noanswer = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE (','||call_status||',') LIKE '%,noanswer,%'")->fetch()['c'];
$k_won      = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='won'")->fetch()['c'];
$k_total    = (int)$db->query("SELECT COUNT(*) c FROM leads")->fetch()['c'];
$sources = $db->query("SELECT DISTINCT source FROM leads WHERE source<>'' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

// карта дублей по нормализованному телефону: phone_norm => [первый id, сколько всего]
$dups=[];
foreach($db->query("SELECT phone_norm, MIN(id) mn, COUNT(*) c FROM leads WHERE phone_norm<>'' GROUP BY phone_norm HAVING c>1") as $d){
  $dups[$d['phone_norm']]=['mn'=>(int)$d['mn'],'c'=>(int)$d['c']];
}

crm_head('Лиды'); ?>
<?php if(isset($_GET['deleted'])){ ?><div style="background:#173a24;color:#8ff0b0;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px">Лид удалён.</div><?php } ?>
<div class="kpi">
  <a class="k" href="?due=1" style="text-decoration:none;border-color:<?=$k_due?'#ff8a5b':'var(--line)'?>"><b style="color:<?=$k_due?'#ff8a5b':'#5fd08a'?>"><?=$k_due?></b><span>на сегодня</span></a>
  <a class="k" href="?status=new" style="text-decoration:none;border-color:<?=$k_new?'var(--acc)':'var(--line)'?>"><b style="color:var(--acc)"><?=$k_new?></b><span>новых</span></a>
  <a class="k" href="?call=noanswer" style="text-decoration:none"><b style="color:#9aa2ab"><?=$k_noanswer?></b><span>не дозвонился</span></a>
  <div class="k" style="opacity:.75"><b style="color:#5fd08a"><?=$k_won?></b><span>продажи</span></div>
  <div class="k" style="opacity:.75"><b><?=$k_total?></b><span>всего</span></div>
</div>

<?php $chipOn='background:var(--acc);color:#12181f;font-weight:700'; ?>
<div class="filters" style="margin-bottom:8px">
  <a class="pill" href="index.php" style="<?=(!$fMine&&!$fUnassigned)?$chipOn:''?>">Все лиды</a>
  <a class="pill" href="?mine=1" style="<?=$fMine?$chipOn:''?>">Мои лиды</a>
  <a class="pill" href="?unassigned=1" style="<?=$fUnassigned?$chipOn:''?>">Нераспределённые</a>
  <a class="pill" href="?due=1" style="<?=$fDue?$chipOn:''?>">На сегодня<?=$k_due?' · '.$k_due:''?></a>
</div>

<form class="filters" method="get">
  <?php if($fMine){ ?><input type="hidden" name="mine" value="1"><?php } ?>
  <?php if($fUnassigned){ ?><input type="hidden" name="unassigned" value="1"><?php } ?>
  <select name="status" onchange="this.form.submit()"><option value="">Все статусы</option>
    <?php foreach($ST as $k=>$v){ ?><option value="<?=$k?>" <?=$fStatus===$k?'selected':''?>><?=h($v)?></option><?php } ?></select>
  <select name="call" onchange="this.form.submit()"><option value="">Любой канал</option>
    <?php foreach(crm_contacts() as $k=>$v){ ?><option value="<?=$k?>" <?=$fCall===$k?'selected':''?>><?=h($v['l'])?></option><?php } ?></select>
  <select name="source" onchange="this.form.submit()"><option value="">Все источники</option>
    <?php foreach($sources as $s){ ?><option value="<?=h($s)?>" <?=$fSource===$s?'selected':''?>><?=h($s)?></option><?php } ?></select>
  <input name="q" value="<?=h($q)?>" placeholder="Поиск: имя или телефон (любой формат)" style="min-width:220px">
  <button class="btn btn-sec">Найти</button>
  <?php if($fStatus||$fCall||$fSource||$q||$fMine||$fUnassigned||$fDue){ ?><a href="index.php" class="muted">сбросить</a><?php } ?>
  <span class="sp" style="flex:1"></span>
  <span class="muted"><?=$total?> шт.<?=$pages>1?' · стр. '.$page.'/'.$pages:''?></span>
</form>

<div class="card" style="padding:0;overflow-x:auto">
<table class="leads">
<thead><tr><th>Клиент</th><th>Запрос</th><th>Связь</th><th>Статус</th><th>Связаться</th><th>Когда</th></tr></thead>
<tbody>
<?php foreach($rows as $r){ $dig=crm_phone_digits($r['contact']); $e164=crm_phone_e164($r['contact']); $digN=ltrim($e164,'+'); $req=array_filter([$r['use_'],$r['type'],$r['capacity'],$r['budget'],$r['items']]); $reqs=implode(' · ',$req); ?>
<tr onclick="location='view.php?id=<?=$r['id']?>'" style="cursor:pointer">
  <td>
    <a href="view.php?id=<?=$r['id']?>" class="lead-name" onclick="event.stopPropagation()"><b><?=h($r['name']?:'—')?></b></a><a href="view.php?id=<?=$r['id']?>" target="_blank" rel="noopener" class="newtab" onclick="event.stopPropagation()" title="Открыть лид в новой вкладке">↗</a><?php if(isset($dups[$r['phone_norm']]) && $r['id']!=$dups[$r['phone_norm']]['mn']){ ?> <span class="badge" style="background:#ff8a5b" title="Этот номер уже обращался — есть более ранняя заявка">повтор</span><?php } ?>
    <br><?php if($dig){ ?><span class="cphone" data-c="<?=$e164?>" onclick="event.stopPropagation();crmCopy(this)" title="Нажмите, чтобы скопировать номер"><?=h(crm_phone_fmt($r['contact']))?></span><?php }else{ ?><span class="muted"><?=h(crm_phone_fmt($r['contact']))?></span><?php } ?>
    <?php if($r['channel']){ ?> <span class="want" title="Способ связи, который клиент выбрал в квизе">хочет <?=h(crm_channel_label($r['channel']))?></span><?php } ?>
  </td>
  <td class="muted req" title="<?=h($reqs)?>"><?=h($reqs)?></td>
  <td><?php $ccl=crm_contact_list($r['call_status']); if($ccl){ foreach($ccl as $ck){ ?><span class="badge-o" style="color:<?=crm_contact_color($ck)?>;margin:1px 2px 1px 0"><?=h(crm_contact_label($ck))?></span><?php } }else{ ?><span class="muted">—</span><?php } ?></td>
  <td><span class="badge" style="background:<?=crm_status_color($r['status'])?>"><?=h($ST[$r['status']]??$r['status'])?></span></td>
  <td style="white-space:nowrap" onclick="event.stopPropagation()"><?php if($dig){ ?><a class="qa" href="tel:<?=$e164?>" title="Позвонить">📞</a><a class="qa" href="https://wa.me/<?=$digN?>" target="_blank" rel="noopener" title="WhatsApp">WA</a><a class="qa" href="tg://resolve?phone=<?=$digN?>" title="Telegram">TG</a><?php }else{ ?><span class="muted">—</span><?php } ?></td>
  <td style="white-space:nowrap" class="muted"><?=crm_dt($r['created_at'])?><?php $na=$r['next_action_at']; $over=$na && strtotime($na)<time() && !in_array($r['status'],['won','lost'],true); if($na){ ?><br><span style="color:<?=$over?'#ff8a5b':'#5fd08a'?>;font-weight:600"><?=$over?'⏰':'📅'?> <?=crm_dt($na)?></span><?php } ?></td>
</tr>
<?php } if(!$rows){ ?><tr><td colspan="6" class="muted" style="padding:24px;text-align:center"><?=($fStatus||$fCall||$fSource||$q||$fMine||$fUnassigned||$fDue)?'По этому фильтру лидов нет. ':'Лидов пока нет. Как только придёт заявка с сайта — появится здесь.'?></td></tr><?php } ?>
</tbody></table>
</div>

<?php if($pages>1){ $qs=$_GET; ?>
<div class="filters" style="justify-content:center;margin-top:6px">
  <?php if($page>1){ $qs['page']=$page-1; ?><a class="btn btn-sec" href="index.php?<?=h(http_build_query($qs))?>">← назад</a><?php } ?>
  <span class="muted">стр. <?=$page?> из <?=$pages?></span>
  <?php if($page<$pages){ $qs['page']=$page+1; ?><a class="btn btn-sec" href="index.php?<?=h(http_build_query($qs))?>">вперёд →</a><?php } ?>
</div>
<?php } ?>

<div id="newlead" onclick="location.reload()" style="display:none;position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:50;background:var(--acc);color:#12181f;font-weight:700;padding:10px 16px;border-radius:22px;box-shadow:0 6px 20px rgba(0,0,0,.4);cursor:pointer">🔔 <span id="newleadn">0</span> новых — обновить</div>
<script>
(function(){ var base=<?=$maxId?>, title=document.title;
  setInterval(function(){
    fetch('ping.php',{cache:'no-store'}).then(function(r){return r.json();}).then(function(j){
      if(!j||!j.ok) return; var diff=j.max-base;
      if(diff>0){ document.getElementById('newleadn').textContent=diff; document.getElementById('newlead').style.display='block'; document.title='('+diff+') '+title; }
    }).catch(function(){});
  }, 30000);
})();
</script>
<?php crm_foot();
