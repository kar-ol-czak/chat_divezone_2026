/**
 * T-011: Editorial Picks — admin UI module.
 *
 * - Lista picków (GET /api/admin/editorial-picks) z filtrami status / sort
 * - Form Add (POST), modal Edit (PUT subset), akcje: Extend +30d / Mark reviewed / Deactivate / Delete
 * - Banner pending-reviews: GET /api/admin/editorial-picks/pending-reviews — graceful 404
 * - Hash routing przez DiveAdmin.router (admin.js)
 *
 * Vanilla JS, integruje się z DiveAdmin (api / send / fmt / toast / escHtml).
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    var API_BASE = '/api/admin/editorial-picks';
    var SHOP_PRODUCT_URL = 'https://divezone.pl/index.php?id_product=';

    // Stan modułu
    var state = {
        loaded: false,            // pierwsze załadowanie listy odbyło się
        bannerChecked: false,     // pending-reviews endpoint: probował (404 → nigdy więcej)
        currentEdit: null,        // pick obecnie edytowany w modalu (null = Add mode)
    };

    // --- Helpers formatujące ---

    function relativeTime(iso, opts) {
        if (!iso) return opts && opts.fallback != null ? opts.fallback : '—';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        var diffMs = d.getTime() - Date.now();
        var pastFuture = diffMs >= 0 ? 'za ' : '';
        var suffix = diffMs < 0 ? ' temu' : '';
        var absSec = Math.abs(diffMs) / 1000;
        var n, unit;
        if (absSec < 60) { n = Math.round(absSec); unit = 'sek'; }
        else if (absSec < 3600) { n = Math.round(absSec / 60); unit = n === 1 ? 'minuta' : (n < 5 ? 'minuty' : 'minut'); }
        else if (absSec < 86400) { n = Math.round(absSec / 3600); unit = n === 1 ? 'godzina' : (n < 5 ? 'godziny' : 'godzin'); }
        else if (absSec < 86400 * 30) { n = Math.round(absSec / 86400); unit = n === 1 ? 'dzień' : 'dni'; }
        else if (absSec < 86400 * 365) { n = Math.round(absSec / (86400 * 30)); unit = n === 1 ? 'miesiąc' : (n < 5 ? 'miesiące' : 'miesięcy'); }
        else { n = Math.round(absSec / (86400 * 365)); unit = n === 1 ? 'rok' : (n < 5 ? 'lata' : 'lat'); }
        return pastFuture + n + ' ' + unit + suffix;
    }

    function isExpired(pick) {
        if (!pick.active) return true;
        if (!pick.expires_at) return false;
        return new Date(pick.expires_at).getTime() <= Date.now();
    }

    function expiringSoon(pick, daysThreshold) {
        if (!pick.expires_at) return false;
        var diffMs = new Date(pick.expires_at).getTime() - Date.now();
        return diffMs > 0 && diffMs < daysThreshold * 86400 * 1000;
    }

    function truncate(text, max) {
        if (!text) return '';
        if (text.length <= max) return text;
        return text.substring(0, max - 1) + '…';
    }

    // Format ISO datetime → "yyyy-MM-ddTHH:mm" (input type=datetime-local)
    function isoForInput(iso) {
        if (!iso) return '';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return '';
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // --- Render ---

    function renderRow(pick) {
        var tr = document.createElement('tr');
        var expired = isExpired(pick);
        var soon = !expired && expiringSoon(pick, 7);

        var productCell = document.createElement('td');
        var prodLink = document.createElement('a');
        prodLink.href = SHOP_PRODUCT_URL + encodeURIComponent(pick.product_id);
        prodLink.target = '_blank';
        prodLink.rel = 'noopener noreferrer';
        prodLink.textContent = pick.product_name || ('#' + pick.product_id);
        productCell.appendChild(prodLink);
        var pidSmall = document.createElement('div');
        pidSmall.className = 'editorial-cell__sub';
        pidSmall.textContent = '#' + pick.product_id;
        productCell.appendChild(pidSmall);
        tr.appendChild(productCell);

        var catCell = document.createElement('td');
        if (pick.category_hint) {
            catCell.textContent = pick.category_hint;
        } else {
            var allBadge = document.createElement('span');
            allBadge.className = 'badge badge--muted';
            allBadge.textContent = 'wszystkie kategorie';
            catCell.appendChild(allBadge);
        }
        tr.appendChild(catCell);

        var boostCell = document.createElement('td');
        boostCell.className = 'num';
        boostCell.appendChild(renderBoostBar(parseFloat(pick.boost_factor)));
        tr.appendChild(boostCell);

        var reasonCell = document.createElement('td');
        reasonCell.className = 'editorial-cell__reason';
        reasonCell.title = pick.reason || '';
        reasonCell.textContent = truncate(pick.reason || '', 60);
        tr.appendChild(reasonCell);

        var addedCell = document.createElement('td');
        addedCell.innerHTML = DiveAdmin.escHtml(pick.added_by || 'admin')
            + '<div class="editorial-cell__sub">' + DiveAdmin.escHtml(relativeTime(pick.added_at)) + '</div>';
        tr.appendChild(addedCell);

        var expiresCell = document.createElement('td');
        if (!pick.expires_at) {
            var foreverBadge = document.createElement('span');
            foreverBadge.className = 'badge badge--muted';
            foreverBadge.textContent = 'bezterminowy';
            expiresCell.appendChild(foreverBadge);
        } else {
            var expiresSpan = document.createElement('span');
            expiresSpan.textContent = relativeTime(pick.expires_at);
            if (soon) expiresSpan.className = 'editorial-cell__expires-soon';
            if (expired) expiresSpan.className = 'editorial-cell__expires-passed';
            expiresCell.appendChild(expiresSpan);
        }
        tr.appendChild(expiresCell);

        var reviewCell = document.createElement('td');
        if (!pick.last_review_at) {
            reviewCell.innerHTML = '<span class="editorial-cell__sub">—</span>';
        } else {
            reviewCell.textContent = relativeTime(pick.last_review_at);
        }
        tr.appendChild(reviewCell);

        var statusCell = document.createElement('td');
        var statusBadge = document.createElement('span');
        if (expired) {
            statusBadge.className = 'badge badge--expired';
            statusBadge.textContent = pick.active ? 'wygasły' : 'nieaktywny';
        } else {
            statusBadge.className = 'badge badge--active';
            statusBadge.textContent = 'aktywny';
        }
        statusCell.appendChild(statusBadge);
        tr.appendChild(statusCell);

        var actionsCell = document.createElement('td');
        actionsCell.className = 'actions';
        actionsCell.appendChild(actionLink('Edit', function () { openEditModal(pick); }));
        actionsCell.appendChild(actionLink('Mark reviewed', function () { quickAction(pick, 'mark_reviewed', { mark_reviewed: true }, 'Oznaczono jako przejrzane'); }));
        actionsCell.appendChild(actionLink('+30d', function () { quickAction(pick, 'extend', { ttl_extend_days: 30 }, 'Pick przedłużony o 30 dni'); }));
        if (pick.active) {
            actionsCell.appendChild(actionLink('Deactivate', function () { quickAction(pick, 'deactivate', { active: false }, 'Pick zdezaktywowany'); }));
        }
        actionsCell.appendChild(actionLink('Delete', function () { confirmDelete(pick); }, 'danger'));
        tr.appendChild(actionsCell);

        return tr;
    }

    function actionLink(label, onClick, kind) {
        var a = document.createElement('a');
        a.href = '#';
        a.className = 'editorial-action' + (kind ? ' editorial-action--' + kind : '');
        a.textContent = label;
        a.addEventListener('click', function (e) {
            e.preventDefault();
            onClick();
        });
        return a;
    }

    function renderBoostBar(boost) {
        var wrap = document.createElement('div');
        wrap.className = 'boost-bar';
        var label = document.createElement('span');
        label.className = 'boost-bar__label';
        label.textContent = '×' + boost.toFixed(1);
        // skala 1.0-2.5 → 0-100%
        var pct = Math.max(0, Math.min(100, ((boost - 1.0) / 1.5) * 100));
        var bar = document.createElement('span');
        bar.className = 'boost-bar__fill';
        bar.style.width = pct.toFixed(0) + '%';
        wrap.appendChild(label);
        wrap.appendChild(bar);
        return wrap;
    }

    // --- Load list ---

    function loadList() {
        var statusEl = document.getElementById('editorialFilterStatus');
        var sortEl = document.getElementById('editorialFilterSort');
        var status = statusEl ? statusEl.value : '1';
        var orderBy = sortEl ? sortEl.value : 'added_at';

        var url = API_BASE + '?active=' + encodeURIComponent(status) + '&order_by=' + encodeURIComponent(orderBy);

        var tbody = document.getElementById('editorialBody');
        var emptyEl = document.getElementById('editorialEmpty');
        var countEl = document.getElementById('editorialCount');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="data-table__empty">Ładowanie…</td></tr>';
        if (emptyEl) emptyEl.classList.add('hidden');

        DiveAdmin.api(url)
            .then(function (data) {
                var picks = data.picks || [];
                if (countEl) countEl.textContent = picks.length + ' pozyc' + (picks.length === 1 ? 'ja' : 'ji');
                tbody.innerHTML = '';
                if (picks.length === 0) {
                    tbody.innerHTML = '';
                    if (emptyEl) emptyEl.classList.remove('hidden');
                    return;
                }
                picks.forEach(function (pick) {
                    tbody.appendChild(renderRow(pick));
                });
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="9" class="data-table__empty">Błąd: '
                    + DiveAdmin.escHtml(err.message)
                    + ' <button type="button" class="btn" id="editorialRetryBtn">Spróbuj ponownie</button></td></tr>';
                var retry = document.getElementById('editorialRetryBtn');
                if (retry) retry.addEventListener('click', loadList);
            });
    }

    // --- Banner pending-reviews ---

    function checkPendingReviewsBanner() {
        if (state.bannerChecked) return;
        state.bannerChecked = true;
        DiveAdmin.api(API_BASE + '/pending-reviews')
            .then(function (data) {
                var banner = document.getElementById('editorialBanner');
                var text = document.getElementById('editorialBannerText');
                if (!banner || !text) return;
                var count = data && (data.count != null ? data.count : (data.picks ? data.picks.length : 0));
                if (!count) return;
                var expired = (data && data.expired) || 0;
                var noReview = (data && data.no_review) || 0;
                text.textContent = count + ' picków wymaga review: '
                    + expired + ' wygasłych w tym tygodniu, '
                    + noReview + ' bezterminowych bez review > 30 dni.';
                banner.classList.remove('hidden');
            })
            .catch(function (err) {
                // 404 = endpoint nie zaimplementowany; cisza, żaden banner
                if (err && (err.message || '').indexOf('404') === -1) {
                    console.warn('pending-reviews check failed:', err.message);
                }
            });
    }

    // --- Modal Add / Edit ---

    function openAddModal() {
        state.currentEdit = null;
        document.getElementById('editorialModalTitle').textContent = 'Dodaj editorial pick';
        document.getElementById('editorialFormSubmit').textContent = 'Dodaj pick';
        var form = document.getElementById('editorialForm');
        form.reset();
        document.querySelector('input[name="ttl_days"][value="60"]').checked = true;
        setBoostInputValue(1.5);
        document.getElementById('editorialEditFields').classList.add('hidden');
        // Product fields odblokowane
        form.product_id.readOnly = false;
        form.product_name.readOnly = false;
        showFormError(null);
        showModal();
    }

    function openEditModal(pick) {
        state.currentEdit = pick;
        document.getElementById('editorialModalTitle').textContent = 'Edytuj pick #' + pick.id;
        document.getElementById('editorialFormSubmit').textContent = 'Zapisz zmiany';
        var form = document.getElementById('editorialForm');
        form.reset();
        form.product_id.value = pick.product_id;
        form.product_name.value = pick.product_name || '';
        form.product_id.readOnly = true;
        form.product_name.readOnly = true;
        form.category_hint.value = pick.category_hint || '';
        setBoostInputValue(parseFloat(pick.boost_factor));
        form.reason.value = pick.reason || '';
        // TTL ukryte przy edycie — pokaż edit-only fields zamiast
        document.getElementById('editorialEditFields').classList.remove('hidden');
        form.active.checked = !!pick.active;
        form.expires_at.value = isoForInput(pick.expires_at);
        showFormError(null);
        showModal();
    }

    function showModal() {
        document.getElementById('editorialModal').classList.remove('hidden');
        // Focus pierwszy input
        setTimeout(function () {
            var firstInput = document.querySelector('#editorialForm input:not([readonly]):not([type=radio]), #editorialForm textarea');
            if (firstInput) firstInput.focus();
        }, 50);
    }

    function hideModal() {
        document.getElementById('editorialModal').classList.add('hidden');
    }

    function showFormError(msg) {
        var el = document.getElementById('editorialFormError');
        if (!el) return;
        if (msg) {
            el.textContent = msg;
            el.classList.remove('hidden');
        } else {
            el.textContent = '';
            el.classList.add('hidden');
        }
    }

    function setBoostInputValue(v) {
        var input = document.getElementById('editorialBoostInput');
        var label = document.getElementById('editorialBoostLabel');
        if (input) input.value = v;
        if (label) label.textContent = '×' + Number(v).toFixed(1);
    }

    function readForm() {
        var form = document.getElementById('editorialForm');
        var fd = new FormData(form);
        var body = {
            product_id: parseInt(fd.get('product_id'), 10),
            product_name: (fd.get('product_name') || '').trim(),
            category_hint: (fd.get('category_hint') || '').trim() || null,
            boost_factor: parseFloat(fd.get('boost_factor')),
            reason: (fd.get('reason') || '').trim(),
        };
        if (state.currentEdit) {
            // Edit mode — mapuj pola edit-only
            body.active = form.active.checked;
            var exp = (fd.get('expires_at') || '').trim();
            if (exp) {
                // input datetime-local → ISO string (browser local timezone)
                body.expires_at = new Date(exp).toISOString();
            } else {
                body.expires_at = null;
            }
        } else {
            var ttl = fd.get('ttl_days');
            if (ttl !== null && ttl !== '') {
                body.ttl_days = parseInt(ttl, 10);
            }
        }
        return body;
    }

    function submitForm(e) {
        e.preventDefault();
        var body = readForm();
        showFormError(null);

        // Walidacja klient-side
        if (!body.product_id || body.product_id <= 0) {
            showFormError('Product ID musi być liczbą > 0');
            return;
        }
        if (!body.product_name) {
            showFormError('Product name wymagane');
            return;
        }
        if (!body.reason || body.reason.length < 10) {
            showFormError('Powód wymagany (min 10 znaków)');
            return;
        }
        if (body.boost_factor < 1.0 || body.boost_factor > 2.5) {
            showFormError('Boost musi być w zakresie 1.0–2.5');
            return;
        }

        var btn = document.getElementById('editorialFormSubmit');
        btn.disabled = true;

        var promise;
        if (state.currentEdit) {
            // PUT — wysyłamy subset (product_id/name nie są aktualizowane)
            var putBody = {
                boost_factor: body.boost_factor,
                reason: body.reason,
                expires_at: body.expires_at,
                active: body.active,
            };
            promise = DiveAdmin.send('PUT', API_BASE + '/' + state.currentEdit.id, putBody);
        } else {
            promise = DiveAdmin.send('POST', API_BASE, body);
        }

        promise
            .then(function () {
                hideModal();
                DiveAdmin.toast(state.currentEdit ? 'Pick zaktualizowany' : 'Pick dodany', 'success');
                loadList();
            })
            .catch(function (err) {
                showFormError('Błąd: ' + err.message);
            })
            .finally(function () {
                btn.disabled = false;
            });
    }

    // --- Quick actions ---

    function quickAction(pick, kind, body, successMsg) {
        DiveAdmin.send('PUT', API_BASE + '/' + pick.id, body)
            .then(function () {
                DiveAdmin.toast(successMsg, 'success');
                loadList();
            })
            .catch(function (err) {
                DiveAdmin.toast('Błąd: ' + err.message, 'error');
            });
    }

    function confirmDelete(pick) {
        if (!window.confirm('Usunąć pick "' + (pick.product_name || '#' + pick.product_id) + '" permanentnie?')) {
            return;
        }
        DiveAdmin.send('DELETE', API_BASE + '/' + pick.id)
            .then(function () {
                DiveAdmin.toast('Pick usunięty', 'success');
                loadList();
            })
            .catch(function (err) {
                DiveAdmin.toast('Błąd usuwania: ' + err.message, 'error');
            });
    }

    // --- Init ---

    function bindEvents() {
        var addBtn = document.getElementById('editorialAddBtn');
        if (addBtn) addBtn.addEventListener('click', openAddModal);

        var emptyAdd = document.getElementById('editorialEmptyAdd');
        if (emptyAdd) emptyAdd.addEventListener('click', function (e) {
            e.preventDefault();
            openAddModal();
        });

        var statusEl = document.getElementById('editorialFilterStatus');
        var sortEl = document.getElementById('editorialFilterSort');
        if (statusEl) statusEl.addEventListener('change', loadList);
        if (sortEl) sortEl.addEventListener('change', loadList);

        var form = document.getElementById('editorialForm');
        if (form) form.addEventListener('submit', submitForm);

        var boostInput = document.getElementById('editorialBoostInput');
        if (boostInput) boostInput.addEventListener('input', function () {
            document.getElementById('editorialBoostLabel').textContent = '×' + Number(boostInput.value).toFixed(1);
        });

        // Close modal: backdrop / close button
        document.querySelectorAll('#editorialModal [data-close-modal]').forEach(function (el) {
            el.addEventListener('click', hideModal);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !document.getElementById('editorialModal').classList.contains('hidden')) {
                hideModal();
            }
        });
    }

    function ensureLoaded(route) {
        if (route.tab !== 'editorial-picks') return;
        if (route.query && route.query.status === 'needs_review') {
            // Banner CTA — wybierz "wszystkie", użytkownik dalej filtruje sortowaniem
            var statusEl = document.getElementById('editorialFilterStatus');
            if (statusEl) statusEl.value = 'all';
        }
        if (!state.loaded) {
            state.loaded = true;
            bindEvents();
        }
        loadList();
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Banner pending-reviews — sprawdź raz, niezależnie od tab
        checkPendingReviewsBanner();

        // Reaguj na zmianę hash; uruchom raz po DOMContentLoaded gdyby tab był od razu editorial-picks
        if (DiveAdmin.router) {
            DiveAdmin.router.onChange(ensureLoaded);
            ensureLoaded(DiveAdmin.router.get());
        }
    });

    DiveAdmin.editorial = {
        loadList: loadList,
        openAddModal: openAddModal,
    };
})();
