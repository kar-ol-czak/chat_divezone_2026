/**
 * DiveZone Sidebar Persist (T-035).
 *
 * Modyfikuje zachowanie sidebar menu admina PS 1.7:
 *  1. MULTI-OPEN: kazda sekcja top-level (li.link-levelone.has_submenu) moze
 *     byc niezaleznie otwarta/zamknieta. Domyslny PS handler auto-close
 *     pozostalych — nadpisujemy to.
 *  2. PERSIST: stan otwartych sekcji zapisywany w localStorage, restorowany
 *     przy load strony. Per-przegladarka, per-user.
 *
 * Hook: displayBackOfficeHeader z modulu divezone_sidebar.php — JS ładuje
 * sie tylko w backofficie, frontend sklepu nieruszony.
 *
 * Selektory wziete z _we345_adm/themes/new-theme/template/components/layout/nav_bar.tpl:
 *   .nav-bar                          (kontener sidebar)
 *   li.link-levelone.has_submenu      (sekcja top-level z dziecmi, klikalna)
 *   > a                               (link sekcji, na nim default handler PS)
 *   ul.submenu.panel-collapse         (lista dzieci, slideUp/Down)
 *   .ul-open .open                    (PS markery otwartej sekcji)
 *   i.material-icons.sub-tabs-arrow   (strzalka, keyboard_arrow_up/down)
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'dz_sidebar_open_sections';
    var $ = window.jQuery || window.$;
    if (!$) {
        // jQuery brak — PS 1.7 zawsze go ma w admin, ale defensive.
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

    function writeState(arr) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
        } catch (e) {
            // QuotaExceeded / private mode — silently ignore.
        }
    }

    function getSectionId($li) {
        // data-submenu jest stable (id_tab z PS), fallback na id element.
        return $li.attr('data-submenu') || $li.attr('id') || null;
    }

    function arrowUp($li) {
        $li.find('> a i.material-icons.sub-tabs-arrow').text('keyboard_arrow_up');
    }

    function arrowDown($li) {
        $li.find('> a i.material-icons.sub-tabs-arrow').text('keyboard_arrow_down');
    }

    function openSection($li, animate) {
        if ($li.hasClass('ul-open')) return;
        var $submenu = $li.find('> ul.submenu');
        $li.addClass('ul-open open');
        if (animate) {
            $submenu.stop(true, true).slideDown(200, function () {
                $(this).removeAttr('style');
            });
        } else {
            $submenu.show().removeAttr('style');
        }
        arrowUp($li);
    }

    function closeSection($li, animate) {
        if (!$li.hasClass('ul-open')) return;
        var $submenu = $li.find('> ul.submenu');
        if (animate) {
            $submenu.stop(true, true).slideUp(200, function () {
                $(this).parent().removeClass('ul-open open -hover');
                $(this).removeAttr('style');
            });
        } else {
            $li.removeClass('ul-open open -hover');
            $submenu.hide().removeAttr('style');
        }
        arrowDown($li);
    }

    function persistOpenSections() {
        var open = [];
        $('.nav-bar li.link-levelone.has_submenu.ul-open').each(function () {
            var id = getSectionId($(this));
            if (id) open.push(id);
        });
        writeState(open);
    }

    function restoreState() {
        var saved = readState();
        if (!saved.length) return;
        $('.nav-bar li.link-levelone.has_submenu').each(function () {
            var $li = $(this);
            var id = getSectionId($li);
            if (id && saved.indexOf(id) !== -1) {
                openSection($li, false); // no animacji przy load
            }
        });
    }

    function bindMultiOpenHandler() {
        // Replace default PS handler:
        //  - PS default zamyka wszystkie inne sekcje przed otwarciem klikni¦tej
        //  - my chcemy niezalezne toggle, multi-open
        $('.nav-bar li.link-levelone.has_submenu > a')
            .off('click.dzSidebar')          // wyczysc jesli moduł re-injects
            .off('click')                    // ZWALNIA default PS handler
            .on('click.dzSidebar', function (e) {
                e.preventDefault();
                e.stopPropagation();
                var $li = $(this).parent();
                if ($li.hasClass('ul-open')) {
                    closeSection($li, true);
                } else {
                    openSection($li, true);
                }
                persistOpenSections();
            });
    }

    $(function () {
        // PS bundle (main.bundle.js) wpina swój handler na DOMReady — nasz
        // setup MUSI byc po nim. setTimeout(0) odłozyć do nastepnego ticka
        // event loop, kiedy PS juz zarejestrowal swoj listener.
        setTimeout(function () {
            bindMultiOpenHandler();
            restoreState();
        }, 0);
    });
})();
