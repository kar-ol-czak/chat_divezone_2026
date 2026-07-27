# CHAT-T-124 — Widget: render `~~przekreślenie~~` (GFM strikethrough) w renderMarkdown

**Status:** DONE (kod front). Deploy = ręczny rsync Karola do newtmp2 (ŚWIAT 2) + czyszczenie cache — patrz Wynik.
**Instancja:** frontend | **Powiązane:** CHAT-T-115 (link/cena wg języka), ADR-093 (reduction_tax), SystemPrompt (price_before_discount). **Decyzja:** 36a (pokazywać przekreśloną cenę — element marketingowy, kotwica cenowa).

## Kontekst
Model formatuje starą cenę jako `~~5 670 zł~~` (GFM strikethrough) obok ceny aktualnej. Stara cena to REALNA promocja z `pr_specific_price` (MysqlProductEnrichmentService, ADR-093) — NIE fabrykacja. SystemPrompt zwraca `price` (aktualna) + `price_before_discount` (przed rabatem), model przekreśla tę drugą. Problem: `renderMarkdown` w `widget-bundle.js` (linia ~310) to minimalny parser (bold/list/link/paragraf) BEZ reguły na `~~`, więc klient widzi dosłowne tyldy zamiast przekreślenia. Reprodukcja: dowolna odpowiedź z ceną promocyjną (np. Shearwater Teric).

## Zakres
1. **Dodaj regułę strikethrough w `renderMarkdown`** (obok reguły bold z linii ~321): `safe = safe.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');`. Umieść PO escapowaniu HTML (jak bold), PRZED przetwarzaniem paragrafów/list. Kolejność względem bolda bez znaczenia (rozłączne znaczniki), ale trzymaj spójny styl z istniejącym kodem.
2. **CSS:** upewnij się, że `<del>` renderuje się czytelnie w bąblu bota (`widget.css`). Domyślny `text-decoration: line-through` przeglądarki wystarcza; jeśli reset CSS go zjada, dodaj jawnie `.dz-bubble--bot del { text-decoration: line-through; opacity: .7; }` (opacity dyskretnie odróżnia starą cenę). Bez nowych zależności.
3. **Guard:** regex `[^~\n]+` (bez zagnieżdżeń, bez wieloliniowości) — jak bold. Nie parsuj `~` pojedynczego (tylde w tekście typu "~5 dni" NIE ma być ruszana; `~~` podwójne wymagane). Zweryfikuj że `~5 670` (pojedyncza tylda, np. "około") NIE jest przekreślane.

## Uwaga
- Zmiana czysto prezentacyjna, front-only. Backend/prompt bez zmian (cena już prawdziwa).
- Cache po deploy: po rsync do newtmp2 wyczyścić var/cache/prod + LSCache; test w incognito (stary bundle w cache przeglądarki).
- Deploy = ręczny rsync Karola do ~/public_html/newtmp2/modules/divezone_chat/views/js/ (widget-bundle.js [+ widget.css jeśli pkt 2 dotknięty]).

## Definicja ukończenia
- `~~5 670 zł~~` renderuje się jako przekreślenie w bąblu bota.
- Pojedyncza tylda `~` w tekście nietknięta.
- Reprodukcja (Shearwater Teric z promocją) pokazuje starą cenę przekreśloną, aktualną normalnie.

## Wynik (2026-07-02, CC)
1. **`renderMarkdown`** (`widget-bundle.js`): dodana reguła strikethrough tuż po boldzie —
   `safe = safe.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');` (po escape HTML, przed listami/paragrafami).
2. **CSS** (`widget.css`): jawny `.dz-bubble--bot del { text-decoration: line-through; opacity: .7; }`
   (odporność na reset + dyskretne wyszarzenie starej ceny jako kotwicy cenowej).
3. **Guard** zweryfikowany (node, 6 przypadków): `~~5 670 zł~~` → `<del>5 670 zł</del>`;
   pojedyncza tylda `~5 dni` / `~5 670 zł` ("około") NIETKNIĘTA; bez zagnieżdżeń/wieloliniowości (jak bold).
   `node --check` OK.

**Deploy (ŚWIAT 2 — nie zrobione, do Karola):** rsync `widget-bundle.js` **oraz** `widget.css` do
`~/public_html/newtmp2/modules/divezone_chat/views/{js,css}/`; po rsync skasować `var/cache/prod` + LSCache;
test w incognito/twardy refresh (cache-busting `?v=md5_8` pomaga, ale bundle siedzi w cache przeglądarki).
