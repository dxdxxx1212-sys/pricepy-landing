// CRM «Восток Прицеп» — общие скрипты панели: тост, копирование, правка комментария. Подключается из crm_foot().
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
