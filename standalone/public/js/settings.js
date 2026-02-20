/**
 * DiveChat — Panel ustawien
 * Load/save z /api/settings, dynamiczne modele z API, effort UI.
 */
(function () {
    'use strict';

    // Elementy DOM
    var elProvider = document.getElementById('settingProvider');
    var elModel = document.getElementById('settingModel');
    var elEscalation = document.getElementById('settingEscalation');
    var elTemp = document.getElementById('settingTemp');
    var elTempValue = document.getElementById('tempValue');
    var elEmoji = document.getElementById('settingEmoji');
    var elGapThreshold = document.getElementById('settingGapThreshold');
    var elGapValue = document.getElementById('gapValue');
    var elSaved = document.getElementById('settingSaved');
    var elEffortGroup = document.getElementById('effortGroup');
    var elEffortOpenai = document.getElementById('effortOpenai');
    var elEffortClaude = document.getElementById('effortClaude');
    var elEffortClaudeValue = document.getElementById('effortClaudeValue');

    // Modele z API (ładowane dynamicznie)
    var availableModels = null;
    var saveTimeout = null;

    // --- Init ---
    loadSettings();

    // --- Event listeners ---
    elProvider.addEventListener('change', function () {
        updateModelDropdowns(elProvider.value);
        saveAll();
    });

    elModel.addEventListener('change', function () { saveAll(); });
    elEscalation.addEventListener('change', function () {
        updateEffortUI();
        saveAll();
    });

    elTemp.addEventListener('input', function () {
        elTempValue.textContent = elTemp.value;
    });
    elTemp.addEventListener('change', function () { saveAll(); });

    elEmoji.addEventListener('change', function () { saveAll(); });

    elGapThreshold.addEventListener('input', function () {
        elGapValue.textContent = elGapThreshold.value;
    });
    elGapThreshold.addEventListener('change', function () { saveAll(); });

    if (elEffortOpenai) {
        elEffortOpenai.addEventListener('change', function () { saveAll(); });
    }
    if (elEffortClaude) {
        elEffortClaude.addEventListener('input', function () {
            elEffortClaudeValue.textContent = elEffortClaude.value;
        });
        elEffortClaude.addEventListener('change', function () { saveAll(); });
    }

    // --- Funkcje ---

    function loadSettings() {
        fetch('/api/test-token')
            .then(function (r) { return r.json(); })
            .then(function () {
                return fetch('/api/settings');
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                availableModels = data.available_models || null;
                var s = data.settings || {};
                applySettings(s);
            })
            .catch(function (err) {
                console.error('Blad ladowania ustawien:', err);
            });
    }

    function applySettings(s) {
        // Provider
        if (s.ai_provider) {
            elProvider.value = s.ai_provider;
        }

        // Aktualizuj dropdowny modeli
        updateModelDropdowns(elProvider.value);

        // Ustaw wybrane modele
        if (s.primary_model) {
            elModel.value = s.primary_model;
        }
        if (s.escalation_model) {
            elEscalation.value = s.escalation_model;
        }

        // Temperature
        if (s.temperature != null) {
            elTemp.value = s.temperature;
            elTempValue.textContent = s.temperature;
        }

        // Emoji
        if (s.emoji_enabled != null) {
            elEmoji.checked = !!s.emoji_enabled;
        }

        // Knowledge gap threshold
        if (s.knowledge_gap_threshold != null) {
            elGapThreshold.value = s.knowledge_gap_threshold;
            elGapValue.textContent = s.knowledge_gap_threshold;
        }

        // Effort
        if (s.escalation_effort != null) {
            if (elEffortOpenai && typeof s.escalation_effort === 'string') {
                elEffortOpenai.value = s.escalation_effort;
            }
            if (elEffortClaude && typeof s.escalation_effort === 'number') {
                elEffortClaude.value = s.escalation_effort;
                elEffortClaudeValue.textContent = s.escalation_effort;
            }
        }

        updateEffortUI();
    }

    function updateModelDropdowns(provider) {
        var models = availableModels ? availableModels[provider] : null;

        // Fallback jeśli API nie zwróciło modeli
        if (!models) {
            return;
        }

        // Primary
        elModel.innerHTML = '';
        (models.primary || []).forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            elModel.appendChild(opt);
        });

        // Escalation
        elEscalation.innerHTML = '';
        (models.escalation || []).forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            elEscalation.appendChild(opt);
        });

        updateEffortUI();
    }

    function updateEffortUI() {
        if (!elEffortGroup || !availableModels) return;

        var provider = elProvider.value;
        var escalationValue = elEscalation.value;
        var models = availableModels[provider];
        if (!models || !models.escalation) {
            elEffortGroup.classList.add('hidden');
            return;
        }

        // Znajdz wybrany model eskalacyjny
        var selectedModel = null;
        models.escalation.forEach(function (m) {
            if (m.value === escalationValue) selectedModel = m;
        });

        if (!selectedModel || !selectedModel.supports_effort) {
            elEffortGroup.classList.add('hidden');
            return;
        }

        elEffortGroup.classList.remove('hidden');

        // Pokaz odpowiedni kontroler effort
        var isOpenai = selectedModel.effort_param === 'reasoning_effort';
        var isClaude = selectedModel.effort_param === 'extended_thinking';

        if (elEffortOpenai) {
            elEffortOpenai.parentElement.style.display = isOpenai ? '' : 'none';
        }
        if (elEffortClaude) {
            elEffortClaude.parentElement.style.display = isClaude ? '' : 'none';
        }
    }

    function saveAll() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(doSave, 500);
    }

    function doSave() {
        // Rozpoznaj effort na podstawie providera
        var provider = elProvider.value;
        var effort;
        if (provider === 'claude' && elEffortClaude) {
            effort = parseInt(elEffortClaude.value, 10);
        } else if (elEffortOpenai) {
            effort = elEffortOpenai.value;
        } else {
            effort = 'medium';
        }

        var settings = {
            ai_provider: elProvider.value,
            primary_model: elModel.value,
            escalation_model: elEscalation.value,
            temperature: parseFloat(elTemp.value),
            emoji_enabled: elEmoji.checked,
            knowledge_gap_threshold: parseFloat(elGapThreshold.value),
            escalation_effort: effort,
        };

        fetch('/api/settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ settings: settings }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                showSaved();
            }
        })
        .catch(function (err) {
            console.error('Blad zapisu ustawien:', err);
        });
    }

    function showSaved() {
        elSaved.classList.remove('hidden');
        setTimeout(function () {
            elSaved.classList.add('hidden');
        }, 2000);
    }
})();
