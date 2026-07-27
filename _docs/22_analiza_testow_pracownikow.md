# Analiza testów pracowników (Arkusz2) — 2026-05-15

**Źródło:** divezone_chat_testy_-_Arkusz2.csv, 41 testów, testerzy JANEK/BARTAS/GRZEGORZ
**Stan czatu w testach:** PRZED pakietem T-003…T-012 (część uwag już zaadresowana)

## Klasyfikacja 41 uwag

### JUŻ NAPRAWIONE (przez T-003…T-012) — do re-testu

| ID | Uwaga | Naprawione przez |
|---|---|---|
| 6 | Santi suchy "nie mamy" → polecił Avatary | T-006 (availability out_of_stock=2) + T-007 (mix statusów) |
| 14 | Logbook → zrozumiał literalnie, zwrócił książki | T-003 patch D (Logbooki vs Książki nurkowe) |
| 32 | "Przepraszam, nie udało się wygenerować" (brak tekstu) | prawdopodobnie transient API error, re-test |
| 42 | Shearwater "niedostępny" mimo dostępności | T-006 |
| 49,52 | Bot ma wątpliwość co do "skrzydło" (pyta o oczywiste) | T-012 patch v6 (NIE dopytuj o doprecyzowane) — częściowo |
| 119 | EN "discount two suits" → odpowiedź PL + nie zrozumiał suchy | T-003 patch B (język) — re-test, ale patrz BUG JĘZYK niżej |

### BUGI POTWIERDZONE — NOWE (nie zaadresowane)

| ID | Priorytet | Bug | Typ |
|---|---|---|---|
| 23 | P2 | "pink mask for child" → szukał child OR pink zamiast child AND pink | search logic (boolean) |
| 23,45,58,59,70,119 | P1 | EN pytanie → odpowiedź PL (regresja patch B, nie działa w wielu przypadkach) | prompt/lang detection |
| 52 | P1 | Miflex HP wąż → bot pyta o model (jest jeden typ) + bredzi o DIN/INT końcówkach węża | prompt + INT/DIN |
| 65,66,70 | P1 | Stawki wysyłki HARDCODED i BŁĘDNE (15,99/14,99/12,99/499 zł) + brak pobrania | dane wysyłki |
| 124,121 | P2 | "mogę zarezerwować" / sugestie rezerwacji — sklep NIE rezerwuje bez zamówienia | prompt |

### UWAGI MERYTORYCZNE (jakość rekomendacji, nie bugi techniczne)

| ID | Uwaga | Akcja |
|---|---|---|
| 1 | Maski do nurkowania — nie wspominać o pełnotwarzowych do snorkelingu; miniatury pokazują pełnotwarzowe | prompt + search ranking |
| 2 | Komputery — pokazał tylko Eon Core, brak Suunto Nautic/Ocean (Garmin killer) | Editorial Picks (boost) |
| 6 | Santi → polecił Avatar (inny brand) bez info o Santi na zamówienie | prompt (brand fidelity) |
| 8 | Prezenty — dodać voucher + ograniczyć drobnicę od 30-70 zł | prompt + price floor |
| 11 | Manometr — brak Thermo 2K, błędna sugestia manometru kontrolnego | Editorial Picks + prompt |
| 15 | Aquazone → BLACKLISTA marki (firma zniknęła, wadliwe sztuki) | NOWA funkcja blacklist |
| 26,119 | Latarki budżet 700 zł → pokazał ładowarki/uchwyty za 60 zł | price floor (±% od budżetu) |
| 39 | Snorkeling junior — brak pytania o budżet + link do kat. juniorskiej | prompt |
| 29,36,103 | Brak CTA/linków do kategorii, brak plusów/minusów modeli | prompt |
| 42 | Shearwater — istnieje też Peregrine TX | encyklopedia/dane |
| 47 | Ammonite latarki główne nie znalezione (tylko akumulatory) | search/dane |
| 51 | Latarki nocne — dopytać o wody, konkretne rozwiązania | prompt |
| 55 | Suunto Tank POD baterie — kierować do dedykowanych zestawów | prompt + dane |
| 57 | Montaż karabinka → ściana tekstu | prompt (zwięzłość) |
| 59 | Apeks ATX40/DS4 → DS4 to I stopień nie II; nie podaje od razu ceny | encyklopedia + prompt |
| 60,71 | Po poprawnej odpowiedzi dodaje zbędne (manometr, adres firmy) | prompt (zwięzłość) |
| 73 | Brak maila potwierdzenia → pyta o numer zamówienia (którego klient nie ma) | prompt (logika) |
| 102 | Płetwy — sugeruje konkretny kolor/rozmiar bez pytania; stan magazynowy wariantu | search (combination stock) |
| 58,59 | EN: brak info o czystości tlenowej >21% O2 w EU (M26) | prompt (wiedza ekspercka) |

## Wnioski — grupowanie w taski

**GRUPA A — Krytyczne dane (P1):**
- Wysyłka: stawki HARDCODED i błędne → potrzebny prawdziwy cennik z PrestaShop (pr_carrier) lub config. Testy 65,66,70.
- Język EN: regresja, w wielu testach odpowiada PL. Patch B nie wystarcza. Testy 23,45,58,59,70,119.

**GRUPA B — Nowe funkcje:**
- Blacklista marek (Aquazone) — filtr w search. Test 15.
- Price floor / tolerancja budżetu (±% albo min od wartości) — by uniknąć ładowarki przy budżecie 700 zł. Testy 8,26.

**GRUPA C — Prompt refinement (jakość):**
- Zwięzłość (ściany tekstu, zbędne dodatki) — testy 57,60,71
- Brand fidelity (Santi→Avatar) — test 6
- CTA/linki do kategorii — testy 29,36,39
- Plusy/minusy modeli w porównaniach — testy 103,51
- Logika kontekstu (brak maila→pyta o numer; rezerwacje) — testy 73,121,124
- Wiedza ekspercka (DS4 stopień, M26 oxygen clean, Miflex jeden typ) — testy 52,58,59

**GRUPA D — Search/dane:**
- Boolean AND vs OR (child AND pink) — test 23
- Combination stock (wariant koloru/rozmiaru) — test 102
- Editorial Picks zastosowanie (Suunto Ocean, Thermo 2K, Nautic) — testy 2,11
- Encyklopedia gaps (Peregrine TX, Ammonite latarki) — testy 42,47
