# CHAT-T-075 — FRONTEND: renderowanie markdown w /m (bold, linki, listy) jak na produkcji

**Status:** DONE (2026-06-05). Pełny raport: `_instances/frontend/handoff/CHAT-T-075_done.md`. 8/8 kryteriów PASS. renderMarkdown skopiowany 1:1 z widget-bundle.js (264-321), uzyty escHtml jako funkcjonalny ekwiwalent escapeHtml. XSS lokalny smoke 4 payloady — wszystkie escapowane. Karol potwierdził render PROD: bold/listy/linki klikalne na desktop + telefon.

**Instancja:** frontend (standalone public/m/). CC deployuje SAM. ZERO modułu PS, ZERO backendu PHP.
**Powiązany ADR:** ADR-086. **Zależność:** CHAT-T-072 DONE (/m działa).

## Cel
Widok rozmowy w /m renderuje treść wiadomości tak jak produkcyjny widget na sklepie: **bold**, linki [label](url) jako klikalne, listy, paragrafy. Obecnie /m pokazuje surowy tekst (gwiazdki, gołe URL-e) bo używa escHtml(content) zamiast renderera markdown.

## Problem (zweryfikowany w kodzie)
public/m/app.js, renderDetail() (~linia 423): `'<div class="'+cls+'">' + escHtml(content) + '</div>'` — surowy escape, brak markdown. Stąd `**Pianki**` widać jako gwiazdki, `[link](https://...)` jako tekst.

## Źródło prawdy — renderer produkcyjny (skopiować 1:1)
Widget produkcyjny: modules/divezone_chat/views/js/widget-bundle.js, funkcje escapeHtml() + renderMarkdown() (linie 264-321). Logika (XSS-safe, KOLEJNOŚĆ KRYTYCZNA):
1. escapeHtml(text) NAJPIERW (& < > " ' ) — zabezpieczenie XSS.
2. linki: `[label](url)` tylko http(s)/mailto → `<a href target="_blank" rel="noopener noreferrer">label</a>`.
3. bold: `**...**` → `<strong>`.
4. listy: linie `- ` lub `• ` → `<ul><li>`.
5. paragrafy: grupy linii → `<p>` z `<br>` między liniami; pusta linia = separator.

## KROK 1 — dodać renderMarkdown do app.js
- Skopiować escapeHtml() i renderMarkdown() z widget-bundle.js (linie 264-321) do public/m/app.js. 1:1, bez modyfikacji logiki.
- UWAGA nazewnictwo: app.js ma już escHtml() (własna). Albo (a) użyć istniejącej escHtml jako escapeHtml wewnątrz renderMarkdown (jeśli identyczna — POTWIERDZIĆ że escHtml escape'uje te same 5 znaków: & < > " '), albo (b) wkleić escapeHtml pod inną nazwą i użyć jej w renderMarkdown. REKOMENDACJA: sprawdzić escHtml; jeśli robi te same 5 zamian → renderMarkdown używa escHtml (jedna funkcja, spójność). Jeśli różni się → wkleić escapeHtml z widgetu i użyć jej w renderMarkdown (renderer musi escape'ować PRZED dokładaniem tagów — inaczej XSS).

## KROK 2 — podmiana renderu treści w renderDetail
- Linia ~423: zmienić `escHtml(content)` na `renderMarkdown(content)`:
  `'<div class="' + cls + '">' + renderMarkdown(content) + '</div>'`
- NIE zmieniać filtra (user/assistant only), stringifyContent, ani struktury dymków — TYLKO renderowanie treści.
- Dymki user też przez renderMarkdown? Pytanie: produkcyjny widget renderuje user przez escapeHtml (linia 486: appendUser → escapeHtml), bot przez renderMarkdown (linia 494). W /m: user to pytania klienta (zwykle bez markdown) — bezpieczniej renderMarkdown dla OBU (escape i tak w środku), ALE dla zachowania spójności z produkcją: user = renderMarkdown też OK (escape chroni; user rzadko ma markdown, więc wynik = czysty tekst). REKOMENDACJA: renderMarkdown dla obu (prostsze, XSS-safe, a jeśli user wkleił URL w nawiasach markdown to też zadziała). Jeśli chcesz 1:1 z produkcją — user escHtml, assistant renderMarkdown. Domyślnie: renderMarkdown dla obu.

## KROK 3 — CSS dla nowych elementów
- public/m/styles.css: dodać style dla `.bubble strong`, `.bubble a`, `.bubble ul`, `.bubble li`, `.bubble p` wewnątrz dymków — czytelne na mobile (linki wyróżnione kolorem + podkreślenie/kontrast, listy z wcięciem, <p> margines). Spójne z istniejącym wyglądem dymków. Linki dotykalne (min wysokość, nie za małe).
- Sprawdzić, że <p>/<br> nie psują odstępów w dymku (renderMarkdown owija w <p> — może trzeba zerować margin pierwszego/ostatniego <p> w .bubble).

## Granice
- TYLKO public/m/app.js + public/m/styles.css. ZERO backendu, ZERO modułu PS.
- renderMarkdown XSS-safe (escape przed tagami) — NIE wstrzykiwać surowego content do innerHTML bez escape.
- Nie zmieniać logiki filtra wiadomości, auth, listy, statusów.
- Vanilla (zero bibliotek).

## Kryteria akceptacji
1. Wiadomość assistant z `**tekst**` → pogrubienie (nie gwiazdki).
2. Wiadomość z `[Pianki](https://divezone.pl/...)` → klikalny link otwierający się w nowej karcie (target=_blank, rel=noopener).
3. Lista `- poz1 / - poz2` → wypunktowanie <ul>.
4. Paragrafy / wielolinijkowość zachowane (puste linie separują).
5. XSS: wiadomość zawierająca `<script>` lub `<img onerror>` → wyrenderowana jako tekst, NIE wykonana (escape działa). PRZETESTOWAĆ celowo złośliwy content.
6. Linki i bold czytelne/dotykalne na telefonie (Android Chrome + iPhone Safari).
7. Reszta /m bez regresji (lista, login, status, logout, pull-to-refresh).
8. Renderer logiką identyczny z produkcyjnym widgetem (bold/link/lista/paragraf).

## KROK FINALNY — deploy + raport + status + git
- Deploy: app.js + styles.css do public/m/ na chat.divezone.pl (SCP, jak T-072). STOP przed: raport, deploy, smoke 1-8 (w tym test XSS i telefon).
- Raport: _instances/frontend/handoff/CHAT-T-075_done.md.
- Status: dopisać CHAT-T-075 do _docs/21_STATUS_PROJEKTU.md.
- Git: git status; git add per ścieżka (public/m/app.js, public/m/styles.css, task, handoff); commit wg konwencji; push origin main. Osobny "docs:" dla statusu.
