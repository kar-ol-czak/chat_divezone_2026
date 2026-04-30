/**
 * DiveChat — Panel ustawień (TASK-052c).
 *
 * Klucze settings (ADR-051):
 *   model_primary       - model główny używany w czacie
 *   model_escalation    - alternatywny (na przyszłość, panel zachowuje wybór)
 *   temperature         - tylko dla modeli z supports_temperature=true
 *   reasoning_effort    - tylko dla modeli z supports_reasoning_effort=true
 *   emoji_enabled       - bool
 *   knowledge_gap_threshold - float
 *
 * Reaktywność: zmiana model_primary -> przebudowa kontroli temp/effort wg flag modelu.
 */
(function () {
    'use strict';

    var elProvider = document.getElementById('settingProvider');
    var elModel = document.getElementById('settingModel');
    var elEscalation = document.getElementById('settingEscalation');
    var elTemp = document.getElementById('settingTemp');
    var elTempValue = document.getElementById('tempValue');
    var elTempInfoIcon = document.getElementById('tempInfoIcon');
    var elEffortGroup = document.getElementById('effortGroup');
    var elEffort = document.getElementById('settingEffort');
    var elEmoji = document.getElementById('settingEmoji');
    var elGapThreshold = document.getElementById('settingGapThreshold');
    var elGapValue = document.getElementById('gapValue');
    var elSaved = document.getElementById('settingSaved');

    // Public API – chat.js i history.js wywołują refresh widget kosztu
    window.DiveChat = window.DiveChat || {};
    window.DiveChat.Settings = {
        getAvailableModels: function () { return availableModels; },
        getExchangeRate: function () { return exchangeRate; },
        formatCost: formatCost,
        updateCostWidget: updateCostWidget,
        clearCostWidget: clearCostWidget,
    };

    var availableModels = null;     // {provider: {primary: [...], escalation: [...]}}
    var exchangeRate = null;        // float USD->PLN
    var saveTimeout = null;
    var initialLoad = true;

    // --- Init ---
    loadSettings();

    // --- Events ---
    elProvider.addEventListener('change', function () {
        rebuildModelDropdowns();
        // Reset wybranego modelu jeśli nie należy do nowego providera
        ensureModelMatchesProvider();
        applyModelDependentControls();
        if (!initialLoad) saveAll();
    });

    elModel.addEventListener('change', function () {
        applyModelDependentControls();
        if (!initialLoad) saveAll();
    });
    elEscalation.addEventListener('change', function () {
        applyModelDependentControls();
        if (!initialLoad) saveAll();
    });

    elTemp.addEventListener('input', function () {
        elTempValue.textContent = parseFloat(elTemp.value).toFixed(1);
    });
    elTemp.addEventListener('change', function () { if (!initialLoad) saveAll(); });

    elEffort.addEventListener('change', function () { if (!initialLoad) saveAll(); });

    elEmoji.addEventListener('change', function () { if (!initialLoad) saveAll(); });

    elGapThreshold.addEventListener('input', function () {
        elGapValue.textContent = parseFloat(elGapThreshold.value).toFixed(2);
    });
    elGapThreshold.addEventListener('change', function () { if (!initialLoad) saveAll(); });

    // --- Funkcje ---

    function loadSettings() {
        fetch('/api/settings')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                availableModels = data.available_models || {};
                exchangeRate = typeof data.exchange_rate_usd_pln === 'number'
                    ? data.exchange_rate_usd_pln
                    : null;
                applySettings(data.settings || {});
                initialLoad = false;
            })
            .catch(function (err) {
                console.error('Błąd ładowania ustawień:', err);
                initialLoad = false;
            });
    }

    function applySettings(s) {
        // Provider: nowy klucz ai_provider lub legacy
        var provider = s.ai_provider || 'openai';
        elProvider.value = provider;

        rebuildModelDropdowns();

        // Wybrane modele – akceptuj nowe klucze (model_primary/model_escalation) i legacy (primary_model/escalation_model).
        var primary = s.model_primary || s.primary_model || '';
        var escalation = s.model_escalation || s.escalation_model || '';

        if (primary && optionExists(elModel, primary)) {
            elModel.value = primary;
        } else {
            elModel.value = ''; // placeholder "Wybierz model..."
        }

        if (escalation && optionExists(elEscalation, escalation)) {
            elEscalation.value = escalation;
        } else {
            elEscalation.value = '';
        }

        // Temperature
        if (s.temperature != null) {
            elTemp.value = s.temperature;
            elTempValue.textContent = parseFloat(s.temperature).toFixed(1);
        }

        // Reasoning effort: nowy klucz albo legacy escalation_effort (jeśli string)
        var effortVal = s.reasoning_effort
            || (typeof s.escalation_effort === 'string' ? s.escalation_effort : 'medium');
        if (['minimal', 'low', 'medium', 'high'].indexOf(effortVal) >= 0) {
            elEffort.value = effortVal;
        }

        // Emoji
        if (s.emoji_enabled != null) {
            elEmoji.checked = !!s.emoji_enabled;
        }

        // Knowledge gap threshold
        if (s.knowledge_gap_threshold != null) {
            elGapThreshold.value = s.knowledge_gap_threshold;
            elGapValue.textContent = parseFloat(s.knowledge_gap_threshold).toFixed(2);
        }

        applyModelDependentControls();
    }

    function rebuildModelDropdowns() {
        var provider = elProvider.value;
        var byProvider = (availableModels || {})[provider] || {};

        rebuildOneDropdown(elModel, byProvider, provider);
        rebuildOneDropdown(elEscalation, byProvider, provider);
    }

    function rebuildOneDropdown(selectEl, byProvider, provider) {
        var prevValue = selectEl.value;
        selectEl.innerHTML = '<option value="">Wybierz model...</option>';

        var primary = (byProvider.primary || []).slice().sort(byPriceAsc);
        var escalation = (byProvider.escalation || []).slice().sort(byPriceAsc);

        primary.forEach(function (m) { selectEl.appendChild(createModelOption(m)); });

        if (escalation.length > 0) {
            var sep = document.createElement('option');
            sep.disabled = true;
            sep.textContent = '──── eskalacja ────';
            selectEl.appendChild(sep);
            escalation.forEach(function (m) { selectEl.appendChild(createModelOption(m)); });
        }

        if (prevValue && optionExists(selectEl, prevValue)) {
            selectEl.value = prevValue;
        }
    }

    function createModelOption(m) {
        var opt = document.createElement('option');
        opt.value = m.value;
        var inPrice = formatPriceForDropdown(m.input_price);
        var outPrice = formatPriceForDropdown(m.output_price);
        opt.textContent = m.label + ' — $' + inPrice + ' in / $' + outPrice + ' out';
        opt.dataset.supportsTemperature = m.supports_temperature ? '1' : '0';
        opt.dataset.supportsReasoningEffort = m.supports_reasoning_effort ? '1' : '0';
        return opt;
    }

    function byPriceAsc(a, b) {
        return (a.input_price || 0) - (b.input_price || 0);
    }

    function formatPriceForDropdown(p) {
        if (typeof p !== 'number') return '?.??';
        return p.toFixed(2);
    }

    function optionExists(selectEl, value) {
        for (var i = 0; i < selectEl.options.length; i++) {
            if (selectEl.options[i].value === value) return true;
        }
        return false;
    }

    function ensureModelMatchesProvider() {
        // Po zmianie providera wybór primary/escalation może już nie istnieć w dropdown.
        if (elModel.value === '' || !optionExists(elModel, elModel.value)) {
            elModel.value = '';
        }
        if (elEscalation.value === '' || !optionExists(elEscalation, elEscalation.value)) {
            elEscalation.value = '';
        }
    }

    /**
     * Pokazuje / ukrywa kontrolki temperature i reasoning_effort na podstawie flag
     * aktualnie wybranego model_primary. Jeśli primary nie jest wybrany (placeholder),
     * fallback do flag z modelu eskalacji – żeby UI się degradował gracefully gdy
     * baza ma stale state (np. model_primary nie pasuje do providera, patrz TASK-053).
     */
    function applyModelDependentControls() {
        var meta = getSelectedModelMeta(elModel) || getSelectedModelMeta(elEscalation);

        // Temperature: zawsze widoczny slider, ale disabled+info gdy model nie wspiera.
        if (meta && meta.supports_temperature) {
            elTemp.disabled = false;
            elTempInfoIcon.classList.add('hidden');
            elTemp.parentElement.classList.remove('setting-group--disabled');
        } else {
            elTemp.disabled = true;
            elTempInfoIcon.classList.remove('hidden');
            elTemp.parentElement.classList.add('setting-group--disabled');
        }

        // Reasoning effort: widoczne tylko jeśli aktywny lub fallback model wspiera.
        if (meta && meta.supports_reasoning_effort) {
            elEffortGroup.classList.remove('hidden');
        } else {
            elEffortGroup.classList.add('hidden');
        }
    }

    function getSelectedModelMeta(selectEl) {
        if (!selectEl.value) return null;
        var opt = selectEl.options[selectEl.selectedIndex];
        if (!opt) return null;
        return {
            supports_temperature: opt.dataset.supportsTemperature === '1',
            supports_reasoning_effort: opt.dataset.supportsReasoningEffort === '1',
        };
    }

    function saveAll() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(doSave, 400);
    }

    function doSave() {
        var settings = {
            ai_provider: elProvider.value,
            model_primary: elModel.value,
            model_escalation: elEscalation.value,
            temperature: parseFloat(elTemp.value),
            reasoning_effort: elEffort.value,
            emoji_enabled: elEmoji.checked,
            knowledge_gap_threshold: parseFloat(elGapThreshold.value),
        };

        fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings: settings }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) showSaved();
        })
        .catch(function (err) {
            console.error('Błąd zapisu ustawień:', err);
        });
    }

    function showSaved() {
        elSaved.classList.remove('hidden');
        setTimeout(function () { elSaved.classList.add('hidden'); }, 1500);
    }

    // --- Cost widget (chat-header) ---

    var elChatCost = document.getElementById('chatCost');

    function updateCostWidget(cost) {
        if (!cost || !elChatCost) return;
        var usd = cost.total_usd || 0;
        var pln = cost.total_pln || 0;
        var inT = cost.input_tokens || 0;
        var outT = cost.output_tokens || 0;
        var msgs = cost.message_count || 0;

        elChatCost.textContent = 'Koszt rozmowy: ' + formatCost(usd)
            + ' (' + formatPln(pln) + ') · '
            + formatTokens(inT) + ' in / ' + formatTokens(outT) + ' out · '
            + msgs + ' wiad.';
        elChatCost.classList.remove('hidden');
    }

    function clearCostWidget() {
        if (!elChatCost) return;
        elChatCost.textContent = '';
        elChatCost.classList.add('hidden');
    }

    function formatCost(usd) {
        // 4 miejsca dla < $0.01, 2 powyżej.
        if (usd < 0.01) return '$' + usd.toFixed(4);
        return '$' + usd.toFixed(2);
    }

    function formatPln(pln) {
        return pln.toFixed(2) + ' zł';
    }

    function formatTokens(n) {
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    }
})();
