<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Утилиты: экранирование, даты, телефоны, склонения ----
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
// Текущий момент в формате хранения (ISO 8601 с зоной) — единый для created_at/updated_at/событий.
function crm_now(){ return date('c'); }
function crm_dt($iso){ if(!$iso) return '—'; $t=strtotime($iso); return $t? date('d.m.Y H:i',$t):h($iso); }
function crm_ucfirst($s){ return mb_strtoupper(mb_substr($s,0,1,'UTF-8'),'UTF-8').mb_substr($s,1,null,'UTF-8'); }
function crm_dt_short($iso){ $t=$iso?strtotime($iso):0; return $t? date('d.m H:i',$t):'—'; } // без года — для тесных мест в списке
// Канонический ключ телефона для дедупа/поиска/связки: РФ-номер → 7XXXXXXXXXX, иностранный в формате +… → цифры.
// Всё, что НЕ похоже на телефон, → '' (ник @ivan2024, мусор, обрывки). Иначе «2024» из двух разных ников
// склеивал разных клиентов в «повтор», а кнопки «Позвонить»/WhatsApp вели в никуда.
// Единственное место, где разбирается формат номера.
function crm_phone_norm($c){
  $s = preg_split('/[\/,;]|\b(?:доб|ext)\.?\s*\d/iu', (string)$c)[0];  // «… / второй номер», «… доб. 101» — берём первый номер
  if(strpos($s,'@')!==false) return '';                                   // телеграм-ник / почта — не телефон
  $intl = (ltrim($s)[0] ?? '')==='+';
  $d = preg_replace('/\D+/','',$s);
  if(strlen($d)===11 && ($d[0]==='8'||$d[0]==='7')) return '7'.substr($d,1);   // 8 900… / +7 900… / 7 495…
  if(strlen($d)===10 && strpos('3489',$d[0])!==false && substr($d,0,2)!=='89') return '7'.$d; // без кода страны: 900…, 495…, 812…; «89…» из 10 цифр — мобильный без цифры
  if($intl && strlen($d)>=11 && strlen($d)<=15) return $d;                       // иностранный: +375…, +996…
  return '';
}
// Телефон в формате для набора/мессенджеров (МАКС, WhatsApp, звонилка): +7XXXXXXXXXX. '' — если номера нет.
function crm_phone_e164($c){ $n=crm_phone_norm($c); return $n==='' ? '' : '+'.$n; }
// Красивый вид телефона: +7 900 123-45-67. Ник/необычный формат — как есть.
function crm_phone_fmt($c){
  if(preg_match('/[\/,;]|\b(?:доб|ext)\.?\s*\d/iu',(string)$c)) return trim((string)$c);   // добавочный / второй номер — не теряем при показе
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
