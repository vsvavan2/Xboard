<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="robots" content="noindex, nofollow" />
  <title>{{ $app_name }} — Ядро OlcRTC: памятка и обновление</title>
  <style>
    :root {
      --bg: #0b0f17;
      --bg2: #121826;
      --card: #151c2c;
      --card2: #1b2436;
      --border: #263149;
      --text: #e6ecf5;
      --muted: #93a1b8;
      --accent: #5b8cff;
      --accent2: #22c55e;
      --code-bg: #0d1320;
      --warn: #f59e0b;
    }
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      background: radial-gradient(1200px 600px at 20% -10%, #16203a 0%, var(--bg) 55%) no-repeat, var(--bg);
      color: var(--text);
      font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
      padding-bottom: 64px;
    }
    .wrap { max-width: 1120px; margin: 0 auto; padding: 28px 18px 0; }
    header.top {
      display: flex; flex-wrap: wrap; gap: 14px; align-items: center; justify-content: space-between;
      padding: 20px 22px; border: 1px solid var(--border); border-radius: 16px;
      background: linear-gradient(180deg, var(--card2), var(--card));
      box-shadow: 0 12px 40px rgba(0,0,0,.35);
    }
    .top h1 { font-size: 20px; margin: 0; display: flex; align-items: center; gap: 10px; letter-spacing: .2px; }
    .top .sub { color: var(--muted); font-size: 13px; margin-top: 4px; }
    .badge {
      display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px;
      background: rgba(91,140,255,.14); color: #a9c1ff; border: 1px solid rgba(91,140,255,.35);
    }
    .btn {
      display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
      padding: 10px 16px; border-radius: 10px; font-weight: 600; font-size: 14px;
      background: var(--accent); color: #fff; border: 1px solid transparent; cursor: pointer;
    }
    .btn.ghost { background: transparent; color: var(--text); border-color: var(--border); }
    .btn:hover { filter: brightness(1.08); }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 16px; margin-top: 18px; }
    .card {
      border: 1px solid var(--border); border-radius: 14px; background: var(--card);
      padding: 18px 18px 14px; box-shadow: 0 8px 26px rgba(0,0,0,.22);
    }
    .card h2 { font-size: 15px; margin: 0 0 4px; display: flex; align-items: center; gap: 9px; }
    .card p.desc { color: var(--muted); font-size: 13px; margin: 6px 0 12px; }
    .card.full { grid-column: 1 / -1; }
    pre {
      position: relative; margin: 0 0 10px; padding: 13px 14px; overflow-x: auto;
      background: var(--code-bg); border: 1px solid #1d2740; border-radius: 10px;
      font: 13px/1.55 ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
      color: #cfe3ff; white-space: pre;
    }
    pre code { white-space: pre; }
    .copy {
      position: absolute; top: 8px; right: 8px; font-size: 11px; padding: 4px 9px;
      border-radius: 7px; border: 1px solid var(--border); background: #182135; color: var(--muted);
      cursor: pointer; opacity: .85;
    }
    .copy:hover { color: #fff; border-color: var(--accent); }
    .copy.ok { color: #baf7cf; border-color: var(--accent2); }
    ul.clean { margin: 6px 0 10px; padding-left: 18px; }
    ul.clean li { margin: 5px 0; }
    a.link { color: #a9c1ff; text-decoration: none; border-bottom: 1px dashed rgba(169,193,255,.4); }
    a.link:hover { color: #fff; border-bottom-color: #fff; }
    .note {
      border-left: 3px solid var(--warn); background: rgba(245,158,11,.08);
      padding: 10px 12px; border-radius: 8px; color: #ffd9a3; font-size: 13px; margin: 8px 0 12px;
    }
    .ok { border-left-color: var(--accent2); background: rgba(34,197,94,.08); color: #baf7cf; }
    code.inl {
      background: #0d1320; border: 1px solid #1d2740; border-radius: 6px; padding: 1px 6px;
      font: 12.5px ui-monospace, Menlo, Consolas, monospace; color: #cfe3ff;
    }
    table.tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
    table.tbl th, table.tbl td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
    table.tbl th { color: var(--muted); font-weight: 600; }
    footer.foot { color: var(--muted); font-size: 12px; text-align: center; margin-top: 24px; }
    .kbd { font: 12px ui-monospace, monospace; background: #0d1320; border: 1px solid var(--border); border-radius: 5px; padding: 1px 6px; }
    @media (max-width: 560px) {
      .wrap { padding: 16px 12px 0; }
      header.top { padding: 16px; }
      .top h1 { font-size: 17px; }
      .grid { grid-template-columns: 1fr; }
      pre { font-size: 12px; }
    }
  </style>
</head>
<body>
<div class="wrap">

  <header class="top">
    <div>
      <h1>🔄 Ядро OlcRTC <span class="badge">openlibrecommunity/olcrtc</span></h1>
      <div class="sub">Памятка: как обновлять ядро и панель, диагностика, оплата и полезные команды</div>
    </div>
    <a class="btn ghost" href="/{{ $secure_path }}">← В админку</a>
  </header>

  <div class="grid">

    <!-- 1 -->
    <section class="card">
      <h2>🧬 1. Обновить ядро OlcRTC (одна команда)</h2>
      <p class="desc">Ключи <code class="inl">olcrtc://…</code> выдаёт бинарник <b>olcrtc</b>, вшитый внутрь образа
        <code class="inl">olcrtc-manager</code>. У апстрима нет релизов — обновление = пересборка образа.</p>
<pre><button class="copy">копировать</button><code>cd /opt/xboard
sh update-olcrtc.sh --check     # есть ли обновление (код 10 = есть)
sh update-olcrtc.sh --status    # что установлено и что в апстриме
sh update-olcrtc.sh             # ОБНОВИТЬ (одна команда)</code></pre>
      <div class="note">Обычный <code class="inl">docker compose build</code> берёт <b>закэшированный</b> слой и новый код
        ядра не подтянет — используйте именно <code class="inl">update-olcrtc.sh</code>.</div>
    </section>

    <!-- 2 -->
    <section class="card">
      <h2>✅ 2. Проверить установленную версию ядра</h2>
      <p class="desc">Версия ядра пишется при сборке и отдаётся в <code class="inl">/healthz</code>.</p>
<pre><button class="copy">копировать</button><code>docker compose exec olcrtc-manager cat /etc/olcrtc.version
curl -s http://127.0.0.1:8080/healthz
# {"status":"ok","olcrtc_version":"&lt;commit-sha&gt;"}</code></pre>
      <div class="note ok">Ключи пользователей (<code class="inl">olcrtc://…</code>) при обновлении ядра <b>не меняются</b> —
        переустанавливать клиентам ничего не нужно.</div>
    </section>

    <!-- 3 -->
    <section class="card">
      <h2>⏰ 3. Автообновление (чтобы «всегда работало»)</h2>
      <p class="desc">Раз в неделю скрипт сам проверяет апстрим и пересобирает ядро только при наличии нового коммита.</p>
<pre><button class="copy">копировать</button><code>sh update-olcrtc.sh --install-cron    # ставить (вс 04:30)
sh update-olcrtc.sh --uninstall-cron  # снять
sh update-olcrtc.sh --force --prune   # принудительно + очистка кэша (малый диск)</code></pre>
      <p class="desc">Пересборка под конкретный тег/ветку/коммит: <code class="inl">sh update-olcrtc.sh --ref v0.0.1</code></p>
    </section>

    <!-- 4 -->
    <section class="card">
      <h2>⬆️ 4. Обновить саму панель (Xboard)</h2>
      <p class="desc">Стандартное обновление: оно <b>тоже</b> автоматически подтягивает свежее ядро.</p>
<pre><button class="copy">копировать</button><code>cd /opt/xboard
sh update.sh</code></pre>
      <div class="note"><b>Never</b> запускайте <code class="inl">install.sh</code> повторно на рабочей панели — он пересоздаёт
        БД и настройки. Для обновления — только <code class="inl">update.sh</code>.</div>
    </section>

    <!-- 5 -->
    <section class="card full">
      <h2>🩺 5. Диагностика и обслуживание</h2>
<pre><button class="copy">копировать</button><code>cd /opt/xboard

docker compose ps                          # статус всех контейнеров
docker compose logs -f --tail=120 xboard   # логи панели (Laravel/Octane)
docker compose logs -f --tail=120 olcrtc-manager   # логи VPN-менеджера

docker compose restart xboard olcrtc-manager       # перезапуск
docker compose up -d --force-recreate olcrtc-manager  # перечитать .env

# После правок Blade/роутов ОБЯЗАТЕЛЬНО:
docker compose exec xboard php artisan optimize:clear

# Ключи содержат room-URL (а не uuid) — проверка:
docker compose exec olcrtc-manager sh -lc 'cat /var/lib/olcrtc-manager/instances/*.yaml | head -20'

# Очистить кэш сборки (нужно на VDS с малым диском):
docker builder prune -af</code></pre>
      <table class="tbl">
        <tr><th>Симптом</th><th>Что делать</th></tr>
        <tr><td>Обновил ядро, версия не изменилась</td><td>Docker build-cache — используйте <code class="inl">sh update-olcrtc.sh</code></td></tr>
        <tr><td>Правки в Blade/роутах не видны</td><td><code class="inl">php artisan optimize:clear</code> + пересборка образа</td></tr>
        <tr><td>Сборка: <code class="inl">no space left on device</code></td><td><code class="inl">docker builder prune -af</code></td></tr>
        <tr><td>Правки <code class="inl">routes/web.php</code> не применились</td><td>Файл внутри образа → нужна пересборка <code class="inl">xboard</code></td></tr>
      </table>
    </section>

    <!-- 6 -->
    <section class="card full">
      <h2>💳 6. Оплата: ЮKassa и ЮMoney</h2>
      <p class="desc">Настройка — админка → <b>Платежи</b> (методы оплаты). Ключи API берутся в кабинетах платёжных систем.</p>
      <table class="tbl">
        <tr><th>Система</th><th>Что заполнить и где взять</th><th>Webhook (уведомления)</th></tr>
        <tr>
          <td><b>ЮKassa</b></td>
          <td><code class="inl">shop_id</code> — «Настройки» → «Магазин»; <code class="inl">secret_key</code> — «Настройки» → «Ключи API» (<code class="inl">live_…</code>/<code class="inl">test_…</code>)</td>
          <td>Только <b>HTTPS</b> (443/8443), «Интеграция» → «HTTP-уведомления», событие <code class="inl">payment.succeeded</code></td>
        </tr>
        <tr>
          <td><b>ЮMoney</b></td>
          <td><code class="inl">wallet</code> — номер кошелька; <code class="inl">secret</code> — «Секрет для проверки подлинности» на странице HTTP-уведомлений</td>
          <td>Работает по HTTP и HTTPS. Вставьте URL уведомлений ниже (без query-параметров)</td>
        </tr>
      </table>
<pre><button class="copy">копировать</button><code>URL уведомлений ЮMoney (в «Куда отправлять (URL сайта)»):
{{ rtrim(config('app.url'), '/') }}/api/v1/guest/payment/notify/yoomoney/{UUID-из-админки}

Страницы платёжных систем:
ЮKassa  → https://yookassa.ru/my/api-keys        (и https://yookassa.ru/my → HTTP-уведомления)
ЮMoney  → https://yoomoney.ru/transfer/myservices/http-notification</code></pre>
      <p class="desc" style="margin-top:14px">
        <b>Валюта — рубли (₽):</b> цены во всей панели и в личном кабинете показываются в рублях
        (<code class="inl">RUB</code> · <code class="inl">₽</code>). Значение хранится в настройках
        (<code class="inl">currency</code> / <code class="inl">currency_symbol</code>) и автоматически
        приводится к рублю, даже если в базе остался старый «¥/CNY».
      </p>
      <p class="desc">
        <b>Ошибка «reason=referer» при оплате ЮMoney?</b> Так ЮMoney отклоняет переходы с сайтов, которые
        не зарегистрированы у него как «магазин» (проверяется заголовок Referer). В этой сборке Referer
        от страниц панели не отправляется (<code class="inl">Referrer-Policy: no-referrer</code>), поэтому
        форма оплаты открывается сразу. После обновления кода пересоберите образ и очистите кэш:
        <code class="inl">docker compose build xboard &amp;&amp; docker compose up -d &amp;&amp; docker compose exec xboard php artisan optimize:clear</code>.
      </p>
    </section>

    <!-- 7 -->
    <section class="card">
      <h2>🔗 7. Полезные ссылки</h2>
      <ul class="clean">
        <li>Ядро OlcRTC (апстрим): <a class="link" href="https://github.com/openlibrecommunity/olcrtc" target="_blank" rel="noopener">openlibrecommunity/olcrtc</a></li>
        <li>Этот форк: <a class="link" href="https://github.com/vsvavan2/Xboard" target="_blank" rel="noopener">vsvavan2/Xboard</a></li>
        <li>Панель Xboard (апстрим): <a class="link" href="https://github.com/cedar2025/xboard" target="_blank" rel="noopener">cedar2025/xboard</a></li>
        <li>Последний коммит ядра — API: <a class="link" href="https://api.github.com/repos/openlibrecommunity/olcrtc/commits?sha=master&amp;per_page=1" target="_blank" rel="noopener">/commits?sha=master</a></li>
        <li>Документация: <a class="link" href="https://github.com/vsvavan2/Xboard/blob/master/QUICKSTART.md" target="_blank" rel="noopener">QUICKSTART.md</a> · <a class="link" href="https://github.com/vsvavan2/Xboard/blob/master/docs/TROUBLESHOOTING.md" target="_blank" rel="noopener">TROUBLESHOOTING.md</a></li>
      </ul>
    </section>

    <!-- 8 -->
    <section class="card">
      <h2>🔑 8. Ключ OlcRTC — рабочий формат</h2>
<pre><button class="copy">копировать</button><code>olcrtc://jitsi?datachannel@https://&lt;JITSI-ХОСТ&gt;/olcrtc-xxxxxxxx#&lt;64hex&gt;$olc</code></pre>
      <ul class="clean">
        <li><code class="inl">room.id</code> (после <code class="inl">@</code>) — <b>полный URL</b> комнаты, не UUID;</li>
        <li>после <code class="inl">#</code> — 64-символьный <code class="inl">crypto_key</code>; хвост <code class="inl">$olc</code> обязателен;</li>
        <li>Jitsi-домен задаётся в <code class="inl">.env</code> → <code class="inl">OLCRMGR_JITSI_SERVER</code>.</li>
      </ul>
    </section>

  </div>

  <footer class="foot">
    {{ $app_name }} · админ-памятка OlcRTC · путь: <span class="kbd">/{{ $secure_path }}</span>
  </footer>
</div>

<script>
(function () {
  document.querySelectorAll('pre > .copy').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var pre = btn.parentNode;
      var text = pre.querySelector('code') ? pre.querySelector('code').innerText : pre.innerText;
      var done = function () {
        var old = btn.textContent;
        btn.textContent = 'скопировано';
        btn.classList.add('ok');
        setTimeout(function () { btn.textContent = old; btn.classList.remove('ok'); }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
      } else {
        fallback(text, done);
      }
    });
  });
  function fallback(text, done) {
    var ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) {}
    document.body.removeChild(ta);
  }
})();
</script>
</body>
</html>
