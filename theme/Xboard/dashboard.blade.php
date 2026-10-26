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
  <!-- ЮMoney Quickpay отклоняет переходы с заголовком Referer (reason=referer),
       поэтому от страниц панели не отправляем Referer вообще. -->
  <meta name="referrer" content="no-referrer" />
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
    html,body{margin:0;padding:0;background:var(--mv-bg);color:var(--mv-text);font-family:ui-monospace,Menlo,Consolas,"Courier New",monospace;min-height:100vh;width:100%;overflow-x:hidden;overflow-y:auto;-webkit-overflow-scrolling:touch;overscroll-behavior-y:auto}
    body{position:relative;display:block}
    html{height:auto;overflow-y:scroll;scroll-behavior:smooth}
    a{color:var(--mv-green);text-decoration:none}
    #mv-matrix-canvas{position:fixed;inset:0;width:100vw;height:100vh;z-index:0;background:#000;display:block;image-rendering:pixelated;pointer-events:none}
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
    #app{position:relative;z-index:20;margin-top:18px;min-height:400px;overflow-y:visible;overflow-x:visible}
    .mv-noscript{position:relative;z-index:50;padding:14px;border:1px dashed #ffe066;border-radius:10px;color:#ffe066;background:#191200cc;margin-top:14px;font-size:13px}
    html.mv-auth-mode #app, html.mv-spa-only-mode #app{margin-top:16px;min-height:calc(100vh - 20px);overflow-y:auto}
    html.mv-spa-only-mode #app{margin-top:0;min-height:unset !important;padding-bottom:60px !important}
    html.mv-auth-mode body, html.mv-landing-mode body, html body, body.mv-unlocked{min-height:100vh;overflow-y:auto!important;overflow-x:hidden!important;height:auto!important}
    html{min-height:100vh;height:auto}
    /* Override Umi / AntD modal wrappers that set overflow:hidden on html/body and trap scroll */
    html.mv-unlocked, body.mv-unlocked{overflow-y:auto!important;overflow-x:hidden!important;position:static!important;height:auto!important}
    html.mv-auth-mode{min-height:100vh;height:auto;overflow-y:auto!important}
    /* Force modal host overlay itself to be self-scrollable (not page-blocking) */
    .ant-mask, .ant-modal-mask, .ant-modal-wrap, .ant-modal-root{overflow-y:auto!important;position:fixed!important;inset:0!important;width:100%!important;height:100vh!important;display:block!important}
    .ant-modal-centered .ant-modal{top:0!important;padding:56px 0 32px 0!important;margin:0 auto!important;max-height:100vh!important;display:flex!important;align-items:center!important;justify-content:center!important}
    .ant-modal{margin:16px auto!important}
    /* Prevent any 3rd party inline style from locking html/body scroll on SPA mount */
    .ant-modal-body{max-height:calc(100vh - 120px);overflow-y:auto}
    html.mv-spa-only-mode #app,
    html.mv-spa-only-mode body,
    html.mv-spa-only-mode,
    html.mv-auth-mode #app,
    html.mv-auth-mode body,
    html.mv-auth-mode{
      overflow-x:hidden!important;overflow-y:auto!important;position:static!important;height:auto!important;min-height:100vh!important;max-height:none!important;touch-action:pan-y!important
    }

    /* ============================================================ */
    /* 🔌 MATRIX-GREEN THEME OVERRIDE — ВЕСЬ ЛК + LOGIN SPA + ADMIN  */
    /* ============================================================ */
    #app, html.mv-spa-only-mode #app, html.mv-auth-mode #app, html.mv-landing-mode #app{
      background:transparent !important;
    }
    :root{
      --ant-primary:#00ff9c;
      --ant-primary-hover:#33ffb0;
      --ant-primary-active:#00e588;
      --ant-bg:#06120a;
      --ant-panel:#0a1a12;
      --ant-panel-2:#0d2218;
      --ant-border:#00ff9c33;
      --ant-border-strong:#00ff9c66;
      --ant-text:#c9ffd9;
      --ant-text-dim:#74a18a;
      --ant-heading:#e8ffef;
      --ant-danger:#ff5c7a;
      --ant-warn:#ffd166;
      --ant-success:#00ff9c;
      --ant-bg-mask:#000000d0;
    }
    #app .ant-pro-layout, #app .ant-layout, #app [class*=Layout], #app [class*=BasicLayout], #app [class*=UserLayout]{
      background:linear-gradient(180deg, #05110a 0%, #030805 100%) !important;
      color:var(--ant-text) !important;
    }
    #app .ant-layout-header, #app .ant-pro-global-header, #app [class*=GlobalHeader], #app [class*=RightContent], #app [class*=Header], #app header[class*=ant], #app header{
      background:#04120a !important;
      color:var(--ant-text) !important;
      border-bottom:1px solid var(--ant-border) !important;
      backdrop-filter: blur(6px);
    }
    #app .ant-layout-sider, #app aside[class*=ant], #app .ant-pro-sider, #app [class*=Sider]{
      background:#041008 !important;
      border-right:1px solid var(--ant-border) !important;
    }
    #app .ant-layout-content, #app main{ background:transparent !important; color:var(--ant-text) !important; }
    #app .ant-pro-page-container, #app [class*=PageContainer], #app .ant-pro-grid-content,
    #app [class*=GridContent], #app [class*=page-container], #app [id^=user_], #app [id^=admin_],
    #app .ant-layout-content > div, #app [class*=ProLayout] > div,
    #app [class*=children-container], #app [class*=ChildrenContainer] {
      background: linear-gradient(180deg, #05110a 0%, #030805 100%) !important;
      color: var(--ant-text) !important;
    }
    #app .ant-list-item, #app [class*=ant-list-items] > div, #app [class*=knowledge] [class*=item],
    #app .ant-page-header, #app [class*=Statistic] .ant-card-body, #app .ant-empty,
    #app [class*=Quick], #app [class*=Shortcut], #app [class*=ProCard] {
      background: #08150e !important;
      color: var(--ant-text) !important;
      border: 1px solid var(--ant-border) !important;
      border-radius: 10px !important;
    }
    #app .ant-menu{ background:transparent !important; color:var(--ant-text-dim) !important; border:0 !important; }
    #app .ant-menu-item, #app .ant-menu-submenu-title{
      color:var(--ant-text-dim) !important; border-radius:10px !important; margin:4px 8px !important;
    }
    #app .ant-menu-item:hover, #app .ant-menu-submenu-title:hover{ background:#00ff9c11 !important; color:#d8ffe4 !important; }
    #app .ant-menu-item-selected, #app .ant-menu-item-active, #app li.ant-menu-item-selected{
      background:linear-gradient(135deg,#00ff9c22,#00e5ff22) !important;
      color:#00ff9c !important;
      box-shadow: inset 0 0 0 1px var(--ant-border-strong), 0 4px 14px rgba(0,255,156,.12);
      font-weight:600;
    }
    #app .ant-card, #app section[class*=ant-card], #app .ant-statistic, #app .ant-alert, #app .ant-tag,
    #app .ant-pagination, #app .ant-table, #app .ant-table-wrapper, #app .ant-tabs, #app .ant-collapse,
    #app .ant-empty, #app [class*=ProCard], #app [class*=PageContainer], #app [class*=GridContent],
    #app .ant-drawer-body, #app .ant-drawer-header, #app .ant-popover-inner, #app .ant-dropdown-menu{
      background:#08150e !important;
      color:var(--ant-text) !important;
      border:1px solid var(--ant-border) !important;
      border-radius:12px !important;
      box-shadow:0 6px 24px rgba(0,0,0,.45);
    }
    #app .ant-card-head, #app .ant-card-head-title, #app .ant-modal-header, #app .ant-modal-title,
    #app [class*=CardTitle], #app .ant-statistic-title, #app h1, #app h2, #app h3, #app h4,
    #app label, #app .ant-form-item-label > label{ color:var(--ant-heading) !important; }
    #app p, #app span, #app li, #app td, #app th{ color:var(--ant-text) !important; }
    #app .ant-table-tbody > tr:hover > td{ background:#00ff9c0a !important; }
    #app .ant-btn{ border-radius:10px !important; transition:.15s transform, .15s box-shadow, .15s filter !important; }
    #app .ant-btn-primary, #app button[type=submit], #app [class*=ant-btn-primary],
    #app .ant-btn-primary:hover, #app .ant-btn-primary:focus,
    #app button.ant-btn.ant-btn-primary,
    #app [role=button].ant-btn-primary{
      background:linear-gradient(135deg,#00ff9c,#00e5ff) !important;
      background-image:linear-gradient(135deg,#00ff9c,#00e5ff) !important;
      background-color:#00ff9c !important;
      color:#00110a !important; border:0 !important;
      box-shadow: 0 6px 20px rgba(0,255,156,.28) !important;
      font-weight:800 !important; text-shadow:none !important;
      font-size:15px !important;
      padding: 12px 18px !important;
    }
    #app .ant-modal-wrap button.ant-btn-primary, #app [class*=Login] button.ant-btn-primary, #app [class*=Register] button.ant-btn-primary,
    #app [class*=UserLayout] .ant-modal button.ant-btn-primary, #app div.ant-modal-content button.ant-btn-primary{
      background:linear-gradient(135deg,#00ff9c,#00e5ff) !important;
      background-image:linear-gradient(135deg,#00ff9c,#00e5ff) !important;
      background-color:#00ff9c !important;
      color:#00110a !important;
      font-weight:800 !important;
      border:0 !important;
    }
    #app .ant-btn-default, #app .ant-btn, #app .ant-btn-ghost{
      background:#0a1a12 !important; color:var(--ant-text) !important; border:1px solid var(--ant-border) !important;
      font-size:14px !important;
    }
    #app .ant-btn:hover, #app .ant-btn-default:hover, #app .ant-btn-ghost:hover{
      border-color:var(--ant-border-strong) !important;
      box-shadow:0 4px 14px rgba(0,255,156,.08);
      filter:brightness(1.05);
    }
    #app .ant-card, #app section[class*=ant-card], #app .ant-statistic, #app .ant-alert, #app .ant-tag,
    #app .ant-pagination, #app .ant-table, #app .ant-table-wrapper, #app .ant-tabs, #app .ant-collapse,
    #app .ant-empty, #app [class*=ProCard], #app [class*=PageContainer], #app [class*=GridContent],
    #app .ant-drawer-body, #app .ant-drawer-header, #app .ant-popover-inner, #app .ant-dropdown-menu{
      background:#08150e !important;
      color:var(--ant-text) !important;
      border:1px solid var(--ant-border) !important;
      border-radius:12px !important;
      box-shadow:0 6px 24px rgba(0,0,0,.45);
      font-size:15px !important;
      line-height:1.55 !important;
    }
    #app .ant-card .ant-card-body{
      font-size:15px !important;
      line-height:1.6 !important;
      padding:20px !important;
      color:var(--ant-text) !important;
    }
    #app .ant-card-meta-title, #app [class*=PlanCard] [class*=title], #app h1.ant-typography, #app h2.ant-typography, #app h3.ant-typography{
      font-weight:800 !important;
      font-size:18px !important;
      color:#fff !important;
    }
    #app .ant-typography, #app .ant-card-body div, #app .ant-card-body span, #app .ant-statistic-content{
      font-size:15px !important;
      color:var(--ant-text) !important;
    }
    #app .ant-layout-sider .ant-menu-item, #app .ant-menu-title-content{
      font-size:14.5px !important;
      font-weight:500 !important;
    }
    #app .ant-input, #app .ant-input-number, #app .ant-select-selector, #app .ant-picker,
    #app .ant-input-affix-wrapper, #app textarea, #app input[type=text], #app input[type=email],
    #app input[type=password], #app input[type=number]{
      background:#00120a !important; color:var(--ant-heading) !important;
      border:1px solid var(--ant-border) !important; border-radius:10px !important; box-shadow:none !important;
    }
    #app .ant-input::placeholder, #app input::placeholder, #app textarea::placeholder{ color:#3e6b54 !important; }
    #app .ant-input:focus, #app .ant-select-focused .ant-select-selector, #app input:focus,
    #app .ant-input-number-focused, #app .ant-picker-focused{
      border-color:#00ff9c !important;
      box-shadow:0 0 0 3px rgba(0,255,156,.14) !important;
    }
    #app thead.ant-table-thead > tr > th, #app th[class*=ant-table-cell]{
      background:#041008 !important; color:#00ff9c !important;
      border-bottom:1px solid var(--ant-border-strong) !important; font-weight:700;
    }
    #app .ant-modal-content, #app .ant-modal-wrap .ant-modal, #app [class*=login] > .ant-card,
    #app [class*=Login], #app [class*=Register]{
      background:#06150d !important; color:var(--ant-text) !important;
      border:1px solid var(--ant-border-strong) !important; border-radius:14px !important;
      box-shadow:0 20px 60px #000000a0, 0 0 0 1px rgba(0,255,156,.08) inset;
    }
    #app .ant-modal-mask, #app .ant-modal-root .ant-mask{ background:#000000d2 !important; backdrop-filter: blur(3px); }
    #app a{ color:#00ff9c !important; }
    #app a:hover{ color:#33ffb0 !important; text-decoration:underline; }
    #app .ant-message-notice-content, #app .ant-notification-notice{
      background:#07170e !important; border:1px solid var(--ant-border-strong) !important;
      color:var(--ant-heading) !important; border-radius:10px !important;
      box-shadow: 0 10px 28px #00000090 !important;
    }
    /* High readability QR code box — mobile camera 1st try decode */
    #app canvas, #app [class*=QRCode], #app [class*=qrcode], #app [class*=qr],
    #app [data-testid=qr-code], #app [class*=ant-qrcode], #app img[alt*=QR], #app img[alt*=qr],
    #app svg[class*=qr], #app [class*=QRImg]{
      background:#ffffff !important; padding:12px !important; border-radius:10px !important;
      border:1px solid #cbd5e1 !important;
      filter:contrast(1.42) saturate(0) brightness(1.13) !important;
      box-shadow: 0 6px 22px rgba(0,0,0,.45) !important;
      position:relative !important; z-index:40 !important;
      mix-blend-mode:normal !important; opacity:1 !important;
      max-width: 300px !important; max-height: 300px !important;
      min-width: 240px !important; min-height: 240px !important;
      width: 280px !important; height: 280px !important;
    }
    #app [class*=QR] *, #app [class*=qr] *, #app [data-testid*=qr] *, #app [data-testid*=QR] *,
    #app canvas.qr, #app canvas[class*=qr]{
      background:#ffffff !important; color:#000000 !important; border-color:#cbd5e1 !important;
      filter:contrast(1.42) saturate(0) brightness(1.13) !important;
    }
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
  <!-- ===== Тёмная тема ЛК по умолчанию =====
       Umi-SPA определяет тему через VueUse useDark(), который хранит режим в
       localStorage['vueuse-color-scheme'] со значениями 'dark' | 'light' | 'auto'.
       Стартовое значение — 'auto' (следовать системе), поэтому на светлых системах
       ЛК открывался светлым и «луна» сверху была выключена. Принудительно ставим
       'dark', пока пользователь сам не выбрал 'light' или 'dark'.
       Скрипт ОБЯЗАТЕЛЬНО стоит до подключения umi.js — useDark() читает хранилище
       в момент инициализации модуля. -->
  <script id="olcrtc-default-dark">
    (function () {
      try {
        var KEY = 'vueuse-color-scheme';
        var cur = window.localStorage.getItem(KEY);
        if (cur !== 'light' && cur !== 'dark') {
          window.localStorage.setItem(KEY, 'dark');
          document.documentElement.classList.add('dark');
        }
      } catch (e) {}
    })();
  </script>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
  <!-- ===== OlcRTC-ONLY: скрываем legacy-разделы SPA, которые бесполезны без V2Ray-узлов =====
       «Статус узлов» (/node) и «Детали трафика» (/traffic) показывают данные V2Ray-нод,
       которых в этом продукте нет (0 узлов). Скрываем через CSS (а не правкой бандла),
       чтобы не ломать JS-синтаксис umi.js. -->
  <style id="olcrtc-hide-legacy-menu">
    #app a[href="#/node"], #app a[href="/node"], #app a[href$="/node"],
    #app a[href="#/traffic"], #app a[href="/traffic"], #app a[href$="/traffic"],
    #app [data-menu-id="/node"], #app [data-menu-id="/traffic"] { display:none !important; }
    #app li:has(> a[href="#/node"]), #app li:has(> a[href$="/node"]),
    #app li:has(> a[href="#/traffic"]), #app li:has(> a[href$="/traffic"]) { display:none !important; }
  </style>
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
      <div class="mv-eyebrow">⚡ ЛИЧНЫЙ КАБИНЕТ · ВХОД / РЕГИСТРАЦИЯ — ниже форма или жми кнопку</div>
      <h1>
        <span class="mv-grad">Mobi VPN</span> — быстрый и безопасный<br />
        VPN через OlcRTC · ключ в 1 клик
      </h1>
      <p class="lead" style="font-size:15.5px;font-weight:500;color:#d8ffe4;line-height:1.7">
        ✅ Купил подписку → сразу получил <b style="color:#fff">зелёную карточку</b> с ключом
        <b style="color:var(--mv-green)">📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC</b> прямо в кабинете. Вставил в клиент —
        подключился. Без сложных настроек. Без устаревших протоколов.
      </p>
      <div class="mv-tagrow" style="margin-top:10px">
        <span class="mv-tag" style="font-size:13px">🛡️ WebRTC datachannel · обход DPI</span>
        <span class="mv-tag" style="font-size:13px">🇷🇺 DNS Яндекс 77.88.8.8 уже в ключе</span>
        <span class="mv-tag" style="font-size:13px">💳 ЮKassa / карты / СБП / Сбер</span>
        <span class="mv-tag" style="font-size:13px">⚙️ 3 тарифа: 30 дней · 90 дней · 1 год</span>
        <span class="mv-tag" style="font-size:13px">🧩 Клиенты OlcBox (ПК) · owenclave (телефон)</span>
      </div>
      <div class="mv-cta-row" style="margin-top:16px">
        <button class="mv-btn mv-btn-primary" id="mv-cta-reg" type="button" style="font-size:15px;padding:14px 18px">🚀 РЕГИСТРАЦИЯ (2 минуты, бесплатно)</button>
        <button class="mv-btn mv-btn-ghost" id="mv-cta-login" type="button" style="font-size:15px;padding:14px 16px">🔐 ВОЙТИ В ЛИЧНЫЙ КАБИНЕТ</button>
        <button class="mv-btn mv-btn-ghost" id="mv-cta-docs" type="button" style="font-size:14px;padding:12px 14px">📚 КАК ПОДКЛЮЧИТЬСЯ (инструкция)</button>
      </div>

      <div class="mv-counters" aria-label="Сервис: счётчики" style="margin-top:22px">
        <div class="mv-counter"><div class="mv-num" id="mv-num-users" style="font-size:26px">0</div><div class="mv-lab" style="font-size:12px;font-weight:600">Подключено клиентов<div id="mv-u-sub" style="display:inline-block;margin-left:6px"></div></div></div>
        <div class="mv-counter"><div class="mv-num" id="mv-num-keys" style="font-size:26px">0</div><div class="mv-lab" style="font-size:12px;font-weight:600">OlcRTC-ключей выдано<div class="mv-unit">шт</div></div></div>
        <div class="mv-counter"><div class="mv-num" id="mv-num-up" style="font-size:26px">0</div><div class="mv-lab" style="font-size:12px;font-weight:600">Uptime без падений<div class="mv-unit">дн</div></div></div>
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
      <span class="mv-badge-lv" style="margin-left:8px">🧪 Формат БЗ Шаг-2: <b style="color:var(--mv-green)">olcrtc://jitsi?datachannel@https://host/room#HASH$olc</b></span>
    </div>
    <div>© Mobi VPN · <span id="mv-y">{{ date('Y') }}</span> · смените админ-пароль сразу после первого входа</div>
  </div>

  <noscript>
    <div class="mv-noscript">
      ⚠️ В вашем браузере отключён JavaScript. Страница входа/регистрации (Umi SPA) и матрица-фон требуют включенный JS.
      Включите JS и перезагрузите. Временные прямые ссылки: <b>/#/login</b> · <b>/#/register</b> · <b>/#/knowledge</b>.
    </div>
  </noscript>

  <!-- ===== SPA mount point (Umi.js React login/register/dashboard renders inside this div, replaces children only) ===== -->
  <div id="app"></div>
</div>

<div id="olcrtc-widget-home" hidden aria-hidden="true"></div>

<!-- ===== WIDGET: unified subscription workspace inside the SPA cabinet ===== -->
<style id="olcrtc-widget-style">
  /* Единый тёмный стиль кабинета: карточка ключа оформлена в той же
     matrix-green палитре, что и остальной ЛК, без белых блоков. */
  #olcrtc-key-widget{position:relative;max-width:820px;margin:18px auto;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.55);overflow:hidden;border:1px solid var(--ant-border-strong,#00ff9c66);background:#08150e;color:var(--ant-text,#c9ffd9)}
  #olcrtc-key-widget .okw-head{padding:14px 18px;font-weight:800;font-size:17px;line-height:1.45;color:#00110a;background:linear-gradient(135deg,#00ff9c 0%,#00e5ff 100%);display:flex;align-items:center;flex-wrap:wrap;gap:8px}
  #olcrtc-key-widget .okw-body{background:#08150e;padding:16px 18px 18px;color:var(--ant-text,#c9ffd9)}
  #olcrtc-key-widget .okw-banner{background:rgba(255,209,102,.12);color:#ffd166;border:1px solid rgba(255,209,102,.42);border-radius:12px;padding:10px 12px;font-size:14px;line-height:1.55;margin:0 0 12px}
  #olcrtc-key-widget .okw-banner.ok{background:rgba(0,255,156,.12);color:#7dffc4;border-color:rgba(0,255,156,.42)}
  #olcrtc-key-widget .okw-banner.err{background:rgba(255,92,122,.12);color:#ff9db0;border-color:rgba(255,92,122,.45)}
  #olcrtc-key-widget .okw-uri{display:flex;gap:8px;align-items:center;margin:8px 0 10px}
  #olcrtc-key-widget textarea.okw-uri-ta{flex:1;border:1px solid var(--ant-border-strong,#00ff9c66);border-radius:12px;padding:10px 12px;font-size:13px;min-height:84px;line-height:1.5;word-break:break-all;resize:vertical;background:#00120a;color:#c8ffe0;font-family:ui-monospace,Menlo,Consolas,"Courier New",monospace;box-sizing:border-box;width:100%}
  #olcrtc-key-widget textarea.okw-uri-ta:focus{outline:none;border-color:#00ff9c;box-shadow:0 0 0 3px rgba(0,255,156,.16)}
  #olcrtc-key-widget .okw-btns{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 2px}
  #olcrtc-key-widget button.okw-btn{appearance:none;border:0;border-radius:11px;padding:11px 15px;font-weight:700;font-size:14px;cursor:pointer;transition:.14s transform,.14s box-shadow,.14s background,.14s filter;font-family:inherit}
  #olcrtc-key-widget button.okw-btn:active{transform:translateY(1px)}
  #olcrtc-key-widget button.okw-primary{background:linear-gradient(135deg,#00ff9c,#00e5ff);color:#00110a;box-shadow:0 10px 28px rgba(0,255,156,.3)}
  #olcrtc-key-widget button.okw-ghost{background:#0d2218;color:#c9ffd9;border:1px solid var(--ant-border,#00ff9c33)}
  #olcrtc-key-widget button.okw-ghost:hover{background:rgba(0,255,156,.1);border-color:var(--ant-border-strong,#00ff9c66)}
  #olcrtc-key-widget button.okw-danger{background:rgba(255,92,122,.14);color:#ff9db0;border:1px solid rgba(255,92,122,.42)}
  #olcrtc-key-widget button.okw-danger:hover{background:rgba(255,92,122,.22)}
  #olcrtc-key-widget .okw-hint{font-size:13px;line-height:1.6;color:#9fd7b8;margin:12px 0 0;padding:10px 12px;background:#05110a;border:1px dashed var(--ant-border-strong,#00ff9c66);border-radius:12px}
  #olcrtc-key-widget .okw-clients{margin-top:12px;border-top:1px dashed var(--ant-border,#00ff9c33);padding-top:12px}
  #olcrtc-key-widget .okw-clients h4{margin:0 0 8px;font-size:14px;color:#e8ffef}
  #olcrtc-key-widget .okw-clients ul{margin:0;padding-left:18px;font-size:13px;line-height:1.65;color:#9fd7b8}
  #olcrtc-key-widget .okw-clients a{color:#00ff9c;text-decoration:none;font-weight:700;border-bottom:1px dashed rgba(0,255,156,.5)}
  #olcrtc-key-widget .okw-clients a:hover{color:#fff;border-bottom-color:#fff}
  /* QR оставлен на белом фоне — так камера телефона считывает его с первого раза */
  #olcrtc-key-widget .okw-qr{display:flex;flex-direction:column;align-items:center;gap:8px;margin:12px 0 0;padding:14px;border:1px solid var(--ant-border,#00ff9c33);border-radius:14px;background:#05110a}
  #olcrtc-key-widget .okw-qr-title{font-size:14px;font-weight:800;color:#e8ffef}
  #olcrtc-key-widget .okw-qr img{width:220px;height:220px;background:#fff;padding:10px;border-radius:10px}
  #olcrtc-key-widget .okw-qr-sub{font-size:12px;color:#9fd7b8;text-align:center;line-height:1.55}
  #olcrtc-key-widget .okw-status{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;margin-left:auto;background:rgba(0,17,10,.32);color:#00110a;vertical-align:middle}
  #olcrtc-key-widget .okw-loader{display:inline-block;width:18px;height:18px;border:3px solid rgba(0,255,156,.22);border-top-color:#00ff9c;border-radius:50%;vertical-align:-4px;animation:okw-spin 1s linear infinite;margin-right:8px}
  @keyframes okw-spin{to{transform:rotate(360deg)}}
  #olcrtc-key-widget.okw-guest,#olcrtc-key-widget.okw-hide{display:none !important}
  #olcrtc-key-widget .okw-trial{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:14px 16px;margin:0 0 14px;border-radius:14px;background:linear-gradient(135deg,rgba(0,255,156,.12),rgba(0,229,255,.10));border:1px solid rgba(0,255,156,.38)}
  #olcrtc-key-widget .okw-trial-info{display:flex;align-items:center;gap:12px;flex:1;min-width:260px}
  #olcrtc-key-widget .okw-trial-icon{font-size:28px;line-height:1;flex-shrink:0;filter:drop-shadow(0 0 8px rgba(0,255,156,.5))}
  #olcrtc-key-widget .okw-trial-texts{flex:1;min-width:0}
  #olcrtc-key-widget .okw-trial-title{font-weight:800;font-size:15px;line-height:1.45;color:#e8ffef}
  #olcrtc-key-widget .okw-trial-sub{font-size:12.5px;line-height:1.55;color:#9fd7b8;margin-top:3px}
  #olcrtc-key-widget .okw-trial-btns{flex-shrink:0}
  #olcrtc-key-widget .okw-trial-btn{padding:13px 18px;font-size:15px !important;white-space:nowrap}
  #olcrtc-key-widget .okw-trial[aria-disabled="true"]{opacity:.8}
  #olcrtc-key-widget .okw-trial-claimed{background:rgba(148,163,184,.10);border-color:rgba(148,163,184,.35)}
  #olcrtc-key-widget .okw-trial-claimed .okw-trial-title{color:#cbd5e1}
  #olcrtc-key-widget .okw-trial-claimed .okw-trial-sub{color:#94a3b8}
  /* ===== Чипы состояния подписки (тариф · осталось · до какой даты) ===== */
  #olcrtc-key-widget .okw-meta{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
  #olcrtc-key-widget .okw-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:999px;font-size:12.5px;font-weight:700;line-height:1.4;background:rgba(0,255,156,.1);color:#8affc8;border:1px solid rgba(0,255,156,.32)}
  #olcrtc-key-widget .okw-chip.okw-chip-trial{background:rgba(0,229,255,.12);color:#7fe8ff;border-color:rgba(0,229,255,.38)}
  #olcrtc-key-widget .okw-chip.okw-chip-warn{background:rgba(255,92,122,.12);color:#ff9db0;border-color:rgba(255,92,122,.45)}
  /* ===== Блок «Как подключиться — 3 шага» ===== */
  #olcrtc-key-widget .okw-steps{background:#05110a;border:1px solid var(--ant-border,#00ff9c33);border-radius:12px;padding:12px 14px;margin:0 0 12px}
  #olcrtc-key-widget .okw-steps-title{font-weight:800;font-size:13.5px;color:#e8ffef;margin:0 0 6px}
  #olcrtc-key-widget .okw-steps-list{margin:0;padding-left:20px;font-size:13px;line-height:1.7;color:#9fd7b8}
  #olcrtc-key-widget .okw-steps-list li{margin:0 0 4px}
  #olcrtc-key-widget .okw-steps-list b{color:#e8ffef}
  #olcrtc-key-widget .okw-steps .okw-clients{margin-top:10px;padding-top:10px}
  #olcrtc-key-widget .okw-steps .okw-hint{margin-top:10px;background:transparent}
  /* ===== Нижние CTA-кнопки (купить тариф / мои заказы) ===== */
  #olcrtc-key-widget .okw-cta-btns{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0;padding-top:12px;border-top:1px dashed var(--ant-border,#00ff9c33)}
  #olcrtc-key-widget a.okw-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border-radius:11px;font-weight:700;font-size:14px;padding:11px 15px;text-decoration:none;text-align:center;background:#0d2218;color:#c9ffd9;border:1px solid var(--ant-border,#00ff9c33)}
  #olcrtc-key-widget a.okw-btn:hover{background:rgba(0,255,156,.1);border-color:var(--ant-border-strong,#00ff9c66);color:#fff}
  #olcrtc-key-widget a.okw-buy,#olcrtc-key-widget button.okw-buy{background:linear-gradient(135deg,#00ff9c,#00e5ff);color:#00110a;border:0;box-shadow:0 10px 28px rgba(0,255,156,.3)}
  /* ===== Единое меню кабинета: все разделы в одной строке-навигации внутри
     карточки ключа. На мобильных переносится в сетку 2×2, крупные тап-зоны. ===== */
  #olcrtc-key-widget .okw-nav{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:0 0 12px;border-bottom:1px dashed var(--ant-border,#00ff9c33)}
  #olcrtc-key-widget .okw-nav-item{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:11px;font-size:13.5px;font-weight:700;line-height:1.3;text-decoration:none;background:#0d2218;color:#c9ffd9;border:1px solid var(--ant-border,#00ff9c33);transition:.14s transform, .14s box-shadow, .14s background}
  #olcrtc-key-widget .okw-nav-item:hover{background:rgba(0,255,156,.1);color:#fff;border-color:var(--ant-border-strong,#00ff9c66);transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,229,160,.22)}
  #olcrtc-key-widget .okw-nav-item.okw-nav-active{background:linear-gradient(135deg,#00ff9c,#00e5ff);color:#00110a;border-color:transparent;box-shadow:0 6px 18px rgba(0,229,160,.28)}
  /* ===== Вкладки карточки: ключ · купить подписку · инструкция =====
     Всё содержимое собрано на вкладках внутри блока «Моя подписка», чтобы
     пользователю не приходилось пролистывать длинную простыню целиком. */
  #olcrtc-key-widget .okw-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:6px;background:#05110a;border:1px solid var(--ant-border,#00ff9c33);border-radius:13px}
  #olcrtc-key-widget button.okw-tab{appearance:none;flex:1 1 auto;min-width:132px;cursor:pointer;font-family:inherit;padding:11px 14px;border-radius:9px;border:1px solid transparent;background:transparent;color:#9fd7b8;font-weight:800;font-size:13.5px;line-height:1.3;transition:.14s background,.14s color,.14s box-shadow,.14s border-color}
  #olcrtc-key-widget button.okw-tab:hover{background:rgba(0,255,156,.08);color:#e8ffef}
  #olcrtc-key-widget button.okw-tab.okw-tab-active{background:linear-gradient(135deg,#00ff9c,#00e5ff);color:#00110a;border-color:transparent;box-shadow:0 6px 18px rgba(0,229,160,.28)}
  #olcrtc-key-widget .okw-panel{display:none}
  #olcrtc-key-widget .okw-panel.okw-panel-active{display:block;animation:okw-fade .18s ease}
  @keyframes okw-fade{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:none}}
  /* ===== ЛК mode: когда пользователь авторизован — убираем маркетинговый лендинг
     и оставляем ЕДИНОЕ рабочее место: карточка ключа с меню + SPA-кабинет.
     Плавающие «виджеты» сверху (маркетинговые пилюли в шапке) скрываем. ===== */
  body.mv-lk .mv-hero,
  body.mv-lk .mv-bottom,
  body.mv-lk .mv-noscript{display:none !important}
  body.mv-lk .mv-topbar .mv-pill{display:none !important}
  body.mv-lk .mv-topbar{padding:6px 12px}
  body.mv-lk #olcrtc-key-widget{margin-top:10px}
  body.mv-lk #app{margin-top:10px}
  /* ===== Адаптивность (мобильные): крупные кнопки, читаемые ссылки, без
     горизонтального скролла. ===== */
  @media (max-width: 640px){
    #olcrtc-key-widget{margin:12px 8px;border-radius:12px}
    #olcrtc-key-widget .okw-head{padding:12px 14px;font-size:15.5px}
    #olcrtc-key-widget .okw-body{padding:12px 14px 16px}
    #olcrtc-key-widget .okw-nav{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    #olcrtc-key-widget .okw-nav-item{justify-content:center;padding:11px 8px;font-size:13px}
    #olcrtc-key-widget .okw-tabs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;padding:5px}
    #olcrtc-key-widget button.okw-tab{min-width:0;padding:10px 4px;font-size:12px}
    #olcrtc-key-widget .okw-btns,#olcrtc-key-widget .okw-cta-btns{display:grid;grid-template-columns:1fr;gap:8px}
    #olcrtc-key-widget button.okw-btn,#olcrtc-key-widget a.okw-btn{width:100%;justify-content:center;text-align:center}
    #olcrtc-key-widget .okw-trial{padding:12px}
    #olcrtc-key-widget .okw-trial-btn{width:100%}
    #olcrtc-key-widget textarea.okw-uri-ta{font-size:12.5px;min-height:96px}
  }
</style>
<style id="olcrtc-pay-style">
  #olcrtc-widget-home{display:none !important}
  #olcrtc-spa-widget-wrapper{margin:0 0 18px 0;position:relative;z-index:45}
  #olcrtc-key-widget .okw-pay{margin:14px 0 0;border:1px solid var(--ant-border,#00ff9c33);border-radius:14px;background:#05110a;padding:14px}
  #olcrtc-key-widget .okw-pay-title{font-weight:800;font-size:15px;color:#e8ffef;margin:0 0 8px}
  #olcrtc-key-widget .okw-pay-sub{font-size:13px;line-height:1.65;color:#9fd7b8;margin:0 0 12px}
  #olcrtc-key-widget .okw-pay-label{font-size:12px;font-weight:800;letter-spacing:.02em;text-transform:uppercase;color:#74a18a;margin:12px 0 8px}
  #olcrtc-key-widget .okw-pay-methods{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 10px}
  #olcrtc-key-widget .okw-pay-m{display:inline-flex;align-items:center;gap:6px;padding:9px 13px;border-radius:11px;background:#0d2218;border:1px solid var(--ant-border,#00ff9c33);font-size:13px;font-weight:700;color:#c9ffd9}
  #olcrtc-key-widget button.okw-pay-m{appearance:none;cursor:pointer;font-family:inherit;transition:.14s transform,.14s box-shadow,.14s border-color,.14s background}
  #olcrtc-key-widget button.okw-pay-m:hover{transform:translateY(-1px);background:rgba(0,255,156,.1);border-color:var(--ant-border-strong,#00ff9c66)}
  #olcrtc-key-widget button.okw-pay-m.okw-active{background:linear-gradient(135deg,rgba(0,255,156,.28),rgba(0,229,255,.28));border-color:#00e5a0;color:#eafff5;box-shadow:0 6px 18px rgba(0,229,160,.22)}
  #olcrtc-key-widget .okw-plan{border:1px solid var(--ant-border,#00ff9c33);background:#08150e;border-radius:12px;padding:11px 13px;margin:0 0 8px}
  #olcrtc-key-widget .okw-plan-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 8px}
  #olcrtc-key-widget .okw-plan-name{font-weight:800;font-size:14.5px;color:#e8ffef}
  #olcrtc-key-widget .okw-plan-limit{font-size:12px;color:#9fd7b8;background:#05110a;border:1px solid var(--ant-border,#00ff9c33);border-radius:999px;padding:2px 10px}
  #olcrtc-key-widget .okw-plan-prices{display:grid;grid-template-columns:repeat(auto-fit,minmax(126px,1fr));gap:8px}
  #olcrtc-key-widget button.okw-price{appearance:none;display:flex;flex-direction:column;align-items:flex-start;gap:2px;min-width:104px;padding:10px;border-radius:11px;border:1px solid var(--ant-border,#00ff9c33);background:#0d2218;cursor:pointer;font-family:inherit;transition:.14s transform,.14s box-shadow,.14s border-color,.14s background;text-align:left}
  #olcrtc-key-widget button.okw-price:hover{transform:translateY(-1px);background:rgba(0,255,156,.08);border-color:var(--ant-border-strong,#00ff9c66)}
  #olcrtc-key-widget button.okw-price.okw-active{background:linear-gradient(135deg,rgba(0,255,156,.26),rgba(0,229,255,.26));border-color:#00e5a0;box-shadow:0 6px 18px rgba(0,229,160,.22)}
  #olcrtc-key-widget .okw-price b{font-size:15px;color:#e8ffef}
  #olcrtc-key-widget .okw-price span{font-size:11.5px;color:#9fd7b8}
  #olcrtc-key-widget .okw-pay-summary{margin-top:12px;font-size:13px;line-height:1.65;color:#c9ffd9;background:rgba(0,229,255,.08);border:1px solid rgba(0,229,255,.3);border-radius:12px;padding:10px 12px}
  #olcrtc-key-widget .okw-pay-summary b{color:#e8ffef}
  #olcrtc-key-widget .okw-pay-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
  #olcrtc-key-widget .okw-pay-main[disabled]{opacity:.55;cursor:not-allowed;filter:saturate(.65);box-shadow:none}
  #olcrtc-key-widget .okw-pay-note{margin-top:10px;font-size:12.5px;line-height:1.65;color:#ffd9a0;background:rgba(255,209,102,.09);border:1px solid rgba(255,209,102,.32);border-radius:12px;padding:10px 12px}
  #olcrtc-key-widget .okw-pay-note b{color:#ffe9bd}
  @media (max-width:640px){
    #olcrtc-key-widget .okw-pay-actions{display:grid;grid-template-columns:1fr;gap:8px}
    #olcrtc-key-widget .okw-plan-prices{grid-template-columns:1fr 1fr}
  }
</style>
<div id="olcrtc-key-widget" class="okw-hide" aria-live="polite">
  <div class="okw-head">
    <span id="okw-title">🔑 Ваш ключ подключения OlcRTC (VPN)</span>
    <span class="okw-status" id="okw-status">Загрузка…</span>
  </div>
  <div class="okw-body">
    <nav class="okw-nav" id="okw-nav" aria-label="Меню кабинета">
      <a class="okw-nav-item" href="#/dashboard" data-okw-nav="dashboard">🔑 Моя подписка</a>
      <a class="okw-nav-item" href="#/plan" data-okw-nav="plan">🛒 Тарифы</a>
      <a class="okw-nav-item" href="#/order" data-okw-nav="order">🧾 Заказы и оплата</a>
      <a class="okw-nav-item" href="#/knowledge" data-okw-nav="knowledge">📚 Инструкция</a>
    </nav>
    <div class="okw-banner" id="okw-banner"><span class="okw-loader"></span>Загружаем ключ из кабинета… это займёт 5–10 секунд.</div>
    <!-- ===== Вкладки карточки. Всё содержимое «Моей подписки» разложено по трём
         вкладкам, чтобы пользователь видел нужное одним тапом, без длинной
         простыни и без «бегания» по странице. ===== -->
    <div class="okw-tabs" id="okw-tabs" role="tablist" aria-label="Разделы карточки OlcRTC">
      <button type="button" class="okw-tab okw-tab-active" data-okw-tab="key" role="tab" aria-selected="true">🔑 Мой ключ</button>
      <button type="button" class="okw-tab" data-okw-tab="buy" role="tab" aria-selected="false">🛒 Купить подписку</button>
      <button type="button" class="okw-tab" data-okw-tab="help" role="tab" aria-selected="false">📚 Инструкция</button>
    </div>

    <!-- Вкладка 1: ключ, статус подписки, бесплатный тест -->
    <section class="okw-panel okw-panel-active" data-okw-panel="key" role="tabpanel">
      <div class="okw-meta" id="okw-meta" style="display:none">
        <span class="okw-chip" id="okw-chip-plan">—</span>
        <span class="okw-chip" id="okw-chip-left">—</span>
        <span class="okw-chip" id="okw-chip-until">—</span>
      </div>
      <div class="okw-trial" id="okw-trial-row" style="display:none">
        <div class="okw-trial-info">
          <span class="okw-trial-icon">🆓</span>
          <div class="okw-trial-texts">
            <div class="okw-trial-title" id="okw-trial-title">Получите БЕСПЛАТНУЮ тестовую подписку OlcRTC VPN на 3 дня (72 часа)</div>
            <div class="okw-trial-sub" id="okw-trial-sub">Один раз на аккаунт · без карты · сразу ключ в кабинете ниже · работает в OlcBox / owenclave</div>
          </div>
        </div>
        <div class="okw-trial-btns" id="okw-trial-btns">
          <button type="button" class="okw-btn okw-primary okw-trial-btn" id="okw-trial-btn">🚀 АКТИВИРОВАТЬ ТЕСТ НА <span id="okw-trial-h">3 дня (72 часа)</span> (1 РАЗ!)</button>
        </div>
      </div>
      <div class="okw-uri" id="okw-uri-row" style="display:none">
        <textarea id="okw-uri-ta" class="okw-uri-ta" spellcheck="false" readonly placeholder="Ключ появится здесь…" aria-label="OlcRTC URI ключ"></textarea>
      </div>
      <div class="okw-btns" id="okw-btns-row" style="display:none">
        <button type="button" class="okw-btn okw-primary" id="okw-copy-btn">📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (URI)</button>
        <button type="button" class="okw-btn okw-ghost" id="okw-copy-sub-btn">🔗 Скопировать ссылку-подписку</button>
        <button type="button" class="okw-btn okw-ghost" id="okw-yaml-btn">⬇️ Скачать client.yaml</button>
        <button type="button" class="okw-btn okw-ghost" id="okw-qr-btn">📱 Показать QR-код</button>
        <a id="okw-sub-a" href="#" target="_blank" rel="noopener" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">👁 Открыть ссылку-подписку</a>
        <button type="button" class="okw-btn okw-ghost" data-okw-goto="help">📚 Инструкция подключения</button>
        <button type="button" class="okw-btn okw-danger" id="okw-refresh-btn">🔄 Пересоздать ключ (обновить)</button>
      </div>
      <div class="okw-qr" id="okw-qr-row" style="display:none">
        <div class="okw-qr-title">📱 QR-код вашего ключа — отсканируйте телефоном</div>
        <img id="okw-qr-img" alt="QR-код ключа OlcRTC" width="220" height="220">
        <div class="okw-qr-sub">В клиенте owenclave на телефоне: ➕ → «Scan QR» → наведите камеру на код. Тот же ключ, что и кнопкой «📋 Скопировать».</div>
      </div>
    </section>

    <!-- Вкладка 2: покупка / продление подписки -->
    <section class="okw-panel" data-okw-panel="buy" role="tabpanel">
      <div class="okw-cta-btns" id="okw-cta-row" style="display:none">
        <a id="okw-buy-a" href="#/plan" class="okw-btn okw-buy" style="text-decoration:none">🛒 Купить тариф</a>
        <a id="okw-orders-a" href="#/order" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">🧾 Мои заказы и оплата</a>
      </div>
      <div class="okw-pay" id="okw-pay-box" style="display:none;margin-top:0;border:0;background:transparent;padding:0">
        <div class="okw-pay-title">💳 Продлить или купить подписку</div>
        <div class="okw-pay-sub">Всё собрано в одном месте: выберите способ оплаты, срок и тариф прямо здесь. После успешной оплаты ключ и ссылка-подписка появятся в этом же разделе автоматически.</div>
        <div class="okw-pay-label">Способ оплаты</div>
        <div class="okw-pay-methods" id="okw-pay-methods"></div>
        <div class="okw-pay-label">Тариф и срок</div>
        <div class="okw-pay-plans" id="okw-pay-plans"></div>
        <div class="okw-pay-summary" id="okw-pay-summary" style="display:none"></div>
        <div class="okw-pay-actions">
          <button type="button" id="okw-pay-now-btn" class="okw-btn okw-buy okw-pay-main" disabled>🛒 Выберите тариф и способ оплаты</button>
          <a id="okw-pay-cta-a" href="#/plan" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">📋 Открыть страницу тарифов</a>
          <a id="okw-pay-support-a" href="#/ticket" class="okw-btn okw-ghost" style="text-decoration:none;color:inherit">🆘 Написать в поддержку</a>
        </div>
        <div class="okw-pay-note">
          <b>Порядок оплаты:</b> 1) выберите срок и тариф выше → 2) нажмите кнопку оплаты → 3) завершите оплату в ЮMoney или ЮKassa.
          Ключ <b>выдаётся автоматически</b> в течение 1–2 минут после успешной оплаты. Если оплата прошла, а ключа нет больше 5 минут — нажмите
          <a id="okw-pay-support-note-a" href="#/ticket" style="color:#ffd166;font-weight:800;text-decoration:underline">«Написать в поддержку»</a>
          и приложите номер заказа (он указан в разделе «Заказы и оплата»).
        </div>
      </div>
    </section>

    <!-- Вкладка 3: как подключиться (3 шага + клиенты + подсказка) -->
    <section class="okw-panel" data-okw-panel="help" role="tabpanel">
      <div class="okw-steps" id="okw-steps" style="display:none">
        <div class="okw-steps-title">🚀 Как подключиться — 3 шага</div>
        <ol class="okw-steps-list">
          <li><b>Скачайте клиент</b> — OlcBox (ПК) или owenclave (Android). Ссылки — в блоке ниже.</li>
          <li><b>Скопируйте ключ</b> во вкладке «🔑 Мой ключ» (или отсканируйте QR: кнопка «📱 Показать QR-код»).</li>
          <li><b>Вставьте ключ в клиент</b>: ➕ → «Import / Paste from clipboard» (на телефоне — «Scan QR») → Connect ▶️.</li>
        </ol>
        <div class="okw-clients" id="okw-clients">
          <h4>📥 Скачать клиент VPN (OlcRTC-совместимые)</h4>
          <ul id="okw-clients-ul">
            <li><strong>Windows / macOS / Linux:</strong> <a href="https://github.com/alananisimov/olcbox/releases" target="_blank" rel="noopener">OlcBox — скачать .msi / .dmg</a>. Установите → ➕ → Paste from Clipboard → Connect.</li>
            <li><strong>Android:</strong> <a href="https://github.com/owenewans/owenclave/releases" target="_blank" rel="noopener">owenclave — скачать app-*-release.apk</a>. Разрешите «Неизвестные источники» → установите → ➕ → Import from clipboard → Play ▶️.</li>
          </ul>
        </div>
        <div class="okw-hint" id="okw-hint">DNS Яндекс уже вшит в ключ — дополнительных настроек не требуется. Если ключ не подключается, нажмите «🔄 Пересоздать ключ (обновить)» во вкладке «🔑 Мой ключ».</div>
      </div>
    </section>
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
    var $yaml=document.getElementById('okw-yaml-btn');
    var $qrBtn=document.getElementById('okw-qr-btn');
    var $qrRow=document.getElementById('okw-qr-row');
    var $qrImg=document.getElementById('okw-qr-img');
    var $meta=document.getElementById('okw-meta');
    var $chipPlan=document.getElementById('okw-chip-plan');
    var $chipLeft=document.getElementById('okw-chip-left');
    var $chipUntil=document.getElementById('okw-chip-until');
    var $steps=document.getElementById('okw-steps');
    var $copySub=document.getElementById('okw-copy-sub-btn');
    var $ctaRow=document.getElementById('okw-cta-row');
    var $buyA=document.getElementById('okw-buy-a');
    var $ordersA=document.getElementById('okw-orders-a');
    var $refresh=document.getElementById('okw-refresh-btn');
    var $subA=document.getElementById('okw-sub-a');
    var $clientsUl=document.getElementById('okw-clients-ul');
    var $trialRow=document.getElementById('okw-trial-row');
    var $trialBtn=document.getElementById('okw-trial-btn');
    var $trialTitle=document.getElementById('okw-trial-title');
    var $trialSub=document.getElementById('okw-trial-sub');
    var $trialH=document.getElementById('okw-trial-h');
    var $payBox=document.getElementById('okw-pay-box');
    var $payMethods=document.getElementById('okw-pay-methods');
    var $payPlans=document.getElementById('okw-pay-plans');
    var $paySummary=document.getElementById('okw-pay-summary');
    var $payNow=document.getElementById('okw-pay-now-btn');
    var $payCta=document.getElementById('okw-pay-cta-a');
    var payLoaded=false;
    var payState={info:null,methods:[],selectedOfferKey:'',selectedMethodId:'',busy:false};
    var ok=false;
    var trialOk=false;
    var retriesScheduled=0;
    var widgetMountRetrySeq=0;

    // ===== Вкладки карточки (ключ · купить подписку · инструкция) =====
    var currentTab='key';
    function activateTab(name){
      try{
        name=String(name||'key');
        var tabs=$w.querySelectorAll('.okw-tab');
        for(var i=0;i<tabs.length;i++){
          var on=tabs[i].getAttribute('data-okw-tab')===name;
          if(on){ tabs[i].classList.add('okw-tab-active'); } else { tabs[i].classList.remove('okw-tab-active'); }
          tabs[i].setAttribute('aria-selected', on?'true':'false');
        }
        var panels=$w.querySelectorAll('.okw-panel');
        for(var j=0;j<panels.length;j++){
          var p=panels[j];
          if(p.getAttribute('data-okw-panel')===name){ p.classList.add('okw-panel-active'); } else { p.classList.remove('okw-panel-active'); }
        }
        currentTab=name;
      }catch(e){}
    }
    function bindTabs(){
      try{
        var tabs=$w.querySelectorAll('.okw-tab');
        for(var i=0;i<tabs.length;i++){
          tabs[i].addEventListener('click',function(e){
            e.preventDefault();
            activateTab(this.getAttribute('data-okw-tab'));
          });
        }
        var gos=$w.querySelectorAll('[data-okw-goto]');
        for(var k=0;k<gos.length;k++){
          gos[k].addEventListener('click',function(e){
            e.preventDefault();
            activateTab(this.getAttribute('data-okw-goto'));
          });
        }
      }catch(e){}
    }

    // The Umi SPA authenticates with a Sanctum Bearer token kept in localStorage
    // under "VUE_NAIVE_ACCESS_TOKEN" (prefix "Vue_Naive_" + "access_token", then
    // uppercased by the SPA storage wrapper).  It does NOT create a cookie
    // session, so plain same-origin fetches stay anonymous.  Forward the token.
    //
    // IMPORTANT: the storage wrapper writes JSON — localStorage.getItem() returns
    // '{"value":"<token>","time":123,"expire":null}', NOT the raw token.  Sending
    // that JSON blob as the Bearer value makes Sanctum reject the token and the
    // widget falls back to "guest" (invisible key card).  Unwrap .value first.
    function unwrapToken(raw){
      if(raw===null || raw===undefined) return null;
      var s=String(raw).trim();
      if(!s) return null;
      if(s.charAt(0)==='{'){
        try{
          var o=JSON.parse(s);
          if(o && typeof o==='object'){
            var v=o.value!==undefined?o.value:(o.access_token!==undefined?o.access_token:null);
            if(v===null || v===undefined) return null;
            v=String(v).trim();
            return v||null;
          }
        }catch(e){ return s; }
      }
      return s;
    }
    function readAccessToken(){
      function from(st){
        if(!st) return null;
        try{
          var t=unwrapToken(st.getItem('VUE_NAIVE_ACCESS_TOKEN'));
          if(t) return t;
          for(var i=0;i<st.length;i++){
            var k=st.key(i)||'';
            if(/access_token$/i.test(k)){ t=unwrapToken(st.getItem(k)); if(t) return t; }
          }
        }catch(e){}
        return null;
      }
      return from(window.localStorage) || from(window.sessionStorage);
    }
    function authHeaders(extra){
      var h=extra||{};
      h['X-Requested-With']='XMLHttpRequest';
      h['Accept']='application/json';
      try{
        var t=readAccessToken();
        if(t){ h['Authorization']=/^Bearer\s/i.test(t)?t:('Bearer '+t); }
      }catch(e){}
      return h;
    }

    function setBanner(html,cls){
      if(!$banner) return;
      cls=cls||'';
      $banner.className='okw-banner '+(cls?('okw-banner '+cls):'okw-banner').replace(/okw-banner\s*okw-banner/,'okw-banner');
      $banner.innerHTML=html;
    }
    function setStatus(t){ if($status) $status.textContent=t; }
    function showWidget(){
      $w.classList.remove('okw-hide');
      $w.classList.remove('okw-guest');
      // Authenticated → switch the page into "cabinet" mode: landing hidden and
      // the OlcRTC key card is placed on top (see enterLk()).
      enterLk();
    }
    function hideWidget(){
      $w.classList.add('okw-hide');
    }

    // Чипы состояния подписки: «тариф / тест · сколько осталось · до какой даты».
    function renderMeta(d){
      if(!$meta) return;
      d=d||{};
      var type=String(d.plan_type||'').toLowerCase();
      if(type!=='trial' && type!=='paid'){ $meta.style.display='none'; return; }
      var title=String(d.plan_title||'').trim();
      if($chipPlan){
        $chipPlan.className='okw-chip'+(type==='trial'?' okw-chip-trial':'');
        $chipPlan.textContent=(type==='trial'?'🎁 Бесплатный тест на 3 дня':('💳 Тариф'+(title?' «'+title+'»':'')));
      }
      if($chipLeft){
        $chipLeft.className='okw-chip'+(d.expiring_soon?' okw-chip-warn':'');
        $chipLeft.textContent='⏳ '+(String(d.time_left_text||'').trim()||'—');
      }
      if($chipUntil){
        $chipUntil.className='okw-chip';
        $chipUntil.textContent='📅 действует до '+((String(d.expired_at_text||'').trim())||'—');
      }
      $meta.style.display='flex';
    }

    // Штатный Umi-кабинет рисует свою карточку «Быстрая подписка» (一键订阅),
    // которая экспортирует ссылку в V2Ray/Clash. В режиме OlcRTC-ONLY это
    // дублирует нашу карточку ключа и путает пользователя — прячем её,
    // оставляя ЕДИНОЕ понятное место: наш виджет с ключом выше.
    function hideLegacyQuickSub(){
      try{
        var nodes=document.querySelectorAll('#app .ant-card, #app [class*="card"]');
        for(var i=0;i<nodes.length;i++){
          var el=nodes[i];
          if(el.getAttribute && el.getAttribute('data-okw-hidden')) continue;
          var txt=String(el.textContent||'');
          if(txt.indexOf('Быстрая подписка')>=0 || txt.indexOf('一键订阅')>=0){
            if(el.querySelectorAll('*').length>400) continue;
            el.style.display='none';
            el.setAttribute('data-okw-hidden','1');
          }
        }
      }catch(e){}
    }
    function watchLegacyQuickSub(){
      try{
        var app=document.getElementById('app');
        if(!app || !window.MutationObserver) return;
        var t=null;
        var mo=new MutationObserver(function(){
          if(t) return;
          t=setTimeout(function(){ t=null; hideLegacyQuickSub(); }, 400);
        });
        mo.observe(app,{childList:true,subtree:true});
      }catch(e){}
    }

    // Надёжное копирование: navigator.clipboard работает только в secure context
    // (HTTPS/localhost), а кабинет открывается по обычному http://IP:7001 —
    // поэтому всегда есть fallback через скрытый textarea + execCommand('copy').
    function copyText(text, onOk, onFail){
      var v=String(text||'').trim();
      if(!v){ onFail && onFail(''); return false; }
      function fallback(){
        try{
          var tmp=document.createElement('textarea');
          tmp.value=v;
          tmp.setAttribute('readonly','');
          tmp.style.position='fixed';
          tmp.style.top='-1000px';
          tmp.style.left='-1000px';
          tmp.style.opacity='0';
          document.body.appendChild(tmp);
          tmp.focus();
          tmp.select();
          try{ tmp.setSelectionRange(0, tmp.value.length); }catch(e){}
          var okc=document.execCommand('copy');
          document.body.removeChild(tmp);
          if(okc){ onOk && onOk(); return true; }
        }catch(e){}
        return false;
      }
      try{
        if(navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext){
          navigator.clipboard.writeText(v).then(function(){ onOk && onOk(); }, function(){ if(!fallback()){ onFail && onFail(v); } });
          return true;
        }
      }catch(e){}
      if(!fallback()){ onFail && onFail(v); }
      return true;
    }

    function doCopy(){
      var v=($ta.value||'').trim();
      if(!v){ alert('⚠️ Ключ ещё не загрузился. Подождите 5–10 секунд и нажмите «🔄 Пересоздать».'); return false; }
      copyText(v, function(){
        if($copy){ var orig=$copy.textContent; $copy.textContent='✅ СКОПИРОВАНО! Вставляйте в клиент ➕ Import'; setTimeout(function(){ $copy.textContent=orig; }, 3200); }
        if(window.navigator && navigator.vibrate){ try{ navigator.vibrate(40); }catch(e){} }
      }, function(){
        try{ $ta.focus(); $ta.select(); }catch(e){}
        alert('Скопируйте вручную: выделите текст внутри поля → Ctrl+C / Cmd+C');
      });
      return true;
    }

    function isLkDashboard(){
      var h=(location.hash||'').toLowerCase();
      if(!h || h==='#' || h==='#/') return false;
      // anything that is not a login/register/forgot screen is the customer area
      if(/^#\/?(user\/)?(login|register|forgot|reset|passwordreset)/i.test(h)) return false;
      if(/^#\//.test(h)) return true;
      return false;
    }

    // Раздел «Моя подписка» против остальных разделов кабинета.
    // Раньше карточка OlcRTC монтировалась на ВСЕХ страницах ЛК и, когда SPA-карточку
    // «Моя подписка» не удавалось найти, вставала в самом верху (над #app). Из-за этого
    // при переходе в «Заказы/Тарифы/Инструкцию» страница уезжала наверх, и приходилось
    // каждый раз пролистывать виджет, чтобы дойти до нужного раздела.
    // Теперь карточка живёт ТОЛЬКО в разделе подписки, а в остальных разделах скрывается.
    function isSubscriptionRoute(){
      var h=(location.hash||'').toLowerCase().replace(/^#\/?/,'');
      h=h.replace(/^user\//,'');
      var seg=h.split(/[/?#]/)[0];
      var nonSub=['plan','plans','order','orders','knowledge','ticket','tickets','traffic','node','nodes','profile','account','invite','shop','servers','server'];
      return nonSub.indexOf(seg)<0;
    }

    function getWidgetHome(){
      return document.getElementById('olcrtc-widget-home') || document.body;
    }

    function parkWidgetHome(){
      try{
        var wrap=document.getElementById('olcrtc-spa-widget-wrapper');
        if(wrap && wrap.parentNode){ wrap.parentNode.removeChild(wrap); }
        var home=getWidgetHome();
        if(home && $w.parentElement!==home){ home.appendChild($w); }
      }catch(e){}
    }

    function hideLegacySubscriptionStub(root){
      try{
        var scope=root||document;
        var nodes=scope.querySelectorAll('.ant-empty, .ant-card-body > div, [class*=empty], [class*=Empty], [class*=subscription]');
        for(var i=0;i<nodes.length;i++){
          var el=nodes[i];
          if(!el || el.id==='olcrtc-spa-widget-wrapper') continue;
          if(el.closest && el.closest('#olcrtc-spa-widget-wrapper')) continue;
          if(el.getAttribute && el.getAttribute('data-okw-hidden-sub')) continue;
          var txt=String(el.innerText||el.textContent||'').replace(/\s+/g,' ').trim();
          if(!txt || txt.length>240) continue;
          if(/(Купить подписку|暂无订阅|暂未订阅|No subscription|No data|一键订阅)/i.test(txt)){
            el.style.display='none';
            el.setAttribute('data-okw-hidden-sub','1');
          }
        }
      }catch(e){}
    }

    function findSubscriptionMount(){
      try{
        var cards=document.querySelectorAll('#app .ant-card, #app [class*=ProCard], #app section[class*=ant-card], #app [class*=subscription], #app [class*=GridContent] > [class*=ant-col] > div, #app [class*=PageContainer] [class*=children] > [class*=ant-card]');
        for(var i=0;i<cards.length;i++){
          var txt=(cards[i].innerText||cards[i].textContent||'').replace(/\s+/g,' ').trim().substring(0,420);
          if(txt.indexOf('Моя подписка')>=0 || txt.indexOf('My Subscription')>=0 || txt.indexOf('我的订阅')>=0 || txt.indexOf('订阅')>=0){
            return {
              card: cards[i],
              target: cards[i].querySelector('.ant-card-body, [class*=card-body], [class*=Body], [class*=content]') || cards[i]
            };
          }
        }
        var h=(location.hash||'').toLowerCase();
        if(/^#\/(dashboard|subscription|plan|order|knowledge|ticket)/i.test(h)){
          var fallback=document.querySelector('#app .ant-pro-grid-content, #app [class*=GridContent], #app [class*=PageContainer] [class*=children], #app .ant-pro-page-container');
          if(fallback) return {card:null,target:fallback};
        }
      }catch(e){}
      return null;
    }

    // Резервная, ВСЕГДА ВИДИМАЯ точка монтирования: карточка ключа ставится
    // прямо под шапкой кабинета, НАД SPA-приложением. Нужна именно для случая,
    // когда Umi ещё не отрисовал карточку «Моя подписка» (или отрисовал её
    // другой разметкой). Без этого виджет уходил в #olcrtc-widget-home, а он
    // скрыт (display:none !important) — пользователь вообще не видел ключ/тест.
    function mountWidgetFallback(){
      try{
        var wrap=document.getElementById('olcrtc-spa-widget-wrapper');
        if(!wrap){
          wrap=document.createElement('div');
          wrap.setAttribute('id','olcrtc-spa-widget-wrapper');
        }
        if($w.parentElement!==wrap){ wrap.appendChild($w); }
        var app=document.getElementById('app');
        var host=(app && app.parentElement) || document.querySelector('.mv-wrap');
        if(!host) return false;
        var topbar=host.querySelector ? host.querySelector('.mv-topbar') : null;
        var before=topbar ? topbar.nextSibling : host.firstChild;
        if(wrap.parentElement!==host || wrap.nextSibling!==before){
          host.insertBefore(wrap, before || null);
        }
        return true;
      }catch(e){}
      return false;
    }

    function mountWidgetForRoute(){
      try{
        if(!isLkDashboard()){
          parkWidgetHome();
          return false;
        }
        // Карточка OlcRTC — часть раздела «Моя подписка». В остальных разделах
        // кабинета (тарифы, заказы, инструкция, тикеты, трафик, узлы, аккаунт)
        // она не нужна и только мешает навигации — полностью скрываем.
        if(!isSubscriptionRoute()){
          parkWidgetHome();
          return false;
        }
        var found=findSubscriptionMount();
        if(!found || !found.target){
          return mountWidgetFallback();
        }
        var wrap=document.getElementById('olcrtc-spa-widget-wrapper');
        if(!wrap){
          wrap=document.createElement('div');
          wrap.setAttribute('id','olcrtc-spa-widget-wrapper');
        }
        if($w.parentElement!==wrap){ wrap.appendChild($w); }
        if(!found.target.querySelector('#olcrtc-spa-widget-wrapper')){
          found.target.insertBefore(wrap, found.target.firstChild || null);
        }
        hideLegacyQuickSub();
        hideLegacySubscriptionStub(found.card || found.target);
        return true;
      }catch(e){}
      return false;
    }

    function scheduleWidgetMountRetry(){
      try{
        widgetMountRetrySeq++;
        var seq=widgetMountRetrySeq;
        [120, 400, 900, 1800].forEach(function(delay){
          setTimeout(function(){
            try{
              if(seq!==widgetMountRetrySeq) return;
              if(isLkDashboard()) mountWidgetForRoute();
            }catch(e){}
          }, delay);
        });
      }catch(e){}
    }

    var lkEntered=false;
    function enterLk(){
      lkEntered=true;
      try{
        document.body.classList.add('mv-lk');
        mountWidgetForRoute();
        scheduleWidgetMountRetry();
      }catch(e){}
    }

    function exitLk(){
      lkEntered=false;
      try{
        parkWidgetHome();
        document.body.classList.remove('mv-lk');
      }catch(e){}
    }

    function fetchTrialStatus(){
      if(!$trialRow) return Promise.resolve({claimed:false,active:false});
      var headers=authHeaders();
      return fetch('/api/olcrtc/trial-status',{credentials:'same-origin',cache:'no-store',headers:headers}).then(function(r){
        var ct=r.headers.get('Content-Type')||'';
        if(ct.indexOf('application/json')>=0) return r.json();
        // Ответ НЕ JSON (страница SPA при отсутствующем маршруте, ошибка 500 и
        // т.п.). Раньше это трактовалось как «гость», и плашка бесплатного
        // теста молча исчезала из кабинета авторизованного пользователя.
        return {claimed:false,active:false,unavailable:true};
      }).catch(function(){ return {claimed:false,active:false,unavailable:true}; });
    }

    function trialLabel(hours){
      hours=parseInt(hours,10)||72;
      if(hours>=24 && hours%24===0) return (hours/24)+' дня ('+hours+' часа)';
      return hours+' часов';
    }

    function renderTrialButton(d){
      if(!$trialRow) return;
      d=d||{};
      var hours=parseInt(d.try_out_hour||d.hours||72,10)||72;
      var claimed=!!d.claimed;
      var active=!!d.active;
      var planType=String(d.plan_type||'').toLowerCase();
      var guest=(d.status==='guest' || d.guest===true);
      if(guest){
        $trialRow.style.display='none';
        return;
      }

      // Блок тестовой подписки ВСЕГДА виден в кабинете — в одном из трёх
      // понятных состояний. Раньше он полностью скрывался (display:none) после
      // активации теста или покупки тарифа, из-за чего пользователю казалось,
      // что «тестовая подписка пропала» из панели управления. Теперь плашка
      // остаётся на месте и показывает текущий статус + что делать дальше.
      $trialRow.style.display='flex';

      if(active){
        trialOk=true;
        $trialRow.classList.add('okw-trial-claimed');
        $trialRow.setAttribute('aria-disabled','true');
        if(planType==='trial'){
          if($trialTitle){ $trialTitle.textContent='🎁 Бесплатная тестовая подписка АКТИВНА'+(d.expired_at_text?(' — работает до '+d.expired_at_text+' (МСК)'):''); }
          if($trialSub){ $trialSub.textContent='Осталось: '+(String(d.time_left_text||'').trim()||'—')+' · ключ olcrtc:// уже в кабинете ниже · после окончания оформите тариф, чтобы не потерять доступ'; }
          if($trialBtn){ $trialBtn.disabled=true; $trialBtn.style.opacity='0.85'; $trialBtn.style.cursor='default'; $trialBtn.innerHTML='🎁 ТЕСТ АКТИВЕН'; }
        } else {
          if($trialTitle){ $trialTitle.textContent='✅ Подписка OlcRTC VPN активна'+(d.plan_title?(' — тариф «'+d.plan_title+'»'):''); }
          if($trialSub){ $trialSub.textContent='Ключ olcrtc:// и ссылка-подписка — в этом же разделе ниже. Бесплатный тест доступен 1 раз на аккаунт.'; }
          if($trialBtn){ $trialBtn.disabled=true; $trialBtn.style.opacity='0.85'; $trialBtn.style.cursor='default'; $trialBtn.innerHTML='✅ ПОДПИСКА АКТИВНА'; }
        }
        return;
      }

      if(claimed){
        trialOk=true;
        $trialRow.classList.add('okw-trial-claimed');
        $trialRow.setAttribute('aria-disabled','true');
        if($trialTitle){ $trialTitle.textContent='⛔ Тестовая подписка уже была активирована'; }
        if($trialSub){ $trialSub.textContent='Бесплатный тест даётся 1 раз на аккаунт и уже использован. Оформите тариф кнопкой ниже — ключ появится здесь автоматически сразу после оплаты.'; }
        if($trialBtn){ $trialBtn.disabled=true; $trialBtn.style.opacity='0.85'; $trialBtn.style.cursor='default'; $trialBtn.innerHTML='🛒 ТЕСТ ИСПОЛЬЗОВАН — ОФОРМИТЕ ТАРИФ'; }
        return;
      }

      trialOk=false;
      $trialRow.classList.remove('okw-trial-claimed');
      $trialRow.removeAttribute('aria-disabled');
      if($trialTitle){ $trialTitle.textContent='Получите БЕСПЛАТНУЮ тестовую подписку OlcRTC VPN на '+trialLabel(hours); }
      if($trialSub){ $trialSub.textContent='Один раз на аккаунт · без карты · сразу ключ в кабинете ниже · работает в OlcBox / owenclave'; }
      if($trialBtn){ $trialBtn.disabled=false; $trialBtn.style.opacity='1'; $trialBtn.style.cursor='pointer'; $trialBtn.innerHTML='🚀 АКТИВИРОВАТЬ ТЕСТ НА '+trialLabel(hours)+' (1 РАЗ!)'; }
    }

    function claimTrialOnClick(e){
      e && e.preventDefault && e.preventDefault();
      if(!$trialBtn) return;
      $trialBtn.disabled=true;
      $trialBtn.style.opacity='.75';
      $trialBtn.style.cursor='progress';
      var origText=$trialBtn.innerHTML;
      $trialBtn.innerHTML='<span class="okw-loader"></span>Активируем тест… (3–10 сек)';
      var headers=authHeaders({'Content-Type':'application/json'});
      fetch('/api/olcrtc/claim-trial',{method:'POST',credentials:'same-origin',headers:headers,body:JSON.stringify({_token:((window.Laravel||{}).csrfToken||'')})}).then(function(r){
        var ct=r.headers.get('Content-Type')||'';
        return ct.indexOf('application/json')>=0 ? r.json() : r.text().then(function(t){return {status:'fail',message:t,http:r.status};});
      }).then(function(d){
        d=d||{};
        var msg=String(d.message||d.banner||'').trim();
        var http=parseInt(d.http||0,10);
        if(d.status==='success' || d.status==='ok' || (d.http && d.http<400 && d.expired_at_text)){
          setBanner((msg?msg:'✅ Тестовая подписка активирована!') + (d.expired_at_text?('<br><small>Работает до: <b>'+d.expired_at_text+' (МСК)</b></small>'):''),'ok');
          setStatus('✅ Тест актив');
          renderTrialButton({claimed:true,active:true,plan_type:'trial',expired_at_text:d.expired_at_text||'',time_left_text:'',try_out_hour:d.try_out_hour||72});
          retriesScheduled=0;
          setTimeout(function(){ try{ loadWidget(false); }catch(e){} }, 1600);
          if(window.navigator && navigator.vibrate){ try{ navigator.vibrate([30,40,30]); }catch(e){} }
        } else if(http===409 || /уже|expired|claimed|active|актив|повтор|one.?time/i.test(msg)){
          renderTrialButton({claimed:true,active:false,try_out_hour:d.try_out_hour||72});
          setBanner(msg || '⚠️ Тестовая подписка уже была активирована ранее (1 раз на аккаунт). Купите тариф для продления.', '');
        } else {
          setBanner('❌ Не удалось активировать тест: '+(msg?msg:'сетевая ошибка')+' — попробуйте ещё раз через 10 секунд.','err');
          if($trialBtn){ $trialBtn.disabled=false; $trialBtn.style.opacity='1'; $trialBtn.style.cursor='pointer'; $trialBtn.innerHTML=origText; }
        }
      }).catch(function(err){
        setBanner('❌ Ошибка сети: '+(err && err.message?err.message:String(err))+' — проверьте подключение и нажмите ещё раз.','err');
        if($trialBtn){ $trialBtn.disabled=false; $trialBtn.style.opacity='1'; $trialBtn.style.cursor='pointer'; $trialBtn.innerHTML=origText; }
      });
    }

    // ---- Витрина «Как оплатить»: способы оплаты и тарифы из админки ----
    function payEsc(s){
      return String(s===null||s===undefined?'':s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function apiData(res){
      return res && res.data!==undefined ? res.data : res;
    }
    function apiMessage(res,fallback){
      return String((res && (res.message || res.error || res.raw)) || fallback || '').trim();
    }
    function fetchJson(url,opts){
      return fetch(url,opts).then(function(r){
        return r.text().then(function(t){
          var data={http:r.status,raw:t};
          try{ data=JSON.parse(t); }catch(e){}
          if(data && typeof data==='object' && data.http===undefined){ data.http=r.status; }
          return data;
        });
      });
    }
    function legacyPeriodKey(period){
      var map={
        'monthly':'month_price',
        'quarterly':'quarter_price',
        'half_yearly':'half_year_price',
        'yearly':'year_price',
        'two_yearly':'two_year_price',
        'three_yearly':'three_year_price',
        'onetime':'onetime_price',
        'reset_traffic':'reset_price'
      };
      return map[String(period||'').toLowerCase()] || '';
    }
    function payOfferKey(planId,period){
      return String(planId||'')+'::'+String(period||'');
    }
    function getSelectedOffer(){
      var d=payState.info||{};
      var plans=d.plans||[];
      for(var i=0;i<plans.length;i++){
        var prices=plans[i].prices||[];
        for(var j=0;j<prices.length;j++){
          if(payOfferKey(plans[i].id,prices[j].period)===payState.selectedOfferKey){
            return {
              key: payState.selectedOfferKey,
              planId: plans[i].id,
              planName: plans[i].name,
              transferLimit: plans[i].transfer_limit,
              period: prices[j].period,
              periodLabel: prices[j].label,
              priceText: prices[j].price_text
            };
          }
        }
      }
      return null;
    }
    function getSelectedMethod(){
      for(var i=0;i<payState.methods.length;i++){
        if(String(payState.methods[i].id)===String(payState.selectedMethodId)) return payState.methods[i];
      }
      return null;
    }
    function renderPaySelection(){
      var offer=getSelectedOffer();
      var method=getSelectedMethod();
      if($payPlans){
        var offerNodes=$payPlans.querySelectorAll('[data-okw-offer]');
        for(var i=0;i<offerNodes.length;i++){
          if(String(offerNodes[i].getAttribute('data-okw-offer'))===String(payState.selectedOfferKey)) offerNodes[i].classList.add('okw-active');
          else offerNodes[i].classList.remove('okw-active');
        }
      }
      if($payMethods){
        var methodNodes=$payMethods.querySelectorAll('[data-okw-method]');
        for(var j=0;j<methodNodes.length;j++){
          if(String(methodNodes[j].getAttribute('data-okw-method'))===String(payState.selectedMethodId)) methodNodes[j].classList.add('okw-active');
          else methodNodes[j].classList.remove('okw-active');
        }
      }
      if($payNow){
        if(payState.busy){
          $payNow.disabled=true;
          $payNow.textContent='⏳ Открываем страницу оплаты…';
        }else if(offer && method){
          $payNow.disabled=false;
          $payNow.textContent='💳 Оплатить '+offer.priceText+' через '+String(method.name||method.payment||'способ оплаты');
        }else if(offer){
          $payNow.disabled=true;
          $payNow.textContent='💳 Выберите способ оплаты';
        }else{
          $payNow.disabled=true;
          $payNow.textContent='🛒 Выберите тариф и способ оплаты';
        }
      }
      if($paySummary){
        if(offer && method){
          $paySummary.style.display='block';
          $paySummary.innerHTML='<b>Выбрано:</b> '+payEsc(offer.planName)+' · '+payEsc(offer.periodLabel)+' · <b>'+payEsc(offer.priceText)+'</b><br>'
            +'Оплата: <b>'+payEsc(method.name||method.payment||'')+'</b>. После перехода на страницу оплаты завершите платёж и вернитесь в кабинет.';
        }else if(offer){
          $paySummary.style.display='block';
          $paySummary.innerHTML='<b>Вы выбрали тариф:</b> '+payEsc(offer.planName)+' · '+payEsc(offer.periodLabel)+' · <b>'+payEsc(offer.priceText)+'</b><br>Теперь выберите удобный способ оплаты.';
        }else{
          $paySummary.style.display='none';
          $paySummary.innerHTML='';
        }
      }
    }
    // Переход на внешнюю страницу оплаты БЕЗ заголовка Referer.
    //
    // ЮMoney Quickpay отклоняет сайты, не зарегистрированные у него как «магазин»,
    // и возвращает https://yoomoney.ru/transfer/quickpay/error?reason=referer
    // («На этом сайте переводы недоступны»). Причина — браузер отправляет Referer
    // с адресом нашей панели. Навигация по ссылке с rel/referrerpolicy="no-referrer"
    // этот заголовок не отправляет, поэтому платёж открывается штатно.
    function payGoNoRef(url){
      try{
        var a=document.createElement('a');
        a.href=url;
        a.rel='noreferrer noopener';
        a.setAttribute('referrerpolicy','no-referrer');
        a.style.display='none';
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){ try{ document.body.removeChild(a); }catch(e){} }, 0);
      }catch(e){
        window.location.href=url;
      }
    }
    function bindPayUi(){
      if($payMethods && !$payMethods.getAttribute('data-okw-bound')){
        $payMethods.setAttribute('data-okw-bound','1');
        $payMethods.addEventListener('click', function(e){
          var btn=e.target && e.target.closest ? e.target.closest('[data-okw-method]') : null;
          if(!btn || btn.disabled) return;
          payState.selectedMethodId=String(btn.getAttribute('data-okw-method')||'');
          renderPaySelection();
        });
      }
      if($payPlans && !$payPlans.getAttribute('data-okw-bound')){
        $payPlans.setAttribute('data-okw-bound','1');
        $payPlans.addEventListener('click', function(e){
          var btn=e.target && e.target.closest ? e.target.closest('[data-okw-offer]') : null;
          if(!btn || btn.disabled) return;
          payState.selectedOfferKey=String(btn.getAttribute('data-okw-offer')||'');
          renderPaySelection();
        });
      }
      if($payNow && !$payNow.getAttribute('data-okw-bound')){
        $payNow.setAttribute('data-okw-bound','1');
        $payNow.addEventListener('click', function(e){
          e.preventDefault();
          var offer=getSelectedOffer();
          var method=getSelectedMethod();
          if(!offer || !method || payState.busy){
            renderPaySelection();
            return;
          }
          var legacyPeriod=legacyPeriodKey(offer.period);
          if(!legacyPeriod){
            setBanner('❌ Для выбранного тарифа не удалось определить период оплаты. Обновите страницу и попробуйте снова.','err');
            return;
          }
          payState.busy=true;
          renderPaySelection();
          setStatus('💳 Переход к оплате');
          setBanner('<span class="okw-loader"></span>Создаём заказ на '+payEsc(offer.planName)+' · '+payEsc(offer.periodLabel)+'…', '');
          var headers=authHeaders({'Content-Type':'application/json'});
          fetchJson('/api/v1/user/order/save',{
            method:'POST',
            credentials:'same-origin',
            cache:'no-store',
            headers:headers,
            body:JSON.stringify({plan_id:offer.planId,period:legacyPeriod})
          }).then(function(saveRes){
            if(!saveRes || saveRes.status==='fail'){
              throw new Error(apiMessage(saveRes,'Не удалось создать заказ'));
            }
            var tradeNo=String(apiData(saveRes)||'').trim();
            if(!tradeNo){
              throw new Error('Сервер не вернул номер заказа');
            }
            return fetchJson('/api/v1/user/order/checkout',{
              method:'POST',
              credentials:'same-origin',
              cache:'no-store',
              headers:headers,
              body:JSON.stringify({trade_no:tradeNo,method:method.id})
            });
          }).then(function(payRes){
            if(payRes && payRes.status==='fail'){
              throw new Error(apiMessage(payRes,'Не удалось открыть оплату'));
            }
            // /api/v1/user/order/checkout отдаёт {type,data}. Но некоторые сборки
            // панели (и прокси перед ней) заворачивают ответ в {status,data:{type,data}}.
            // Без разворота ссылка на оплату терялась: кнопка «Оплатить» ничего не
            // открывала, а лишь переключала hash на «Заказы и оплата».
            if(payRes && payRes.type===undefined && payRes.data && typeof payRes.data==='object'
               && (payRes.data.type!==undefined || payRes.data.data!==undefined)){
              payRes=payRes.data;
            }
            var payType=parseInt(payRes && payRes.type!==undefined ? payRes.type : '',10);
            var payData=payRes && payRes.data!==undefined ? payRes.data : null;
            if(payType===-1 || payData===true){
              setBanner('✅ Заказ оплачен сразу. Обновляю вашу подписку…','ok');
              setTimeout(function(){ try{ loadWidget(false); }catch(e){} }, 1200);
              return;
            }
            var redirectUrl='';
            if(typeof payData==='string' && payData.trim()){ redirectUrl=payData.trim(); }
            else if(payData && typeof payData==='object'){
              redirectUrl=String(payData.url || payData.pay_url || payData.payment_url || payData.confirmation_url || payData.redirect_url || '').trim();
            }
            // Относительные ссылки (например /pay/xxx) приводим к абсолютным.
            if(redirectUrl && redirectUrl.charAt(0)==='/' && !/^\/\//.test(redirectUrl)){
              redirectUrl=window.location.origin+redirectUrl;
            }
            // Платёжные шлюзы (ЮKassa/ЮMoney) отдают абсолютный https-URL —
            // уводим пользователя прямо на страницу оплаты (без Referer, см. payGoNoRef).
            if(redirectUrl && /^https?:\/\//i.test(redirectUrl)){
              setBanner('✅ Заказ создан. Перенаправляю на страницу оплаты…','ok');
              setTimeout(function(){ payGoNoRef(redirectUrl); }, 320);
              return;
            }
            if(redirectUrl){
              setBanner('✅ Заказ создан. Перенаправляю на страницу оплаты…','ok');
              setTimeout(function(){ payGoNoRef(redirectUrl); }, 320);
              return;
            }
            location.hash='#/order';
            setBanner('✅ Заказ создан. Откройте раздел «Заказы и оплата», чтобы завершить оплату.'
              +'<br><small>Возникла проблема с оплатой или ключом? <a href="#/ticket" style="color:#ffd166;font-weight:800">🆘 Написать в поддержку</a>.</small>','ok');
          }).catch(function(err){
            var msg=(err && err.message ? err.message : String(err||'')).trim() || 'Неизвестная ошибка';
            setStatus('⚠️ Нужна оплата');
            setBanner('❌ '+payEsc(msg)+'<br><small>Если у вас уже есть неоплаченный заказ, откройте раздел «Заказы и оплата» и завершите его там.'
              +'<br>Нужна помощь? <a href="#/ticket" style="color:#ffd166;font-weight:800">🆘 Написать в поддержку</a>.</small>','err');
          }).finally(function(){
            payState.busy=false;
            renderPaySelection();
          });
        });
      }
    }
    function loadPayInfo(){
      if(payLoaded) return;
      payLoaded=true;
      try{
        // getPaymentMethod — НЕобязательный источник: его API требует живую
        // авторизацию и может не ответить. Если он упадёт, берём способы оплаты
        // из публичной витрины pay-info. Без .catch() здесь падал весь
        // Promise.all и блок оплаты не появлялся вообще.
        Promise.all([
          fetchJson('/api/olcrtc/pay-info',{credentials:'same-origin',cache:'no-store',headers:authHeaders()}),
          fetchJson('/api/v1/user/order/getPaymentMethod',{credentials:'same-origin',cache:'no-store',headers:authHeaders()})
            .catch(function(){ return null; })
        ]).then(function(responses){
          var infoRes=responses[0], methodsRes=responses[1];
          var d=(infoRes && infoRes.methods) ? infoRes : apiData(infoRes);
          if(!d || !$payBox) return;
          var plans=(d.plans||[]);
          var methods=(methodsRes && methodsRes.status==='success') ? (apiData(methodsRes)||[]) : [];
          if(!methods.length && d.methods && d.methods.length){
            methods=d.methods.map(function(m,idx){
              return {
                id:m.id,
                name:m.name||m.code||'Оплата',
                payment:m.code||'',
                icon:m.icon||'💳',
                handling_fee_fixed:m.handling_fee_fixed||0,
                handling_fee_percent:m.handling_fee_percent||0
              };
            });
          }
          if(!methods.length && !plans.length) return;
          payState.info=d;
          payState.methods=methods;
          var methodIconMap={};
          for(var mi=0;mi<(d.methods||[]).length;mi++){
            var mm=d.methods[mi];
            methodIconMap[String(mm.code||'')]=String(mm.icon||'💳');
          }
          var mHtml='';
          for(var i=0;i<methods.length;i++){
            var m=methods[i], code=String(m.payment||m.code||''), icon=String(m.icon||methodIconMap[code]||'💳');
            mHtml+='<button type="button" class="okw-pay-m" data-okw-method="'+payEsc(m.id)+'">'+payEsc(icon)+' '+payEsc(m.name||code||'Способ оплаты')+'</button>';
          }
          var pHtml='';
          for(var j=0;j<plans.length;j++){
            var p=plans[j], prices=p.prices||[], prHtml='';
            for(var k=0;k<prices.length;k++){
              var pr=prices[k], offerKey=payOfferKey(p.id,pr.period);
              prHtml+='<button type="button" class="okw-price" data-okw-offer="'+payEsc(offerKey)+'">'
                + '<b>'+payEsc(pr.price_text||'')+'</b><span>'+payEsc(pr.label||pr.period||'')+'</span></button>';
            }
            pHtml+='<div class="okw-plan">'
              + '<div class="okw-plan-head">'
              +   '<span class="okw-plan-name">'+payEsc(p.name||'Тариф')+'</span>'
              +   '<span class="okw-plan-limit">Трафик: '+payEsc(p.transfer_limit||'—')+'</span>'
              + '</div>'
              + '<div class="okw-plan-prices">'+prHtml+'</div>'
              + '</div>';
          }
          if($payMethods){ $payMethods.innerHTML = mHtml || '<span class="okw-pay-m">💳 Способы оплаты пока не настроены</span>'; }
          if($payPlans){ $payPlans.innerHTML = pHtml; }
          if($payCta){ $payCta.href = String(d.plan_url||'#/plan'); }
          if(!payState.selectedOfferKey && plans.length && plans[0].prices && plans[0].prices.length){
            payState.selectedOfferKey=payOfferKey(plans[0].id,plans[0].prices[0].period);
          }
          if(!payState.selectedMethodId && methods.length){
            payState.selectedMethodId=String(methods[0].id);
          }
          bindPayUi();
          renderPaySelection();
          $payBox.style.display='block';
        }).catch(function(){
          // Витрина оплаты временно недоступна — разрешаем повторную попытку
          // при следующем обновлении карточки (иначе блок оплаты пропал бы навсегда).
          payLoaded=false;
        });
      }catch(e){ payLoaded=false; }
    }

    function loadWidget(firstLoad){
      var isLk=isLkDashboard();
      // Ключ мог измениться (новая покупка / пересоздание) — сбрасываем QR-код,
      // чтобы пользователь не отсканировал устаревший ключ.
      try{
        if($qrRow) $qrRow.style.display='none';
        if($qrImg && $qrImg.getAttribute('src')) $qrImg.removeAttribute('src');
        if($qrBtn) $qrBtn.textContent='📱 Показать QR-код';
      }catch(eQr){}
      // Initial banner during load
      setBanner('<span class="okw-loader"></span>'+(isLk?'Загружаем OlcRTC-ключ из кабинета (ЛК)…':'Загружаем статус авторизации…'), '');
      setStatus('Загрузка…');
      var headers=authHeaders();
      fetch(apiPath,{credentials:'same-origin',cache:'no-store',headers:headers}).then(function(r){
        var ct=r.headers.get('Content-Type')||'';
        if(ct.indexOf('application/json')>=0) return r.text().then(function(t){ try{ return JSON.parse(t); }catch(e){ return {raw:t,http:r.status}; } });
        return r.text().then(function(t){ return {raw:t,http:r.status}; });
      }).then(function(d){
        var data=d;
        for(var i=0;i<2;i++){if(data && data.data && typeof data.data==='object' && (data.data.uri || data.data.banner || data.data.client_downloads || data.data.status==='guest')){data=data.data;}else{break;}}
        if(data && data.status==='guest'){
          hideWidget();
          exitLk();
          // If user appears to be in ЛК area but JSON says guest → SPA login just happened, cookies haven't propagated yet.
          // Retry up to 5 times with 1500ms pauses — total ~7.5s. After page reload, cookie will be fresh anyway.
          if(isLk && retriesScheduled < 5){
            retriesScheduled++;
            setTimeout(function(){ loadWidget(false); }, 1500 * retriesScheduled);
          }
          return;
        }
        ok=true;
        // Сервер ответил НЕ JSON (устаревший образ панели → Caddy отдаёт страницу
        // SPA, либо 500-заглушка). Раньше в этом случае баннер навсегда замирал
        // на «Загружаем…», а ключ и ссылка просто не появлялись без объяснений.
        if(data && data.raw!==undefined && data.status===undefined){
          showWidget();
          mountWidgetForRoute();
          scheduleWidgetMountRetry();
          setStatus('❌ Сервер вернул не JSON');
          setBanner('❌ Раздел ключа недоступен: эндпоинт <code>/api/olcrtc/widget</code> вернул не JSON (HTTP ' + (data.http||'?') + ').<br><small style="opacity:.85">Обычно это значит, что панель не пересобрана после обновления. Выполните на сервере:<br><code>docker compose build xboard &amp;&amp; docker compose up -d &amp;&amp; docker compose exec xboard php artisan optimize:clear</code></small>','err');
          if($btns) $btns.style.display='flex';
          return;
        }
        showWidget();
        mountWidgetForRoute();
        scheduleWidgetMountRetry();
        renderMeta(data);
        hideLegacyQuickSub();
        loadPayInfo();
        if($buyA){ $buyA.href=String(data.purchase_url||'#/plan'); }
        if($ordersA){ $ordersA.href=String(data.orders_url||'#/order'); }
        if($ctaRow){ $ctaRow.style.display='flex'; }
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
          if($steps) $steps.style.display='block';
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
          if($steps) $steps.style.display='block'; // вкладка «Инструкция» всегда наполнена

          // =========================================================
          // RC7.4 ISSUE 3 FIX: admin руками подтвердил заказ в админке
          // → instance НЕ создан (webhook не сработал).
          // → ЕСЛИ user план ACTIVE но uri empty, retries >0 → AUTO-CALL
          //   POST /api/olcrtc/recreate чтобы Manager создал инстанс.
          // =========================================================
          try {
            var planActiveHint = String(data.plan_active || data.has_active_plan || data.status || '').toLowerCase();
            var looksActive =
              planActiveHint.indexOf('active') >= 0 ||
              planActiveHint.indexOf('paid') >= 0 ||
              planActiveHint.indexOf('success') >= 0 ||
              (data.expired_at && String(data.expired_at).length > 3) ||
              (data.plan_id && parseInt(String(data.plan_id), 10) > 0);
            if(retriesScheduled >= 1 && looksActive && window.fetch){
              try{
                var headers=authHeaders({'Content-Type':'application/json'});
                fetch('/api/olcrtc/recreate',{method:'POST',credentials:'same-origin',cache:'no-store',headers:headers,body:JSON.stringify({_token:((window.Laravel||{}).csrfToken||'')})})
                  .then(function(r){ return r.json().catch(function(){return {http:r.status};}); })
                  .then(function(rd){
                    if(rd && (rd.status==='success' || rd.success)){ setBanner((rd.message?rd.message:'✅ OlcRTC инстанс создан! Ждите ключ 3–6 секунд…')+'<br><small>Перезапрашиваю через 2 секунды…</small>','ok'); }
                    else if(rd && rd.message){ setBanner(rd.message, (rd.http && rd.http===409)?'':'err'); }
                    setTimeout(function(){ retriesScheduled=0; loadWidget(false); }, 2200);
                  }).catch(function(){});
                return;
              }catch(eRec){}
            }
          }catch(eAuto){}

          // If instance not created yet but user logged in → reschedule a single retry 2s later
          if(retriesScheduled < 2){ retriesScheduled++; setTimeout(function(){ loadWidget(false); }, 2000); }
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
    if($copySub) $copySub.addEventListener('click', function(e){
      e.preventDefault();
      var v=String(($subA && $subA.getAttribute('href'))||'').trim();
      if(!v || v==='#'){ alert('⚠️ Ссылка-подписка ещё не готова. Подождите 5–10 секунд и нажмите «🔄 Пересоздать ключ».'); return; }
      copyText(v, function(){
        var o=$copySub.textContent; $copySub.textContent='✅ Ссылка скопирована!';
        setTimeout(function(){ $copySub.textContent=o; }, 3200);
      }, function(txt){
        alert('Скопируйте ссылку вручную:\n'+txt);
      });
    });
    if($refresh) $refresh.addEventListener('click', function(e){ e.preventDefault(); retriesScheduled=0; loadWidget(false); });
    if($yaml) $yaml.addEventListener('click', function(e){ e.preventDefault(); var url=$yaml.getAttribute('data-url'); if(url) window.open(url,'olcrtcyaml','noopener,noreferrer'); else alert('Ссылка на yaml ещё не получена, попробуйте через 10 секунд.'); });
    // QR-код строится на сервере из ТОГО ЖЕ ключа (GET /api/v1/user/olcrtc/qr).
    // Его нельзя открыть в новой вкладке (нужен Bearer), поэтому забираем SVG
    // запросом с токеном и вставляем как data-URI прямо в карточку.
    if($qrBtn) $qrBtn.addEventListener('click', function(e){
      e.preventDefault();
      var already=$qrRow && $qrRow.style.display!=='none';
      if(already){
        $qrRow.style.display='none';
        $qrBtn.textContent='📱 Показать QR-код';
        return;
      }
      if(!$qrImg) return;
      var hasKey=$ta && String($ta.value||'').trim()!=='';
      if(!hasKey){ alert('⚠️ Сначала получите ключ OlcRTC — QR-код строится из вашего ключа.'); return; }
      if($qrImg.getAttribute('src')){
        $qrRow.style.display='flex';
        $qrBtn.textContent='🙈 Скрыть QR-код';
        return;
      }
      var old=$qrBtn.textContent; $qrBtn.textContent='⏳ Готовим QR…';
      fetch('/api/v1/user/olcrtc/qr',{credentials:'same-origin',cache:'no-store',headers:authHeaders()})
        .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
        .then(function(svg){
          var t=String(svg||'');
          if(t.indexOf('<svg')<0) throw new Error('сервер вернул не QR-код');
          $qrImg.setAttribute('src','data:image/svg+xml;base64,'+btoa(unescape(encodeURIComponent(t))));
          $qrRow.style.display='flex';
          $qrBtn.textContent='🙈 Скрыть QR-код';
        })
        .catch(function(err){
          $qrBtn.textContent=old;
          alert('❌ Не удалось получить QR-код: '+(err&&err.message?err.message:String(err))+'\nНажмите «🔄 Пересоздать ключ» и попробуйте снова.');
        });
    });
    if($trialBtn) $trialBtn.addEventListener('click', function(e){ e.preventDefault(); claimTrialOnClick(e); });

    // Initial load
    function initOnce(){ try{ bindTabs(); }catch(e){} if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){ bindTabs(); loadWidget(true); try{ fetchTrialStatus().then(renderTrialButton); }catch(e){} }, {once:true}); else { loadWidget(true); try{ fetchTrialStatus().then(renderTrialButton); }catch(e){} } }
    initOnce();
    watchLegacyQuickSub();
    scheduleWidgetMountRetry();

    // Re-run on SPA hash change (very common: login AJAX → hash "#/user" dashboard → re-fetch widget with fresh cookie session)
    window.addEventListener('hashchange', function(){
      retriesScheduled=0;
      if(!isLkDashboard()) exitLk();
      else enterLk();
      scheduleWidgetMountRetry();
      try{ loadWidget(false); }catch(e){}
      try{ fetchTrialStatus().then(renderTrialButton); }catch(e){}
    }, false);

    // Перепроверка сессии. SPA может разлогинить пользователя БЕЗ смены hash
    // (выход в этом же табе) — тогда hashchange не сработает, и карточка с ключом
    // осталась бы «висеть» на экране главной. Пока карточка показана, раз в 20 сек
    // и при возврате фокуса/вкладки сверяем статус и убираем её, если мы снова гость.
    var lastSessionCheck=0;
    function recheckSession(){
      var now=Date.now();
      if(now-lastSessionCheck<8000) return;
      lastSessionCheck=now;
      try{ loadWidget(false); }catch(e){}
    }
    window.addEventListener('focus', function(){ recheckSession(); });
    document.addEventListener('visibilitychange', function(){ if(!document.hidden) recheckSession(); });
    setInterval(function(){ if(ok && !document.hidden) recheckSession(); }, 20000);

    // Fallback background retries during first 30s — catches the common race where
    // Umi mounts, AJAX login succeeds, cookie is set ~2s after widget's initial loadWidget() ran.
    [1500, 3500, 7000, 14000, 22000].forEach(function(delay){
      setTimeout(function(){
        if(!ok && (isLkDashboard() || (location.hash||'').match(/^#\/(user|admin)/i))){
          try{ loadWidget(false); }catch(e){}
        }
        if(!trialOk && (isLkDashboard() || (location.hash||'').match(/^#\/(user|admin)/i))){
          try{ fetchTrialStatus().then(renderTrialButton); }catch(e){}
        }
      }, delay);
    });
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

  // ================================================================
  // RC7.4 FIX: Force AntD Login/Register/Tariff card buttons and
  // containers to MATRIX-GREEN GRADIENT even when Umi React sets
  // inline style (background: #1677ff etc.). Watches DOM mutations.
  // ================================================================
  (function(){
    var GRADIENT='linear-gradient(135deg,#00ff9c,#00e5ff)';
    var BTN_COLOR='#00110a';
    function widgetInjector(){
      try{
        var wOriginal=document.getElementById('olcrtc-key-widget');
        if(!wOriginal) return;
        var h=(location.hash||'');
        var isSpaLk=h && !/login|register|forgot/i.test(h) && /#\/(dashboard|subscription|user|admin|passport|knowledge|plan|order|ticket|traffic|node|invite)/i.test(h);
        if(!isSpaLk) return;
        var target=null;
        var cards=document.querySelectorAll('#app .ant-card, #app [class*=ProCard], #app section[class*=ant-card], #app [class*=subscription], #app [class*=GridContent] > [class*=ant-col] > div, #app [class*=PageContainer] [class*=children] > [class*=ant-card]');
        for(var i=0;i<cards.length;i++){
          var txt=(cards[i].innerText||cards[i].textContent||'').substring(0,320);
          if(txt.indexOf('Моя подписка')>=0 || (txt.indexOf('Подписка')>=0 && txt.indexOf('Купить')>=0) || (txt.indexOf('Купить подписку')>=0 && /dashboard/i.test(h))){
            target=cards[i].querySelector('.ant-card-body, [class*=card-body]') || cards[i];
            break;
          }
        }
        if(!target && /dashboard|subscription|plan/i.test(h)){ target=document.querySelector('#app .ant-pro-grid-content, #app [class*=GridContent], #app [class*=PageContainer] [class*=children]'); }
        if(!target && /^#\/(user|admin)/i.test(h)){ target=document.querySelector('#app .ant-pro-page-container, #app [class*=PageContainer]'); }
        if(!target) return;
        if(target.querySelector('#olcrtc-key-widget') || target.querySelector('#olcrtc-spa-widget-wrapper')) return;
        var wrap=document.createElement('div');
        wrap.setAttribute('id','olcrtc-spa-widget-wrapper');
        wrap.style.cssText='margin:0 0 18px 0;position:relative;z-index:45';
        wOriginal.setAttribute('data-moved-to-spa','1');
        wrap.appendChild(wOriginal);
        target.insertBefore(wrap, target.firstChild);
      }catch(eInj){}
    }
    function applyRecolour(root){
      if(!root) return;
      try{
        widgetInjector();
        if(root.querySelectorAll){
          var btns=root.querySelectorAll('button.ant-btn-primary, [class*=ant-btn-primary], button[type=submit].ant-btn, .ant-modal button.ant-btn-primary, [class*=Login] button.ant-btn-primary, [class*=Register] button.ant-btn-primary, [class*=PlanCard] button.ant-btn-primary, #app button.ant-btn:not(.ant-btn-default)');
          for(var i=0;i<btns.length;i++){ var b=btns[i];
            b.style.setProperty('background-image', GRADIENT, 'important');
            b.style.setProperty('background',       GRADIENT, 'important');
            b.style.setProperty('background-color', '#00ff9c', 'important');
            b.style.setProperty('color',            BTN_COLOR, 'important');
            b.style.setProperty('border','0 !important');
            b.style.setProperty('font-weight','800','important');
            b.style.setProperty('padding','12px 18px','important');
            b.style.setProperty('font-size','15px','important');
            b.style.setProperty('box-shadow','0 6px 20px rgba(0,255,156,.28)','important');
            b.style.setProperty('border-radius','10px','important');
          }
          var cards=root.querySelectorAll('#app .ant-card, #app [class*=PlanCard], #app [class*=Login] .ant-card, #app [class*=Register] .ant-card, #app .ant-modal-content, #app [class*=Shortcut], #app [class*=Quick]');
          for(var j=0;j<cards.length;j++){ var c=cards[j];
            c.style.setProperty('background','#08150e','important');
            c.style.setProperty('background-color','#08150e','important');
            c.style.setProperty('color','#c9ffd9','important');
            c.style.setProperty('border','1px solid rgba(0,255,156,.22)','important');
            c.style.setProperty('border-radius','14px','important');
            c.style.setProperty('box-shadow','0 6px 24px rgba(0,0,0,.45)','important');
          }
          var cwrappers=root.querySelectorAll('#app .ant-pro-page-container, #app [class*=PageContainer], #app .ant-pro-grid-content, #app [class*=GridContent], #app [class*=children-container], #app .ant-layout-content > div, #app .ant-page-header');
          for(var cw=0;cw<cwrappers.length;cw++){ var w=cwrappers[cw];
            w.style.setProperty('background','#05110a','important');
            w.style.setProperty('background-color','#05110a','important');
            w.style.setProperty('color','#c9ffd9','important');
            w.style.setProperty('border-radius','14px','important');
          }
          var listItems=root.querySelectorAll('#app .ant-list-item, #app [class*=knowledge] [class*=item], #app [class*=ant-list-items] > div, #app .ant-empty');
          for(var li=0;li<listItems.length;li++){ var liEl=listItems[li];
            liEl.style.setProperty('background','#08150e','important');
            liEl.style.setProperty('background-color','#08150e','important');
            liEl.style.setProperty('border','1px solid rgba(0,255,156,.18)','important');
            liEl.style.setProperty('color','#c9ffd9','important');
            liEl.style.setProperty('margin','6px 0','important');
            liEl.style.setProperty('padding','12px 14px','important');
            liEl.style.setProperty('border-radius','10px','important');
          }
          var inputs=root.querySelectorAll('#app .ant-input, #app input.ant-input, #app textarea.ant-input, #app input[type=email], #app input[type=password], #app input[type=text], #app .ant-select-selector, #app .ant-picker');
          for(var k=0;k<inputs.length;k++){ var inp=inputs[k];
            inp.style.setProperty('background','#00120a','important');
            inp.style.setProperty('color','#e8ffef','important');
            inp.style.setProperty('border-color','#00ff9c55','important');
            inp.style.setProperty('border-radius','10px','important');
            inp.style.setProperty('font-size','15px','important');
          }
          var labels=root.querySelectorAll('#app label, #app .ant-form-item-label label, #app h1, #app h2, #app h3, #app h4, #app .ant-typography');
          for(var m=0;m<labels.length;m++){ var l=labels[m]; l.style.setProperty('color','#e8ffef','important'); }
          var ps=root.querySelectorAll('#app p, #app span, #app div.ant-card-body, #app div.ant-card-meta-description, #app td, #app li');
          for(var n=0;n<ps.length;n++){ var pp=ps[n]; pp.style.setProperty('color','#c9ffd9','important'); pp.style.setProperty('font-size','15px','important'); pp.style.setProperty('line-height','1.6','important'); }
          var aLinks=root.querySelectorAll('#app a, #app .ant-typography a');
          for(var al=0;al<aLinks.length;al++){
            aLinks[al].style.setProperty('color','#00ff9c','important');
            aLinks[al].style.setProperty('text-decoration','none','important');
            aLinks[al].style.setProperty('font-weight','600','important');
          }
          var menus=root.querySelectorAll('#app .ant-layout-sider, #app aside[class*=ant], #app .ant-menu, #app .ant-menu-dark, #app .ant-menu-sub, #app [class*=Sider] [class*=Menu]');
          for(var mn=0;mn<menus.length;mn++){ var mm=menus[mn]; mm.style.setProperty('background','#041008','important'); mm.style.setProperty('color','#c9ffd9','important'); if(mm.className && mm.className.indexOf && mm.className.indexOf('ant-menu')>=0){ mm.style.setProperty('border-right','1px solid rgba(0,255,156,.22)','important'); } }
          var iconSvg=root.querySelectorAll('#app svg, #app svg *[fill], #app svg path, #app svg polygon, #app svg rect, #app svg circle, #app svg line');
          for(var sg=0;sg<iconSvg.length;sg++){ var svgEl=iconSvg[sg]; var curFill=(svgEl.getAttribute('fill')||'').toLowerCase(); if(!curFill || curFill==='none' || curFill==='currentcolor' || curFill==='#000' || curFill==='#000000' || curFill==='black'){ svgEl.setAttribute('fill','#ffffff'); svgEl.style.setProperty('fill','#ffffff','important'); } svgEl.style.setProperty('color','#c9ffd9','important'); }
          var iconCls=root.querySelectorAll('#app .anticon, #app [class*=ant-] [class*=icon], #app [class*=Icon], #app [class*=Shortcut] > div:first-child, #app [class*=Quick] > div:first-child, #app [class*=shortcutAction]');
          for(var ic=0;ic<iconCls.length;ic++){ iconCls[ic].style.setProperty('color','#c9ffd9','important'); iconCls[ic].style.setProperty('fill','#ffffff','important'); }
          var cardHead=root.querySelectorAll('#app .ant-card-head, #app .ant-card-head-title, #app .ant-card-extra, #app [class*=ProCard] [class*=title], #app [class*=PageHeader] [class*=title]');
          for(var ch=0;ch<cardHead.length;ch++){ cardHead[ch].style.setProperty('color','#e8ffef','important'); cardHead[ch].style.setProperty('background','#0a1a10','important'); }
          var listMeta=root.querySelectorAll('#app .ant-list-item-meta-title, #app .ant-list-item-meta-description, #app [class*=Statistic] [class*=title], #app [class*=Statistic] [class*=content], #app [class*=Statistic] [class*=value]');
          for(var lm=0;lm<listMeta.length;lm++){ listMeta[lm].style.setProperty('color','#e8ffef','important'); listMeta[lm].style.setProperty('font-size','15px','important'); }
        }
      }catch(eMo){}
    }
    if(typeof MutationObserver !== 'undefined'){
      var mo=new MutationObserver(function(muts){
        for(var i=0;i<muts.length;i++){ var m=muts[i];
          if(m.addedNodes && m.addedNodes.length){
            for(var j=0;j<m.addedNodes.length;j++){
              applyRecolour(m.addedNodes[j]);
            }
          }
          if(m.target && m.target.nodeType===1){ applyRecolour(m.target); }
        }
      });
      function startMo(){
        try{
          applyRecolour(document.documentElement);
          mo.observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['style','class']});
          // Re-baseline every 2.5s in case Umi React renders after observer attach
          setInterval(function(){ applyRecolour(document.documentElement); }, 2500);
        }catch(e){}
      }
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',startMo,{once:true}); else startMo();
    } else {
      // Fallback (no MutationObserver): poll every 1.5s
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){setInterval(function(){applyRecolour(document.documentElement);},1500);},{once:true});
      else setInterval(function(){applyRecolour(document.documentElement);},1500);
    }
  })();

  try{ var y=document.getElementById('mv-y'); if(y) y.textContent=new Date().getFullYear(); }catch(e){}

  function goHash(h){ try{ window.location.hash=h; }catch(e){ window.location.href=(window.location.pathname||'/')+h; } }
  var btnReg=document.getElementById('mv-cta-reg'), btnLogin=document.getElementById('mv-cta-login'), btnDocs=document.getElementById('mv-cta-docs');
  if(btnReg) btnReg.addEventListener('click',function(){ goHash('#/user/register'); });
  if(btnLogin) btnLogin.addEventListener('click',function(){ goHash('#/user/login'); });
  if(btnDocs) btnDocs.addEventListener('click',function(){ goHash('#/knowledge'); });

  // Единое меню кабинета (#okw-nav): подсвечиваем активный раздел по текущему
  // hash, чтобы пользователь всегда видел, где он находится (и на ПК, и на телефоне).
  (function(){
    var items=document.querySelectorAll('#okw-nav .okw-nav-item');
    if(!items || !items.length) return;
    function isActive(key,h){
      if(key==='dashboard') return h===''||h==='#'||h==='#/'||h.indexOf('#/dashboard')===0;
      return h.indexOf('#/'+key)===0;
    }
    function syncNav(){
      var h=(location.hash||'').toLowerCase();
      for(var i=0;i<items.length;i++){
        var a=items[i];
        var key=(a.getAttribute('data-okw-nav')||'').toLowerCase();
        if(isActive(key,h)) a.classList.add('okw-nav-active'); else a.classList.remove('okw-nav-active');
      }
    }
    syncNav();
    window.addEventListener('hashchange', syncNav, false);
    window.addEventListener('popstate', syncNav, false);
    document.addEventListener('click', function(){ setTimeout(syncNav, 60); }, true);
  })();

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
      // On these routes keep TOPBAR only (matrix bg + logo + online pill).
      // The Umi SPA uses "#/login" / "#/register" (older builds used "#/user/login").
      return /^#\/(?:user\/)?(login|register|forgot|passwordReset)/i.test(h || '');
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
      function unlockScroll(){
        try{
          document.documentElement.classList.add('mv-unlocked');
          document.body.classList.add('mv-unlocked');
          document.documentElement.style.overflowY='auto';
          document.documentElement.style.overflowX='hidden';
          document.body.style.overflowY='auto';
          document.body.style.overflowX='hidden';
          document.body.style.position='static';
          document.documentElement.style.position='static';
          document.body.style.height='auto';
          document.documentElement.style.height='auto';
          document.body.style.minHeight='100vh';
          document.documentElement.style.minHeight='100vh';
          document.body.style.top='';
          document.body.style.left='';
          document.body.style.right='';
          document.body.style.bottom='';
        }catch(e){}
      }
      unlockScroll();
      if(root){
        if(topbar) topbar.style.display='';
        if(hero) hero.style.display='';
        if(bottom) bottom.style.display='';
        if(nosc) nosc.style.display='';
        document.documentElement.classList.add('mv-landing-mode');
        document.documentElement.classList.remove('mv-auth-mode');
        document.documentElement.classList.remove('mv-spa-only-mode');
        window.scrollTo({top:0,behavior:'instant' in window ? 'instant':'auto'});
      } else if(auth){
        if(topbar) topbar.style.display='';
        if(hero) hero.style.display='none';
        if(bottom) bottom.style.display='none';
        if(nosc) nosc.style.display='none';
        document.documentElement.classList.add('mv-auth-mode');
        document.documentElement.classList.add('mv-landing-mode');
        document.documentElement.classList.remove('mv-spa-only-mode');
        unlockScroll();
        setTimeout(function(){
          unlockScroll();
          try{ window.scrollTo({top:0,behavior:'auto'}); }catch(e){}
          try{
            var mc=document.querySelector('.ant-modal-centered, .ant-modal-wrap, [class*=Modal], [class*=modal]');
            if(mc){ mc.style.top='auto'; mc.style.margin='24px auto 24px auto'; mc.style.position='relative'; mc.style.padding='28px 0'; }
          }catch(e){}
        }, 180);
      } else {
        if(topbar) topbar.style.display='none';
        if(hero) hero.style.display='none';
        if(bottom) bottom.style.display='none';
        if(nosc) nosc.style.display='none';
        document.documentElement.classList.add('mv-spa-only-mode');
        document.documentElement.classList.remove('mv-auth-mode');
        document.documentElement.classList.remove('mv-landing-mode');
        unlockScroll();
      }
      setTimeout(unlockScroll, 250);
      setTimeout(unlockScroll, 900);
      setTimeout(unlockScroll, 2200);
    }
    try{ apply(location.hash || ''); }catch(e){}
    window.addEventListener('hashchange', function(){ try{ apply(location.hash || ''); }catch(e){} }, false);
    window.addEventListener('popstate', function(){ try{ apply(location.hash || location.pathname); }catch(e){} }, false);
    /* scroll-unlock watchdog: every 800ms check AntD hasn't re-locked html/body overflow:hidden */
    (function(){
      function force(){
        try{
          var de=document.documentElement,b=document.body;
          if(/hidden|none|scroll|clip/.test(de.style.overflow+de.style.overflowY) && !/scrollable-y-lock/.test(b.className+'')){
            de.style.overflowY='auto'; de.style.overflowX='hidden';
          }
          if(/hidden|none|clip/.test(b.style.overflow+b.style.overflowY)){
            b.style.overflowY='auto'; b.style.overflowX='hidden';
          }
          if(/fixed|absolute|sticky/.test(b.style.position)){ b.style.position='static'; }
          if(/^\d+(px|%)$/.test(b.style.top)){ b.style.top=''; b.style.left=''; b.style.right=''; }
          if(!de.classList.contains('mv-unlocked')) de.classList.add('mv-unlocked');
          if(!b.classList.contains('mv-unlocked')) b.classList.add('mv-unlocked');
        }catch(e){}
      }
      force();
      setInterval(force, 800);
      ['click','focus','focusin','transitionend','animationend','visibilitychange','scroll'].forEach(function(ev){
        document.addEventListener(ev, function(){ setTimeout(force, 50); }, true);
      });
    })();
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
