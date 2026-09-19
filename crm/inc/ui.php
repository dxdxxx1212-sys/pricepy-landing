<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Вёрстка ----
function crm_head($title){ $u=crm_user();
  // X-Frame-Options (DENY) и nosniff уже ставит nginx на весь поддомен — здесь не дублируем,
  // иначе в ответе два разных X-Frame-Options. Добавляем только то, чего у nginx нет:
  // no-referrer — в URL карточки есть id лида, а из неё уходят ссылки на wa.me / t.me / max.ru.
  if(!headers_sent()){ header('Referrer-Policy: no-referrer'); }
  ?><!DOCTYPE html><html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?=h($title)?> · CRM Восток Прицеп</title>
<style>
:root{--bg:#0f141a;--panel:#171e26;--line:#262f3a;--ink:#e7edf3;--muted:#93a0ae;--muted2:#aeb9c5;--acc:#f5b301;--acc2:#3b82f6;
  --ph:#c9d3dd;                                   /* телефон/имя — заметнее */
  --chip-bg:#1f2731;--chip-ink:#c4ccd6;--chip-line:#2a3540;  /* нейтральная плашка */
  --alert:#f2843c;                               /* единый тон срочности */
  --ok-bg:#173a24;--ok-ink:#8ff0b0;--warn-bg:#3a2417;--warn-ink:#f0a86a;--warn-line:#5a4433;--danger-bg:#3a1717;--danger-ink:#ffb0b0}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:15px}
a{color:#9cc4ff;text-decoration:none}a:hover{text-decoration:underline}
:focus-visible{outline:2px solid var(--acc2);outline-offset:2px}
.icn{width:15px;height:15px;display:inline-block;vertical-align:-2px;flex:none}
.top{background:var(--panel);border-bottom:1px solid var(--line);padding:12px 18px;display:flex;align-items:center;gap:18px;position:sticky;top:0;z-index:10}
.top .brand{font-weight:800;color:var(--acc)}.top .brand span{color:#fff}
.top nav{display:flex;gap:16px}.top nav a{color:var(--muted);font-weight:600}.top nav a.on{color:#fff}
.top .sp{flex:1}.top .me{color:var(--muted);font-size:13px}
.wrap{max-width:1200px;margin:0 auto;padding:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:16px}
.btn{display:inline-block;border:0;cursor:pointer;font-family:inherit;font-weight:700;border-radius:8px;background:var(--acc);color:#1a1a1a;padding:9px 16px;font-size:14px}
.btn:hover{filter:brightness(1.05);text-decoration:none}.btn-sec{background:#2a3542;color:var(--ink)}.btn-b{background:var(--acc2);color:#fff}
input,select,textarea{font-family:inherit;font-size:14px;background:#0f151c;border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:9px 11px}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--acc)}
table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);font-size:14px;vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
tr:hover td{background:#1b232c}
.badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;color:#12181f}
/* нейтральная плашка — каналы, «хочет», менеджер, типы событий */
.chip{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);line-height:1.5}
.chip.warn{background:var(--warn-bg);color:var(--warn-ink);border-color:var(--warn-line)}
.ch-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex:none}
.mgr .icn{width:12px;height:12px;color:var(--muted)}
.qa .icn{width:15px;height:15px}
.pill{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);font-size:12px;margin:1px}
.muted{color:var(--muted)}.right{text-align:right}
.grid2{display:grid;grid-template-columns:1fr 340px;gap:16px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:16px}
.kpi .k{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px}
.kpi .k b{font-size:24px;display:block}.kpi .k span{color:var(--muted);font-size:12px}
.dl{display:grid;grid-template-columns:130px 1fr;gap:6px 10px;font-size:14px}.dl dt{color:var(--muted)}.dl dd{margin:0}
.cmt{border-top:1px solid var(--line);padding:10px 0}.cmt .m{color:var(--muted);font-size:12px}
.req{max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.qa{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:28px;padding:0 8px;border:1px solid var(--chip-line);border-radius:8px;font-size:12px;font-weight:600;color:var(--chip-ink);margin-right:4px;vertical-align:middle}
.qa:hover{background:#1b232c;text-decoration:none;color:#fff}
.qa.want-ch{border-color:var(--warn-line);color:var(--warn-ink)}   /* канал, который клиент выбрал */
.want{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line)}
.lead-name{color:#fff;font-weight:700}.lead-name:hover{color:#fff;text-decoration:none}
.newtab{display:inline-block;margin-left:5px;color:#5a6777;font-size:13px;line-height:1;vertical-align:middle}
.newtab:hover{color:#9cc4ff;text-decoration:none}
.cphone{color:var(--ph);font-weight:500;cursor:pointer;border-bottom:1px dashed #3a4653}
.cphone:hover{color:#fff}
.mgr{display:inline-flex;align-items:center;gap:5px;margin-top:6px;font-size:12px;background:var(--chip-bg);color:var(--chip-ink);border:1px solid var(--chip-line);border-radius:6px;padding:1px 8px}
.mgr-none{display:inline-block;margin-top:6px;color:var(--muted);padding:1px 8px;border:1px dashed var(--line);border-radius:6px;font-size:12px}
/* строка последнего комментария + превью вложения в списке лидов */
.lc{display:flex;align-items:center;gap:8px;margin-top:8px}
.lc-thumb{flex:none;width:40px;height:40px;border-radius:7px;overflow:hidden;border:1px solid var(--line);display:block;background:#0f151c}
.lc-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.lc-txt{color:var(--muted);font-size:12.5px;line-height:1.35;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:270px}
.lc-more{color:#7f8a96;font-size:11px}
/* колонка «Коммент» в списке: клик открывает поп-ап */
.cmt-col .lc{cursor:pointer;margin-top:0}
.cmt-col .lc:hover .lc-txt{color:#c9d3dd}
.cmt-col .lc-txt{max-width:200px}
/* карточка комментария: инструменты владельца, форма правки, сетка фото */
.att-grid{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.att-th{display:block;width:96px;height:96px;border-radius:8px;overflow:hidden;border:1px solid var(--line);background:#0f151c}
.att-th img{width:100%;height:100%;object-fit:cover;display:block}
.att-th:hover{border-color:var(--acc)}
.cmt-body{white-space:pre-wrap}
.cmt-tools{margin-top:5px;display:flex;gap:14px}
.cmt-tools button{background:none;border:0;color:#9cc4ff;cursor:pointer;font:inherit;font-size:12px;text-decoration:underline;padding:0}
.cmt-del-btn{color:#e0796b}
.cmt-cancel{background:none;border:0;color:var(--muted);cursor:pointer;font:inherit;font-size:13px;text-decoration:underline;padding:4px 2px}
.cmt-editform textarea{background:#0f151c;border:1px solid var(--line);color:var(--ink);border-radius:8px;padding:8px;font-family:inherit;font-size:14px}
/* поп-ап истории + комментариев (страница списка) */
.hm{position:fixed;inset:0;z-index:100;display:flex;align-items:flex-start;justify-content:center}
.hm[hidden]{display:none}
.hm-back{position:absolute;inset:0;background:rgba(0,0,0,.62)}
.hm-box{position:relative;z-index:1;background:var(--panel);border:1px solid var(--line);border-radius:12px;max-width:560px;width:calc(100% - 28px);margin:5vh 0;max-height:90vh;overflow:auto;padding:18px 18px 22px}
.hm-x{position:absolute;top:6px;right:10px;background:none;border:0;color:var(--muted);font-size:26px;line-height:1;cursor:pointer}
.hist-head{font-size:16px;margin:0 0 6px;padding-right:26px}
.hist-sec{margin-top:16px}
.hist-lbl{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px;margin-bottom:8px}
.cphone.ok{color:#5fd08a;border-bottom-color:transparent}
#crmtoast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#173a24;color:#8ff0b0;padding:9px 16px;border-radius:22px;font-size:14px;font-weight:600;box-shadow:0 6px 20px rgba(0,0,0,.4);z-index:60;opacity:0;transition:opacity .18s;pointer-events:none;max-width:90vw;text-align:center}
#crmtoast.on{opacity:1}
@media(max-width:820px){.grid2{grid-template-columns:1fr}.top nav{gap:12px;font-size:14px}}
/* Мобильные карточки: таблица лидов превращается в стопку карточек, без гориз. скролла */
@media(max-width:760px){
  /* поля ≥16px — iOS иначе зумит страницу при фокусе */
  input,select,textarea{font-size:16px}
  .wrap{padding:14px 12px}
  /* верхняя панель: перенос и компактность на узком экране */
  .top{padding:10px 12px;gap:8px 12px;flex-wrap:wrap}
  .top .me{font-size:12px}
  /* KPI — по 2 в ряд */
  .kpi{grid-template-columns:repeat(2,1fr);gap:8px}
  .kpi .k{padding:12px}.kpi .k b{font-size:21px}
  /* фильтры на всю ширину — крупные тап-таргеты */
  .filters{gap:8px}
  .filters select{flex:1 1 46%;min-width:0}
  .filters input[name=q]{flex:1 1 100%;min-width:0}
  .filters .btn{flex:1 1 100%}
  table.leads thead{display:none}
  table.leads,table.leads tbody,table.leads tr,table.leads td{display:block;width:100%}
  table.leads tr{border:1px solid var(--line);border-radius:10px;margin-bottom:10px;padding:8px 12px;background:var(--panel)}
  table.leads tr:hover td{background:transparent}
  table.leads td{border:0;padding:5px 0}
  .req{max-width:none;white-space:normal}
  .lc-txt{max-width:none}
  /* крупнее для пальца: кнопки связи ≥44px по высоте (переопределяем фикс. height:28px) */
  .qa{min-height:44px;height:auto;padding:0 14px;font-size:14px;margin-right:7px}
  .qa .icn{width:18px;height:18px}
  .newtab{padding:6px 11px;font-size:15px;line-height:22px}
  .cphone{padding:8px 0;display:inline-block}
}
</style></head><body>
<div class="top"><span class="brand">Восток<span>Прицеп</span> · CRM</span>
<?php if($u){ ?><nav><a href="index.php">Лиды</a><?php if($u['role']==='owner'){ ?><a href="users.php">Операторы</a><?php } ?></nav>
<span class="sp"></span><span class="me"><?=h($u['name'])?> · <?=$u['role']==='owner'?'владелец':'оператор'?></span> <a href="logout.php" class="muted">выйти</a><?php } ?>
</div><div class="wrap"><?php }
function crm_foot(){ ?></div><div id="crmtoast"></div><script>
function crmToast(m){var t=document.getElementById('crmtoast');if(!t)return;t.textContent=m;t.classList.add('on');clearTimeout(window._crmtt);window._crmtt=setTimeout(function(){t.classList.remove('on');},1600);}
function crmCopy(x){var el=(x&&x.nodeType)?x:null;var v=el?(el.getAttribute('data-c')||el.textContent.trim()):String(x);
  var ok=function(){if(el)el.classList.add('ok');crmToast('Скопировано: '+v);if(el)setTimeout(function(){el.classList.remove('ok');},1200);};
  var fb=function(){try{var t=document.createElement('textarea');t.value=v;t.style.position='fixed';t.style.opacity='0';document.body.appendChild(t);t.focus();t.select();document.execCommand('copy');document.body.removeChild(t);ok();}catch(e){crmToast('Не удалось скопировать');}};
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(v).then(ok,fb);}else{fb();}}
// правка комментария: показать/скрыть форму (владелец). Делегировано — работает и в карточке, и в поп-апе.
document.addEventListener('click',function(e){
  var eb=e.target.closest && e.target.closest('.cmt-edit-btn');
  if(eb){ var c=eb.closest('.cmt'); if(c){ var v=c.querySelector('.cmt-view'), f=c.querySelector('.cmt-editform'); if(v&&f){ v.style.display='none'; f.style.display='block'; var t=f.querySelector('textarea'); if(t){t.focus();} } } return; }
  var cc=e.target.closest && e.target.closest('.cmt-cancel');
  if(cc){ var c2=cc.closest('.cmt'); if(c2){ var v2=c2.querySelector('.cmt-view'), f2=c2.querySelector('.cmt-editform'); if(v2&&f2){ f2.style.display='none'; v2.style.display=''; } } }
});
</script></body></html><?php }

function crm_events_list_html($events){
  if(!$events) return '<div class="muted" style="font-size:14px">Действий ещё не было.</div>';
  $h=''; foreach($events as $e){ $h.='<div class="cmt" style="padding:7px 0"><span class="pill">'.h($e['type']).'</span> '.h($e['detail']).' <span class="m"> — '.h($e['un']?:'?').', '.crm_dt($e['created_at']).'</span></div>'; }
  return $h;
}
