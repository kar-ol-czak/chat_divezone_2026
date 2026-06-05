/* DiveZone — mobilny widok obsługi rozmów (CHAT-T-072, ADR-086).
   Vanilla JS SPA. Konsumuje /m/api/* (cookie dz_madmin, Path=/m, HttpOnly).
   ZERO localStorage dla danych klientów (RODO). ZERO auto-pollingu (216a). */

(function () {
  'use strict';

  // ===== Konfiguracja =====
  var API_BASE = '/m/api';
  var PER_PAGE = 20;
  var STATUS_LABELS = {
    new: 'Nowa',
    reviewed: 'Przejrzana',
    knowledge_created: 'Dodano wiedzę',
    ignored: 'Pominięta'
  };
  var FILTER_LABELS = { todo: 'Do obsługi', gap: 'Luki wiedzy', all: 'Wszystkie' };

  // ===== Stan w pamięci =====
  var state = {
    role: null,
    employeeId: null,
    listFilter: 'todo',
    listSearch: '',
    listPage: 1,
    listTotal: 0,
    listItems: [],
    currentSessionId: null
  };

  // ===== DOM =====
  var $ = function (id) { return document.getElementById(id); };

  var views = {
    login: $('view-login'),
    list: $('view-list'),
    detail: $('view-detail')
  };

  // ===== Helpery =====
  function escHtml(s) {
    if (s === null || s === undefined) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showView(name) {
    Object.keys(views).forEach(function (k) {
      views[k].hidden = (k !== name);
    });
    window.scrollTo(0, 0);
  }

  function toast(msg, kind) {
    var t = $('toast');
    t.textContent = msg;
    t.className = 'toast' + (kind === 'err' ? ' err' : '');
    t.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.hidden = true; }, 2400);
  }

  function relativeTime(iso) {
    if (!iso) return '';
    var ts = Date.parse(iso.replace(' ', 'T'));
    if (isNaN(ts)) return '';
    var diff = Math.floor((Date.now() - ts) / 1000);
    if (diff < 45) return 'przed chwilą';
    if (diff < 90) return '1 min temu';
    var m = Math.floor(diff / 60);
    if (m < 60) return m + ' min temu';
    var h = Math.floor(m / 60);
    if (h < 24) return h + (h === 1 ? ' godz. temu' : ' godz. temu');
    var d = Math.floor(h / 24);
    if (d < 7) return d + (d === 1 ? ' dzień temu' : ' dni temu');
    var dt = new Date(ts);
    return dt.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit', year: 'numeric' });
  }

  function fullDateTime(iso) {
    if (!iso) return '';
    var ts = Date.parse(iso.replace(' ', 'T'));
    if (isNaN(ts)) return '';
    return new Date(ts).toLocaleString('pl-PL', {
      day: '2-digit', month: '2-digit', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  }

  // ===== API =====
  function api(method, path, body) {
    var opts = {
      method: method,
      credentials: 'include',
      headers: { 'Accept': 'application/json' }
    };
    if (body !== undefined && body !== null) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(API_BASE + path, opts).then(function (res) {
      return res.text().then(function (txt) {
        var data = null;
        if (txt) { try { data = JSON.parse(txt); } catch (e) { data = null; } }
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function handleAuthFailure() {
    state.role = null;
    state.employeeId = null;
    showView('login');
    var err = $('login-error');
    err.textContent = 'Sesja wygasła. Zaloguj się ponownie.';
    err.hidden = false;
  }

  // ===== LOGIN =====
  function bindLogin() {
    var form = $('login-form');
    var btn = $('login-submit');
    var errEl = $('login-error');

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var email = $('login-email').value.trim();
      var password = $('login-password').value;
      errEl.hidden = true;
      if (!email || !password) {
        errEl.textContent = 'Podaj e-mail i hasło.';
        errEl.hidden = false;
        return;
      }
      btn.disabled = true;
      btn.textContent = 'Loguję…';
      api('POST', '/login', { email: email, password: password }).then(function (r) {
        btn.disabled = false;
        btn.textContent = 'Zaloguj';
        if (r.status === 429) {
          errEl.textContent = (r.data && r.data.error) || 'Za dużo prób. Spróbuj za 15 minut.';
          errEl.hidden = false;
          return;
        }
        if (!r.ok || !r.data || !r.data.ok) {
          errEl.textContent = (r.data && r.data.error) || 'Nieprawidłowy login lub hasło.';
          errEl.hidden = false;
          return;
        }
        state.role = r.data.role || null;
        $('login-password').value = '';
        // Weryfikacja zapisu cookie (iOS Safari/przegladarki potrafia
        // opoznic persist Set-Cookie miedzy fetchami) — krotka pauza
        // mikrotaskowa + whoami.
        setTimeout(function () { verifyAndEnter(); }, 0);
      }).catch(function () {
        btn.disabled = false;
        btn.textContent = 'Zaloguj';
        errEl.textContent = 'Brak połączenia. Spróbuj ponownie.';
        errEl.hidden = false;
      });
    });
  }

  function verifyAndEnter() {
    api('GET', '/whoami').then(function (r) {
      var errEl = $('login-error');
      if (r.ok && r.data && r.data.employee_id) {
        state.employeeId = r.data.employee_id;
        state.role = r.data.role || state.role;
        errEl.hidden = true;
        goToList(true);
        return;
      }
      // Login dal 200, ale whoami 401 -> przegladarka NIE zachowala cookie
      // (typowo: tryb prywatny / blokery cookies / "Block all cookies" iOS).
      errEl.textContent = 'Logowanie udane, ale przegladarka nie zachowala cookie sesji. ' +
        'Wylacz tryb prywatny / odblokuj cookies dla chat.divezone.pl i sprobuj ponownie.';
      errEl.hidden = false;
    }).catch(function () {
      var errEl = $('login-error');
      errEl.textContent = 'Logowanie udane, ale brak polaczenia przy sprawdzaniu sesji.';
      errEl.hidden = false;
    });
  }

  // ===== LIST =====
  function bindList() {
    var tabs = views.list.querySelectorAll('.tab');
    tabs.forEach(function (t) {
      t.addEventListener('click', function () {
        if (t.getAttribute('aria-selected') === 'true') return;
        tabs.forEach(function (x) { x.setAttribute('aria-selected', 'false'); });
        t.setAttribute('aria-selected', 'true');
        state.listFilter = t.dataset.filter;
        state.listPage = 1;
        loadList();
      });
    });

    $('btn-refresh').addEventListener('click', function () {
      state.listPage = 1;
      loadList();
    });

    $('btn-logout').addEventListener('click', logout);

    var search = $('list-search');
    var searchDebounce;
    search.addEventListener('input', function () {
      clearTimeout(searchDebounce);
      searchDebounce = setTimeout(function () {
        state.listSearch = search.value.trim();
        state.listPage = 1;
        loadList();
      }, 350);
    });

    $('btn-load-more').addEventListener('click', function () {
      state.listPage += 1;
      loadList(true);
    });

    setupPullToRefresh();
  }

  function loadList(append) {
    var params = ['page=' + state.listPage, 'per_page=' + PER_PAGE];
    if (state.listFilter === 'todo') params.push('admin_status=new');
    if (state.listFilter === 'gap') params.push('knowledge_gap=1');
    if (state.listSearch) params.push('search=' + encodeURIComponent(state.listSearch));

    var statusEl = $('list-status');
    statusEl.textContent = append ? 'Ładuję więcej…' : 'Ładuję…';
    setRefreshing(true);

    return api('GET', '/conversations?' + params.join('&')).then(function (r) {
      setRefreshing(false);
      if (r.status === 401) { handleAuthFailure(); return; }
      if (!r.ok || !r.data || !r.data.conversations) {
        statusEl.textContent = 'Błąd pobierania. Spróbuj odświeżyć.';
        return;
      }
      state.listTotal = r.data.total || 0;
      if (append) {
        state.listItems = state.listItems.concat(r.data.conversations);
      } else {
        state.listItems = r.data.conversations.slice();
      }
      renderList();
    }).catch(function () {
      setRefreshing(false);
      statusEl.textContent = 'Brak połączenia.';
    });
  }

  function renderList() {
    var container = $('list-items');
    var statusEl = $('list-status');
    var loadMore = $('btn-load-more');

    if (state.listItems.length === 0) {
      container.innerHTML = '';
      statusEl.textContent = state.listFilter === 'todo'
        ? 'Brak rozmów do obsługi.'
        : 'Brak rozmów spełniających filtr.';
      loadMore.hidden = true;
      return;
    }

    statusEl.textContent = 'Pokazano ' + state.listItems.length + ' z ' + state.listTotal + '.';

    var html = state.listItems.map(function (c) {
      var first = (c.first_message || '(brak treści)').slice(0, 240);
      var status = c.admin_status || 'new';
      var statusLabel = STATUS_LABELS[status] || status;
      var gapBadge = c.knowledge_gap
        ? '<span class="badge badge-gap" title="Luka wiedzy">Luka</span>' : '';
      var when = relativeTime(c.updated_at || c.started_at);
      var msgCount = (c.message_count || 0);

      return '<button type="button" role="listitem" class="conv-card" data-sid="' +
        escHtml(c.session_id) + '">' +
        '<p class="conv-card-first">' + escHtml(first) + '</p>' +
        '<div class="conv-card-meta">' +
          '<span>' + escHtml(when) + '</span>' +
          '<span class="badge badge-status-' + escHtml(status) + '">' + escHtml(statusLabel) + '</span>' +
          gapBadge +
          '<span class="conv-card-count">' + msgCount + ' wiad.</span>' +
        '</div>' +
      '</button>';
    }).join('');

    container.innerHTML = html;
    Array.prototype.forEach.call(container.querySelectorAll('.conv-card'), function (el) {
      el.addEventListener('click', function () {
        openDetail(el.dataset.sid);
      });
    });

    loadMore.hidden = state.listItems.length >= state.listTotal;
  }

  function setRefreshing(on) {
    var btn = $('btn-refresh');
    if (on) btn.classList.add('is-refreshing'); else btn.classList.remove('is-refreshing');
    var ptr = $('ptr-indicator');
    if (on && ptr.dataset.ptrTriggered === '1') {
      ptr.classList.add('is-visible', 'is-refreshing');
    } else {
      ptr.classList.remove('is-visible', 'is-refreshing');
      ptr.dataset.ptrTriggered = '';
    }
  }

  // ===== Pull-to-refresh =====
  function setupPullToRefresh() {
    var ptr = $('ptr-indicator');
    var startY = null;
    var pulling = false;
    var THRESHOLD = 70;

    function atTop() {
      return (window.scrollY || document.documentElement.scrollTop || 0) <= 0;
    }

    views.list.addEventListener('touchstart', function (e) {
      if (!atTop() || e.touches.length !== 1) { startY = null; pulling = false; return; }
      startY = e.touches[0].clientY;
      pulling = false;
    }, { passive: true });

    views.list.addEventListener('touchmove', function (e) {
      if (startY === null) return;
      var dy = e.touches[0].clientY - startY;
      if (dy <= 0) { pulling = false; return; }
      if (dy > 12) {
        pulling = true;
        var label = ptr.querySelector('.ptr-label');
        if (label) label.textContent = dy >= THRESHOLD ? 'Puść, aby odświeżyć' : 'Pociągnij, aby odświeżyć';
        ptr.classList.add('is-visible');
      }
    }, { passive: true });

    views.list.addEventListener('touchend', function (e) {
      if (!pulling || startY === null) { startY = null; pulling = false; return; }
      var endY = (e.changedTouches[0] || {}).clientY || startY;
      var dy = endY - startY;
      startY = null;
      pulling = false;
      if (dy >= THRESHOLD) {
        ptr.dataset.ptrTriggered = '1';
        state.listPage = 1;
        loadList();
      } else {
        ptr.classList.remove('is-visible');
      }
    }, { passive: true });
  }

  // ===== DETAIL =====
  function bindDetail() {
    $('btn-back').addEventListener('click', function () {
      state.currentSessionId = null;
      showView('list');
    });

    $('status-form').addEventListener('submit', function (e) {
      e.preventDefault();
      saveStatus();
    });
  }

  function openDetail(sessionId) {
    state.currentSessionId = sessionId;
    showView('detail');
    $('detail-meta').innerHTML = '<span>Ładuję rozmowę…</span>';
    $('detail-messages').innerHTML = '';
    var feedback = $('status-feedback');
    feedback.hidden = true; feedback.textContent = '';

    api('GET', '/conversations/' + encodeURIComponent(sessionId)).then(function (r) {
      if (r.status === 401) { handleAuthFailure(); return; }
      if (r.status === 404 || !r.data) {
        $('detail-meta').innerHTML = '<span>Rozmowa nie znaleziona.</span>';
        return;
      }
      renderDetail(r.data);
    }).catch(function () {
      $('detail-meta').innerHTML = '<span>Brak połączenia.</span>';
    });
  }

  function renderDetail(conv) {
    var customerLabel = conv.customer_id && conv.customer_id > 0
      ? 'klient #' + conv.customer_id
      : 'gość';
    var cost = conv.conversation_cost;
    var costLabel = '';
    if (cost && typeof cost === 'object') {
      if (typeof cost.pln === 'number') costLabel = cost.pln.toFixed(2) + ' zł';
      else if (typeof cost.usd === 'number') costLabel = '$' + cost.usd.toFixed(3);
    }
    var meta = [
      '<strong>' + escHtml(fullDateTime(conv.started_at || conv.updated_at)) + '</strong>',
      escHtml(customerLabel),
      escHtml(conv.model_used || '—'),
      costLabel ? escHtml(costLabel) : null
    ].filter(Boolean).map(function (s) { return '<span>' + s + '</span>'; }).join('');
    $('detail-meta').innerHTML = meta;

    var messages = Array.isArray(conv.messages) ? conv.messages : [];
    var rendered = messages.filter(function (m) {
      return m && (m.role === 'user' || m.role === 'assistant');
    }).map(function (m) {
      var content = stringifyContent(m.content);
      if (!content) return '';
      var cls = m.role === 'user' ? 'bubble bubble-user' : 'bubble bubble-assistant';
      return '<div class="' + cls + '">' + escHtml(content) + '</div>';
    }).join('');

    if (!rendered) {
      rendered = '<div class="bubble bubble-empty">Brak wiadomości do wyświetlenia.</div>';
    }
    $('detail-messages').innerHTML = rendered;

    var status = conv.admin_status || 'new';
    var radios = views.detail.querySelectorAll('input[name="status"]');
    Array.prototype.forEach.call(radios, function (r) {
      r.checked = (r.value === status);
    });
    $('status-notes').value = conv.admin_notes || '';
  }

  function stringifyContent(content) {
    if (content === null || content === undefined) return '';
    if (typeof content === 'string') return content;
    // Niektóre wpisy assistant mogą mieć tablicę bloków (Claude content[]).
    if (Array.isArray(content)) {
      return content.map(function (b) {
        if (b && typeof b === 'object' && typeof b.text === 'string') return b.text;
        if (typeof b === 'string') return b;
        return '';
      }).filter(Boolean).join('\n');
    }
    if (typeof content === 'object' && typeof content.text === 'string') return content.text;
    return '';
  }

  function saveStatus() {
    if (!state.currentSessionId) return;
    var radio = views.detail.querySelector('input[name="status"]:checked');
    if (!radio) {
      toast('Wybierz status.', 'err');
      return;
    }
    var status = radio.value;
    var notes = $('status-notes').value.trim();
    var btn = $('btn-save-status');
    var feedback = $('status-feedback');
    feedback.hidden = true;
    btn.disabled = true;
    btn.textContent = 'Zapisuję…';

    api('POST', '/conversations/' + encodeURIComponent(state.currentSessionId) + '/status', {
      status: status,
      notes: notes || null
    }).then(function (r) {
      btn.disabled = false;
      btn.textContent = 'Zapisz';
      if (r.status === 401) { handleAuthFailure(); return; }
      if (r.status === 404) {
        feedback.textContent = 'Rozmowa nie znaleziona.';
        feedback.className = 'form-feedback err';
        feedback.hidden = false;
        return;
      }
      if (!r.ok || !r.data || r.data.success !== true) {
        feedback.textContent = (r.data && r.data.error) || 'Nie udało się zapisać.';
        feedback.className = 'form-feedback err';
        feedback.hidden = false;
        return;
      }
      toast('Zapisano (' + (STATUS_LABELS[status] || status) + ')');
      // Odśwież listę w tle, by status na liście był spójny po powrocie.
      loadList();
    }).catch(function () {
      btn.disabled = false;
      btn.textContent = 'Zapisz';
      feedback.textContent = 'Brak połączenia.';
      feedback.className = 'form-feedback err';
      feedback.hidden = false;
    });
  }

  // ===== Logout =====
  function logout() {
    api('POST', '/logout').then(function () {
      state.role = null;
      state.employeeId = null;
      state.listItems = [];
      state.listTotal = 0;
      state.currentSessionId = null;
      $('login-email').value = '';
      $('login-password').value = '';
      var err = $('login-error');
      err.hidden = true;
      showView('login');
    }).catch(function () {
      showView('login');
    });
  }

  function goToList(freshLogin) {
    showView('list');
    if (freshLogin) {
      // Po świeżym logowaniu zacznij od domyślnego filtra "Do obsługi".
      state.listFilter = 'todo';
      state.listSearch = '';
      $('list-search').value = '';
      var tabs = views.list.querySelectorAll('.tab');
      Array.prototype.forEach.call(tabs, function (t) {
        t.setAttribute('aria-selected', t.dataset.filter === 'todo' ? 'true' : 'false');
      });
    }
    state.listPage = 1;
    loadList();
  }

  // ===== Bootstrap =====
  function boot() {
    bindLogin();
    bindList();
    bindDetail();

    api('GET', '/whoami').then(function (r) {
      if (r.ok && r.data && r.data.employee_id) {
        state.role = r.data.role || null;
        state.employeeId = r.data.employee_id;
        goToList(true);
      } else {
        showView('login');
      }
    }).catch(function () {
      showView('login');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
