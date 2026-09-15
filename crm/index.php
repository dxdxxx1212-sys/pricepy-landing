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
  // убираем ведущий код страны (8/7 перед мобильной 9) — чтобы номер находился в любом формате (phone_norm = 7XXXXXXXXXX)
  $qtail = (strlen($qd)>=2 && ($qd[0]==='7'||$qd[0]==='8') && $qd[1]==='9') ? substr($qd,1) : $qd;
  if($qd!==''){ $where[]='(name LIKE ? OR contact LIKE ? OR phone_norm LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$qtail%"; }
  else { $where[]='(name LIKE ? OR contact LIKE ?)'; $args[]="%$q%"; $args[]="%$q%"; }
}
$wsql = $where ? ('WHERE '.implode(' AND ',$where)) : '';
$tc=$db->prepare("SELECT COUNT(*) c FROM leads $wsql"); $tc->execute($args); $total=(int)$tc->fetch()['c'];
$per=100; $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)($_GET['page']??1))); $off=($page-1)*$per;
$order = $fDue ? 'next_action_at ASC' : 'created_at DESC, id DESC'; // по дате заявки (не по порядку добавления — иначе импорт «прыгает»)
$st=$db->prepare("SELECT * FROM leads $wsql ORDER BY $order LIMIT $per OFFSET $off");
$st->execute($args); $rows=$st->fetchAll();
$maxId=(int)$db->query("SELECT COALESCE(MAX(id),0) m FROM leads")->fetch()['m']; // для сигнала о новом лиде

// Для страницы: последний комментарий и последнее вложение по каждому лиду (одним запросом, без N+1).
$lastCmt=[]; $lastAtt=[];
$pageIds=array_map(function($r){return (int)$r['id'];}, $rows);
if($pageIds){ $in=implode(',',$pageIds);
  foreach($db->query("SELECT c.lead_id, c.body FROM comments c JOIN (SELECT lead_id, MAX(id) mid FROM comments WHERE lead_id IN($in) GROUP BY lead_id) m ON m.mid=c.id") as $r){ $lastCmt[(int)$r['lead_id']]=$r['body']; }
  foreach($db->query("SELECT a.lead_id, a.id FROM attachments a JOIN (SELECT lead_id, MAX(id) mid FROM attachments WHERE lead_id IN($in) GROUP BY lead_id) m ON m.mid=a.id") as $r){ $lastAtt[(int)$r['lead_id']]=(int)$r['id']; }
}

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
  <a class="k" href="?due=1" style="text-decoration:none;border-color:<?=$k_due?'var(--alert)':'var(--line)'?>"><b style="color:<?=$k_due?'var(--alert)':'#5fd08a'?>"><?=$k_due?></b><span>на сегодня</span></a>
  <a class="k" href="?status=new" style="text-decoration:none;border-color:<?=$k_new?'var(--acc)':'var(--line)'?>"><b style="color:var(--acc)"><?=$k_new?></b><span>новых</span></a>
  <a class="k" href="?call=noanswer" style="text-decoration:none"><b style="color:var(--muted2)"><?=$k_noanswer?></b><span>не дозвонился</span></a>
  <a class="k" href="?status=won" style="text-decoration:none"><b style="color:#22a06b"><?=$k_won?></b><span>продажи</span></a>
  <a class="k" href="index.php" style="text-decoration:none"><b style="color:var(--muted2)"><?=$k_total?></b><span>всего</span></a>
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
<thead><tr><th>Клиент</th><th>Запрос</th><th>Связь</th><th>Статус</th><th>Коммент</th><th>Связаться</th><th>Когда</th></tr></thead>
<tbody>
<?php foreach($rows as $r){ $dig=crm_phone_digits($r['contact']); $e164=crm_phone_e164($r['contact']); $digN=ltrim($e164,'+'); $req=array_filter([$r['use_'],$r['type'],$r['capacity'],$r['budget'],$r['items']]); $reqs=implode(' · ',$req); ?>
<tr onclick="location='view.php?id=<?=$r['id']?>'" style="cursor:pointer">
  <td>
    <a href="view.php?id=<?=$r['id']?>" class="lead-name" onclick="event.stopPropagation()"><b><?=h($r['name']?:'—')?></b></a><a href="view.php?id=<?=$r['id']?>" target="_blank" rel="noopener" class="newtab" onclick="event.stopPropagation()" title="Открыть лид в новой вкладке">↗</a><?php if(isset($dups[$r['phone_norm']]) && $r['id']!=$dups[$r['phone_norm']]['mn']){ ?> <span class="chip warn" title="Этот номер уже обращался — есть более ранняя заявка">повтор</span><?php } ?>
    <br><?php if($dig){ ?><span class="cphone" data-c="<?=$e164?>" onclick="event.stopPropagation();crmCopy(this)" title="Нажмите, чтобы скопировать номер"><?=h(crm_phone_fmt($r['contact']))?></span><?php }else{ ?><span class="muted"><?=h(crm_phone_fmt($r['contact']))?></span><?php } ?>
    <?php if($r['channel']){ ?> <span class="want" title="Способ связи, который клиент выбрал в квизе"><span class="ch-dot" style="background:<?=crm_channel_color($r['channel'])?>"></span>хочет <?=h(crm_channel_label($r['channel']))?></span><?php } ?>
  </td>
  <td class="muted req" title="<?=h($reqs)?>"><?=h($reqs)?></td>
  <td><?php $ccl=crm_contact_list($r['call_status']); if($ccl){ foreach($ccl as $ck){ $warn=($ck==='noanswer'); ?><span class="chip<?=$warn?' warn':''?>" style="margin:1px 3px 1px 0"><?php if(!$warn){ ?><span class="ch-dot" style="background:<?=crm_contact_color($ck)?>"></span><?php } ?><?=h(crm_contact_label($ck))?></span><?php } }else{ ?><span class="muted">—</span><?php } ?></td>
  <td><span class="badge" style="background:<?=crm_status_color($r['status'])?>;color:<?=crm_status_ink($r['status'])?>"><?=h($ST[$r['status']]??$r['status'])?></span><?php $aid=(int)$r['assignee_id']; if($aid && isset($users[$aid])){ ?><br><span class="mgr" title="Менеджер, который взял лид"><?=crm_icon('person')?><?=h($users[$aid])?></span><?php }else{ ?><br><span class="mgr-none" title="Лид пока никто не взял">не взят</span><?php } ?></td>
  <td class="cmt-col"><?php $lc=$lastCmt[$r['id']]??''; $la=$lastAtt[$r['id']]??0; if($lc!==''||$la){ ?><div class="lc" onclick="event.stopPropagation();openHist(<?=$r['id']?>)" title="Открыть комментарии и фото"><?php if($lc!==''){ ?><span class="lc-txt"><?=h(mb_strimwidth(preg_replace('/\s+/u',' ',$lc),0,60,'…','UTF-8'))?></span><?php } ?><?php if($la){ ?><span class="lc-thumb"><img src="att.php?id=<?=$la?>" loading="lazy" alt=""></span><?php } ?></div><?php }else{ ?><span class="muted">—</span><?php } ?></td>
  <td style="white-space:nowrap" onclick="event.stopPropagation()"><?php if($dig){ $want=crm_channel_norm($r['channel']); ?><a class="qa<?=$want==='phone'?' want-ch':''?>" href="tel:<?=$e164?>" title="Позвонить"><?=crm_icon('phone')?></a><a class="qa<?=$want==='whatsapp'?' want-ch':''?>" href="https://wa.me/<?=$digN?>" target="_blank" rel="noopener" title="WhatsApp">WA</a><a class="qa<?=$want==='telegram'?' want-ch':''?>" href="tg://resolve?phone=<?=$digN?>" title="Telegram">TG</a><?php }else{ ?><span class="muted">—</span><?php } ?></td>
  <td style="white-space:nowrap"><?php $na=$r['next_action_at']; $over=$na && strtotime($na)<time() && !in_array($r['status'],['won','lost'],true); if($na){ ?><span style="color:<?=$over?'var(--alert)':'#5fd08a'?>;font-weight:600"><?=crm_icon($over?'clock':'cal')?> <?=crm_dt($na)?></span><br><?php } ?><span style="color:#6b7580;font-size:12px">заявка <?=crm_dt($r['created_at'])?></span></td>
</tr>
<?php } if(!$rows){ ?><tr><td colspan="7" class="muted" style="padding:24px;text-align:center"><?=($fStatus||$fCall||$fSource||$q||$fMine||$fUnassigned||$fDue)?'По этому фильтру лидов нет. ':'Лидов пока нет. Как только придёт заявка с сайта — появится здесь.'?></td></tr><?php } ?>
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
<!-- поп-ап: комментарии + история изменений лида -->
<div id="histModal" class="hm" hidden>
  <div class="hm-back" onclick="closeHist()"></div>
  <div class="hm-box"><button class="hm-x" type="button" onclick="closeHist()" title="Закрыть">×</button><div id="histBody"></div></div>
</div>
<script>
var histReq=0, histChanged=false;
function openHist(id){ var m=document.getElementById('histModal'), b=document.getElementById('histBody');
  histReq=id; histChanged=false;                // актуальный запрошенный лид — ответы старых игнорируем
  b.innerHTML='<div class="muted" style="padding:24px 4px">Загрузка…</div>'; b.setAttribute('data-id',id);
  m.hidden=false; document.body.style.overflow='hidden';
  fetch('hist.php?id='+id,{cache:'no-store'}).then(function(r){return r.text();}).then(function(html){ if(histReq===id && !document.getElementById('histModal').hidden) b.innerHTML=html; })
    .catch(function(){ if(histReq===id) b.innerHTML='<div class="muted" style="padding:24px 4px">Не удалось загрузить</div>'; }); }
function closeHist(){ document.getElementById('histModal').hidden=true; document.body.style.overflow='';
  if(histChanged){ histChanged=false; location.reload(); } }   // применились действия — обновляем список
document.addEventListener('keydown',function(e){ if(e.key==='Escape' && !document.getElementById('histModal').hidden) closeHist(); });
// любые действия в поп-апе (канал/статус/перезвон/назначение/коммент, правка/удаление) — через fetch, затем перерисовать фрагмент
document.getElementById('histBody').addEventListener('submit',function(e){
  var f=e.target.closest && e.target.closest('form'); if(!f) return;
  if(e.defaultPrevented) return;              // отмена подтверждения удаления
  e.preventDefault();
  var b=document.getElementById('histBody'), id=b.getAttribute('data-id'), fd=new FormData(f);
  if(e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value); // значение нажатой кнопки-чипа
  fetch('hist.php?id='+id,{method:'POST',body:fd,cache:'no-store'}).then(function(r){return r.text();}).then(function(html){ if(document.getElementById('histBody').getAttribute('data-id')===String(id)){ b.innerHTML=html; b.setAttribute('data-id',id); histChanged=true; } })
    .catch(function(){ if(window.crmToast)crmToast('Не удалось сохранить'); }); });
</script>
<?php crm_foot();
