<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Справочники ----
// Два независимых поля под флоу оператора:
//  1) КАК СВЯЗАЛИСЬ (call_status) — сначала ищем в мессенджере, иначе звоним.
//  2) СТАТУС СДЕЛКИ (status) — воронка: новый → в работе → подборка → итог.
function crm_statuses(){ return [
  'new'  => 'Новый',
  'work' => 'В работе',
  'sent' => 'Отправил подборку',
  'won'  => 'Продажа',
  'lost' => 'Отказ',
];}
function crm_status_color($s){ return [
  'new'=>'#eab308','work'=>'#3b82f6','sent'=>'#38bdf8','won'=>'#22a06b','lost'=>'#64748b',
][$s] ?? '#64748b'; }
// цвет текста на бейдже статуса (светлый фон → тёмный текст, насыщенный → белый)
function crm_status_ink($s){ return in_array($s,['new','sent'],true) ? '#12181f' : '#fff'; }
// Моно-SVG иконка (currentColor) вместо эмодзи — стабильный вид на всех ОС.
function crm_icon($n,$cls='icn'){
  $p=[
    'phone'=>'<path d="M4 4.5c0 6 5.5 11.5 11.5 11.5l.6-3-3.6-1.2-1.4 1.4C8.6 11.7 8 11 6.3 8.3l1.4-1.4L6.5 3.3z"/>',
    'person'=>'<circle cx="8" cy="5" r="3"/><path d="M2.5 14c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/>',
    'clock'=>'<circle cx="8" cy="8" r="6.2"/><path d="M8 4.6V8l2.4 1.5"/>',
    'cal'=>'<rect x="2.5" y="3.5" width="11" height="10" rx="1.5"/><path d="M2.5 6.5h11M5.5 2v3M10.5 2v3"/>',
    'ext'=>'<path d="M6 3h7v7M13 3l-8 8"/>',
    'copy'=>'<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V4A1.5 1.5 0 0 0 9 2.5H3.5A1.5 1.5 0 0 0 2 4v5.5A1.5 1.5 0 0 0 3.5 11H5"/>',
    'attach'=>'<path d="M12.5 7.2 7 12.7a3 3 0 0 1-4.2-4.2l5.7-5.7a2 2 0 0 1 2.8 2.8l-5.6 5.6a1 1 0 0 1-1.4-1.4l5-5"/>',
    'warn'=>'<path d="M8 2.6 14.6 13.4H1.4z"/><path d="M8 6.6v3.1M8 11.5v.1"/>',
    'star'=>'<path d="M8 2.2l1.7 3.6 3.9.5-2.9 2.7.7 3.9L8 11.1 4.6 12.9l.7-3.9L2.4 6.3l3.9-.5z"/>',
    'check'=>'<path d="M3 8.4l3.1 3.1L13 4.8"/>',
    'bell'=>'<path d="M5 7a3 3 0 0 1 6 0c0 3 1.3 3.8 1.6 4.5H3.4C3.7 10.8 5 10 5 7z"/><path d="M6.6 13a1.5 1.5 0 0 0 2.8 0"/>',
  ];
  return '<svg class="'.$cls.'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">'.($p[$n]??'').'</svg>';
}

// Канал связи: как оператор реально достучался до клиента. '' = ещё не связывались.
// g — группа (msg = в мессенджере, call = по телефону) для группировки в UI.
function crm_contacts(){ return [
  'wa'       => ['l'=>'WhatsApp',         'g'=>'msg'],
  'tg'       => ['l'=>'Telegram',         'g'=>'msg'],
  'max'      => ['l'=>'МАКС',             'g'=>'msg'],
  'nomsg'    => ['l'=>'Нет в мессенджере','g'=>'msg'],
  'called'   => ['l'=>'Дозвонился',       'g'=>'call'],
  'noanswer' => ['l'=>'Не дозвонился',    'g'=>'call'],
];}
function crm_contact_label($c){ $C=crm_contacts(); return $C[$c]['l'] ?? ''; }
function crm_contact_color($c){ return [
  'wa'=>'#22c55e','tg'=>'#0ea5e9','max'=>'#8b5cf6','nomsg'=>'#f59e0b','called'=>'#1f9d55','noanswer'=>'#9aa2ab',
][$c] ?? '#9aa2ab'; }
// С клиентом могут связаться в нескольких каналах (созвон + перевели в мессенджер),
// поэтому call_status хранит НЕСКОЛЬКО ключей через запятую: "called,max". '' = ещё не связывались.
function crm_contact_list($cs){ $out=[]; foreach(explode(',',(string)$cs) as $k){ $k=trim($k); if($k!==''&&!in_array($k,$out,true)) $out[]=$k; } return $out; }
// Включить/выключить канал в наборе. Правила: «Дозвонился»/«Не дозвонился» — взаимоисключающие;
// «Нет в мессенджере» несовместимо с конкретным мессенджером (wa/tg/max) и наоборот.
function crm_contact_toggle($cs,$k){
  $C=crm_contacts(); if(!isset($C[$k])) return (string)$cs;
  $cur=crm_contact_list($cs);
  if(in_array($k,$cur,true)){
    $cur=array_values(array_filter($cur,function($x)use($k){ return $x!==$k; }));
  } else {
    if(($C[$k]['g']??'')==='call'){ $cur=array_values(array_filter($cur,function($x)use($C){ return ($C[$x]['g']??'')!=='call'; })); }
    if($k==='nomsg'){ $cur=array_values(array_filter($cur,function($x){ return !in_array($x,['wa','tg','max'],true); })); }
    elseif(in_array($k,['wa','tg','max'],true)){ $cur=array_values(array_filter($cur,function($x){ return $x!=='nomsg'; })); }
    $cur[]=$k;
  }
  $order=array_keys($C); // канонический порядок как в crm_contacts()
  usort($cur,function($a,$b)use($order){ return array_search($a,$order)-array_search($b,$order); });
  return implode(',',$cur);
}
// Канал, который клиент выбрал в квизе (поле channel) — куда он ждёт сообщение.
// Значение приходит по-разному (max/МАКС/макс, phone/Телефон, WhatsApp/whatsapp) из
// разных версий квиза и импорта — нормализуем к единому ключу whatsapp/telegram/max/phone.
function crm_channel_norm($ch){
  $c = mb_strtolower(trim((string)$ch), 'UTF-8');
  $map = [
    'whatsapp'=>'whatsapp','вотсап'=>'whatsapp','ватсап'=>'whatsapp','wa'=>'whatsapp',
    'telegram'=>'telegram','телеграм'=>'telegram','тг'=>'telegram','tg'=>'telegram',
    'max'=>'max','макс'=>'max',
    'phone'=>'phone','телефон'=>'phone','тел'=>'phone','звонок'=>'phone',
  ];
  return $map[$c] ?? '';
}
function crm_channel_label($ch){
  $l = ['whatsapp'=>'WhatsApp','telegram'=>'Telegram','max'=>'МАКС','phone'=>'Телефон'][crm_channel_norm($ch)] ?? '';
  return $l !== '' ? $l : trim((string)$ch);
}
function crm_channel_color($ch){
  return ['whatsapp'=>'#22c55e','telegram'=>'#0ea5e9','max'=>'#8b5cf6','phone'=>'#9aa2ab'][crm_channel_norm($ch)] ?? '#9aa2ab';
}
