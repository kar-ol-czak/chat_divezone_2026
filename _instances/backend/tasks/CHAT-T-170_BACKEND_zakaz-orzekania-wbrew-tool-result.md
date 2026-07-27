# CHAT-T-170 — BACKEND — Zakaz orzekania o wariancie wbrew tool_result get_product_combinations

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl) — TYLKO ten plik
**ADR:** ADR-136 (architekt zapisze przed deployem)

---

## 1. Objaw — bot przekłamał dane, które miał podane wprost

Replay rozmowy `id=833` (2026-07-25, po wdrożeniu CHAT-T-168). Klient pytał
o szkła +3,5 do maski TUSA Intega. Bot wywołał `get_product_combinations`
dla 6994, 6577, 6573 i **napisał odpowiedź sprzeczną z tym, co dostał**:

Bot napisał:
- "MC211 LEWE i PRAWE — moc +3,5 — 179 zł" (dla obu)
- "⚠️ seria BF211 (prawe) nie ma wariantu +3,5"
- komplet: maska 399 + MC211 lewe 179 + MC211 prawe 179 = 757 zł

Co bot faktycznie dostał w tool_result (zweryfikowane w bazie):
- **6994 (BF211 prawe):** wariant `+3,5`, `id_product_attribute 61566` — **JEST**
- **6573 (MC211 prawe):** warianty +1, +1,5, +2, +2,5, +3, +4, +4,5 — **+3,5 NIE MA**
- **6577 (MC211 lewe):** wariant `+3,5`, `id_product_attribute 63639` — jest

Bot odwrócił rzeczywistość: przypisał +3,5 produktowi, który go nie ma (6573),
i odmówił +3,5 produktowi, który go ma (6994). To nie jest brak danych —
to orzeczenie WBREW danym trzymanym w tool_result.

## 2. Współprzyczyna (warstwa A — NIE naprawiamy w tym tasku)

W rozmowie 833 `search_products` nie zwróciło **6993 (BF211 lewe)** — bot widział
z linii BF211 tylko prawe (6994). Bez pary nie mógł złożyć kompletu BF211
i to popchnęło go ku MC211.

**To już nie występuje.** Pomiar na żywo (wdrożony ProductSearch, limit=5,
3 próby, deterministycznie): wyniki = `6577, 6994, 6573, 6993, 7444` — oba BF211
obecne. Między rozmową 833 (05:27 UTC) a pomiarem przeszło nocne odświeżenie
rankingu i 6993 wszedł do top-5. Problem jest KRUCHY (przy 4 bliźniaczych
produktach lewe/prawe kolejność RRF waha się z dnia na dzień), ale dziś nie
szkodzi. Zapisany jako otwarty w ADR-135/136, obserwowany. **Ten task go nie
dotyka** — task naprawia wyłącznie to, że bot orzeka wbrew tool_result,
co jest błędem niezależnym od tego, które produkty wchodzą do wyników.

## 3. Dlaczego istniejące reguły nie wystarczyły

Reguła "PARAMETR LICZBOWY = OBOWIĄZKOWE get_product_combinations" (ADR-134,
linia ~540) każe SPRAWDZIĆ kombinacje i bot je sprawdził. Brakuje drugiej
połowy: **zakazu orzekania niezgodnie z tym, co narzędzie zwróciło.** Bot wykonał
wywołanie i zignorował wynik. Żadna z obecnych reguł tego nie zakazuje wprost.

---

## KROK 0 — pull i lektura

```
git pull --rebase
```

Przeczytaj `standalone/src/Chat/SystemPrompt.php`, sekcja "WARIANTY" (~500-545),
szczególnie regułę "PARAMETR LICZBOWY" — nowa reguła wchodzi bezpośrednio po niej.

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, ADR-ów,
`GetProductCombinations.php` (to zakres T-168, już wdrożony), plików poza
`SystemPrompt.php`.

## KROK 1 — reguła: WIERNOŚĆ tool_result

Wstaw bezpośrednio po regule "PARAMETR LICZBOWY" (~linia 541). Treść merytoryczna
(sformułowanie dostosuj do stylu sąsiednich reguł):

> **NIE ORZEKAJ O WARIANCIE WBREW tool_result (ADR-136).** Gdy podajesz, czy dana
> moc/rozmiar/kolor jest dostępna, opierasz się WYŁĄCZNIE na tablicy `warianty`
> z ostatniego `get_product_combinations` dla TEGO konkretnego produktu (po
> `product_id`). Zanim napiszesz "ma +3,5" lub "nie ma +3,5", znajdź w `atrybuty`
> wariant o tej wartości. Jest → dostępny. Nie ma → napisz, że nie ma.
>
> ZAKAZANE:
> - przypisanie wartości jednego produktu drugiemu (moc z produktu A do produktu B),
> - orzekanie "nie ma", gdy wariant JEST w tool_result,
> - orzekanie "ma", gdy wariantu w tool_result NIE MA.
>
> Sprawdzaj po `product_id`: wynik `get_product_combinations` dotyczy JEDNEGO
> produktu. Nie przenoś jego wariantów na inny produkt o podobnej nazwie.
>
> Bug do uniknięcia (czat 833): bot dostał 6994 (BF211 prawe) z wariantem +3,5
> i 6573 (MC211 prawe) BEZ +3,5, po czym napisał odwrotnie — że MC211 prawe ma
> +3,5, a BF211 nie ma. Odpowiedź myliła klienta co do tego, który produkt kupić.

## KROK 2 — wzmocnienie reguły KOMPLETU (para lewe+prawe)

W istniejącej regule "PARAMETR LICZBOWY", w zdaniu o komplecie, dopisz zasadę
spójności par (dostosuj sformułowanie):

> Gdy składasz komplet z pary lewe+prawe, OBA muszą pochodzić z tej samej serii
> produktowej (obie połówki MC211 albo obie BF211 — nie mieszaj serii). Jeśli
> dla żądanej mocy jedna połówka serii jest dostępna, a druga nie — powiedz to
> wprost zamiast podmieniać serię. Nie zastępuj brakującej połówki połówką
> z innej serii bez wyraźnego zaznaczenia, że to inny produkt.

## KROK 3 — walidacja

```
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```

Sprawdź brak kolizji z regułami "PARAMETR LICZBOWY" i "WARIANTY — ZAWSZE
Z NARZĘDZIA". Sprzeczność → zgłoś w raporcie, nie rozstrzygaj sam.

## KROK 4 — STOP

**STOP przed rsync (ADR-089).** Czekaj na "deployuj".

## KROK 5 — deploy (po autoryzacji)

Świat 1, jeden plik:

```
backup → _deploy_bak/CHAT-T-170/
rsync -e 'ssh -p 5739' standalone/src/Chat/SystemPrompt.php \
  divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/src/Chat/
md5 local ↔ prod
ea-php84 -l na produkcji
smoke: /api/health
```

**Bez `--delete`. Bez blanket-rsync.**

## KROK 6 — status i raport

Dopisz **NA GÓRZE** `_docs/21_STATUS_PROJEKTU.md`.

```
git status
git add standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-170 backend: zakaz orzekania o wariancie wbrew tool_result (ADR-136)"
git push origin main
```
Po deployu osobny commit `docs(...)`.

---

## Kryterium akceptacji (weryfikuje architekt przez replay.py po T-169)

1. Replay pytania z rozmowy 833 → bot podaje +3,5 dla BF211 (produkt, który go ma),
   NIE przypisuje +3,5 produktowi 6573
2. Jeśli oba BF211 w wynikach → bot składa komplet BF211 lewe+prawe, nie MC211
3. Bot nie twierdzi o żadnym wariancie niczego, czego nie ma w tool_result
