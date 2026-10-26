<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
    <script src="/assets/admin/locales/en-US.js"></script>
    <script src="/assets/admin/locales/zh-CN.js"></script>
    <script src="/assets/admin/locales/ko-KR.js"></script>
  @endif
</head>

<body>
  <div id="root"></div>

  {{-- OlcRTC: раздел «Ядро OlcRTC · Памятка».
       Админ-панель — скомпилированный React SPA из внешнего репозитория, свои
       роуты внутрь не добавить. Поэтому аккуратно вставляем пункт меню <li> в
       существующий сайдбар (Ant Design) и, как запасной вариант, показываем
       плавающую кнопку. Ссылка открывается в новой вкладке и ведёт на
       серверную страницу-памятку /{secure_path}/olcrtc-guide. --}}
  <script>
  (function () {
    var securePath = (window.settings && window.settings.secure_path) || '';
    if (!securePath) return;
    var url = '/' + securePath + '/olcrtc-guide';
    var NAV_ID = 'olcrtc-guide-nav';
    var FAB_ID = 'olcrtc-guide-fab';
    var LABEL = 'Ядро OlcRTC · Памятка';

    function buildLink(inFab) {
      var a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener';
      a.style.cssText = 'display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none;' +
        (inFab ? 'padding:10px 16px;font-weight:600;' : '');
      a.innerHTML = '<span style="font-size:16px;line-height:1;">🔄</span><span>' + LABEL + '</span>';
      return a;
    }

    function injectSidebar() {
      if (document.getElementById(NAV_ID)) return true;
      var anchor = document.querySelector('a[href="/dashboard"], a[href$="/dashboard"], a[href="#/dashboard"]');
      var menu = anchor ? anchor.closest('ul') : document.querySelector('ul.ant-menu-root');
      if (!menu) return false;
      var li = document.createElement('li');
      li.id = NAV_ID;
      li.className = 'ant-menu-item';
      li.setAttribute('role', 'menuitem');
      li.style.marginTop = '6px';
      li.appendChild(buildLink(false));
      menu.appendChild(li);
      var fab = document.getElementById(FAB_ID);
      if (fab && fab.parentNode) fab.parentNode.removeChild(fab);
      return true;
    }

    function showFab() {
      if (document.getElementById(FAB_ID) || document.getElementById(NAV_ID)) return;
      var fab = document.createElement('div');
      fab.id = FAB_ID;
      fab.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:2147483000;' +
        'background:#151c2c;border:1px solid #263149;border-radius:12px;' +
        'box-shadow:0 10px 30px rgba(0,0,0,.45);color:#e6ecf5;' +
        'font:14px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Arial,sans-serif;';
      fab.appendChild(buildLink(true));
      document.body.appendChild(fab);
    }

    new MutationObserver(injectSidebar).observe(document.body, { childList: true, subtree: true });
    injectSidebar();
    setTimeout(function () { if (!injectSidebar()) showFab(); }, 4000);
  })();
  </script>
</body>

</html>
