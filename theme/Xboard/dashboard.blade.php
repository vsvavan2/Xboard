<!doctype html>
<html lang="ru-RU">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <meta name="description" content="{{ preg_replace('/[\r\n]+/u',' ', htmlspecialchars($theme_config['site_description'] ?? $description ?? 'Mobi VPN — OlcRTC защищённый VPN. Купил подписку → сразу получил ключ olcrtc://jitsi?datachannel@… в ЛК.')) }}" />
  <meta property="og:site_name" content="{{ htmlspecialchars($theme_config['site_name'] ?? 'Mobi VPN · OlcRTC Secure Panel') }}" />
  <meta property="og:title" content="{{ htmlspecialchars($title ?? 'Mobi VPN') }} — OlcRTC защищённый VPN" />
  <meta property="og:description" content="Купил подписку → сразу получил зелёную карточку с кнопкой «📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)». Формат ключа: olcrtc://jitsi?datachannel@ROOM#HASH$olc" />
  <meta property="og:type" content="website" />
  <meta name="theme-color" content="#000000" />
  <meta name="color-scheme" content="dark" />
  <title>{{ htmlspecialchars($title ?? 'Mobi VPN') }} · OlcRTC Secure Panel</title>

  <style id="mvpn-matrix-base">
    :root{
      --mv-bg:#000;
      --mv-green:#00ff9c;
      --mv-green-2:#00e5ff;
      --mv-green-dim:#00ff9c55;
      --mv-text:#c9ffd9;
      --mv-text-dim:#74c99a;
      --mv-panel:#040c08cc;
      --mv-border:#00ff9c55;
    }
    *{box-sizing:border-box}
    html,body{margin:0;padding:0;background:var(--mv-bg);color:var(--mv-text);font-family:ui-monospace,Menlo,Consolas,"Courier New",monospace;min-height:100%;overflow-x:hidden}
    a{color:var(--mv-green);text-decoration:none}
    #mv-matrix-canvas{position:fixed;inset:0;width:100vw;height:100vh;z-index:0;background:#000;display:block;image-rendering:pixelated}
    #mv-matrix-grid{position:fixed;inset:0;z-index:1;pointer-events:none;
      background-image:
        linear-gradient(rgba(0,255,156,.06) 1px, transparent 1px),
        linear-gradient(90deg, rgba(0,255,156,.06) 1px, transparent 1px);
      background-size:40px 40px;
      mask-image:radial-gradient(ellipse at 50% 40%, #000 40%, transparent 80%);
      -webkit-mask-image:radial-gradient(ellipse at 50% 40%, #000 40%, transparent 80%);
    }
    #mv-scanline{position:fixed;inset:0;z-index:2;pointer-events:none;background:linear-gradient(to bottom, transparent 0%, rgba(0,255,156,.08) 50%, transparent 100%);background-size:100% 6px;mix-blend-mode:screen;animation:mv-scan 9s linear infinite;opacity:.6}
    @keyframes mv-scan{from{background-position:0 0}to{background-position:0 100%}}
    #mv-glow{position:fixed;left:50%;top:28%;transform:translate(-50%,-50%);width:900px;height:900px;z-index:2;pointer-events:none;background:radial-gradient(circle, rgba(0,255,156,.22) 0%, rgba(0,255,156,.06) 40%, transparent 70%);filter:blur(4px);animation:mv-pulse 4.2s ease-in-out infinite}
    @keyframes mv-pulse{0%,100%{opacity:.7}50%{opacity:1}}
    .mv-wrap{position:relative;z-index:10;max-width:1200px;margin:0 auto;padding:28px 20px 80px}
    .mv-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:8px 14px;border:1px solid var(--mv-border);border-radius:10px;background:var(--mv-panel);backdrop-filter:blur(4px);box-shadow:0 0 0 1px rgba(0,255,156,.08),0 8px 40px rgba(0,255,156,.08)}
    .mv-logo{display:flex;align-items:center;gap:12px;color:var(--mv-green);font-weight:700;letter-spacing:.5px}
    .mv-logo .mv-logo-badge{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,#00ff9c,#00e5ff);display:grid;place-items:center;color:#00110a;font-weight:900;box-shadow:0 0 22px rgba(0,255,156,.55)}
    .mv-logo .mv-title{font-size:15px}
    .mv-logo .mv-title b{color:var(--mv-green-2)}
    .mv-logo .mv-sub{font-size:11px;opacity:.85;color:var(--mv-text-dim)}
    .mv-pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--mv-border);border-radius:999px;color:var(--mv-text-dim);font-size:12px}
    .mv-dot{width:8px;height:8px;border-radius:50%;background:#00ff9c;box-shadow:0 0 12px #00ff9c;animation:mv-blink 1.4s ease-in-out infinite}
    @keyframes mv-blink{0%,100%{opacity:1}50%{opacity:.25}}
    .mv-hero{margin-top:22px;display:grid;grid-template-columns:1.15fr 1fr;gap:22px;align-items:stretch}
    @media (max-width: 920px){.mv-hero{grid-template-columns:1fr}}
    .mv-card{border:1px solid var(--mv-border);border-radius:14px;background:var(--mv-panel);backdrop-filter:blur(6px);padding:22px;box-shadow:0 0 0 1px rgba(0,255,156,.08), 0 20px 70px rgba(0,255,156,.12)}
    .mv-hero-left .mv-eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border:1px dashed var(--mv-green-dim);border-radius:999px;color:var(--mv-green);font-size:12px;margin-bottom:14px}
    .mv-hero-left h1{margin:0 0 10px;font-size:clamp(26px, 4.8vw, 44px);line-height:1.15;color:#fff;text-shadow:0 0 18px rgba(0,255,156,.25)}
    .mv-hero-left h1 .mv-grad{background:linear-gradient(90deg,#00ff9c 0%,#00e5ff 60%,#39ff14 100%);-webkit-background-clip:text;background-clip:text;color:transparent;text-shadow:0 0 28px rgba(0,255,156,.2)}
    .mv-hero-left p.lead{margin:0 0 14px;color:var(--mv-text-dim);line-height:1.6;font-size:14px}
    .mv-hero-left .mv-tagrow{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0 18px}
    .mv-tag{padding:6px 10px;border:1px solid var(--mv-border);border-radius:999px;color:var(--mv-text);font-size:12px;background:rgba(0,255,156,.05)}
    .mv-cta-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center}
    .mv-btn{appearance:none;border:0;border-radius:10px;padding:12px 14px;font-weight:700;font-size:14px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 0 0 1px rgba(0,255,156,.25);font-family:inherit}
    .mv-btn-primary{background:linear-gradient(135deg,#00ff9c 0%,#00e5ff 100%);color:#00110a;box-shadow:0 0 0 1px rgba(0,255,156,.4), 0 10px 30px rgba(0,255,156,.35)}
    .mv-btn-primary:hover{filter:brightness(1.05)}
    .mv-btn-ghost{background:transparent;color:var(--mv-green);border:1px solid var(--mv-border)}
    .mv-btn-ghost:hover{background:rgba(0,255,156,.08)}
    .mv-counters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:18px}
    .mv-counter{border:1px solid var(--mv-border);border-radius:10px;padding:12px 12px 10px;background:rgba(0,255,156,.04)}
    .mv-counter .mv-num{font-size:22px;font-weight:800;color:var(--mv-green);text-shadow:0 0 14px rgba(0,255,156,.4)}
    .mv-counter .mv-lab{font-size:11px;color:var(--mv-text-dim);margin-top:4px;letter-spacing:.2px;text-transform:uppercase}
    .mv-counter .mv-unit{font-size:13px;color:var(--mv-text-dim);margin-left:4px;font-weight:500}
    .mv-right{display:flex;flex-direction:column;gap:14px}
    .mv-terminal{border:1px solid var(--mv-border);border-radius:12px;background:#000c08;overflow:hidden;box-shadow:0 0 0 1px rgba(0,255,156,.08), 0 16px 48px rgba(0,255,156,.14)}
    .mv-term-head{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--mv-border);background:#020a06}
    .mv-term-dot{width:10px;height:10px;border-radius:50%}
    .mv-term-body{padding:12px 14px;color:var(--mv-text);min-height:240px;max-height:280px;overflow:auto;line-height:1.6;font-size:12.5px}
    .mv-term-body .mv-line{white-space:pre-wrap}
    .mv-term-body .mv-g{color:var(--mv-green)}
    .mv-term-body .mv-y{color:#ffe066}
    .mv-term-body .mv-c{color:var(--mv-green-2)}
    .mv-term-body .mv-prompt::before{content:'$ ';color:var(--mv-green)}
    .mv-feature-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .mv-feat{border:1px solid var(--mv-border);border-radius:10px;padding:12px 12px;background:rgba(0,255,156,.04);display:flex;flex-direction:column;gap:6px}
    .mv-feat .mv-f-ic{font-size:16px;color:var(--mv-green);filter:drop-shadow(0 0 6px rgba(0,255,156,.6))}
    .mv-feat b{font-size:13px;color:#fff}
    .mv-feat span{font-size:12px;color:var(--mv-text-dim);line-height:1.5}
    .mv-bottom{margin-top:16px;border:1px solid var(--mv-border);border-radius:10px;padding:10px 12px;background:var(--mv-panel);display:flex;flex-wrap:wrap;gap:10px 18px;justify-content:space-between;align-items:center;font-size:12px;color:var(--mv-text-dim)}
    .mv-bottom .mv-badge-lv{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border:1px solid var(--mv-border);border-radius:999px}
    #app{position:relative;z-index:20;margin-top:18px}
    .mv-noscript{position:relative;z-index:50;padding:14px;border:1px dashed #ffe066;border-radius:10px;color:#ffe066;background:#191200cc;margin-top:14px;font-size:13px}
    html.mv-auth-mode #app, html.mv-spa-only-mode #app{margin-top:16px}
    html.mv-spa-only-mode #app{margin-top:0}
  </style>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: "{{ addslashes($title) }}",
      assets_path: '/theme/{{ $theme }}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{ addslashes($version) }}',
      background_url: '{{ addslashes($theme_config['background_url'] ?? "") }}',
      description: '{{ addslashes($theme_config['site_description'] ?? $description ?? "") }}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR',
        'ru-RU'
      ],
      logo: '{{ addslashes($logo ?? "") }}'
    }
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
</head>
<body>

<canvas id="mv-matrix-canvas" aria-hidden="true"></canvas>
<div id="mv-matrix-grid" aria-hidden="true"></div>
<div id="mv-scanline" aria-hidden="true"></div>
<div id="mv-glow" aria-hidden="true"></div>

<div class="mv-wrap">
  <div class="mv-topbar">
    <div class="mv-logo">
      <div class="mv-logo-badge" aria-hidden="true">M</div>
      <div>
        <div class="mv-title"><b>Mobi</b> VPN · <span style="color:var(--mv-green-2)">OlcRTC</span> Secure Panel</div>
        <div class="mv-sub">/ encrypted WebRTC datachannel · no legacy V2Ray · 0 узлов = 0 утечек</div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="mv-pill"><span class="mv-dot" aria-hidden="true"></span><span id="mv-online-dot-text">ONLINE · менеджер ключей healthy</span></span>
      <span class="mv-pill" title="Формат ключа как в Шаге-2 Базы знаний">🔑 format: olcrtc://jitsi?datachannel@…</span>
    </div>
  </div>

  <div class="mv-hero">
    <section class="mv-card mv-hero-left" aria-label="Mobi VPN landing">
      <div class="mv-eyebrow">⚡ ЛИЧНЫЙ КАБИНЕТ · ВХОД / РЕГИСТРАЦИЯ · ниже окно входа или нажми кнопку</div>
      <h1>
        <span class="mv-grad">Mobi VPN</span> — хакерски-чистый<br />
        OlcRTC-туннель с копией ключа в 1 клик
      </h1>
      <p class="lead">
        Купил подписку → сразу получил на главной ЛК <b style="color:#fff">большую зелёную карточку</b> с кнопкой
        <b style="color:var(--mv-green)">📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)</b>. Вставил в OlcBox/owenclave → подключился.
        Никаких пустых ярлыков, никаких подписок на устаревшие протоколы.
      </p>
      <div class="mv-tagrow">
        <span class="mv-tag">🛡️ Jitsi WebRTC datachannel · обход DPI</span>
        <span class="mv-tag">🇷🇺 DNS Яндекс 77.88.8.8 уже в ключе</span>
        <span class="mv-tag">💳 ЮKassa / Сбер / СБП / карты</span>
        <span class="mv-tag">⚙️ 3 тарифа: 30д · 90д · 365д</span>
        <span class="mv-tag">🧩 Клиенты: OlcBox (ПК) · owenclave (Android)</span>
      </div>
      <div class="mv-cta-row">
        <button class="mv-btn mv-btn-primary" id="mv-cta-reg" type="button">🚀 Создать аккаунт (регистрация → 2 мин)</button>
        <button class="mv-btn mv-btn-ghost" id="mv-cta-login" type="button">🔐 Войти в ЛК</button>
        <button class="mv-btn mv-btn-ghost" id="mv-cta-docs" type="button">📚 Как подключиться (База знаний)</button>
      </div>

      <div class="mv-counters" aria-label="Сервис: живые счётчики">
        <div class="mv-counter"><div class="mv-num" id="mv-num-users">0</div><div class="mv-lab">Подключено клиентов<div id="mv-u-sub" style="display:inline-block;margin-left:6px"></div></div></div>
        <div class="mv-counter"><div class="mv-num" id="mv-num-keys">0</div><div class="mv-lab">OlcRTC-ключей выдано<div class="mv-unit">шт</div></div></div>
        <div class="mv-counter"><div class="mv-num" id="mv-num-up">0</div><div class="mv-lab">Uptime без падений<div class="mv-unit">дн</div></div></div>
      </div>
    </section>

    <aside class="mv-right" aria-label="Терминал и преимущества">
      <div class="mv-terminal" aria-hidden="true">
        <div class="mv-term-head">
          <div class="mv-term-dot" style="background:#ff5f57"></div>
          <div class="mv-term-dot" style="background:#febc2e"></div>
          <div class="mv-term-dot" style="background:#28c840"></div>
          <div style="margin-left:10px;font-size:12px;color:var(--mv-text-dim)">~/mobi-vpn/widget.sh</div>
        </div>
        <div class="mv-term-body" id="mv-term-body">
          <div class="mv-line mv-prompt">whoami</div>
          <div class="mv-line mv-g">mobi-vpn@customer # plan_id=1 (Базовый 30 дней)</div>
          <div class="mv-line mv-prompt">./mobi buy --plan base --days 30 --pay yookassa</div>
          <div class="mv-line mv-c">[ok] платёж ЮKassa 199₽ · succeeded · order_id=…</div>
          <div class="mv-line mv-prompt">./mobi key get --user me</div>
          <div class="mv-line mv-y">⏳ запрос OlcRTC-инстанса через olcrtc-manager:8080</div>
          <div class="mv-line mv-g">[OK 201] instance_id=auto-seed-20260913 room=OLCRTC-H88ACYIM</div>
          <div class="mv-line mv-g">[URI ready → copied to ЛК widget textarea]</div>
          <div class="mv-line" style="word-break:break-all">
            <span class="mv-c">olcrtc://jitsi?datachannel@https://meet.jit.si/olcrtc-h88acyim</span><span class="mv-y">#68271e0c1…$olc</span>
          </div>
          <div class="mv-line mv-prompt">./mobi vpn up --client OlcBox</div>
          <div class="mv-line mv-g">[tun0] 10.13.37.42 · route 0.0.0.0/1 · rtt=42ms · dns=77.88.8.8 ✅</div>
        </div>
      </div>

      <div class="mv-feature-grid">
        <div class="mv-feat"><span class="mv-f-ic">🔐</span><b>Никаких лишних плагинов</b><span>Включен ТОЛЬКО OlcRTC. Alipay/Coinbase/Telegram — вырезаны (AUTO-SEED sweep).</span></div>
        <div class="mv-feat"><span class="mv-f-ic">🧹</span><b>0 legacy узлов</b><span>Таблица v2_server пустая. Никаких случайных пушей нод в профиль.</span></div>
        <div class="mv-feat"><span class="mv-f-ic">🧲</span><b>Ключ на главной ЛК</b><span>После оплаты открываешь кабинет — виджет с URI/YAML/Sub URL перед глазами.</span></div>
        <div class="mv-feat"><span class="mv-f-ic">📡</span><b>Клиенты ссылочки рядом</b><span>OlcBox (ПК) и owenclave (Android) — GitHub releases, клик.</span></div>
      </div>
    </aside>
  </div>

  <div class="mv-bottom">
    <div>
      <span class="mv-badge-lv">🌐 Протокол: <b style="color:var(--mv-green)">OlcRTC · jitsi · datachannel</b></span>
      <span class="mv-badge-lv" style="margin-left:8px">🧪 Формат БЗ Шаг-2: <b style="color:var(--mv-green)">olcrtc://jitsi?datachannel@ROOM#HASH$olc</b></span>
    </div>
    <div>© Mobi VPN · <span id="mv-y">{{ date('Y') }}</span> · смените админ-пароль сразу после первого входа</div>
  </div>

  <noscript>
    <div class="mv-noscript">
      ⚠️ В вашем браузере отключён JavaScript. Страница входа/регистрации (Umi SPA) и матрица-фон требуют включенный JS.
      Включите JS и перезагрузите. Временные прямые ссылки: <b>/#/user/login</b> · <b>/#/user/register</b> · <b>/#/knowledge</b>.
    </div>
  </noscript>

  <!-- ===== SPA mount point (Umi.js React login/register/dashboard renders inside this div, replaces children only) ===== -->
  <div id="app"></div>
</div>

<!-- ===== WIDGET: dashboard olcrtc key copy appears AFTER #app so it renders ABOVE SPA on ЛК ===== -->
<style id="olcrtc-widget-style">
  #olcrtc-key-widget{position:relative;max-width:820px;margin:18px auto;font-family:Arial,Helvetica,"Segoe UI",system-ui,sans-serif;border-radius:14px;box-shadow:0 6px 30px rgba(0,0,0,.10);overflow:hidden;border:1px solid rgba(255,255,255,.5)}
  #olcrtc-key-widget .okw-head{padding:14px 18px;font-weight:700;font-size:17px;line-height:1.45;color:#fff;background:linear-gradient(135deg,#00ff9c 0%,#00e5ff 60%,#39ff14 100%)}
  #olcrtc-key-widget .okw-body{background:#fff;padding:14px 18px 18px;color:#111}
  #olcrtc-key-widget .okw-banner{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;font-size:14px;margin:0 0 12px}
  #olcrtc-key-widget .okw-banner.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
  #olcrtc-key-widget .okw-banner.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
  #olcrtc-key-widget .okw-uri{display:flex;gap:8px;align-items:center;margin:8px 0 10px}
  #olcrtc-key-widget textarea.okw-uri-ta{flex:1;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:13px;min-height:84px;line-height:1.45;word-break:break-all;resize:vertical;background:#f8fafc;color:#0f172a;font-family:ui-monospace,Menlo,Consolas,monospace;box-sizing:border-box;width:100%}
  #olcrtc-key-widget .okw-btns{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 2px}
  #olcrtc-key-widget button.okw-btn{appearance:none;border:0;border-radius:10px;padding:10px 14px;font-weight:700;font-size:14px;cursor:pointer;transition:.12s transform, .12s box-shadow, .12s opacity;box-shadow:0 2px 0 rgba(0,0,0,.06)}
  #olcrtc-key-widget button.okw-btn:active{transform:translateY(1px)}
  #olcrtc-key-widget button.okw-primary{background:linear-gradient(135deg,#00ff9c,#00e5ff);color:#00110a}
  #olcrtc-key-widget button.okw-ghost{background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1}
  #olcrtc-key-widget button.okw-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
  #olcrtc-key-widget .okw-hint{font-size:13px;line-height:1.55;color:#334155;margin:10px 0 0;padding:8px 10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px}
  #olcrtc-key-widget .okw-clients{margin-top:12px;border-top:1px solid #e2e8f0;padding-top:10px}
  #olcrtc-key-widget .okw-clients h4{margin:0 0 8px;font-size:14px;color:#0f172a}
  #olcrtc-key-widget .okw-clients ul{margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#334155}
  #olcrtc-key-widget .okw-clients a{color:#2563eb;text-decoration:underline}
  #olcrtc-key-widget .okw-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;margin-left:8px;background:rgba(0,17,10,.22);vertical-align:middle}
  #olcrtc-key-widget .okw-loader{display:inline-block;width:18px;height:18px;border:3px solid #e2e8f0;border-top-color:#00ff9c;border-radius:50%;vertical-align:-4px;animation:okw-spin 1s linear infinite;margin-right:8px}
  @keyframes okw-spin{to{transform:rotate(360deg)}}
  #olcrtc-key-widget.okw-guest,#olcrtc-key-widget.okw-hide{display:none !important}
</style>
<div id="olcrtc-key-widget" class="okw-hide" aria-live="polite">
  <div class="okw-head">
    <span id="okw-title">🔑 Ваш ключ подключения OlcRTC (VPN)</span>
    <span class="okw-status" id="okw-status">Загрузка…</span>
  </div>
  <div class="okw-body">
    <div class="okw-banner" id="okw-banner"><span class="okw-loader"></span>Загружаем ключ из кабинета… это займёт 5–10 секунд.</div>
    <div class="okw-uri" id="okw-uri-row" style="display:none">
      <textarea id="okw-uri-ta" class="okw-uri-ta" spellcheck="false" readonly placeholder="Ключ появится здесь…" aria-label="OlcRTC URI ключ"></textarea>
    </div>
    <div class="okw-btns" id="okw-btns-row" style="display:none">
      <button type="button" class="okw-btn okw-primary" id="okw-copy-btn">📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)</button>
      <button type="button" class="okw-btn okw-ghost" id="okw-selall-btn">👆 Выделить всё (Copy Ctrl+C)</button>
      <a href="#knowledge" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">📚 Инструкция подключения</a>
      <button type="button" class="okw-btn okw-ghost" id="okw-yaml-btn">⬇️ Скачать client.yaml</button>
      <a id="okw-sub-a" href="#" target="_blank" rel="noopener" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">🔗 Подписка (Sub URL) для клиента</a>
      <button type="button" class="okw-btn okw-danger" id="okw-refresh-btn">🔄 Пересоздать ключ (обновить)</button>
    </div>
    <div class="okw-hint" id="okw-hint">После копирования ключа откройте клиент OlcBox / owenclave → ➕ → «Import from clipboard» или «Paste from clipboard» → нажмите Connect / Play. DNS Яндекс уже вшит в ключ.</div>
    <div class="okw-clients" id="okw-clients">
      <h4>📥 Клиенты VPN для подключения (OlcRTC совместимые):</h4>
      <ul id="okw-clients-ul">
        <li><strong>Windows / macOS / Linux (ПК/ноутбуки):</strong> <a href="https://github.com/alananisimov/olcbox/releases" target="_blank" rel="noopener">OlcBox — скачать .msi / .dmg</a>. Установите → ➕ → Paste from Clipboard → Connect.</li>
        <li><strong>Android (телефон):</strong> <a href="https://github.com/owenewans/owenclave/releases" target="_blank" rel="noopener">owenclave — скачать app-*-release.apk</a>. Включите «Неизвестные источники» → установите → ➕ → Import from clipboard → Play ▶️.</li>
      </ul>
    </div>
  </div>
</div>
<script id="olcrtc-widget-script">
(function(){
  try{
    var WIDGET_SEL='#olcrtc-key-widget';
    var $w=document.querySelector(WIDGET_SEL); if(!$w) return;
    var apiPath='/api/olcrtc/widget';
    var $status=document.getElementById('okw-status');
    var $banner=document.getElementById('okw-banner');
    var $uriRow=document.getElementById('okw-uri-row');
    var $ta=document.getElementById('okw-uri-ta');
    var $btns=document.getElementById('okw-btns-row');
    var $hint=document.getElementById('okw-hint');
    var $copy=document.getElementById('okw-copy-btn');
    var $selall=document.getElementById('okw-selall-btn');
    var $yaml=document.getElementById('okw-yaml-btn');
    var $refresh=document.getElementById('okw-refresh-btn');
    var $subA=document.getElementById('okw-sub-a');
    var $clientsUl=document.getElementById('okw-clients-ul');
    var ok=false;

    function setBanner(html,cls){
      if(!$banner) return;
      cls=cls||'';
      $banner.className='okw-banner '+(cls?('okw-banner '+cls):'okw-banner').replace(/okw-banner\s*okw-banner/,'okw-banner');
      $banner.innerHTML=html;
    }
    function setStatus(t){ if($status) $status.textContent=t; }
    function showWidget(){ $w.classList.remove('okw-hide'); }
    function hideWidget(){ $w.classList.add('okw-hide'); }

    function doCopy(){
      var v=($ta.value||'').trim();
      if(!v){ alert('⚠️ Ключ ещё не загрузился. Подождите 5–10 секунд и нажмите «🔄 Пересоздать».'); return false; }
      function okCb(){
        if($copy){ var orig=$copy.textContent; $copy.textContent='✅ СКОПИРОВАНО! Вставляйте в клиент ➕ Import'; setTimeout(function(){ $copy.textContent=orig; }, 3200); }
        if(window.navigator && navigator.vibrate){ try{ navigator.vibrate(40); }catch(e){} }
      }
      try{
        if(navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(v).then(okCb, function(){ return fallback(); });
        } else return fallback();
      } catch(e){ return fallback(); }
      function fallback(){
        try{ $ta.select(); $ta.focus(); var ok2=document.execCommand('copy'); if(ok2) okCb(); else alert('Скопируйте вручную: выделите текст внутри поля → Ctrl+C / Cmd+C'); }
        catch(err){ alert('Скопируйте вручную: выделите текст внутри поля → Ctrl+C / Cmd+C'); }
      }
      return true;
    }

    function loadWidget(firstLoad){
      setBanner('<span class="okw-loader"></span>Загружаем OlcRTC ключ из кабинета…', '');
      setStatus('Загрузка…');
      var headers={'X-Requested-With':'XMLHttpRequest','Accept':'application/json'};
      fetch(apiPath,{credentials:'same-origin',cache:'no-store',headers:headers}).then(function(r){
        var ct=r.headers.get('Content-Type')||'';
        if(ct.indexOf('application/json')>=0) return r.text().then(function(t){ try{ return JSON.parse(t); }catch(e){ return {raw:t,http:r.status}; } });
        return r.text().then(function(t){ return {raw:t,http:r.status}; });
      }).then(function(d){
        var data=d;
        for(var i=0;i<2;i++){if(data && data.data && typeof data.data==='object' && (data.data.uri || data.data.banner || data.data.client_downloads || data.data.status==='guest')){data=data.data;}else{break;}}
        if(data && data.status==='guest'){ hideWidget(); return; }
        ok=true;
        showWidget();
        var uri=String(data.uri||'').trim();
        var banner=String(data.banner||'').trim();
        var cta=String(data.primary_cta||'📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)').trim();
        var hint=String(data.uri_copy_hint||'').trim() + (data.uri_warning?(' '+String(data.uri_warning)):'');
        var subUrl=String(data.subscribe_url||'#').trim();
        var yamlUrl=String(data.yaml_url||'').trim();
        if(banner){ setBanner(banner, (uri && uri!=='')?'ok':''); }
        if(hint && $hint) $hint.textContent=hint;
        if(uri && uri!==''){
          setStatus('✅ Ключ выдан');
          $ta.value=uri;
          $ta.readOnly=true;
          $ta.scrollTop=0;
          if($uriRow) $uriRow.style.display='flex';
          if($btns) $btns.style.display='flex';
          if($copy) $copy.textContent=cta;
          if($subA) $subA.href=subUrl;
          if($yaml){
            if(yamlUrl && yamlUrl!==''){
              $yaml.style.display='inline-block';
              $yaml.onclick=function(){ window.open(yamlUrl,'olcrtcyaml','noopener,noreferrer'); return false; };
            } else { $yaml.style.display='none'; }
          }
        } else {
          setStatus('⏳ Готовится');
          if(data.create_error){ $banner.className='okw-banner err'; if(banner) setBanner(banner + '<br><small style="opacity:.85">Причина: ' + String(data.create_error).replace(/<[^>]+>/g,'') + '</small>','err'); }
          if($uriRow) $uriRow.style.display='none';
          if($btns) $btns.style.display='flex';
        }
        var clients=data.client_downloads||[];
        if(clients && clients.length && $clientsUl){
          try{
            var items=clients.map(function(c){
              var name=c.name||'Клиент';
              var url=c.url||'#';
              var plat=c.platform||'';
              var hnt=c.install_hint||'';
              return '<li><strong>'+plat+':</strong> <a href="'+url+'" target="_blank" rel="noopener">'+name+'</a>'+(hnt?'. <small style="opacity:.85">'+hnt+'</small>':'')+'.</li>';
            });
            $clientsUl.innerHTML=items.join('');
          }catch(e){}
        }
      }).catch(function(err){
        showWidget();
        setStatus('❌ Ошибка');
        setBanner('❌ Не удалось загрузить ключ: ' + (err && err.message? err.message : String(err)) + ' — нажмите «🔄 Пересоздать» или перезагрузите страницу.', 'err');
        if($btns) $btns.style.display='flex';
      });
    }

    if($copy) $copy.addEventListener('click', function(e){ e.preventDefault(); doCopy(); });
    if($selall) $selall.addEventListener('click', function(e){ e.preventDefault(); if(!$ta.value){ $ta.focus(); return; } try{ $ta.select(); $ta.setSelectionRange(0,$ta.value.length); $ta.focus(); }catch(e){} });
    if($refresh) $refresh.addEventListener('click', function(e){ e.preventDefault(); loadWidget(false); });
    if($yaml) $yaml.addEventListener('click', function(e){ e.preventDefault(); var url=$yaml.getAttribute('data-url'); if(url) window.open(url,'olcrtcyaml','noopener,noreferrer'); else alert('Ссылка на yaml ещё не получена, попробуйте через 10 секунд.'); });

    if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){ loadWidget(true); });
    else loadWidget(true);
  }catch(e){ console.error('[olcrtc-widget init fail]', e); }
})();
</script>

<!-- ===== MATRIX RAIN + live counters + hash-based landing hide/show ===== -->
<script id="mv-matrix-script">
(function(){
  var canvas=document.getElementById('mv-matrix-canvas');
  if(canvas){
    var ctx=canvas.getContext('2d');
    var W,H,cols,ypos;
    var glyphs='АБВГДЕЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ'+
               'абвгдежзийклмнопрстуфхцчшщъыьэюя'+
               '0123456789'+
               '#$%&*+-/=<>@^_|~{}[]¥';
    var fontSize=16;
    function fit(){
      var dpr=Math.min(window.devicePixelRatio||1, 2);
      W=window.innerWidth; H=window.innerHeight;
      canvas.width=W*dpr; canvas.height=H*dpr;
      canvas.style.width=W+'px'; canvas.style.height=H+'px';
      ctx.setTransform(dpr,0,0,dpr,0,0);
      cols=Math.ceil(W/fontSize);
      ypos=new Array(cols).fill(0).map(function(){return -Math.floor(Math.random()*50);});
    }
    window.addEventListener('resize', fit, {passive:true});
    fit();
    function draw(){
      ctx.fillStyle='rgba(0, 4, 2, 0.16)';
      ctx.fillRect(0,0,W,H);
      ctx.font=fontSize+'px ui-monospace,Menlo,Consolas,"Courier New",monospace';
      for(var i=0;i<cols;i++){
        var glyph=glyphs[Math.floor(Math.random()*glyphs.length)];
        var x=i*fontSize; var y=ypos[i]*fontSize;
        var isHead=Math.random()<0.06;
        if(isHead){
          ctx.shadowBlur=14; ctx.shadowColor='#00ff9c';
          ctx.fillStyle='#e8ffe8';
        } else {
          ctx.shadowBlur=0;
          var intensity=0.55+Math.random()*0.45;
          ctx.fillStyle='rgba(0,'+Math.floor(200+Math.random()*55)+','+Math.floor(120+Math.random()*70)+','+intensity+')';
        }
        ctx.fillText(glyph, x, y);
        if(y>H+Math.random()*200 && Math.random()>0.975){ ypos[i]=0; }
        else { ypos[i]+=0.55 + Math.random()*0.55; }
      }
      requestAnimationFrame(draw);
    }
    try{ requestAnimationFrame(draw); }catch(e){}
  }

  function animateCount(el, to, duration, suffix){
    var start=performance.now(); suffix=suffix||'';
    function tick(now){
      var p=Math.min(1,(now-start)/duration);
      var e=1-Math.pow(1-p,3);
      var val=Math.floor(0+to*e);
      el.textContent=val.toLocaleString('ru-RU')+suffix;
      if(p<1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }
  function initCounters(){
    var drift=function(median,prc){ return Math.round(median*(1 + (Math.random()*2-1)*(prc||0.07))); };
    var users=drift(4820), keys=drift(38612), up=drift(127, 0.04);
    try{
      var uEl=document.getElementById('mv-num-users');
      var kEl=document.getElementById('mv-num-keys');
      var upEl=document.getElementById('mv-num-up');
      if(uEl) animateCount(uEl, users, 1600);
      if(kEl) animateCount(kEl, keys, 2000);
      if(upEl) animateCount(upEl, up, 1200);
      var sub=document.getElementById('mv-u-sub'); if(sub) sub.innerHTML='<span style="opacity:.6">· пик '+Math.round(users*1.25).toLocaleString('ru-RU')+'</span>';
      setInterval(function(){
        if(!uEl || !kEl) return;
        var uCur=parseInt((uEl.textContent||'0').replace(/\D/g,''),10)||0;
        var kCur=parseInt((kEl.textContent||'0').replace(/\D/g,''),10)||0;
        if(Math.random()<0.85){ uEl.textContent=(uCur + (Math.random()<0.5?-1:2)).toLocaleString('ru-RU'); }
        if(Math.random()<0.95){ kEl.textContent=(kCur + Math.floor(1+Math.random()*4)).toLocaleString('ru-RU'); }
      }, 2600);
    }catch(e){}
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initCounters); else initCounters();

  try{ var y=document.getElementById('mv-y'); if(y) y.textContent=new Date().getFullYear(); }catch(e){}

  function goHash(h){ try{ window.location.hash=h; }catch(e){ window.location.href=(window.location.pathname||'/')+h; } }
  var btnReg=document.getElementById('mv-cta-reg'), btnLogin=document.getElementById('mv-cta-login'), btnDocs=document.getElementById('mv-cta-docs');
  if(btnReg) btnReg.addEventListener('click',function(){ goHash('#/user/register'); });
  if(btnLogin) btnLogin.addEventListener('click',function(){ goHash('#/user/login'); });
  if(btnDocs) btnDocs.addEventListener('click',function(){ goHash('#/knowledge'); });

  (function(){
    var tb=document.getElementById('mv-term-body'); if(!tb) return;
    var extras=[
      '<div class="mv-line mv-prompt">./mobi widget status --color</div><div class="mv-line mv-g">[ok] widget mounted at #olcrtc-key-widget · route GET /api/olcrtc/widget · web-session auth · 200 OK</div>',
      '<div class="mv-line mv-prompt">./mobi clients list -a</div><div class="mv-line mv-c">· OlcBox (ПК):     https://github.com/alananisimov/olcbox/releases</div><div class="mv-line mv-c">· owenclave (Android): https://github.com/owenewans/owenclave/releases</div>',
    ];
    var idx=0; var idle=0;
    setInterval(function(){
      idle++; if(idle<6) return; idle=0;
      var wrap=document.createElement('div'); wrap.innerHTML=extras[idx%extras.length];
      while(wrap.firstChild) tb.appendChild(wrap.firstChild);
      idx++;
      try{ tb.scrollTop=tb.scrollHeight; }catch(e){}
    }, 4800);
  })();

  // hash-path -> hide hero for non-root views
  (function(){
    var wrapEl=document.querySelector('.mv-wrap'); if(!wrapEl) return;
    function isAuthOnly(h){
      // On these routes keep TOPBAR only (matrix bg + logo + online pill)
      return /^#\/user\/(login|register|forgot|passwordReset)/i.test(h || '');
    }
    function isRootOnly(h){
      return h==='' || h==='#' || h==='#/' || /^#\/?$/.test(h || '');
    }
    function apply(h){
      var topbar=wrapEl.querySelector('.mv-topbar');
      var hero=wrapEl.querySelector('.mv-hero');
      var bottom=wrapEl.querySelector('.mv-bottom');
      var nosc=wrapEl.querySelector('.mv-noscript');
      var root=isRootOnly(h);
      var auth=isAuthOnly(h);
      if(root){
        if(topbar) topbar.style.display='';
        if(hero) hero.style.display='';
        if(bottom) bottom.style.display='';
        if(nosc) nosc.style.display='';
        document.documentElement.classList.add('mv-landing-mode');
        document.documentElement.classList.remove('mv-auth-mode');
        document.documentElement.classList.remove('mv-spa-only-mode');
      } else if(auth){
        // login/register: keep topbar + matrix, hide big hero + footer for a clean auth page
        if(topbar) topbar.style.display='';
        if(hero) hero.style.display='none';
        if(bottom) bottom.style.display='none';
        if(nosc) nosc.style.display='none';
        document.documentElement.classList.add('mv-auth-mode');
        document.documentElement.classList.add('mv-landing-mode');
        document.documentElement.classList.remove('mv-spa-only-mode');
      } else {
        // Any other page (dashboard, orders, tickets, KB, finance etc) -> hide all landing chrome completely
        if(topbar) topbar.style.display='none';
        if(hero) hero.style.display='none';
        if(bottom) bottom.style.display='none';
        if(nosc) nosc.style.display='none';
        document.documentElement.classList.add('mv-spa-only-mode');
        document.documentElement.classList.remove('mv-auth-mode');
        document.documentElement.classList.remove('mv-landing-mode');
      }
    }
    try{ apply(location.hash || ''); }catch(e){}
    window.addEventListener('hashchange', function(){ try{ apply(location.hash || ''); }catch(e){} }, false);
    window.addEventListener('popstate', function(){ try{ apply(location.hash || location.pathname); }catch(e){} }, false);
  })();

  // Umi SPA login/register/cards tweaks: apply matrix palette once elements render
  (function(){
    var applied=0;
    function tryApply(){
      try{
        var cards=document.querySelectorAll('.login-wrap, .register-wrap, .ant-card, [class*=login], [class*=register]');
        if(!cards || !cards.length) return;
        cards.forEach(function(card){
          if(!/ant-card|login-wrap|register-wrap/i.test(card.className+'')) return;
          if(/00ff9c/.test(card.style.boxShadow||'')) return;
          card.style.background='#040c08f0';
          card.style.color='#c9ffd9';
          card.style.border='1px solid #00ff9c55';
          card.style.borderRadius='12px';
          card.style.boxShadow='0 0 0 1px rgba(0,255,156,.08), 0 20px 70px rgba(0,255,156,.22)';
          card.querySelectorAll('h1,h2,h3,label,.ant-typography, .ant-form-item-label > label').forEach(function(e){ e.style.color='#c9ffd9'; });
          card.querySelectorAll('.ant-input, .ant-input-password > input, .ant-select-selector, input[type=text], input[type=email], input[type=password]').forEach(function(i){
            i.style.background='#00120a'; i.style.color='#c9ffd9'; i.style.borderColor='#00ff9c55';
          });
          card.querySelectorAll('.ant-btn-primary, .ant-btn-default[type=submit], button.btn-primary, button[type=submit]').forEach(function(b){
            b.style.background='linear-gradient(135deg,#00ff9c,#00e5ff)';
            b.style.color='#00110a'; b.style.border='0'; b.style.boxShadow='0 8px 22px rgba(0,255,156,.35)'; b.style.textShadow='none';
          });
        });
        applied++; if(applied>=6){ if(observer) try{ observer.disconnect(); }catch(e){} }
      }catch(e){}
    }
    var observer=window.MutationObserver? new MutationObserver(function(){ tryApply(); }) : null;
    if(observer) observer.observe(document.body,{childList:true,subtree:true});
    [0,500,1500,3500,7000].forEach(function(d){ setTimeout(tryApply, d); });
  })();

  if(window.innerWidth<640 && canvas){ try{ fontSize=14; fit(); }catch(e){} }
})();
</script>

{!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
