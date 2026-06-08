/*!
 * DiveZone Chat — widget loader (stub fasady)
 * CHAT-T-037 etap 1 (ADR-060 strategia ladowania, ADR-061 Shadow DOM).
 * CHAT-T-078 / ADR-087: gating w runtime — loader pobiera token z endpointu PRZED
 * rysowaniem launchera. HTML strony jest cache-safe (BOOT bez tokenu, identyczny
 * dla wszystkich), gating decyduje endpoint /token (eligible:true/false).
 *
 * Stub: rysuje TYLKO launcher po fetch /token (cel <20KB).
 * Po pierwszym klikniecu launchera dociaga widget-bundle.js + widget.css
 * + transport.js, ktore montuja okno czatu w tym samym shadow root.
 *
 * Wymaga: window.DIVEZONE_CHAT_BOOT ustawione przez shim PHP (hookDisplayFooter)
 * z poprawnym BOOT.tokenUrl. Brak BOOT lub eligible:false z endpointu = no-op.
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
  // CHAT-T-058 (140a): emoji prefixowany z KODU loadera, NIE z configu.
  // Powod: pr_configuration w PS uzywa utf8 (3-bajt), 4-bajtowy 🤿 ginie jako "????".
  // Plik MUSI byc zapisany w UTF-8.
  var NUDGE_EMOJI = '🤿';

  /* ──────────────── CHAT-T-078 / ADR-087: gating w runtime ────────────────
   * Loader fetchuje BOOT.tokenUrl PRZED rysowaniem launchera. Endpoint zwraca:
   *   {eligible:false}                                            -> no-op (zero DOM, brak launchera)
   *   {eligible:true, token, customerId, time, expires_in}        -> piszemy do BOOT, montujemy
   * Brak BOOT.tokenUrl / fetch fail / nieprawidlowy payload       -> no-op (cichy fallback)
   *
   * Transport.js (po mount przez bundle) dalej czyta BOOT.token — juz ustawiony
   * przez tego fetcha. credentials:'include' bo endpoint jest same-origin (sklep)
   * i potrzebuje ciastka sesji PS by rozpoznac zalogowanego klienta.
   * Cel ADR-087: HTML strony cache-safe (zaden token w zrodle), gating jednoetapowy
   * w runtime — eliminuje kolizje warunkowego renderu z LiteSpeed cache.
   */
  function fetchEligibility(cb) {
    if (!BOOT.tokenUrl) { cb(false); return; }
    var done = false;
    function settle(ok) { if (done) return; done = true; cb(ok); }
    try {
      fetch(BOOT.tokenUrl, {
        method: 'GET',
        credentials: 'include',
        cache: 'no-store',
        headers: { 'Accept': 'application/json' }
      })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(new Error('http ' + r.status)); })
        .then(function (payload) {
          if (!payload || payload.eligible !== true) { settle(false); return; }
          if (!payload.token) { settle(false); return; }
          BOOT.token      = payload.token;
          BOOT.customerId = String(payload.customerId);
          BOOT.time       = String(payload.time);
          settle(true);
        })
        .catch(function () { settle(false); });
    } catch (_) { settle(false); }
  }

  /* ───────── mountAll: rysuje shadow host + launcher + planuje nudge/bundle.
   * Wolany TYLKO po eligible:true (ADR-087). Wczesniej zostawal global side-effect
   * `__divezoneChatMounted=true` ustawiony na poczatku IIFE — gwarancja idempotencji
   * nawet gdy fetch jest pending a kolejna kopia loadera zostanie wstrzyknieta.
   */
  function mountAll() {

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
    /* CHAT-T-056 + CHAT-T-058 + CHAT-T-060: proaktywny dymek (nudge) — nad launcherem, klikalny, X zamyka */
    '.dz-nudge{',
    '  position:fixed;right:20px;bottom:88px;',
    '  width:320px;max-width:calc(100vw - 40px);',
    '  background:#f2feff;color:#1f2937;',
    '  padding:20px 38px 20px 20px;',
    '  border-radius:12px;',
    '  box-shadow:0 8px 24px rgba(0,0,0,0.18),0 2px 6px rgba(0,0,0,0.10);',
    '  font-family:"DM Sans",Arial,sans-serif;font-size:17px;line-height:1.4;',
    '  pointer-events:auto;cursor:pointer;',
    '  animation:dzNudgeIn .3s ease;',
    '}',
    '.dz-nudge__text{margin:0 0 20px;word-break:break-word;}',
    '.dz-nudge__line{display:block;}',
    '.dz-nudge__cta{',
    '  display:block;width:100%;padding:12px 14px;',
    '  background:#f7b427;color:#0b3b3d;',
    '  border:0;border-radius:6px;cursor:pointer;',
    '  font-family:inherit;font-size:16px;font-weight:600;',
    '}',
    '.dz-nudge__cta:hover{background:#e0a31f;}',
    '.dz-nudge__cta:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:2px;}',
    '.dz-nudge__close{',
    '  position:absolute;top:6px;right:10px;',
    '  width:40px;height:40px;',
    '  background:transparent;border:0;',
    '  color:#555555;font-size:36px;',
    '  font-family:"Helvetica Neue",Arial,sans-serif;font-weight:300;',
    '  cursor:pointer;border-radius:4px;line-height:1;',
    '  display:flex;align-items:center;justify-content:center;',
    '}',
    '.dz-nudge__close:hover{background:#f0f0f0;color:#1e6363;}',
    '.dz-nudge__close:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:1px;}',
    '@keyframes dzNudgeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}',
    '@media (prefers-reduced-motion: reduce){',
    '  .dz-launcher{transition:none}',
    '  .dz-nudge{animation:none}',
    '}',
    '@media (max-width: 599.98px){',
    '  .dz-nudge{right:12px;bottom:84px;width:340px;padding:25px 55px 25px 25px;font-size:18px;}',
    '  .dz-nudge__cta{font-size:18px;}',
    '}',
    /* CHAT-T-081 (ADR-090): nudge v2 — karta z gradientem (renderNudgeCard).
     * Klasy .dz-card* osobne od .dz-nudge* (v1 zostaje pixel-identyczne).
     * Tokeny i wymiary z _drafts/design_handoff_welcome_prompt/README.md (W1 Teal Aktywny).
     */
    '.dz-card{',
    '  position:fixed;right:20px;bottom:88px;',
    '  width:384px;max-width:calc(100vw - 32px);',
    '  background:#ffffff;color:#1a1a1a;',
    '  border-radius:18px;overflow:hidden;',
    '  box-shadow:0 18px 60px rgba(0,0,0,0.22),0 3px 10px rgba(0,0,0,0.08);',
    '  font-family:"DM Sans",Arial,sans-serif;',
    '  pointer-events:auto;',
    '  display:flex;flex-direction:column;',
    '  transform-origin:bottom right;',
    '  animation:dzCardIn .18s ease-out;',
    '}',
    '.dz-card__gradient{',
    '  position:relative;',
    '  background:linear-gradient(165deg,#2a8585 0%,#1e6363 34%,#103f4f 70%,#0a2438 100%);',
    '  padding:20px 24px 30px;',
    '}',
    '.dz-card__bubbles{position:absolute;inset:0;overflow:hidden;pointer-events:none;}',
    '.dz-card__bubble{position:absolute;border-radius:50%;}',
    '.dz-card__header{',
    '  position:relative;z-index:1;',
    '  display:flex;align-items:center;justify-content:space-between;',
    '  margin-bottom:30px;',
    '}',
    '.dz-card__brand{display:flex;align-items:center;gap:9px;}',
    '.dz-card__mark{',
    '  width:30px;height:30px;border-radius:50%;',
    '  background:' + AMBER + ';',
    '  display:flex;align-items:center;justify-content:center;',
    '  flex-shrink:0;',
    '}',
    '.dz-card__brand-text{',
    '  font-size:13px;font-weight:700;color:#ffffff;letter-spacing:0.06em;',
    '}',
    '.dz-card__close{',
    '  width:28px;height:28px;border-radius:7px;cursor:pointer;',
    '  background:rgba(255,255,255,0.12);',
    '  border:1px solid rgba(255,255,255,0.22);',
    '  color:#ffffff;font-size:13px;',
    '  font-family:inherit;line-height:1;',
    '  display:flex;align-items:center;justify-content:center;',
    '  flex-shrink:0;',
    '}',
    '.dz-card__close:hover{background:rgba(255,255,255,0.20);}',
    '.dz-card__close:focus-visible{outline:2px solid #ffffff;outline-offset:2px;}',
    '.dz-card__title{',
    '  position:relative;z-index:1;',
    '  font-family:inherit;font-size:27px;font-weight:700;',
    '  color:#ffffff;line-height:1.18;margin:0;',
    '  letter-spacing:-0.01em;text-wrap:balance;',
    '}',
    '.dz-card__desc{',
    '  position:relative;z-index:1;',
    '  font-family:inherit;font-size:17.5px;font-weight:400;',
    '  color:rgba(255,255,255,0.8);line-height:1.5;',
    '  margin:14px 0 0;max-width:320px;text-wrap:pretty;',
    '}',
    '.dz-card__cta{padding:16px;background:#ffffff;}',
    '.dz-card__cta-box{',
    '  display:flex;align-items:center;gap:12px;',
    '  border:1px solid #cde4e4;border-radius:14px;',
    '  padding:14px 14px 14px 18px;',
    '  box-shadow:0 1px 4px rgba(0,0,0,0.04);',
    '  cursor:pointer;background:#ffffff;',
    '}',
    '.dz-card__cta-box:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:2px;}',
    '.dz-card__cta-text{flex:1;display:flex;flex-direction:column;gap:3px;min-width:0;}',
    '.dz-card__cta-title{font-family:inherit;font-size:17px;font-weight:700;color:#1a1a1a;}',
    '.dz-card__cta-sub{font-family:inherit;font-size:13px;font-weight:400;color:#b8b8b8;}',
    '.dz-card__send{',
    '  width:46px;height:46px;border-radius:50%;flex-shrink:0;',
    '  background:' + TEAL + ';border:0;cursor:pointer;',
    '  display:flex;align-items:center;justify-content:center;',
    '  box-shadow:0 2px 8px rgba(30,99,99,0.3);',
    '  transition:opacity .15s ease, transform .15s ease;',
    '}',
    '.dz-card__send:hover{opacity:0.88;}',
    '.dz-card__send:active{transform:scale(0.95);}',
    '.dz-card__send:focus-visible{outline:2px solid ' + TEAL + ';outline-offset:2px;}',
    '@keyframes dzCardIn{from{opacity:0;transform:scale(0.94)}to{opacity:1;transform:scale(1)}}',
    '@media (prefers-reduced-motion: reduce){',
    '  .dz-card{animation:none}',
    '  .dz-card__send{transition:none}',
    '}',
    '@media (max-width: 599.98px){',
    '  .dz-card{right:12px;bottom:84px;width:calc(100vw - 32px);max-width:384px;}',
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
    // ESCAPE: textContent na kazdej linii — anty-XSS dla configu z panelu PS.
    // CHAT-T-058 (140a): emoji prefixowany z loadera, bo pr_configuration utf8 (3-bajt)
    // zjada 4-bajtowy 🤿 jako "????". Tekst z configu zostaje bez emoji.
    // CHAT-T-060: rozbicie na 3 linie po znakach konca zdania (! lub ?). Pierwsza
    // linia w <strong> (bold "Hej!"), pozostale w <span class="dz-nudge__line">.
    // Marker  (kontrolny SOH, niespotykany w UI) zamiast lookbehind regex —
    // max kompatybilnosc przegladarek.
    var fullText = NUDGE_EMOJI + ' ' + text;
    var marker = '';
    var lines = fullText.replace(/([!?])\s+/g, '$1' + marker).split(marker);
    lines.forEach(function (line, i) {
      var trimmed = line.replace(/^\s+|\s+$/g, '');
      if (trimmed === '') return;
      var lineEl = document.createElement(i === 0 ? 'strong' : 'span');
      lineEl.className = 'dz-nudge__line';
      lineEl.textContent = trimmed;
      textEl.appendChild(lineEl);
    });

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

  /* CHAT-T-081 (ADR-090): renderNudgeCard — wariant v2 (karta z gradientem).
   * Spec A5: nagłówek/opis/CTA = stałe w kodzie (copy z _drafts/design_handoff_welcome_prompt/).
   * Parametr `_text` z configu (BOOT.nudge.text) jest IGNOROWANY w v2 — pole zostaje
   * dla v1. Powody: (1) copywriting finalny z draftu, (2) gdyby v2 miało emoji,
   * i tak musiałoby iść z kodu (pr_configuration utf8 3-bajt zjada 4-bajtowe emoji).
   * Markup/wymiary/tokeny: README.md draftu + Hi-Fi.html (linie ~610-740).
   * Zachowania identyczne z v1: ten sam shadow root, nudgeEl, hideNudge, openChatFlow.
   */
  function renderNudgeCard(_text) {
    if (nudgeEl) return;
    nudgeEl = document.createElement('div');
    nudgeEl.className = 'dz-card';
    nudgeEl.setAttribute('role', 'dialog');
    nudgeEl.setAttribute('aria-label', 'Zaproszenie do czatu DIVEZONE.PL');

    /* Górna sekcja — gradient głębi wody */
    var gradient = document.createElement('div');
    gradient.className = 'dz-card__gradient';

    /* Bąble powietrza — 7 dekoracyjnych okręgów, pozycje z Hi-Fi.html (BubbleField). */
    var bubbles = document.createElement('div');
    bubbles.className = 'dz-card__bubbles';
    bubbles.setAttribute('aria-hidden', 'true');
    var BUBBLE_SPECS = [
      { x: '14%', y: '22%', s: 6,  o: 0.10 },
      { x: '78%', y: '16%', s: 10, o: 0.08 },
      { x: '64%', y: '30%', s: 4,  o: 0.12 },
      { x: '30%', y: '40%', s: 5,  o: 0.07 },
      { x: '86%', y: '44%', s: 7,  o: 0.06 },
      { x: '8%',  y: '54%', s: 9,  o: 0.05 },
      { x: '50%', y: '12%', s: 3,  o: 0.14 }
    ];
    BUBBLE_SPECS.forEach(function (b) {
      var d = document.createElement('div');
      d.className = 'dz-card__bubble';
      d.style.left = b.x;
      d.style.top = b.y;
      d.style.width = b.s + 'px';
      d.style.height = b.s + 'px';
      d.style.background = 'rgba(255,255,255,' + b.o + ')';
      d.style.border = '1px solid rgba(255,255,255,' + (b.o + 0.06) + ')';
      bubbles.appendChild(d);
    });
    gradient.appendChild(bubbles);

    /* Mini-nagłówek: kółko amber z maską + "DIVEZONE.PL"  |  przycisk X */
    var header = document.createElement('div');
    header.className = 'dz-card__header';

    var brand = document.createElement('div');
    brand.className = 'dz-card__brand';
    var mark = document.createElement('div');
    mark.className = 'dz-card__mark';
    mark.innerHTML = maskIconSvg(15, '#ffffff'); // reuse z v1, NIE duplikujemy SVG
    var brandText = document.createElement('span');
    brandText.className = 'dz-card__brand-text';
    brandText.textContent = 'DIVEZONE.PL';
    brand.appendChild(mark);
    brand.appendChild(brandText);

    var closeBtn = document.createElement('button');
    closeBtn.className = 'dz-card__close';
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Zamknij');
    closeBtn.textContent = '✕';

    header.appendChild(brand);
    header.appendChild(closeBtn);
    gradient.appendChild(header);

    /* Wielki nagłówek — text-wrap:balance łamie naturalnie (bez ręcznego <br>). */
    var title = document.createElement('h2');
    title.className = 'dz-card__title';
    title.textContent = 'Nie wiesz, jaki sprzęt wybrać?';
    gradient.appendChild(title);

    /* Opis — 2 akapity (separator pusta linia, jak w Copy z README). */
    var desc = document.createElement('p');
    desc.className = 'dz-card__desc';
    desc.appendChild(document.createTextNode(
      'Doradzimy w doborze automatu, komputera nurkowego, pianki lub suchego skafandra - tak jak instruktor na miejscu.'
    ));
    desc.appendChild(document.createElement('br'));
    desc.appendChild(document.createElement('br'));
    desc.appendChild(document.createTextNode('Zapytaj naszych specjalistów.'));
    gradient.appendChild(desc);

    /* Dolna sekcja — biała karta CTA. */
    var cta = document.createElement('div');
    cta.className = 'dz-card__cta';

    var ctaBox = document.createElement('div');
    ctaBox.className = 'dz-card__cta-box';
    ctaBox.setAttribute('role', 'button');
    ctaBox.setAttribute('tabindex', '0');
    ctaBox.setAttribute('aria-label', 'Rozpocznij czat');

    var ctaText = document.createElement('div');
    ctaText.className = 'dz-card__cta-text';
    var ctaTitle = document.createElement('span');
    ctaTitle.className = 'dz-card__cta-title';
    ctaTitle.textContent = 'Porozmawiajmy na czacie';
    var ctaSub = document.createElement('span');
    ctaSub.className = 'dz-card__cta-sub';
    ctaSub.textContent = 'Odpowiadamy 24/7';
    ctaText.appendChild(ctaTitle);
    ctaText.appendChild(ctaSub);

    var sendBtn = document.createElement('button');
    sendBtn.className = 'dz-card__send';
    sendBtn.type = 'button';
    sendBtn.setAttribute('aria-label', 'Rozpocznij czat');
    /* Paper-plane SVG — path skopiowany 1:1 z Hi-Fi.html (linie ~731-734). */
    sendBtn.innerHTML = [
      '<svg width="19" height="19" viewBox="0 0 24 24" fill="none" aria-hidden="true">',
      '<path d="M21.5 2.5 L2.8 11.2 a0.6 0.6 0 0 0 0.05 1.12 L9 14.2 L11.4 20.9 a0.6 0.6 0 0 0 1.12 0.06 L21.5 2.5 Z" fill="#ffffff"/>',
      '<path d="M9 14.2 L21.5 2.5" stroke="' + TEAL + '" stroke-width="1.1" stroke-linecap="round" opacity="0.35"/>',
      '</svg>'
    ].join('');

    ctaBox.appendChild(ctaText);
    ctaBox.appendChild(sendBtn);
    cta.appendChild(ctaBox);

    nudgeEl.appendChild(gradient);
    nudgeEl.appendChild(cta);
    root.appendChild(nudgeEl);

    /* Klik w kartę CTA (lub w przycisk paper-plane przez bubble) — otwórz czat. */
    ctaBox.addEventListener('click', function () { openChatFlow(); });
    ctaBox.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openChatFlow();
      }
    });

    /* X — zamknij + flag w sesji, NIE otwiera czatu. */
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

    // CHAT-T-081 (ADR-090): wybor wygladu nudge. Default 'v1' (klasyczny dymek);
    // 'v2' = karta z gradientem (renderNudgeCard). Kazda inna/brakujaca wartosc = v1.
    var variant = (cfg.variant === 'v2') ? 'v2' : 'v1';

    setTimeout(function () {
      // Recheck guards — czat moze byc otwarty w trakcie czekania.
      if (ssGet('dz_nudge_dismissed') === '1') return;
      if (ssGet('dz_chat_opened') === '1') return;
      if (variant === 'v2') {
        renderNudgeCard(text);
      } else {
        renderNudge(text);
      }
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

  } /* /mountAll */

  // ADR-087: gating w runtime. Eligible:true (token zapisany w BOOT) -> mount;
  // false / fetch fail / brak tokenUrl -> no-op (zero DOM, brak launchera).
  fetchEligibility(function (ok) { if (ok) mountAll(); });
})();
