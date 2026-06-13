/**
 * DiveZone Sidebar Persist v3 (T-035).
 *
 * REALNA STRUKTURA PS 1.7 sidebar (z nav_bar.tpl):
 *
 *   <ul class="main-menu">
 *     <li class="link-levelone" id="tab-AdminDashboard">         <!-- Pulpit, top-level Z ikona -->
 *     <li class="category-title" data-submenu="2" id="tab-SELL">
 *         <span class="title">SPRZEDAZ</span>
 *     </li>
 *     <li class="link-levelone" id="tab-AdminOrders">              <!-- "dziecko" SPRZEDAZ, siblingi -->
 *     <li class="link-levelone" id="tab-AdminCatalog">             <!-- jw -->
 *     <li class="category-title" data-submenu="42" id="tab-IMPROVE">
 *         <span class="title">ULEPSZENIA</span>
 *     </li>
 *     <li class="link-levelone" id="tab-AdminParentModulesSf">     <!-- "dziecko" ULEPSZENIA -->
 *     <li class="link-levelone" id="subtab-AdminDivezoneChat">     <!-- jw -->
 *     ...
 *
 * Top-level BEZ ikony (SPRZEDAZ/ULEPSZENIA/KONFIGURUJ/itp) sa `category-title`,
 * tylko teksty naglowkowe. PS NIE MA dla nich wbudowanej akordion/collapse
 * logiki. "Dzieci" sa SIBLINGAMI (do nastepnego .category-title).
 *
 * Nasz modul:
 * 1. Inject male ikony strzalki (material-icons) do kazdej .category-title
 *    przy initialization — wizualny sygnal ze sekcja jest klikalna.
 * 2. Capture-phase click handler na document — odpala sie PRZED handlers PS.
 * 3. Toggle siblings od .category-title az do nastepnego .category-title
 *    (lub konca .main-menu), slideUp/slideDown animacja.
 * 4. Persist stanu (lista zwinietych section_id) w localStorage.
 *
 * Hook: displayBackOfficeHeader. Zero modyfikacji PS core.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'dz_sidebar_collapsed_categories';
    var COLLAPSE_CLASS = 'dz-collapsed';
    var ARROW_CLASS = 'dz-collapse-arrow';
    var $ = window.jQuery || window.$;
    if (!$) {
        if (window.console) console.warn('[divezone_sidebar] jQuery missing, abort');
        return;
    }

    function readState() {
        try {
            var raw = window.localStorage.getItem(STORAGE_KEY);
            if (!raw) return [];
            var arr = JSON.parse(raw);
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Pierwsza wizyta = klucz w localStorage jeszcze NIE istnieje (null).
     * Wtedy default-collapse-all: zwijamy wszystkie sekcje i persystujemy stan,
     * zeby kolejne load-y od razu uzywaly localStorage (uzytkownik moze potem
     * rozwijac wybrane sekcje, kazda zmiana jest natychmiast zapisywana).
     * Klucz '[]' (pusta lista) = swiadoma decyzja "nic nie zwiniete" — wtedy
     * nie nadpisujemy.
     */
    function isFirstVisit() {
        try {
            return window.localStorage.getItem(STORAGE_KEY) === null;
        } catch (e) {
            return false;
        }
    }

    function writeState(arr) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
        } catch (e) {}
    }

    function getSectionId($cat) {
        return $cat.attr('data-submenu') || $cat.attr('id') || null;
    }

    /**
     * Sibling traversal: od $category w prawo, az do nastepnego .category-title
     * (exclusive) lub konca listy. Zwraca jQuery z odnalezionymi siblingami.
     */
    function getCategoryChildren($category) {
        var children = [];
        var el = $category[0].nextElementSibling;
        while (el) {
            if (el.classList && el.classList.contains('category-title')) {
                break;
            }
            children.push(el);
            el = el.nextElementSibling;
        }
        return $(children);
    }

    function setArrow($category, collapsed) {
        var $arrow = $category.find('.' + ARROW_CLASS);
        if (!$arrow.length) return;
        $arrow.text(collapsed ? 'expand_more' : 'expand_less');
    }

    function collapseCategory($category, animate) {
        if ($category.hasClass(COLLAPSE_CLASS)) return;
        $category.addClass(COLLAPSE_CLASS);
        var $children = getCategoryChildren($category);
        if (animate) {
            $children.stop(true, true).slideUp(180);
        } else {
            $children.hide();
        }
        setArrow($category, true);
    }

    function expandCategory($category, animate) {
        if (!$category.hasClass(COLLAPSE_CLASS)) return;
        $category.removeClass(COLLAPSE_CLASS);
        var $children = getCategoryChildren($category);
        if (animate) {
            $children.stop(true, true).slideDown(180);
        } else {
            $children.show();
        }
        setArrow($category, false);
    }

    function persistCollapsedCategories() {
        var collapsed = [];
        $('.nav-bar li.category-title.' + COLLAPSE_CLASS).each(function () {
            var id = getSectionId($(this));
            if (id) collapsed.push(id);
        });
        writeState(collapsed);
    }

    /**
     * Inject male strzalka ikona obok tytulu kazdej .category-title +
     * marker (data-dz-init) zeby uniknac duplicate injection przy
     * re-bind (np. po PS bundle DOMReady).
     */
    function injectArrows() {
        $('.nav-bar li.category-title').each(function () {
            var $cat = $(this);
            if ($cat.attr('data-dz-init') === '1') return;
            $cat.attr('data-dz-init', '1');
            var $title = $cat.find('.title').first();
            // dodaj strzalke jako span po .title — domyslnie "expand_less" (rozwinieta)
            $cat.append(
                '<i class="material-icons ' + ARROW_CLASS + '" aria-hidden="true">expand_less</i>'
            );
        });
    }

    function restoreState() {
        var saved = readState();
        if (!saved.length) return;
        $('.nav-bar li.category-title').each(function () {
            var $cat = $(this);
            var id = getSectionId($cat);
            if (id && saved.indexOf(id) !== -1) {
                collapseCategory($cat, false); // bez animacji przy load
            }
        });
    }

    function collapseAllCategories() {
        $('.nav-bar li.category-title').each(function () {
            collapseCategory($(this), false); // bez animacji przy initial collapse
        });
    }

    /**
     * Capture-phase listener — odpala sie PRZED kazdym direct handler.
     * stopImmediatePropagation blokuje PS handlers, jesli by sie zarejestrowaly.
     */
    function handleCaptureClick(e) {
        // Znajdz najblizszy ancestor .category-title (akceptuje klik gdziekolwiek na pasku)
        var t = e.target;
        var cat = null;
        while (t && t !== document) {
            if (t.classList && t.classList.contains('category-title')) {
                cat = t;
                break;
            }
            t = t.parentNode;
        }
        if (!cat) return;
        if (!cat.closest('.nav-bar')) return;

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var $cat = $(cat);
        if ($cat.hasClass(COLLAPSE_CLASS)) {
            expandCategory($cat, true);
        } else {
            collapseCategory($cat, true);
        }
        persistCollapsedCategories();
    }

    document.addEventListener('click', handleCaptureClick, true);

    // Inject + restore po DOMReady ($ ready). Jesli juz po — wykona sie od razu.
    $(function () {
        injectArrows();
        if (isFirstVisit()) {
            // Pierwsze uruchomienie aplikacji: default = wszystko zwiniete.
            // Po pierwszym toggle persist zapisuje aktualny stan, kolejne load-y
            // beda uzywaly tego (NIE wracamy do "wszystko zwiniete" za kazdym razem).
            collapseAllCategories();
            persistCollapsedCategories();
        } else {
            restoreState();
        }
    });
})();
