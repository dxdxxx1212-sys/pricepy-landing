<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Утилиты: экранирование, даты, телефоны, склонения ----
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function crm_dt($iso){ if(!$iso) return '—'; $t=strtotime($iso); return $t? date('d.m.Y H:i',$t):h($iso); }
function crm_phone_digits($c){ return preg_replace('/\D+/','',$c); }
// Канонический ключ телефона для дедупа/поиска/связки: РФ-номер → 7XXXXXXXXXX (8→7, 10-значный 9XX→7 9XX).
// Ник/не-телефон — как цифры (обычно ''). Совпадает по логике с crm_phone_e164 (только без «+»).
function crm_phone_norm($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '7'.substr($d,1);
  if(strlen($d)===10 && $d[0]==='9') return '7'.$d;
  return $d;
}
// Телефон в формате для набора/мессенджеров (МАКС, WhatsApp, звонилка): +7XXXXXXXXXX.
// Учитывает, что в базе номер может лежать как 79.., 89.., так и просто 9.. (без кода страны).
function crm_phone_e164($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '+7'.substr($d,1);
  if(strlen($d)===10 && $d[0]==='9') return '+7'.$d;
  if($d==='') return '';
  return '+'.$d; // нестандартный — отдаём как есть с плюсом
}
// Красивый вид телефона: +7 900 123-45-67. Ник/необычный формат — как есть.
function crm_phone_fmt($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(preg_match('/^[78](\d{3})(\d{3})(\d{2})(\d{2})$/',$d,$m)) return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
  if(preg_match('/^(9\d{2})(\d{3})(\d{2})(\d{2})$/',$d,$m))    return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
  return trim((string)$c);
}
// «лид / лида / лидов» по числу
function crm_plural_lead($n){ $n=abs((int)$n)%100; $d=$n%10; if($n>10&&$n<20) return 'лидов'; if($d===1) return 'лид'; if($d>=2&&$d<=4) return 'лида'; return 'лидов'; }
