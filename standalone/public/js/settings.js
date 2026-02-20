/**
 * DiveChat — Panel ustawien
 * Load/save z /api/settings, dropdowny zalezne od providera.
 */
(function () {
    'use strict';

    // Elementy DOM
    const elProvider = document.getElementById('settingProvider');
    const elModel = document.getElementById('settingModel');
    const elEscalation = document.getElementById('settingEscalation');
    const elTemp = document.getElementById('settingTemp');
    const elTempValue = document.getElementById('tempValue');
    const elEmoji = document.getElementById('settingEmoji');
    const elGapThreshold = document.getElementById('settingGapThreshold');
    const elGapValue = document.getElementById('gapValue');
    const elSaved = document.getElementById('settingSaved');

    // Modele per provider
    const MODELS = {
        openai: {
            primary: [
                { value: 'gpt-4.1', label: 'gpt-4.1' },
                { value: 'gpt-5-mini', label: 'gpt-5-mini' },
            ],
            escalation: [
                { value: 'gpt-5.2', label: 'gpt-5.2' },
            ],
        },
        claude: {
            primary: [
                { value: 'claude-sonnet-4-6', label: 'claude-sonnet-4-6' },
                { value: 'claude-haiku-4-5', label: 'claude-haiku-4-5' },
            ],
            escalation: [
                { value: 'claude-opus-4-6', label: 'claude-opus-4-6' },
            ],
        },
    };

    let saveTimeout = null;

    // --- Init ---
    loadSettings();

    // --- Event listeners ---
    elProvider.addEventListener('change', function () {
        updateModelDropdowns(elProvider.value);
        saveAll();
    });

    elModel.addEventListener('change', function () { saveAll(); });
    elEscalation.addEventListener('change', function () { saveAll(); });

    elTemp.addEventListener('input', function () {
        elTempValue.textContent = elTemp.value;
    });
    elTemp.addEventListener('change', function () { saveAll(); });

    elEmoji.addEventListener('change', function () { saveAll(); });

    elGapThreshold.addEventListener('input', function () {
        elGapValue.textContent = elGapThreshold.value;
    });
    elGapThreshold.addEventListener('change', function () { saveAll(); });

    // --- Funkcje ---

    function loadSettings() {
        fetch('/api/test-token')
            .then(function (r) { return r.json(); })
            .then(function () {
                return fetch('/api/settings');
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
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
    }

    function updateModelDropdowns(provider) {
        var models = MODELS[provider] || MODELS.openai;

        // Primary
        elModel.innerHTML = '';
        models.primary.forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            elModel.appendChild(opt);
        });

        // Escalation
        elEscalation.innerHTML = '';
        models.escalation.forEach(function (m) {
            var opt = document.createElement('option');
            opt.value = m.value;
            opt.textContent = m.label;
            elEscalation.appendChild(opt);
        });
    }

    function saveAll() {
        clearTimeout(saveTimeout);
        saveTimeout = setTimeout(doSave, 500);
    }

    function doSave() {
        var settings = {
            ai_provider: elProvider.value,
            primary_model: elModel.value,
            escalation_model: elEscalation.value,
            temperature: parseFloat(elTemp.value),
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
