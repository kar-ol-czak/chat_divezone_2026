/**
 * DiveChat — Konsola debug
 * Logi z timestampami, JSON viewer, diagnostyka z response.
 */
(function () {
    'use strict';

    const MAX_ENTRIES = 100;

    // Elementy DOM
    const logEl = document.getElementById('consoleLog');
    const jsonWrapper = document.getElementById('jsonWrapper');
    const jsonViewer = document.getElementById('jsonViewer');
    const btnToggle = document.getElementById('btnToggleJson');
    const btnCopy = document.getElementById('btnCopyJson');
    const btnClear = document.getElementById('btnClearConsole');

    let entries = 0;
    let lastRawJson = null;
    let jsonVisible = false;

    // --- Public API ---
    window.DiveChat = window.DiveChat || {};
    window.DiveChat.Console = { log, logRequest, logResponse, setRawJson };

    /**
     * Dodaje wpis tekstowy do konsoli.
     */
    function log(text, type) {
        ensureClean();
        const el = document.createElement('div');
        el.className = 'console-entry';
        el.innerHTML = formatLine(text, type);
        logEl.appendChild(el);
        trimEntries();
        scrollToBottom();
    }

    /**
     * Loguje wysylanie requestu.
     */
    function logRequest(message, sessionId) {
        const sid = sessionId ? sessionId.substring(0, 8) : '???';
        const ts = timestamp();
        ensureClean();

        const el = document.createElement('div');
        el.className = 'console-entry';
        el.innerHTML =
            `<span class="console-entry__time">[${ts}]</span> ` +
            `<span class="console-entry__arrow-out">&rarr;</span> ` +
            `Wysylanie: "${escHtml(truncate(message, 60))}" ` +
            `<span style="color:#666">(session: ${sid})</span>`;
        logEl.appendChild(el);
        trimEntries();
        scrollToBottom();
    }

    /**
     * Loguje odpowiedz z diagnostyka.
     */
    function logResponse(data) {
        ensureClean();
        const ts = timestamp();
        const diag = data.diagnostics || {};
        const times = diag.response_times || {};
        const usage = data.usage || {};

        // Linia glowna
        addLine(
            `<span class="console-entry__time">[${ts}]</span> ` +
            `<span class="console-entry__arrow-in">&larr;</span> ` +
            `Odpowiedz: ${Math.round(times.total_ms || 0)}ms total`
        );

        // Model
        if (diag.model_used) {
            addDetail(`Model: ${diag.model_used}`);
        }

        // Czasy
        const parts = [];
        if (times.embedding_ms) parts.push(`Embedding: ${Math.round(times.embedding_ms)}ms`);
        if (times.ai_ms) parts.push(`AI: ${Math.round(times.ai_ms)}ms`);
        if (times.tool_ms) parts.push(`Tools: ${Math.round(times.tool_ms)}ms`);
        if (parts.length) {
            addDetail(parts.join(' | '));
        }

        // Tokeny i koszt
        if (usage.input_tokens || usage.output_tokens) {
            const inT = usage.input_tokens || 0;
            const outT = usage.output_tokens || 0;
            const cost = estimateCost(inT, outT, diag.model_used);
            addDetail(
                `<span>Tokeny: ${inT} in / ${outT} out</span> ` +
                `<span class="console-entry__cost">(~$${cost.toFixed(4)})</span>`
            );
        }

        // Tool calls
        const searchDiags = diag.search_diagnostics || [];
        for (const sd of searchDiags) {
            let simRange = '';
            if (sd.min_similarity != null && sd.max_similarity != null) {
                simRange = `, sim ${sd.min_similarity.toFixed(2)}-${sd.max_similarity.toFixed(2)}`;
            }
            addDetail(
                `<span class="console-entry__tools">Tools: ${sd.tool} ` +
                `(${sd.result_count} wynikow${simRange})</span>`
            );
        }

        // Knowledge gap
        if (diag.knowledge_gap) {
            addDetail(`<span class="console-entry__gap">Knowledge gap: TAK</span>`);
        }

        // Zapisz raw JSON
        setRawJson(data);
        scrollToBottom();
    }

    /**
     * Ustawia surowy JSON do podgladu.
     */
    function setRawJson(data) {
        lastRawJson = data;
        if (jsonVisible) {
            renderJson();
        }
    }

    // --- Prywatne helpery ---

    function ensureClean() {
        const empty = logEl.querySelector('.console-empty');
        if (empty) empty.remove();
    }

    function addLine(html) {
        const el = document.createElement('div');
        el.className = 'console-entry';
        el.innerHTML = html;
        logEl.appendChild(el);
        entries++;
    }

    function addDetail(html) {
        const el = document.createElement('div');
        el.className = 'console-entry__detail';
        el.innerHTML = html;
        // Dodaj do ostatniego entry
        const last = logEl.lastElementChild;
        if (last) {
            last.appendChild(el);
        }
    }

    function trimEntries() {
        const children = logEl.querySelectorAll('.console-entry');
        while (children.length > MAX_ENTRIES) {
            logEl.removeChild(children[0]);
        }
    }

    function scrollToBottom() {
        logEl.scrollTop = logEl.scrollHeight;
    }

    function timestamp() {
        const d = new Date();
        return d.toLocaleTimeString('pl-PL', { hour12: false }) +
            '.' + String(d.getMilliseconds()).padStart(3, '0');
    }

    function formatLine(text, type) {
        const ts = timestamp();
        const cls = type === 'error' ? 'console-entry__gap' : '';
        return `<span class="console-entry__time">[${ts}]</span> <span class="${cls}">${escHtml(text)}</span>`;
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function truncate(s, max) {
        return s.length > max ? s.substring(0, max) + '...' : s;
    }

    /**
     * Szacuje koszt na podstawie modelu i tokenow.
     * Ceny przyblizne ($/1M tokens): input/output
     */
    function estimateCost(inTokens, outTokens, model) {
        const prices = {
            'gpt-4.1':               { in: 2.0, out: 8.0 },
            'gpt-5-mini':            { in: 0.25, out: 2.0 },
            'gpt-5.2':              { in: 1.75, out: 14.0 },
            'claude-sonnet-4-6':     { in: 3.0, out: 15.0 },
            'claude-haiku-4-5':      { in: 0.8, out: 4.0 },
            'claude-opus-4-6':       { in: 15.0, out: 75.0 },
        };
        const p = prices[model] || { in: 3.0, out: 15.0 };
        return (inTokens * p.in + outTokens * p.out) / 1_000_000;
    }

    function renderJson() {
        if (!lastRawJson) {
            jsonViewer.textContent = '(brak danych)';
            return;
        }
        jsonViewer.innerHTML = syntaxHighlight(JSON.stringify(lastRawJson, null, 2));
    }

    function syntaxHighlight(json) {
        return json.replace(
            /("(?:\\.|[^"\\])*")\s*:/g,
            '<span class="json-key">$1</span>:'
        ).replace(
            /:\s*("(?:\\.|[^"\\])*")/g,
            ': <span class="json-string">$1</span>'
        ).replace(
            /:\s*(\d+\.?\d*)/g,
            ': <span class="json-number">$1</span>'
        ).replace(
            /:\s*(true|false)/g,
            ': <span class="json-bool">$1</span>'
        ).replace(
            /:\s*(null)/g,
            ': <span class="json-null">$1</span>'
        );
    }

    // --- Event listeners ---

    btnToggle.addEventListener('click', function () {
        jsonVisible = !jsonVisible;
        jsonWrapper.classList.toggle('hidden', !jsonVisible);
        btnToggle.textContent = jsonVisible ? 'Ukryj JSON' : 'Pokaz JSON';
        if (jsonVisible) renderJson();
    });

    btnCopy.addEventListener('click', function () {
        if (!lastRawJson) return;
        navigator.clipboard.writeText(JSON.stringify(lastRawJson, null, 2)).then(function () {
            const orig = btnCopy.textContent;
            btnCopy.textContent = 'Skopiowano!';
            setTimeout(function () { btnCopy.textContent = orig; }, 1500);
        });
    });

    btnClear.addEventListener('click', function () {
        logEl.innerHTML = '<div class="console-empty">Brak logow</div>';
        entries = 0;
    });

    // Toggle sekcji (collapse/expand)
    document.querySelectorAll('[data-toggle]').forEach(function (header) {
        header.addEventListener('click', function (e) {
            if (e.target.closest('.btn')) return; // Nie zwijaj po kliknieciu przycisku
            const bodyId = header.getAttribute('data-toggle');
            const body = document.getElementById(bodyId);
            if (body) {
                body.classList.toggle('collapsed');
                header.classList.toggle('collapsed');
            }
        });
    });
})();
