// Cloudflare Worker: приём заявки от lead.php и доставка в Telegram.
// Развёрнут ВРУЧНУЮ в Cloudflare (dash.cloudflare.com → Workers → throbbing-union-7326pricepy-leads).
// Секреты в Cloudflare: BOT_TOKEN, CHAT_ID. Этот файл — источник правды (в репо не автодеплоится).
// Особенности: доставка в фоне (ctx.waitUntil) + до 4 повторов (retry_after) — заявки не теряются.
// Поле contact/phone оборачивается в <code> → в Telegram тап по номеру копирует его.
export default {
  async fetch(request, env, ctx) {
    const cors = {'Access-Control-Allow-Origin':'*','Access-Control-Allow-Methods':'POST,OPTIONS','Access-Control-Allow-Headers':'Content-Type,X-Lead-Secret'};
    if (request.method === 'OPTIONS') return new Response(null,{headers:cors});
    if (request.method !== 'POST') return new Response('ok',{headers:cors});

    // Приём только от нашего сервера: если в Cloudflare задан секрет LEAD_SECRET —
    // требуем совпадения заголовка. Пока секрет не задан, воркер работает как раньше
    // (безопасная раскатка: сначала выкатываем lead.php с заголовком, потом включаем проверку).
    if (env.LEAD_SECRET && request.headers.get('X-Lead-Secret') !== env.LEAD_SECRET) {
      return new Response('{"ok":false}',{status:401,headers:{...cors,'Content-Type':'application/json'}});
    }

    let d;
    try { d = await request.json(); } catch(e){ d = {}; }

    const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const copyable = ['contact','phone'];   // эти поля — тап-для-копирования
    const skip = ['hp'];                      // служебные поля (honeypot) — не показываем
    let lines = '';
    for (const [k,v] of Object.entries(d)) {
      if (skip.includes(k)) continue;
      lines += `${esc(k)}: ` + (copyable.includes(k) ? `<code>${esc(v)}</code>` : esc(v)) + '\n';
    }
    const body = JSON.stringify({
      chat_id: env.CHAT_ID,
      text: `🆕 <b>Новая заявка с сайта</b>\n\n${lines}`,
      parse_mode: 'HTML',
      disable_web_page_preview: true
    });
    const url = `https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`;

    // доставка в фоне с повторами — Cloudflare не оборвёт (waitUntil)
    const deliver = (async () => {
      for (let i = 0; i < 4; i++) {
        try {
          const r = await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body});
          const j = await r.json().catch(()=>({}));
          if (j && j.ok) return;
          const wait = (j && j.parameters && j.parameters.retry_after) ? j.parameters.retry_after : 2;
          await new Promise(res => setTimeout(res, Math.min(wait,15)*1000));
        } catch(e) {
          await new Promise(res => setTimeout(res, 2000));
        }
      }
    })();
    ctx.waitUntil(deliver);

    return new Response('{"ok":true}',{headers:{...cors,'Content-Type':'application/json'}});
  }
}
