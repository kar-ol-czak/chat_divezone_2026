/**
 * Editorial Picks — admin UI module.
 *
 * - Lista picków (GET /api/admin/editorial-picks) z filtrami status / sort
 * - Form Add (POST z autocomplete /api/admin/products/search), modal Edit (PUT subset)
 * - Akcje: Edit ✎ / Mark reviewed ✓ / Extend +30d / Deactivate ⏸ / Delete 🗑
 * - Sortable headers (klik → ASC/DESC + ▲▼)
 * - Banner pending-reviews (GET /api/admin/editorial-picks/pending-reviews)
 * - needs_review filter (client-side: bezterminowe bez review > 30 dni LUB wygasłe w 7 dni)
 * - Hash routing przez DiveAdmin.router (admin.js)
 *
 * Vanilla JS, integruje się z DiveAdmin (api / send / fmt / toast / escHtml).
 */
(function () {
    'use strict';

    var DiveAdmin = window.DiveAdmin = window.DiveAdmin || {};

    var API_BASE = '/api/admin/editorial-picks';
    var PRODUCTS_SEARCH_URL = '/api/admin/products/search';
    var SHOP_PRODUCT_URL = 'https://divezone.pl/index.php?id_product=';

    var SORTABLE_COLUMNS = ['product_name', 'boost_factor', 'added_at', 'expires_at', 'last_review_at'];

    // Stan modułu
    var state = {
        loaded: false,                  // pierwsze załadowanie listy odbyło się
        currentEdit: null,              // pick obecnie edytowany w modalu (null = Add mode)
        sort: { col: 'added_at', dir: 'desc' },
        statusFilter: '1',              // active=1|0|all (mirror dropdown)
        needsReviewMode: false,         // tryb klient-side filter z banner CTA
        autocomplete: { timer: null, abortController: null },
        selectedProduct: null,          // {id, name} po wyborze z autocomplete lub manual
    };

    // --- Helpers formatujące ---

    // T-013 UX feedback: bez "temu" dla przeszłości; "za N..." nadal dla przyszłości
    function relativeTime(iso, opts) {
        if (!iso) return opts && opts.fallback != null ? opts.fallback : '—';
        var d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        var diffMs = d.getTime() - Date.now();
        var prefix = diffMs > 0 ? 'za ' : '';
        var absSec = Math.abs(diffMs) / 1000;
        var n, unit;
        if (absSec < 60) { n = Math.round(absSec); unit = 'sek'; }
        else if (absSec < 3600) { n = Math.round(absSec / 60); unit = n === 1 ? 'minuta' : (n < 5 ? 'minuty' : 'minut'); }
        else if (absSec < 86400) { n = Math.round(absSec / 3600); unit = n === 1 ? 'godzina' : (n < 5 ? 'godziny' : 'godzin'); }
        else if (absSec < 86400 * 30) { n = Math.round(absSec / 86400); unit = n === 1 ? 'dzień' : 'dni'; }
        else if (absSec < 86400 * 365) { n = Math.round(absSec / (86400 * 30)); unit = n === 1 ? 'miesiąc' : (n < 5 ? 'miesiące' : 'miesięcy'); }
        else { n = Math.round(absSec / (86400 * 365)); unit = n === 1 ? 'rok' : (n < 5 ? 'lata' : 'lat'); }
        return prefix + n + ' ' + unit;
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
        productCell.dataset.label = 'Produkt';
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
        catCell.dataset.label = 'Kategoria';
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
        boostCell.dataset.label = 'Boost';
        boostCell.className = 'num editorial-cell__boost';
        boostCell.textContent = '×' + parseFloat(pick.boost_factor).toFixed(1);
        tr.appendChild(boostCell);

        var reasonCell = document.createElement('td');
        reasonCell.dataset.label = 'Powód';
        reasonCell.className = 'editorial-cell__reason';
        reasonCell.title = pick.reason || '';
        reasonCell.textContent = pick.reason || '';
        tr.appendChild(reasonCell);

        var addedCell = document.createElement('td');
        addedCell.dataset.label = 'Dodał / kiedy';
        addedCell.innerHTML = DiveAdmin.escHtml(pick.added_by || 'admin')
            + '<div class="editorial-cell__sub">' + DiveAdmin.escHtml(relativeTime(pick.added_at)) + '</div>';
        tr.appendChild(addedCell);

        var expiresCell = document.createElement('td');
        expiresCell.dataset.label = 'Wygasa';
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
        reviewCell.dataset.label = 'Last review';
        if (!pick.last_review_at) {
            reviewCell.innerHTML = '<span class="editorial-cell__sub">—</span>';
        } else {
            reviewCell.textContent = relativeTime(pick.last_review_at);
        }
        tr.appendChild(reviewCell);

        var statusCell = document.createElement('td');
        statusCell.dataset.label = 'Status';
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
        actionsCell.dataset.label = 'Akcje';
        actionsCell.className = 'actions editorial-cell__actions';
        actionsCell.appendChild(iconAction('✎', 'Edytuj', function () { openEditModal(pick); }));
        actionsCell.appendChild(iconAction('✓', 'Oznacz jako przejrzane', function () { quickAction(pick, { mark_reviewed: true }, 'Oznaczono jako przejrzane'); }));
        actionsCell.appendChild(iconAction('+30d', 'Przedłuż o 30 dni', function () { quickAction(pick, { ttl_extend_days: 30 }, 'Pick przedłużony o 30 dni'); }, 'text'));
        if (pick.active) {
            actionsCell.appendChild(iconAction('⏸', 'Dezaktywuj', function () { quickAction(pick, { active: false }, 'Pick zdezaktywowany'); }));
        } else {
            // Placeholder żeby kolumna miała stałą szerokość niezależnie od stanu
            var placeholder = document.createElement('span');
            placeholder.className = 'editorial-action editorial-action--placeholder';
            actionsCell.appendChild(placeholder);
        }
        actionsCell.appendChild(iconAction('🗑', 'Usuń permanentnie', function () { confirmDelete(pick); }, 'danger'));
        tr.appendChild(actionsCell);

        return tr;
    }

    /**
     * Buduje przycisk akcji w komórce. `kind` = 'danger'|'text'|undefined.
     * Native title tooltip (przeglądarka renderuje na hover).
     */
    function iconAction(glyph, tooltip, onClick, kind) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'editorial-action' + (kind ? ' editorial-action--' + kind : '');
        btn.title = tooltip;
        btn.setAttribute('aria-label', tooltip);
        btn.textContent = glyph;
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            onClick();
        });
        return btn;
    }

    // --- Load list ---

    /**
     * Backend zwraca rekordy ORDER BY {col} DESC NULLS LAST. Dir 'asc' realizujemy
     * client-side przez reverse() — proste i wystarczająco poprawne dla picków
     * (typowo <50 rekordów). NULLS przesuwają się na początek przy ASC, co matchuje
     * intuicyjne "Bez review najdłużej" (NULL = nigdy nie reviewed = najpilniejsze).
     */
    function loadList() {
        // Sync state.statusFilter z dropdownem (lub needs_review query)
        var statusEl = document.getElementById('editorialFilterStatus');
        var sortEl = document.getElementById('editorialFilterSort');
        if (statusEl && !state.needsReviewMode) state.statusFilter = statusEl.value;
        if (sortEl) sortEl.value = state.sort.col;
        updateSortIndicators();

        // needs_review mode: pobierz wszystkie (active=all), filter client-side
        var apiActive = state.needsReviewMode ? 'all' : state.statusFilter;
        var url = API_BASE + '?active=' + encodeURIComponent(apiActive) + '&order_by=' + encodeURIComponent(state.sort.col);

        var tbody = document.getElementById('editorialBody');
        var emptyEl = document.getElementById('editorialEmpty');
        var countEl = document.getElementById('editorialCount');
        if (tbody) tbody.innerHTML = '<tr><td colspan="9" class="data-table__empty">Ładowanie…</td></tr>';
        if (emptyEl) emptyEl.classList.add('hidden');

        DiveAdmin.api(url)
            .then(function (data) {
                var picks = data.picks || [];

                // Client-side filter dla needs_review (banner CTA)
                if (state.needsReviewMode) {
                    picks = picks.filter(isPendingReview);
                }

                // Client-side dir override (backend zawsze DESC)
                if (state.sort.dir === 'asc') {
                    picks = picks.slice().reverse();
                }

                if (countEl) {
                    countEl.textContent = picks.length + ' pozyc' + (picks.length === 1 ? 'ja' : 'ji')
                        + (state.needsReviewMode ? ' (filter: needs review)' : '');
                }
                tbody.innerHTML = '';
                if (picks.length === 0) {
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

    // needs_review predykat: aktywne bezterminowe bez review > 30d LUB wygasłe w ostatnich 7d
    function isPendingReview(pick) {
        var now = Date.now();
        var THIRTY_D = 30 * 86400 * 1000;
        var SEVEN_D = 7 * 86400 * 1000;

        if (pick.active && !pick.expires_at) {
            if (!pick.last_review_at) return true;
            var lastReview = new Date(pick.last_review_at).getTime();
            if (now - lastReview > THIRTY_D) return true;
        }

        if (!pick.active && pick.expires_at) {
            var expiresMs = new Date(pick.expires_at).getTime();
            if (expiresMs <= now && now - expiresMs < SEVEN_D) return true;
        }

        return false;
    }

    // --- Sortable headers ---

    function bindSortableHeaders() {
        document.querySelectorAll('#editorialSection th.sortable').forEach(function (th) {
            th.addEventListener('click', function () {
                var col = th.dataset.sort;
                if (!col || SORTABLE_COLUMNS.indexOf(col) === -1) return;
                if (state.sort.col === col) {
                    state.sort.dir = state.sort.dir === 'desc' ? 'asc' : 'desc';
                } else {
                    state.sort.col = col;
                    state.sort.dir = 'desc';
                }
                syncSortToHash();
                loadList();
            });
        });
    }

    function updateSortIndicators() {
        document.querySelectorAll('#editorialSection th.sortable').forEach(function (th) {
            var col = th.dataset.sort;
            th.classList.remove('sortable--active', 'sortable--asc', 'sortable--desc');
            if (col === state.sort.col) {
                th.classList.add('sortable--active', 'sortable--' + state.sort.dir);
            }
        });
    }

    function syncSortToHash() {
        // Zachowaj tab editorial-picks + status + needs_review w URL
        var parts = ['sort=' + encodeURIComponent(state.sort.col), 'dir=' + encodeURIComponent(state.sort.dir)];
        if (state.needsReviewMode) {
            parts.unshift('status=needs_review');
        } else if (state.statusFilter !== '1') {
            parts.unshift('status=' + encodeURIComponent(state.statusFilter));
        }
        var newHash = '#/editorial-picks?' + parts.join('&');
        if (window.location.hash !== newHash) {
            // history.replaceState żeby nie spamować historii kolejnymi sort clickami
            history.replaceState(null, '', newHash);
        }
    }

    // --- Banner pending-reviews ---

    /**
     * GET /api/admin/editorial-picks/pending-reviews → {expired_this_week, long_unreviewed, total}.
     * T-013: endpoint dostarczony przez T-012, więc banner aktywny zawsze gdy total > 0.
     * Dla 404/5xx pokazujemy console.warn ale nie blokujemy UI.
     */
    function checkPendingReviewsBanner() {
        DiveAdmin.api(API_BASE + '/pending-reviews')
            .then(function (data) {
                var banner = document.getElementById('editorialBanner');
                var text = document.getElementById('editorialBannerText');
                if (!banner || !text) return;
                var total = (data && data.total) || 0;
                if (total <= 0) {
                    banner.classList.add('hidden');
                    return;
                }
                var expired = (data && data.expired_this_week) || 0;
                var unreviewed = (data && data.long_unreviewed) || 0;
                text.textContent = total + ' pick' + pluralPicks(total) + ' wymaga review: '
                    + expired + ' wygasł' + pluralExpired(expired) + ' w tym tygodniu, '
                    + unreviewed + ' bezterminow' + pluralIndefinite(unreviewed) + ' bez review > 30 dni.';
                banner.classList.remove('hidden');
            })
            .catch(function (err) {
                console.warn('pending-reviews check failed:', err.message);
            });
    }

    function pluralPicks(n) { return n === 1 ? '' : 'ów'; }
    function pluralExpired(n) {
        if (n === 1) return ' (auto-zdezaktywowany)';
        if (n >= 2 && n <= 4) return 'y';
        return 'ych';
    }
    function pluralIndefinite(n) {
        if (n === 1) return 'y';
        if (n >= 2 && n <= 4) return 'e';
        return 'ych';
    }

    // --- Modal Add / Edit ---

    function openAddModal() {
        state.currentEdit = null;
        state.selectedProduct = null;
        document.getElementById('editorialModalTitle').textContent = 'Dodaj editorial pick';
        document.getElementById('editorialFormSubmit').textContent = 'Dodaj pick';
        var form = document.getElementById('editorialForm');
        form.reset();
        document.querySelector('input[name="ttl_days"][value="60"]').checked = true;
        setBoostInputValue(1.5);
        document.getElementById('editorialEditFields').classList.add('hidden');
        // Reset autocomplete UI: search visible, chip+manual hidden
        resetAutocompleteUi();
        document.getElementById('editorialAutocomplete').classList.remove('hidden');
        showFormError(null);
        showModal();
    }

    function openEditModal(pick) {
        state.currentEdit = pick;
        state.selectedProduct = { id: pick.product_id, name: pick.product_name || '' };
        document.getElementById('editorialModalTitle').textContent = 'Edytuj pick #' + pick.id;
        document.getElementById('editorialFormSubmit').textContent = 'Zapisz zmiany';
        var form = document.getElementById('editorialForm');
        form.reset();
        // Hidden inputs
        document.getElementById('editorialProductId').value = String(pick.product_id);
        document.getElementById('editorialProductName').value = pick.product_name || '';
        // Edit mode: ukryj autocomplete + manual; pokaż chip readonly z nazwą produktu
        document.getElementById('editorialAutocomplete').classList.add('hidden');
        document.getElementById('editorialManualFields').classList.add('hidden');
        showProductChip(pick.product_id, pick.product_name || '', /*editMode*/ true);
        // Reszta pól (category_hint nie używa name="product_*", więc fd.get działa)
        form.category_hint.value = pick.category_hint || '';
        setBoostInputValue(parseFloat(pick.boost_factor));
        form.reason.value = pick.reason || '';
        // Edit-only fields zamiast TTL
        document.getElementById('editorialEditFields').classList.remove('hidden');
        form.active.checked = !!pick.active;
        form.expires_at.value = isoForInput(pick.expires_at);
        showFormError(null);
        showModal();
    }

    // --- Autocomplete /api/admin/products/search ---

    function resetAutocompleteUi() {
        var search = document.getElementById('editorialProductSearch');
        var results = document.getElementById('editorialAutocompleteResults');
        var chip = document.getElementById('editorialAutocompleteChip');
        var manualFields = document.getElementById('editorialManualFields');
        var err = document.getElementById('editorialAutocompleteError');
        if (search) { search.value = ''; search.classList.remove('hidden'); }
        if (results) { results.innerHTML = ''; results.classList.add('hidden'); }
        if (chip) chip.classList.add('hidden');
        if (manualFields) manualFields.classList.add('hidden');
        if (err) { err.textContent = ''; err.classList.add('hidden'); }
        document.getElementById('editorialProductId').value = '';
        document.getElementById('editorialProductName').value = '';
        var manualId = document.getElementById('editorialManualId');
        var manualName = document.getElementById('editorialManualName');
        if (manualId) manualId.value = '';
        if (manualName) manualName.value = '';
    }

    /**
     * Pokaż chip z wybranym produktem; ukryj search input. editMode = true → chip
     * "informacyjny" w Edit modal (X resetuje TYLKO w Add, w Edit X jest noop / hidden).
     */
    function showProductChip(id, name, editMode) {
        var chip = document.getElementById('editorialAutocompleteChip');
        var search = document.getElementById('editorialProductSearch');
        var results = document.getElementById('editorialAutocompleteResults');
        var resetBtn = document.getElementById('editorialChipReset');
        document.getElementById('editorialChipName').textContent = name || ('#' + id);
        document.getElementById('editorialChipId').textContent = '#' + id;
        chip.classList.remove('hidden');
        if (search) search.classList.add('hidden');
        if (results) { results.classList.add('hidden'); results.innerHTML = ''; }
        if (resetBtn) resetBtn.style.display = editMode ? 'none' : '';
    }

    function selectProduct(product) {
        state.selectedProduct = { id: product.id, name: product.name };
        document.getElementById('editorialProductId').value = String(product.id);
        document.getElementById('editorialProductName').value = product.name;
        showProductChip(product.id, product.name, false);
        var err = document.getElementById('editorialAutocompleteError');
        if (err) { err.textContent = ''; err.classList.add('hidden'); }
        document.getElementById('editorialManualFields').classList.add('hidden');
    }

    function runAutocomplete(query) {
        var results = document.getElementById('editorialAutocompleteResults');
        var err = document.getElementById('editorialAutocompleteError');
        if (!query || query.length < 2) {
            if (results) { results.innerHTML = ''; results.classList.add('hidden'); }
            return;
        }

        // Anuluj poprzedni in-flight request
        if (state.autocomplete.abortController) {
            try { state.autocomplete.abortController.abort(); } catch (_) {}
        }
        var ac = new AbortController();
        state.autocomplete.abortController = ac;

        fetch(PRODUCTS_SEARCH_URL + '?q=' + encodeURIComponent(query),
            { credentials: 'same-origin', signal: ac.signal })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                var products = (data && data.products) || [];
                renderAutocompleteResults(products, data && data.message);
                if (err) { err.textContent = ''; err.classList.add('hidden'); }
            })
            .catch(function (e) {
                if (e.name === 'AbortError') return;
                if (results) { results.innerHTML = ''; results.classList.add('hidden'); }
                if (err) {
                    err.textContent = 'Błąd wyszukiwania: ' + e.message + '. Wpisz ID manualnie.';
                    err.classList.remove('hidden');
                }
                document.getElementById('editorialManualFields').classList.remove('hidden');
            });
    }

    function renderAutocompleteResults(products, message) {
        var results = document.getElementById('editorialAutocompleteResults');
        if (!results) return;
        results.innerHTML = '';
        if (products.length === 0) {
            var msg = message || 'Brak wyników';
            var li = document.createElement('li');
            li.className = 'editorial-autocomplete__empty';
            li.textContent = msg;
            results.appendChild(li);
            results.classList.remove('hidden');
            return;
        }
        products.forEach(function (p) {
            var li = document.createElement('li');
            li.className = 'editorial-autocomplete__item';
            li.setAttribute('role', 'option');
            li.tabIndex = 0;
            li.innerHTML =
                '<span class="editorial-autocomplete__item-name">' + DiveAdmin.escHtml(p.name) + '</span>'
                + '<span class="editorial-autocomplete__item-meta">'
                + '<span class="editorial-autocomplete__item-price">' + (p.price ? p.price.toFixed(2) + ' zł' : '—') + '</span>'
                + '<span class="editorial-autocomplete__item-stock ' + (p.in_stock ? 'in-stock' : 'out-stock') + '">'
                + (p.in_stock ? '● od ręki' : '○ na zamówienie')
                + '</span>'
                + '<span class="editorial-autocomplete__item-id">#' + p.id + '</span>'
                + '</span>';
            li.addEventListener('click', function () { selectProduct(p); });
            li.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectProduct(p); }
            });
            results.appendChild(li);
        });
        results.classList.remove('hidden');
    }

    function bindAutocomplete() {
        var search = document.getElementById('editorialProductSearch');
        if (search) {
            search.addEventListener('input', function () {
                if (state.autocomplete.timer) clearTimeout(state.autocomplete.timer);
                var v = search.value.trim();
                state.autocomplete.timer = setTimeout(function () { runAutocomplete(v); }, 300);
            });
            // Klik poza dropdownem zamyka go (mousedown by nie odpalić blur przed click na item)
            document.addEventListener('mousedown', function (e) {
                var results = document.getElementById('editorialAutocompleteResults');
                if (!results || results.classList.contains('hidden')) return;
                if (e.target === search || results.contains(e.target)) return;
                results.classList.add('hidden');
            });
        }

        var resetBtn = document.getElementById('editorialChipReset');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (state.currentEdit) return; // w Edit X jest hidden, ale safety
                state.selectedProduct = null;
                resetAutocompleteUi();
            });
        }

        var manualToggle = document.getElementById('editorialManualToggle');
        if (manualToggle) {
            manualToggle.addEventListener('click', function (e) {
                e.preventDefault();
                document.getElementById('editorialManualFields').classList.remove('hidden');
                document.getElementById('editorialManualId').focus();
            });
        }

        // Manual input: wpisanie zapisuje hidden inputs (i czyści chip jeśli był)
        var manualId = document.getElementById('editorialManualId');
        var manualName = document.getElementById('editorialManualName');
        function syncManualToHidden() {
            var id = parseInt(manualId.value, 10);
            var name = manualName.value.trim();
            if (id > 0 && name) {
                state.selectedProduct = { id: id, name: name };
                document.getElementById('editorialProductId').value = String(id);
                document.getElementById('editorialProductName').value = name;
            }
        }
        if (manualId) manualId.addEventListener('input', syncManualToHidden);
        if (manualName) manualName.addEventListener('input', syncManualToHidden);
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
        // Hidden inputs: product_id + product_name są wypełniane przez selectProduct() lub manual sync
        var body = {
            product_id: parseInt(fd.get('product_id'), 10),
            product_name: (fd.get('product_name') || '').trim(),
            category_hint: (fd.get('category_hint') || '').trim() || null,
            boost_factor: parseFloat(fd.get('boost_factor')),
            reason: (fd.get('reason') || '').trim(),
        };
        if (state.currentEdit) {
            body.active = form.active.checked;
            var exp = (fd.get('expires_at') || '').trim();
            body.expires_at = exp ? new Date(exp).toISOString() : null;
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

    function quickAction(pick, body, successMsg) {
        DiveAdmin.send('PUT', API_BASE + '/' + pick.id, body)
            .then(function () {
                DiveAdmin.toast(successMsg, 'success');
                loadList();
                // Odśwież banner po akcji która może zmienić licznik (mark_reviewed, deactivate, extend)
                checkPendingReviewsBanner();
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
        if (statusEl) statusEl.addEventListener('change', function () {
            // Zmiana statusu wyłącza needs_review mode (banner CTA był jednorazowy)
            state.needsReviewMode = false;
            state.statusFilter = statusEl.value;
            syncSortToHash();
            loadList();
        });
        if (sortEl) sortEl.addEventListener('change', function () {
            state.sort.col = sortEl.value;
            // Dropdown nie zmienia kierunku — zostaw obecny dir (default desc)
            syncSortToHash();
            loadList();
        });

        bindSortableHeaders();
        bindAutocomplete();

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
        if (!state.loaded) {
            state.loaded = true;
            bindEvents();
        }

        // Parse URL hash query → state
        var q = route.query || {};
        if (q.status === 'needs_review') {
            state.needsReviewMode = true;
            // Wyzeruj dropdown statusu wizualnie do "Wszystkie" jako informacja
            var statusEl = document.getElementById('editorialFilterStatus');
            if (statusEl) statusEl.value = 'all';
        } else {
            state.needsReviewMode = false;
            if (q.status === '0' || q.status === 'all' || q.status === '1') {
                state.statusFilter = q.status;
                var statusEl2 = document.getElementById('editorialFilterStatus');
                if (statusEl2) statusEl2.value = q.status;
            }
        }
        if (q.sort && SORTABLE_COLUMNS.indexOf(q.sort) !== -1) {
            state.sort.col = q.sort;
        }
        if (q.dir === 'asc' || q.dir === 'desc') {
            state.sort.dir = q.dir;
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
