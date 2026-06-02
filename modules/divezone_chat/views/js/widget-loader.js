/*!
 * DiveZone Chat — widget loader (stub fasady)
 * CHAT-T-037 etap 1 (ADR-060 strategia ladowania, ADR-061 Shadow DOM).
 *
 * Stub: rysuje TYLKO launcher na requestIdleCallback (cel <20KB).
 * Po pierwszym klikniecu launchera dociaga widget-bundle.js + widget.css
 * + transport.js, ktore montuja okno czatu w tym samym shadow root.
 *
 * Wymaga: window.DIVEZONE_CHAT_BOOT ustawione przez shim PHP (hookDisplayFooter)
 * przed odpaleniem tego skryptu. Brak BOOT = no-op.
 */
(function () {
  'use strict';

  if (!window.DIVEZONE_CHAT_BOOT) {
    return;
  }
  if (window.__divezoneChatMounted) {
    return; // double-include safety
  }
  window.__divezoneChatMounted = true;

  var BOOT = window.DIVEZONE_CHAT_BOOT;
  var TEAL = '#1e6363';
  var TEAL_DARK = '#155050';
  var AMBER = '#e8a800';

  /* ───────────────────────── Shadow host ───────────────────────── */

  var host = document.createElement('div');
  host.id = 'divezone-chat-root';
  host.style.cssText = [
    'all: initial',
    'position: fixed',
    'bottom: 0',
    'right: 0',
    'z-index: 2147483000', // pod max int, nad wiekszosc UI sklepu
    'pointer-events: none' // dziecko launcher/okno znow wlaczy pointer-events
  ].join(';');
  document.body.appendChild(host);

  var root = host.attachShadow({ mode: 'open' });

  // Bazowy CSS stuba: tylko launcher i kontener. Reszta dociaga widget.css.
  var baseStyle = document.createElement('style');
  baseStyle.textContent = [
    ':host{all:initial}',
    '*,*::before,*::after{box-sizing:border-box}',
    '.dz-launcher{',
    '  position:fixed;right:20px;bottom:20px;',
    '  width:56px;height:56px;border-radius:50%;',
    '  background:' + TEAL + ';',
    '  border:0;cursor:pointer;',
    '  display:flex;align-items:center;justify-content:center;',
    '  box-shadow:0 4px 18px rgba(0,0,0,0.22),0 1px 4px rgba(0,0,0,0.10);',
    '  pointer-events:auto;',
    '  transition:transform .15s ease, opacity .15s ease;',
    '  font-family:"DM Sans",Arial,sans-serif;',
    '}',
    '.dz-launcher:hover{opacity:.92}',
    '.dz-launcher:active{transform:scale(.96)}',
    '.dz-launcher:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:3px}',
    '.dz-launcher__dot{',
    '  position:absolute;top:2px;right:2px;',
    '  width:13px;height:13px;border-radius:50%;',
    '  background:' + AMBER + ';',
    '  border:2px solid ' + TEAL + ';',
    '}',
    '@media (prefers-reduced-motion: reduce){',
    '  .dz-launcher{transition:none}',
    '}'
  ].join('\n');
  root.appendChild(baseStyle);

  /* Ikona maski nurkowej (inline SVG, tokeny z design handoff). */
  function maskIconSvg(size, color) {
    var s = String(size);
    var h = String(Math.round(size * 0.85));
    var c = color || '#ffffff';
    return [
      '<svg width="' + s + '" height="' + h + '" viewBox="0 0 20 17" fill="none" aria-hidden="true">',
      '<rect x="1.5" y="3.5" width="17" height="10" rx="4.5" stroke="' + c + '" stroke-width="1.4" fill="rgba(255,255,255,0.10)"/>',
      '<path d="M1.5 7 L0 5.5" stroke="' + c + '" stroke-width="1.3" stroke-linecap="round"/>',
      '<path d="M18.5 7 L20 5.5" stroke="' + c + '" stroke-width="1.3" stroke-linecap="round"/>',
      '<path d="M7.5 13.5 Q10 16 12.5 13.5" stroke="' + c + '" stroke-width="1.1" stroke-linecap="round" fill="none"/>',
      '</svg>'
    ].join('');
  }

  /* ───────────────────────── Launcher ───────────────────────── */

  var launcher = document.createElement('button');
  launcher.className = 'dz-launcher';
  launcher.type = 'button';
  launcher.setAttribute('aria-label', 'Otworz czat DIVEZONE.PL');
  launcher.innerHTML = maskIconSvg(22, '#ffffff') + '<span class="dz-launcher__dot" aria-hidden="true"></span>';
  root.appendChild(launcher);

  /* Lazy bundle loading state */
  var bundleLoading = false;
  var bundleReady   = false;

  function loadAsset(kind, url) {
    return new Promise(function (resolve, reject) {
      var el;
      if (kind === 'script') {
        el = document.createElement('script');
        el.src = url;
        el.async = false; // chcemy order: transport -> bundle
      } else {
        // CSS w shadow root: pobieramy text, wsuwamy jako <style>
        fetch(url, { credentials: 'omit' })
          .then(function (r) { return r.ok ? r.text() : Promise.reject(new Error('css ' + r.status)); })
          .then(function (css) {
            var style = document.createElement('style');
            style.setAttribute('data-divezone-chat-css', '1');
            style.textContent = css;
            root.appendChild(style);
            resolve();
          })
          .catch(reject);
        return;
      }
      el.onload = function () { resolve(); };
      el.onerror = function () { reject(new Error('load fail: ' + url)); };
      document.head.appendChild(el);
    });
  }

  function bootBundle() {
    if (bundleLoading || bundleReady) return Promise.resolve();
    bundleLoading = true;

    // CSS rownolegle z JS, JS w kolejnosci transport -> bundle.
    var assets = BOOT.assets || {};
    var cssP = loadAsset('css', assets.css);
    var jsP  = loadAsset('script', assets.transport)
      .then(function () { return loadAsset('script', assets.bundle); });

    return Promise.all([cssP, jsP]).then(function () {
      bundleReady = true;
      if (typeof window.DivezoneChatMount === 'function') {
        window.DivezoneChatMount({
          root: root,
          host: host,
          launcher: launcher,
          boot: BOOT
        });
      }
    }).catch(function (err) {
      bundleLoading = false;
      // Cichy fallback — launcher zostaje, kliknicie spr. ponownie.
      if (window.console && console.warn) {
        console.warn('[divezone_chat] bundle load failed', err);
      }
    });
  }

  launcher.addEventListener('click', function () {
    if (bundleReady && typeof window.DivezoneChatOpen === 'function') {
      window.DivezoneChatOpen();
    } else {
      bootBundle().then(function () {
        if (typeof window.DivezoneChatOpen === 'function') {
          window.DivezoneChatOpen();
        }
      });
    }
  });

  // Pre-fetch bundla na idle, by pierwszy klik byl natychmiastowy.
  // ADR-060: nie konkurowac z LCP. requestIdleCallback z timeoutem 4s.
  var schedule = window.requestIdleCallback || function (cb) { return setTimeout(cb, 1500); };
  schedule(function () { bootBundle(); }, { timeout: 4000 });
})();
