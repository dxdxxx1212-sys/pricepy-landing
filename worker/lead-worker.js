// Cloudflare Worker: доставка заявок и уведомлений в Telegram (api.telegram.org с российского сервера закрыт).
// Развёрнут ВРУЧНУЮ: dash.cloudflare.com → Workers & Pages → throbbing-union-7326pricepy-leads → Edit code.
// Этот файл — источник правды (в Cloudflare сам не попадает). Переменные в Cloudflare: BOT_TOKEN, CHAT_ID, LEAD_SECRET.
//
// Две ветки, обе за секретом X-Lead-Secret:
//  1) заявка с сайта (от api/lead.php) → форматируется и шлётся в CHAT_ID (владельцу);
//  2) {op_notify:{chat_id,text}} от CRM → готовый текст в личный чат оператора при назначении лида.
//
// Ответ: 200 — Telegram принял (или временный сбой, доставка дожимается в фоне);
//        502 — Telegram отказал насовсем (отозван токен, бот заблокирован, битый текст) — lead.php
//        запишет это в leads-errors.log, а не будет считать заявку доставленной.
const MAX_TEXT = 3800;              // лимит Telegram 4096; длинные поля обрезаем, иначе сообщение не уйдёт вовсе
const json = (o, status = 200) => new Response(JSON.stringify(o), { status, headers: { 'Content-Type': 'application/json' } });

export default {
  async fetch(request, env, ctx) {
    if (request.method !== 'POST') return json({ ok: false }, 405);
    // Секрет обязателен. Раньше при незаданной переменной проверка выключалась целиком — и воркер
    // был открыт всем: можно было слать что угодно в чат владельца и операторам от имени бота.
    if (!env.LEAD_SECRET) return json({ ok: false, error: 'LEAD_SECRET not configured' }, 500);
    if (request.headers.get('X-Lead-Secret') !== env.LEAD_SECRET) return json({ ok: false }, 401);

    let d;
    try { d = await request.json(); } catch (e) { d = null; }
    if (!d || typeof d !== 'object' || Array.isArray(d)) return json({ ok: false }, 400);

    const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    const cut = s => (s.length > MAX_TEXT ? s.slice(0, MAX_TEXT) + '\n… (обрезано, полный текст — в CRM)' : s);

    let payload;
    if (d.op_notify && d.op_notify.chat_id && d.op_notify.text) {
      // текст готовит CRM (уже с HTML-экранированием)
      payload = { chat_id: d.op_notify.chat_id, text: cut(String(d.op_notify.text)) };
    } else {
      const copyable = ['contact', 'phone'];          // тап по полю в Telegram копирует его
      const skip = ['hp', 'rid'];                      // служебные поля
      let lines = '';
      for (const [k, v] of Object.entries(d)) {
        if (skip.includes(k)) continue;
        const val = esc(String(v ?? '').slice(0, 1000));
        lines += `${esc(k)}: ` + (copyable.includes(k) ? `<code>${val}</code>` : val) + '\n';
      }
      payload = { chat_id: env.CHAT_ID, text: cut(`🆕 <b>Новая заявка с сайта</b>\n\n${lines}`) };
    }
    payload.parse_mode = 'HTML';
    payload.disable_web_page_preview = true;

    const url = `https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`;
    const body = JSON.stringify(payload);
    const tg = async () => {
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body });
      const j = await r.json().catch(() => ({}));
      return { ok: !!(j && j.ok), code: r.status, retryAfter: j && j.parameters && j.parameters.retry_after };
    };

    // Первая попытка — сразу, чтобы знать результат. Окончательный отказ (400/401/403/404) возвращаем
    // как 502; временный (429, 5xx, сеть) — дожимаем в фоне и отвечаем 200: заявка не потеряется.
    let first;
    try { first = await tg(); } catch (e) { first = { ok: false, code: 0 }; }
    if (first.ok) return json({ ok: true });
    if ([400, 401, 403, 404].includes(first.code)) return json({ ok: false, error: 'telegram ' + first.code }, 502);

    ctx.waitUntil((async () => {
      let wait = first.retryAfter || 2;
      for (let i = 0; i < 3; i++) {
        await new Promise(res => setTimeout(res, Math.min(wait, 20) * 1000));
        try { const r = await tg(); if (r.ok) return; wait = r.retryAfter || 2; } catch (e) { wait = 2; }
      }
    })());
    return json({ ok: true, queued: true });
  }
};
