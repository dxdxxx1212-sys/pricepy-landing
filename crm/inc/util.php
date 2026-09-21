<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Утилиты: экранирование, даты, телефоны, склонения ----
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Текущий момент в формате хранения (ISO 8601 с зоной) — единый для created_at/updated_at/событий.
function crm_now(){ return date('c'); }
function crm_dt($iso){ if(!$iso) return '—'; $t=strtotime($iso); return $t? date('d.m.Y H:i',$t):h($iso); }
function crm_dt_short($iso){ $t=$iso?strtotime($iso):0; return $t? date('d.m H:i',$t):'—'; } // без года — для тесных мест в списке
// Канонический ключ телефона для дедупа/поиска/связки: РФ-номер → 7XXXXXXXXXX (8→7, 10-значный 9XX→7 9XX).
// Ник/не-телефон — как цифры (обычно ''). Единственное место, где разбирается формат номера.
function crm_phone_norm($c){
  $d = preg_replace('/\D+/','',(string)$c);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '7'.substr($d,1);
  if(strlen($d)===10 && $d[0]==='9') return '7'.$d;
  return $d;
}
// Телефон в формате для набора/мессенджеров (МАКС, WhatsApp, звонилка): +7XXXXXXXXXX. '' — если номера нет.
function crm_phone_e164($c){ $n=crm_phone_norm($c); return $n==='' ? '' : '+'.$n; }
// Красивый вид телефона: +7 900 123-45-67. Ник/необычный формат — как есть.
function crm_phone_fmt($c){
  $n = crm_phone_norm($c);
  if(preg_match('/^7(\d{3})(\d{3})(\d{2})(\d{2})$/',$n,$m)) return '+7 '.$m[1].' '.$m[2].'-'.$m[3].'-'.$m[4];
  return trim((string)$c);
}
// Похоже ли contact на настоящий контакт (для импорта: тест/мусор с кривым телефоном не тащим).
function crm_contact_plausible($contact){
  $n = crm_phone_norm($contact);
  if(preg_match('/^79\d{9}$/', $n)) return true;                          // российский мобильный
  if(preg_match('/@[A-Za-z0-9_]{4,}/u', (string)$contact)) return true;  // телеграм-ник @name
  return false;
}
// «лид / лида / лидов» по числу
function crm_plural_lead($n){ $n=abs((int)$n)%100; $d=$n%10; if($n>10&&$n<20) return 'лидов'; if($d===1) return 'лид'; if($d>=2&&$d<=4) return 'лида'; return 'лидов'; }
