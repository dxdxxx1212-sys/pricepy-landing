<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- База ----
// Одно соединение на запрос. Схема и миграции гоняются ТОЛЬКО когда база отстаёт от CRM_SCHEMA_VERSION
// (PRAGMA user_version) — в обычном запросе это один PRAGMA вместо десятка CREATE ... IF NOT EXISTS.
function crm_db(){
  static $db=null;
  if($db) return $db;
  $db = new PDO('sqlite:'.CRM_DB_PATH);
  $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $db->exec('PRAGMA journal_mode=WAL;');
  $db->exec('PRAGMA busy_timeout=3000;');
  if((int)$db->query("PRAGMA user_version")->fetchColumn() < CRM_SCHEMA_VERSION){
    crm_init_schema($db);
    crm_migrate($db);
  }
  return $db;
}
// Полная актуальная схема. Все выражения идемпотентны (IF NOT EXISTS) — безопасно и для пустой,
// и для старой базы; недостающие колонки в старых таблицах добавляет crm_migrate().
function crm_init_schema($db){
  $db->exec("CREATE TABLE IF NOT EXISTS leads(
    id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, source TEXT,
    name TEXT, contact TEXT, channel TEXT,
    use_ TEXT, capacity TEXT, type TEXT, budget TEXT, timing TEXT,
    utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, utm_content TEXT, utm_term TEXT,
    gclid TEXT, yclid TEXT, items TEXT, phone_norm TEXT,
    ip TEXT, ua TEXT, raw TEXT,
    status TEXT DEFAULT 'new', call_status TEXT DEFAULT '', assignee_id INTEGER,
    next_action_at TEXT, sale_amount TEXT, model TEXT, reject_reason TEXT, updated_at TEXT,
    work_at TEXT, work_by INTEGER, status_at TEXT)"); // work_*: когда/кто впервые взял лид в работу (первый выход из «Новый»); status_at: когда поставлен текущий статус
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_status ON leads(status)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at)");
  // оператор в каждом запросе фильтрует по assignee_id — без индекса это полный скан таблицы
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_assignee ON leads(assignee_id)");
  $db->exec("CREATE TABLE IF NOT EXISTS users(
    id INTEGER PRIMARY KEY AUTOINCREMENT, login TEXT UNIQUE, pass_hash TEXT,
    name TEXT, role TEXT DEFAULT 'operator', active INTEGER DEFAULT 1, created_at TEXT,
    feed_share INTEGER DEFAULT 0, feed_active INTEGER DEFAULT 0, tg_chat_id TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS comments(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, body TEXT, created_at TEXT, edited_at TEXT)");
  $db->exec("CREATE TABLE IF NOT EXISTS events(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, user_id INTEGER, type TEXT, detail TEXT, created_at TEXT)");
  // Вложения к комментариям (фото/скрины). path — имя файла внутри CRM_UPLOAD_DIR (генерим сами, не из ввода).
  $db->exec("CREATE TABLE IF NOT EXISTS attachments(
    id INTEGER PRIMARY KEY AUTOINCREMENT, lead_id INTEGER, comment_id INTEGER, user_id INTEGER,
    path TEXT, orig_name TEXT, mime TEXT, size INTEGER, created_at TEXT)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_att_lead ON attachments(lead_id)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_att_comment ON attachments(comment_id)");
  // антибрутфорс входа: неудачные попытки по IP (см. crm_login_throttle)
  $db->exec("CREATE TABLE IF NOT EXISTS login_fails(ip TEXT, ts INTEGER)");
  $db->exec("CREATE INDEX IF NOT EXISTS idx_login_fails ON login_fails(ip,ts)");
}
// Миграции для баз, созданных до появления колонок (ALTER + бэкофилл). Вызывается только когда
// user_version отстаёт; в конце штампует актуальную версию.
function crm_migrate($db){
  $cols = $db->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if(!in_array('phone_norm',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN phone_norm TEXT"); }
  if(!in_array('call_status',$cols,true)){ $db->exec("ALTER TABLE leads ADD COLUMN call_status TEXT DEFAULT ''"); }
  // v5: когда/кто взял лид в работу. Старым лидам — из истории: первое событие «Новый → …».
  if(!in_array('work_at',$cols,true)){
    $db->exec("ALTER TABLE leads ADD COLUMN work_at TEXT");
    $db->exec("ALTER TABLE leads ADD COLUMN work_by INTEGER");
    $db->exec("UPDATE leads SET
      work_at=(SELECT e.created_at FROM events e WHERE e.lead_id=leads.id AND e.type='статус' AND e.detail LIKE 'Новый → %' ORDER BY e.id LIMIT 1),
      work_by=(SELECT e.user_id   FROM events e WHERE e.lead_id=leads.id AND e.type='статус' AND e.detail LIKE 'Новый → %' ORDER BY e.id LIMIT 1)
      WHERE status<>'new'");
  }
  // v6: когда поставлен текущий статус. Старым лидам — из истории: последнее событие «… → …».
  if(!in_array('status_at',$cols,true)){
    $db->exec("ALTER TABLE leads ADD COLUMN status_at TEXT");
    $db->exec("UPDATE leads SET status_at=(SELECT e.created_at FROM events e WHERE e.lead_id=leads.id AND e.type='статус' ORDER BY e.id DESC LIMIT 1) WHERE status<>'new'");
  }
  $db->exec("CREATE INDEX IF NOT EXISTS idx_leads_phone ON leads(phone_norm)"); // индекс здесь, а не в init: у старой базы колонка появляется только строкой выше
  // комментарии: колонка edited_at (для пометки «изменён» владельцем)
  $ccols = $db->query("PRAGMA table_info(comments)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if($ccols && !in_array('edited_at',$ccols,true)){ $db->exec("ALTER TABLE comments ADD COLUMN edited_at TEXT"); }
  // v3: авто-подача лидов операторам (доля потока + тумблер) и tg-чат оператора (для Этапа 3)
  $ucols = $db->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_COLUMN, 1);
  if($ucols && !in_array('feed_share',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN feed_share INTEGER DEFAULT 0"); }
  if($ucols && !in_array('feed_active',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN feed_active INTEGER DEFAULT 0"); }
  if($ucols && !in_array('tg_chat_id',$ucols,true)){ $db->exec("ALTER TABLE users ADD COLUMN tg_chat_id TEXT"); }
  // Перенос старой плоской воронки в двухполевую модель (идемпотентно — после переноса таких строк нет).
  $legacy = (int)$db->query("SELECT COUNT(*) FROM leads WHERE status IN('messaged','noanswer')")->fetchColumn();
  if($legacy){
    // «Не дозвонился» был этапом → теперь это канал связи, а сделка остаётся «в работе».
    $db->exec("UPDATE leads SET call_status='noanswer', status='work' WHERE status='noanswer'");
    // «Написал в мессенджере» → канал = мессенджер, который клиент выбрал в квизе (если знаем), иначе WhatsApp.
    $db->exec("UPDATE leads SET call_status=CASE channel WHEN 'telegram' THEN 'tg' WHEN 'max' THEN 'max' ELSE 'wa' END, status='work' WHERE status='messaged'");
  }
  // Пересчитать нормализованный телефон для ВСЕХ строк (канонизация 8→7, 10-значный 9XX→7 9XX) —
  // чтобы дедуп/поиск/«повторный клиент» ловили один номер в любом формате.
  $need = $db->query("SELECT id,contact FROM leads WHERE contact<>''")->fetchAll();
  if($need){ $up=$db->prepare("UPDATE leads SET phone_norm=? WHERE id=?");
    foreach($need as $r){ $up->execute([crm_phone_norm($r['contact']), $r['id']]); } }
  $db->exec("PRAGMA user_version=".CRM_SCHEMA_VERSION);
}
