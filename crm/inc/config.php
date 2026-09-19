<?php
// Модуль библиотеки CRM. Подключается только через crm/lib.php — прямой вызов по URL отдаёт 403.
if(!defined('CRM_LIB')){ http_response_code(403); exit; }
// ---- Настройки (пути, версия схемы, внешние адреса) ----
define('CRM_DB_PATH', getenv('CRM_DB') ?: '/var/lib/pricepy-crm/leads.sqlite');
// Вложения к комментариям (фото/скрины) — рядом с базой: вне веб-корня и вне git,
// значит переживают автодеплой и недоступны напрямую по URL (отдаём только через att.php за авторизацией).
define('CRM_UPLOAD_DIR', getenv('CRM_UPLOAD') ?: dirname(CRM_DB_PATH).'/uploads');
define('CRM_SCHEMA_VERSION', 4); // версия схемы (PRAGMA user_version): схема и миграции гоняются только когда БД отстаёт. v4: login_fails в схеме
// Для уведомлений операторам в личный Telegram (Этап 3). Тот же воркер и секрет, что и приём заявок.
define('CRM_WORKER_URL', getenv('CRM_WORKER') ?: 'https://throbbing-union-7326pricepy-leads.dxdxxx1212.workers.dev');
define('CRM_BASE_URL', getenv('CRM_BASE') ?: 'https://crm.xn----ctbklixakchgm2d.xn--p1ai'); // ссылка на карточку лида в уведомлении
date_default_timezone_set('Europe/Moscow'); // все даты/время и KPI «сегодня» — по Москве
