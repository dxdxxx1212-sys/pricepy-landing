<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Telegram: уведомления операторам через Cloudflare Worker ----
// Общий секрет для воркера (X-Lead-Secret). Живёт в api/config.php вне git. В приёме заявки
// (api/lead.php) файл уже подключён на верхнем уровне — берём глобальную $LEAD_SECRET;
// в панели CRM подключаем сами, один раз.
function crm_lead_secret(){
  static $secret=null;
  if($secret!==null) return $secret;
  $secret='';
  if(isset($GLOBALS['LEAD_SECRET'])){ $secret=(string)$GLOBALS['LEAD_SECRET']; }
  else { $cfg=__DIR__.'/../../api/config.php'; if(is_file($cfg)){ include $cfg; if(isset($LEAD_SECRET)) $secret=(string)$LEAD_SECRET; } }
  return $secret;
}
// Низкоуровневая отправка готового HTML-текста в Telegram-чат через тот же Cloudflare Worker
// (ветка op_notify; прямой api.telegram.org с РФ-сервера закрыт). Полностью fail-safe.
function crm_tg_send($chatId,$text){
  try{
    $chatId=trim((string)$chatId); if($chatId===''||$text==='') return;
    $payload=json_encode(['op_notify'=>['chat_id'=>$chatId,'text'=>$text]], JSON_UNESCAPED_UNICODE);
    $ch=curl_init(CRM_WORKER_URL);
    curl_setopt_array($ch,[
      CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Lead-Secret: '.crm_lead_secret()],
      CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>6, CURLOPT_CONNECTTIMEOUT=>4,
    ]);
    curl_exec($ch);
  }catch(Throwable $e){ /* уведомление необязательно — ничего не роняем */ }
}
// Уведомление оператору в его личный Telegram об ОДНОМ назначенном лиде (авто или вручную).
// Тихо не делает ничего, если у оператора не задан tg_chat_id. Fail-safe.
function crm_notify_operator($uid,$leadId){
  try{
    $uid=(int)$uid; $leadId=(int)$leadId; if($uid<=0||$leadId<=0) return;
    $db=crm_db();
    $u=$db->prepare("SELECT tg_chat_id FROM users WHERE id=? AND active=1"); $u->execute([$uid]); $chat=$u->fetchColumn();
    if(trim((string)$chat)==='') return; // нет привязанного чата — выходим тихо
    $L=crm_lead_get($leadId);
    if(!$L) return;
    $req=crm_lead_request_summary($L);
    $phone=crm_phone_e164($L['contact']) ?: $L['contact'];
    $text="🔔 <b>Вам назначен лид</b>\n"
        ."Имя: ".h($L['name']?:'—')."\n"
        ."Телефон: <code>".h($phone)."</code>\n"
        .($req!=='' ? ("Запрос: ".h($req)."\n") : '')
        ."\n".CRM_BASE_URL."/view.php?id=".$leadId;
    crm_tg_send($chat,$text);
  }catch(Throwable $e){ /* fail-safe */ }
}
