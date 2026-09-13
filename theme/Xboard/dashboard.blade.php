<!doctype html>
<html lang="zh-CN">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no" />
  <title>{{$title}}</title>
  <script type="module" crossorigin src="/theme/{{$theme}}/assets/umi.js"></script>
</head>

<body>

  <script>
    window.routerBase = "/";
    window.settings = {
      title: '{{$title}}',
      assets_path: '/theme/{{$theme}}/assets',
      theme: {
        color: '{{ $theme_config['theme_color'] ?? "default" }}',
      },
      version: '{{$version}}',
      background_url: '{{$theme_config['background_url']}}',
      description: '{{$description}}',
      i18n: [
        'zh-CN',
        'en-US',
        'ja-JP',
        'vi-VN',
        'ko-KR',
        'zh-TW',
        'fa-IR'
      ],
      logo: '{{$logo}}'
    }
  </script>
  <div id="app"></div>
  <style id="olcrtc-widget-style">
    #olcrtc-key-widget{position:relative;max-width:820px;margin:18px auto;font-family:Arial,Helvetica,"Segoe UI",system-ui,sans-serif;border-radius:14px;box-shadow:0 6px 30px rgba(0,0,0,.10);overflow:hidden;border:1px solid rgba(255,255,255,.5)}
    #olcrtc-key-widget .okw-head{padding:14px 18px;font-weight:700;font-size:17px;line-height:1.45;color:#fff;background:linear-gradient(135deg,#7c3aed 0%,#2563eb 60%,#0ea5e9 100%)}
    #olcrtc-key-widget .okw-body{background:#fff;padding:14px 18px 18px;color:#111}
    #olcrtc-key-widget .okw-banner{background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:10px;padding:10px 12px;font-size:14px;margin:0 0 12px}
    #olcrtc-key-widget .okw-banner.ok{background:#ecfdf5;color:#065f46;border-color:#a7f3d0}
    #olcrtc-key-widget .okw-banner.err{background:#fef2f2;color:#991b1b;border-color:#fecaca}
    #olcrtc-key-widget .okw-uri{display:flex;gap:8px;align-items:center;margin:8px 0 10px}
    #olcrtc-key-widget textarea.okw-uri-ta{flex:1;border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:13px;min-height:84px;line-height:1.45;word-break:break-all;resize:vertical;background:#f8fafc;color:#0f172a;font-family:ui-monospace,Menlo,Consolas,monospace;box-sizing:border-box;width:100%}
    #olcrtc-key-widget .okw-btns{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0 2px}
    #olcrtc-key-widget button.okw-btn{appearance:none;border:0;border-radius:10px;padding:10px 14px;font-weight:700;font-size:14px;cursor:pointer;transition:.12s transform, .12s box-shadow, .12s opacity;box-shadow:0 2px 0 rgba(0,0,0,.06)}
    #olcrtc-key-widget button.okw-btn:active{transform:translateY(1px)}
    #olcrtc-key-widget button.okw-primary{background:linear-gradient(135deg,#2563eb,#0ea5e9);color:#fff}
    #olcrtc-key-widget button.okw-ghost{background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1}
    #olcrtc-key-widget button.okw-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
    #olcrtc-key-widget .okw-hint{font-size:13px;line-height:1.55;color:#334155;margin:10px 0 0;padding:8px 10px;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px}
    #olcrtc-key-widget .okw-clients{margin-top:12px;border-top:1px solid #e2e8f0;padding-top:10px}
    #olcrtc-key-widget .okw-clients h4{margin:0 0 8px;font-size:14px;color:#0f172a}
    #olcrtc-key-widget .okw-clients ul{margin:0;padding-left:18px;font-size:13px;line-height:1.6;color:#334155}
    #olcrtc-key-widget .okw-clients a{color:#2563eb;text-decoration:underline}
    #olcrtc-key-widget .okw-status{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;margin-left:8px;background:rgba(255,255,255,.22);vertical-align:middle}
    #olcrtc-key-widget .okw-loader{display:inline-block;width:18px;height:18px;border:3px solid #e2e8f0;border-top-color:#2563eb;border-radius:50%;vertical-align:-4px;animation:okw-spin 1s linear infinite;margin-right:8px}
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
        <button type="button" class="okw-btn okw-primary" id="okw-copy-btn">📋 СКОПИРОВАТЬ КЛЮЧ OlcRTC (одноразово)</button>
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

      // autorun when DOM ready — the user MUST be logged in on dashboard SPA load
      if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){ loadWidget(true); });
      else loadWidget(true);
    }catch(e){ console.error('[olcrtc-widget init fail]', e); }
  })();
  </script>
  {!! $theme_config['custom_html'] !!}
</body>

</html>