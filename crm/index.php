<?php
require __DIR__.'/lib.php';
$me = crm_require();
$db = crm_db();
$ST = crm_statuses(); $users = crm_users_map();

// Массовая передача лидов оператору (только владелец). PRG: обрабатываем и редиректим на тот же фильтр.
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act']??'')==='bulk_assign'){
  $qs=$_GET;
  // протухшая сессия не должна молча «съедать» передачу — говорим об этом прямо
  if(!crm_csrf_ok()){ $qs['bulk']='csrf'; }
  else { $qs['bulk'] = ($me['role']==='owner') ? crm_bulk_assign($me, $_POST['ids']??[], $_POST['uid']??'') : 0; }
  header('Location: index.php?'.http_build_query($qs)); exit;
}

// фильтры
$fStatus = $_GET['status'] ?? '';
$fCall = $_GET['call'] ?? '';
$fSource = $_GET['source'] ?? '';
$fDue = isset($_GET['due']);
$q = trim($_GET['q'] ?? '');
$isOwner = ($me['role']==='owner');
$fMine = isset($_GET['mine']);
$fUnassigned = isset($_GET['unassigned']);
$fAssignee = $isOwner ? trim($_GET['assignee'] ?? '') : ''; // '', 'none' или id оператора — только для владельца
$tomorrow = date('c', strtotime('tomorrow')); // граница «на сегодня» = всё, что до начала завтра
$scope = crm_lead_scope_sql($me); // оператор видит только свои лиды (серверное ограничение, не только UI)
$opList = $isOwner ? $db->query("SELECT id,name FROM users WHERE role='operator' ORDER BY name")->fetchAll() : [];
$where=[$scope]; $args=[];
if($fStatus!==''){ $where[]='status=?'; $args[]=$fStatus; }
if($fCall!==''){ $where[]="(','||call_status||',') LIKE ?"; $args[]='%,'.$fCall.',%'; } // членство в наборе каналов
if($fSource!==''){ $where[]='source=?'; $args[]=$fSource; }
if($fDue){ $where[]="next_action_at<>'' AND next_action_at<? AND status NOT IN('won','lost')"; $args[]=$tomorrow; }
if($fMine){ $where[]='assignee_id=?'; $args[]=(int)$me['id']; }
if($fUnassigned){ $where[]='(assignee_id IS NULL OR assignee_id=0)'; }
if($fAssignee==='none'){ $where[]='(assignee_id IS NULL OR assignee_id=0)'; }
elseif($fAssignee!==''){ $where[]='assignee_id=?'; $args[]=(int)$fAssignee; }
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
$maxId=(int)$db->query("SELECT COALESCE(MAX(id),0) m FROM leads WHERE $scope")->fetch()['m']; // для сигнала о новом лиде (в рамках видимости)

// Для страницы: последний комментарий и число фото по каждому лиду (одним запросом, без N+1).
// Превью-картинку в строке не грузим: att.php отдаёт оригинал целиком — 100 строк = 100 тяжёлых
// запросов ради картинки 40×40. Вместо неё скрепка с числом, клик открывает тот же поп-ап.
$lastCmt=[]; $attCnt=[];
$pageIds=array_map(function($r){return (int)$r['id'];}, $rows);
if($pageIds){ $in=implode(',',$pageIds);
  foreach($db->query("SELECT c.lead_id, c.body FROM comments c JOIN (SELECT lead_id, MAX(id) mid FROM comments WHERE lead_id IN($in) GROUP BY lead_id) m ON m.mid=c.id") as $r){ $lastCmt[(int)$r['lead_id']]=$r['body']; }
  foreach($db->query("SELECT lead_id, COUNT(*) c FROM attachments WHERE lead_id IN($in) GROUP BY lead_id") as $r){ $attCnt[(int)$r['lead_id']]=(int)$r['c']; }
}

// Счётчики очередей (вкладки над списком). Считаем только то, что реально показываем.
$k_new   = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='new' AND ($scope)")->fetch()['c'];
$kd=$db->prepare("SELECT COUNT(*) c FROM leads WHERE next_action_at<>'' AND next_action_at<? AND status NOT IN('won','lost') AND ($scope)"); $kd->execute([$tomorrow]); $k_due=(int)$kd->fetch()['c'];
$k_work  = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE status='work' AND ($scope)")->fetch()['c'];
$k_total = (int)$db->query("SELECT COUNT(*) c FROM leads WHERE $scope")->fetch()['c'];
$k_unass = $isOwner ? (int)$db->query("SELECT COUNT(*) c FROM leads WHERE (assignee_id IS NULL OR assignee_id=0)")->fetch()['c'] : 0;

// карта дублей по нормализованному телефону: phone_norm => [первый id, сколько всего]
$dups=[];
foreach($db->query("SELECT phone_norm, MIN(id) mn, COUNT(*) c FROM leads WHERE phone_norm<>'' AND ($scope) GROUP BY phone_norm HAVING c>1") as $d){
  $dups[$d['phone_norm']]=['mn'=>(int)$d['mn'],'c'=>(int)$d['c']];
}

crm_head('Лиды'); ?>
<?php if(isset($_GET['deleted'])){ ?><div style="background:#173a24;color:#8ff0b0;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px">Лид удалён.</div><?php } ?>
<?php if(isset($_GET['bulk'])){ $bulkRaw=is_scalar($_GET['bulk'])?(string)$_GET['bulk']:''; $bn=(int)$bulkRaw;
  $bulkTxt = $bulkRaw==='csrf' ? 'Передача не выполнена: сессия истекла. Обновите страницу (F5) и повторите.'
           : ($bn ? ('Передано '.$bn.' '.crm_plural_lead($bn).'.') : 'Ничего не передано — лиды не выбраны или назначение не изменилось.'); ?>
<div style="background:<?=$bn?'#173a24;color:#8ff0b0':'#3a2417;color:#f0a86a'?>;padding:9px 12px;border-radius:8px;margin-bottom:14px;font-size:14px"><?=h($bulkTxt)?></div><?php } ?>
<?php
// Одна навигация вместо трёх параллельных (плитки + пилюли + селекты).
// Очереди взаимоисключающие: каждая — это готовый фильтр «что делать дальше».
$segPlain = !$fDue && !$fUnassigned;                       // не в спец-очереди
$segAll   = $segPlain && $fStatus==='' && $fAssignee==='' && $q==='';
?>
<div class="segs">
  <a class="seg <?=$k_due?'alert ':''?><?=$fDue?'on':''?>" href="?due=1">На сегодня <b><?=$k_due?></b></a>
  <a class="seg <?=($segPlain&&$fStatus==='new')?'on':''?>" href="?status=new">Новые <b><?=$k_new?></b></a>
  <?php if($isOwner){ ?><a class="seg <?=$fUnassigned?'on':''?>" href="?unassigned=1">Нераспределённые <b><?=$k_unass?></b></a><?php } ?>
  <a class="seg <?=($segPlain&&$fStatus==='work')?'on':''?>" href="?status=work">В работе <b><?=$k_work?></b></a>
  <a class="seg <?=$segAll?'on':''?>" href="index.php"><?=$isOwner?'Все':'Мои'?> <b><?=$k_total?></b></a>
</div>

<form class="filters" method="get">
  <?php /* очередь должна пережить смену фильтра — раньше due терялся и «на сегодня» молча слетало */ ?>
  <?php if($fDue){ ?><input type="hidden" name="due" value="1"><?php } ?>
  <?php if($fMine){ ?><input type="hidden" name="mine" value="1"><?php } ?>
  <?php if($fUnassigned){ ?><input type="hidden" name="unassigned" value="1"><?php } ?>
  <input name="q" value="<?=h($q)?>" placeholder="Поиск: имя или телефон" style="min-width:230px">
  <?php if($isOwner){ ?><select name="assignee" onchange="this.form.submit()"><option value="">Все менеджеры</option><option value="none" <?=$fAssignee==='none'?'selected':''?>>— не распределён</option>
    <?php foreach($opList as $op){ ?><option value="<?=$op['id']?>" <?=$fAssignee===(string)$op['id']?'selected':''?>><?=h($op['name'])?></option><?php } ?></select><?php } ?>
  <select name="status" onchange="this.form.submit()"><option value="">Все статусы</option>
    <?php foreach($ST as $k=>$v){ ?><option value="<?=$k?>" <?=$fStatus===$k?'selected':''?>><?=h($v)?></option><?php } ?></select>
  <button class="btn btn-sec">Найти</button>
  <?php if($fStatus||$fCall||$fSource||$q||$fMine||$fUnassigned||$fAssignee!==''||$fDue){ ?><a href="index.php" class="muted">сбросить</a><?php } ?>
  <span class="sp" style="flex:1"></span>
  <span class="muted"><?=$total?> шт.</span>
</form>

<?php if($isOwner){ ?>
<form id="bulkForm" method="post">
<input type="hidden" name="csrf" value="<?=h(crm_csrf())?>"><input type="hidden" name="act" value="bulk_assign">
<div id="bulkbar" style="display:none;position:sticky;top:56px;z-index:9;align-items:center;gap:12px;flex-wrap:wrap;background:#1b232c;border:1px solid var(--line);border-radius:10px;padding:10px 14px;margin-bottom:10px;box-shadow:0 6px 18px rgba(0,0,0,.28)">
  <b style="font-size:16px">Выбрано <span id="bulkn" style="color:var(--acc)">0</span></b><span class="muted">передать →</span>
  <select name="uid" id="bulkuid" required style="min-width:170px"><option value="">— выберите оператора —</option>
    <?php foreach($opList as $op){ ?><option value="<?=$op['id']?>"><?=h($op['name'])?></option><?php } ?>
    <option value="unassign">— снять назначение —</option>
  </select>
  <button class="btn" type="submit">Передать</button>
  <button type="button" class="btn btn-sec" onclick="bulkClear()">Отмена</button>
</div>
<?php } ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="leads">
<thead><tr><?php if($isOwner){ ?><th class="cbcol"><input type="checkbox" id="bulkall" title="Выбрать все на странице" onclick="bulkAll(this)"></th><?php } ?><th>Клиент</th><th>Запрос и последнее</th><th>Статус</th><th>Связаться</th><th>Когда</th></tr></thead>
<tbody>
<?php
// Имя менеджера в каждой строке — шум, когда и так отфильтровано по нему или когда оператор
// видит только свои лиды. «Не взят» показываем только там, где это ещё вопрос.
$showMgr  = $isOwner && $fAssignee==='';
$showFree = $isOwner && !$fUnassigned && $fAssignee!=='none';
foreach($rows as $r){
  $dig=crm_phone_digits($r['contact']);
  $e164=crm_phone_e164($r['contact']);
  $req=array_filter([$r['use_'],$r['type'],$r['capacity'],$r['budget'],$r['items']]); $reqs=implode(' · ',$req);
  $lc=$lastCmt[$r['id']]??''; $ac=$attCnt[$r['id']]??0;
  $sig=array_values(array_intersect(crm_contact_list($r['call_status']),['noanswer','nomsg'])); // только сигналы, остальное — галочкой на кнопке
?>
<tr onclick="location='view.php?id=<?=$r['id']?>'" style="cursor:pointer">
  <?php if($isOwner){ ?><td class="cbcol" onclick="event.stopPropagation()"><input type="checkbox" class="bulkcb" name="ids[]" value="<?=$r['id']?>" onclick="bulkClick(this,event)"></td><?php } ?>
  <td>
    <a href="view.php?id=<?=$r['id']?>" class="lead-name" onclick="event.stopPropagation()"><b><?=h($r['name']?:'—')?></b></a><?php if(isset($dups[$r['phone_norm']]) && $r['id']!=$dups[$r['phone_norm']]['mn']){ ?> <span class="chip warn" title="Этот номер уже обращался — есть более ранняя заявка">повтор</span><?php } ?>
    <br><?php if($dig){ ?><span class="cphone" data-c="<?=$e164?>" onclick="event.stopPropagation();crmCopy(this)" title="Нажмите, чтобы скопировать номер"><?=h(crm_phone_fmt($r['contact']))?></span><?php }else{ ?><span class="muted"><?=h(crm_phone_fmt($r['contact']))?></span><?php } ?>
  </td>
  <td class="req"><?php if($reqs!==''){ ?><div class="muted" title="<?=h($reqs)?>"><?=h($reqs)?></div><?php } ?>
    <?php if($lc!==''||$ac){ ?><div class="lc" onclick="event.stopPropagation();openHist(<?=$r['id']?>)" title="Открыть комментарии и фото"><?php if($ac){ ?><span class="lc-att"><?=crm_icon('attach')?><?=$ac?></span><?php } ?><?php if($lc!==''){ ?><span class="lc-txt"><?=h(mb_strimwidth(preg_replace('/\s+/u',' ',$lc),0,90,'…','UTF-8'))?></span><?php } ?></div><?php } ?>
    <?php if($reqs===''&&$lc===''&&!$ac){ ?><span class="muted">—</span><?php } ?></td>
  <td><span class="badge" style="background:<?=crm_status_color($r['status'])?>;color:<?=crm_status_ink($r['status'])?>"><?=h($ST[$r['status']]??$r['status'])?></span>
    <?php foreach($sig as $sk){ ?><br><span class="chip warn" style="margin-top:5px"><?=h(crm_contact_label($sk))?></span><?php } ?>
    <?php $aid=(int)$r['assignee_id']; if($aid && $showMgr && isset($users[$aid])){ ?><br><span class="mgr" title="Ответственный"><?=crm_icon('person')?><?=h($users[$aid])?></span><?php } elseif(!$aid && $showFree){ ?><br><span class="mgr-none" title="Лид пока никому не передан">не взят</span><?php } ?></td>
  <td class="qacol" onclick="event.stopPropagation()"><?=crm_contact_buttons($r)?></td>
  <td style="white-space:nowrap"><?php $na=$r['next_action_at']; $over=$na && strtotime($na)<time() && !in_array($r['status'],['won','lost'],true); if($na){ ?><span style="color:<?=$over?'var(--alert)':'#5fd08a'?>;font-weight:600"><?=crm_icon($over?'clock':'cal')?> <?=crm_dt($na)?></span><br><?php } ?><span class="muted" style="font-size:12px" title="Заявка: <?=crm_dt($r['created_at'])?>"><?=crm_ago($r['created_at'])?></span></td>
</tr>
<?php } if(!$rows){ ?><tr><td colspan="<?=$isOwner?6:5?>" class="muted" style="padding:24px;text-align:center"><?=($fStatus||$fCall||$fSource||$q||$fMine||$fUnassigned||$fAssignee!==''||$fDue)?'По этому фильтру лидов нет. ':($isOwner?'Лидов пока нет. Как только придёт заявка с сайта — появится здесь.':'Вам пока не назначено ни одного лида. Как только владелец распределит — они появятся здесь.')?></td></tr><?php } ?>
</tbody></table>
</div>
<?php if($isOwner){ ?></form>
<script>
var bulkLast=-1;
function bulkUpd(){ var cbs=document.querySelectorAll('.bulkcb'), sel=0;
  cbs.forEach(function(c){ if(c.checked) sel++; });
  document.getElementById('bulkn').textContent=sel;
  document.getElementById('bulkbar').style.display = sel===0 ? 'none' : 'flex';
  var all=document.getElementById('bulkall'); if(all){ all.checked = sel>0 && sel===cbs.length; all.indeterminate = sel>0 && sel<cbs.length; } }
// клик по галочке: с Shift — выделить весь диапазон от прошлой отмеченной до текущей
function bulkClick(cb,e){ var cbs=Array.prototype.slice.call(document.querySelectorAll('.bulkcb')), idx=cbs.indexOf(cb);
  if(e && e.shiftKey && bulkLast>-1 && idx>-1){ var a=Math.min(bulkLast,idx), b=Math.max(bulkLast,idx);
    for(var i=a;i<=b;i++){ cbs[i].checked=cb.checked; } }
  bulkLast=idx; bulkUpd(); }
function bulkAll(box){ document.querySelectorAll('.bulkcb').forEach(function(c){ c.checked=box.checked; }); bulkLast=-1; bulkUpd(); }
function bulkClear(){ document.querySelectorAll('.bulkcb').forEach(function(c){ c.checked=false; }); var a=document.getElementById('bulkall'); if(a){a.checked=false;a.indeterminate=false;} bulkLast=-1; bulkUpd(); }
document.getElementById('bulkForm').addEventListener('submit',function(e){
  var uid=document.getElementById('bulkuid');
  var sel=document.querySelectorAll('.bulkcb:checked').length;
  if(sel===0){ e.preventDefault(); return; }
  if(!uid.value){ e.preventDefault(); if(window.crmToast)crmToast('Выберите оператора'); uid.focus(); return; }
  var who = uid.options[uid.selectedIndex].text;
  if(!confirm('Передать '+sel+' лид(ов): '+who+'?')){ e.preventDefault(); } });
</script>
<?php } ?>

<?php if($pages>1){ $qs=$_GET; ?>
<div class="filters" style="justify-content:center;margin-top:6px">
  <?php if($page>1){ $qs['page']=$page-1; ?><a class="btn btn-sec" href="index.php?<?=h(http_build_query($qs))?>">← назад</a><?php } ?>
  <span class="muted">стр. <?=$page?> из <?=$pages?></span>
  <?php if($page<$pages){ $qs['page']=$page+1; ?><a class="btn btn-sec" href="index.php?<?=h(http_build_query($qs))?>">вперёд →</a><?php } ?>
</div>
<?php } ?>

<div id="newlead" onclick="location.reload()" style="display:none;position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:50;background:var(--acc);color:#12181f;font-weight:700;padding:10px 16px;border-radius:22px;box-shadow:0 6px 20px rgba(0,0,0,.4);cursor:pointer"><?=crm_icon('bell')?> <span id="newleadn">0</span> новых — обновить</div>
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
