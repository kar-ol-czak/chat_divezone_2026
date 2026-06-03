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
    /* CHAT-T-056: proaktywny dymek (nudge) — nad launcherem, klikalny, X zamyka */
    '.dz-nudge{',
    '  position:fixed;right:20px;bottom:88px;',
    '  width:280px;max-width:calc(100vw - 40px);',
    '  background:#ffffff;color:#1f2937;',
    '  padding:14px 38px 14px 14px;',
    '  border-radius:12px;',
    '  box-shadow:0 8px 24px rgba(0,0,0,0.18),0 2px 6px rgba(0,0,0,0.10);',
    '  font-family:"DM Sans",Arial,sans-serif;font-size:14px;line-height:1.4;',
    '  pointer-events:auto;cursor:pointer;',
    '  animation:dzNudgeIn .3s ease;',
    '}',
    '.dz-nudge__text{margin:0 0 10px;word-break:break-word;white-space:pre-line;}',
    '.dz-nudge__cta{',
    '  display:block;width:100%;padding:8px 14px;',
    '  background:' + TEAL + ';color:#fff;',
    '  border:0;border-radius:6px;cursor:pointer;',
    '  font-family:inherit;font-size:13px;font-weight:600;',
    '}',
    '.dz-nudge__cta:hover{background:' + TEAL_DARK + ';}',
    '.dz-nudge__cta:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:2px;}',
    '.dz-nudge__close{',
    '  position:absolute;top:6px;right:6px;',
    '  width:24px;height:24px;',
    '  background:transparent;border:0;',
    '  color:#888;font-size:18px;font-family:inherit;',
    '  cursor:pointer;border-radius:4px;line-height:1;',
    '}',
    '.dz-nudge__close:hover{background:#f0f0f0;color:#333;}',
    '.dz-nudge__close:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:1px;}',
    '@keyframes dzNudgeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}',
    '@media (prefers-reduced-motion: reduce){',
    '  .dz-launcher{transition:none}',
    '  .dz-nudge{animation:none}',
    '}',
    '@media (max-width: 599.98px){',
    '  .dz-nudge{right:12px;bottom:84px;width:240px;}',
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

  /* ───────────────────────── CHAT-T-056: nudge ─────────────────────────
   * Proaktywny dymek nad launcherem. Konfig z BOOT.nudge (PHP hookDisplayFooter).
   * Decyzje: 133b (caly dymek klikalny + przycisk, X zamyka), 134a (sessionStorage:
   * dz_nudge_dismissed / dz_chat_opened), 135a (3 pola), 136a (prosty, bez A/B/tracking).
   * Nudge dziedziczy gating launchera — pojawia sie tylko gdy launcher jest widoczny.
   * Lazy: nudge NIE pobiera bundla. Klik w dymek/CTA = ta sama sciezka co klik launcher.
   */
  var nudgeEl = null;

  function ssGet(key) {
    try { return window.sessionStorage && sessionStorage.getItem(key); }
    catch (_) { return null; }
  }
  function ssSet(key, val) {
    try { if (window.sessionStorage) sessionStorage.setItem(key, val); }
    catch (_) {}
  }

  function openChatFlow() {
    ssSet('dz_chat_opened', '1');
    hideNudge();
    if (bundleReady && typeof window.DivezoneChatOpen === 'function') {
      window.DivezoneChatOpen();
    } else {
      bootBundle().then(function () {
        if (typeof window.DivezoneChatOpen === 'function') {
          window.DivezoneChatOpen();
        }
      });
    }
  }

  function hideNudge() {
    if (nudgeEl && nudgeEl.parentNode) {
      nudgeEl.parentNode.removeChild(nudgeEl);
    }
    nudgeEl = null;
  }

  function renderNudge(text) {
    if (nudgeEl) return;
    nudgeEl = document.createElement('div');
    nudgeEl.className = 'dz-nudge';
    nudgeEl.setAttribute('role', 'dialog');
    nudgeEl.setAttribute('aria-label', 'Zaproszenie do czatu DIVEZONE.PL');

    var closeBtn = document.createElement('button');
    closeBtn.className = 'dz-nudge__close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Zamknij zaproszenie');
    closeBtn.textContent = '×';

    var textEl = document.createElement('p');
    textEl.className = 'dz-nudge__text';
    // ESCAPE: textContent zamiast innerHTML — anty-XSS dla configu z panelu PS.
    textEl.textContent = text;

    var ctaBtn = document.createElement('button');
    ctaBtn.className = 'dz-nudge__cta';
    ctaBtn.type = 'button';
    ctaBtn.textContent = 'Porozmawiajmy na czacie';

    nudgeEl.appendChild(closeBtn);
    nudgeEl.appendChild(textEl);
    nudgeEl.appendChild(ctaBtn);
    root.appendChild(nudgeEl);

    // Klik gdziekolwiek w dymku (poza ×) — otworz czat (133b).
    nudgeEl.addEventListener('click', function (e) {
      if (e.target === closeBtn || closeBtn.contains(e.target)) return;
      openChatFlow();
    });

    // Klik × — zamknij + flag, NIE otwiera czatu (stopPropagation, by nie bublowac do kontenera).
    closeBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      ssSet('dz_nudge_dismissed', '1');
      hideNudge();
    });
  }

  function setupNudge() {
    var cfg = BOOT.nudge;
    if (!cfg || !cfg.enabled) return;
    // Sprawdzenie sessionStorage (134a: raz na sesje).
    if (ssGet('dz_nudge_dismissed') === '1') return;
    if (ssGet('dz_chat_opened') === '1') return;

    var delay = (typeof cfg.delay === 'number' && cfg.delay >= 3 && cfg.delay <= 300) ? cfg.delay : 20;
    var text = String(cfg.text || '').replace(/^\s+|\s+$/g, '');
    if (!text) return;

    setTimeout(function () {
      // Recheck guards — czat moze byc otwarty w trakcie czekania.
      if (ssGet('dz_nudge_dismissed') === '1') return;
      if (ssGet('dz_chat_opened') === '1') return;
      renderNudge(text);
    }, delay * 1000);
  }

  /* ──────────────────────────────────────────────────────────────────── */

  launcher.addEventListener('click', function () {
    // CHAT-T-056: klik launchera tez ustawia flage i ukrywa nudge (jakby wszedl rownolegle).
    ssSet('dz_chat_opened', '1');
    hideNudge();
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

  // Uruchom nudge (zaplanuj setTimeout) — bez pobierania bundla.
  setupNudge();

  // Pre-fetch bundla na idle, by pierwszy klik byl natychmiastowy.
  // ADR-060: nie konkurowac z LCP. requestIdleCallback z timeoutem 4s.
  var schedule = window.requestIdleCallback || function (cb) { return setTimeout(cb, 1500); };
  schedule(function () { bootBundle(); }, { timeout: 4000 });
})();
