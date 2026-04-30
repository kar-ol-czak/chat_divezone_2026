/**
 * DiveChat — Panel "Cennik modeli" (TASK-052c sekcja 5).
 * GET /api/admin/pricing → lista 8 modeli z edytowalnymi polami.
 * POST /api/admin/pricing → update jednego modelu.
 */
(function () {
    'use strict';

    var elList = document.getElementById('pricingList');
    var elStatus = document.getElementById('pricingStatus');
    var elBody = document.getElementById('pricingBody');
    var elHeader = document.querySelector('[data-toggle="pricingBody"]');

    var loaded = false;

    if (!elList || !elBody) return;

    // Lazy load: pierwsze rozwinięcie sekcji ładuje cennik.
    if (elHeader) {
        elHeader.addEventListener('click', function () {
            if (!loaded) {
                loaded = true;
                loadPricing();
            }
        });
    }

    function loadPricing() {
        elList.innerHTML = '<div class="pricing-empty">Ładowanie...</div>';
        fetch('/api/admin/pricing')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    elList.innerHTML = '<div class="pricing-empty">Błąd: ' + escHtml(data.error) + '</div>';
                    return;
                }
                renderModels(data.models || []);
            })
            .catch(function (err) {
                elList.innerHTML = '<div class="pricing-empty">Błąd ładowania</div>';
                console.error('Pricing:', err);
            });
    }

    function renderModels(models) {
        if (models.length === 0) {
            elList.innerHTML = '<div class="pricing-empty">Brak modeli</div>';
            return;
        }
        elList.innerHTML = '';
        models.forEach(function (m) { elList.appendChild(buildRow(m)); });
    }

    function buildRow(m) {
        var row = document.createElement('div');
        row.className = 'pricing-row';
        row.dataset.modelId = m.model_id;

        var head = document.createElement('div');
        head.className = 'pricing-row__head';
        head.innerHTML =
            '<span class="pricing-row__label">' + escHtml(m.label) + '</span>' +
            '<span class="pricing-row__provider badge--model">' + escHtml(m.provider) + '</span>' +
            (m.is_escalation ? '<span class="pricing-row__esc badge--gap">eskalacja</span>' : '') +
            '<span class="pricing-row__id">' + escHtml(m.model_id) + '</span>';
        row.appendChild(head);

        var grid = document.createElement('div');
        grid.className = 'pricing-row__grid';

        grid.appendChild(makeNumberField('Input ($/1M)', 'input_price_per_million', m.input_price_per_million));
        grid.appendChild(makeNumberField('Output ($/1M)', 'output_price_per_million', m.output_price_per_million));
        grid.appendChild(makeNumberField('Cache read ($/1M)', 'cache_read_price_per_million', m.cache_read_price_per_million, true));
        grid.appendChild(makeNumberField('Cache create ($/1M)', 'cache_creation_price_per_million', m.cache_creation_price_per_million, true));

        // Aktywny toggle
        var activeWrap = document.createElement('div');
        activeWrap.className = 'pricing-field';
        var activeLabel = document.createElement('label');
        activeLabel.className = 'pricing-field__label';
        activeLabel.textContent = 'Aktywny';
        var toggle = document.createElement('label');
        toggle.className = 'toggle';
        toggle.innerHTML = '<input type="checkbox" data-field="is_active"' + (m.is_active ? ' checked' : '') + '><span class="toggle__slider"></span>';
        activeWrap.appendChild(activeLabel);
        activeWrap.appendChild(toggle);
        grid.appendChild(activeWrap);

        // Save button per row
        var btn = document.createElement('button');
        btn.className = 'btn btn--small btn--ghost pricing-row__save';
        btn.textContent = 'Zapisz';
        btn.addEventListener('click', function () { saveRow(row, btn); });
        grid.appendChild(btn);

        row.appendChild(grid);
        return row;
    }

    function makeNumberField(labelText, field, value, nullable) {
        var wrap = document.createElement('div');
        wrap.className = 'pricing-field';
        var label = document.createElement('label');
        label.className = 'pricing-field__label';
        label.textContent = labelText + (nullable ? ' (opcjonalne)' : '');
        var input = document.createElement('input');
        input.type = 'number';
        input.step = '0.000001';
        input.min = '0';
        input.dataset.field = field;
        input.dataset.nullable = nullable ? '1' : '0';
        input.value = value === null || value === undefined ? '' : value;
        input.className = 'pricing-field__input';
        wrap.appendChild(label);
        wrap.appendChild(input);
        return wrap;
    }

    function saveRow(row, btn) {
        var modelId = row.dataset.modelId;
        var inputs = row.querySelectorAll('input[data-field]');
        var payload = { model_id: modelId };
        var hasError = false;

        inputs.forEach(function (inp) {
            var field = inp.dataset.field;
            if (field === 'is_active') {
                payload[field] = inp.checked;
                return;
            }
            var raw = inp.value.trim();
            if (raw === '') {
                if (inp.dataset.nullable === '1') {
                    payload[field] = null;
                } else {
                    hasError = true;
                    inp.classList.add('pricing-field__input--error');
                }
                return;
            }
            inp.classList.remove('pricing-field__input--error');
            var num = parseFloat(raw);
            if (isNaN(num) || num < 0) {
                hasError = true;
                inp.classList.add('pricing-field__input--error');
                return;
            }
            payload[field] = +num.toFixed(6);
        });

        if (hasError) {
            showStatus('Popraw zaznaczone pola (liczba >= 0).', 'error');
            return;
        }

        // Confirm dialog (decyzja: tak, przed zapisem cen – wpływ na koszty produkcyjne).
        if (!confirm('Zapisać cennik dla ' + modelId + '?')) return;

        btn.disabled = true;
        fetch('/api/admin/pricing', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            if (data.success) {
                showStatus('Zapisano: ' + modelId, 'success');
            } else {
                showStatus('Błąd zapisu: ' + (data.error || 'nieznany'), 'error');
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            showStatus('Błąd sieci: ' + err.message, 'error');
        });
    }

    function showStatus(text, type) {
        if (!elStatus) return;
        elStatus.textContent = text;
        elStatus.className = 'pricing-status pricing-status--' + (type || 'info');
        elStatus.classList.remove('hidden');
        setTimeout(function () { elStatus.classList.add('hidden'); }, 3000);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }
})();
