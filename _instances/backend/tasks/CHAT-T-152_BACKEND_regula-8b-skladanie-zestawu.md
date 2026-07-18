# CHAT-T-152 — BACKEND: Przepisanie reguły 8b — dobór zestawu automatu ze składania komponentów

**Status:** DO WYKONANIA
**Instancja:** backend (PHP)
**Powiązane:** ADR-130, CHAT-T-131 (reguła 8b — przepisujemy), ADR-089 (STOP-gate)
**Karta Trello:** Chat - 14 | **Zależność:** Sklep - 43 (źródłowy fix, poza zakresem)

---

## ŚWIAT WDROŻENIOWY

**BACKEND `chat.divezone.pl`.** WYŁĄCZNIE `src/Chat/SystemPrompt.php`.
**ZERO narzędzi. ZERO `config/tools.php` (omijamy dryf Chat - 42). ZERO migracji. ZERO re-embedu.** Deploy jednego pliku.

---

## KONTEKST (ustalenia architekta na PROD MySQL 2026-07-18, pomiar)

Karta Chat - 14 zakładała „filtr kat. 416 + re-embed". Weryfikacja obaliła założenia:
1. `category_name` bierze się z KONKATENACJI `pr_category_product` (ADR-122), NIE z `id_category_default`. „Zestawy rekreacyjne" JEST już w embeddingach 13/13 zestawów. Re-embed i zmiana `id_category_default` NIEpotrzebne.
2. **12 z 13 zestawów kat. 416 ma stan 0 lub ujemny** (brak SKU w Subiekcie — sklejka, Sklep - 43). Filtr kat. 416 prowadziłby na fałszywe „na zamówienie".

Dlatego: bot składa komplet z KOMPONENTÓW (mają realny stan), nie prowadzi na sklejkę.

**Dane referencyjne (stan na 2026-07-18, mogą się zmienić — to ilustracja, nie hardcode):**
- Pojedyncze octopusy (NIE zestawy): APEKS ATX40 Octopus 710 zł, SCUBAPRO R105 1170 zł, XTX40 Octopus 1214 zł.
- Bazowe zestawy (I+II st.+octopus): ATX40/DS4+Octopus 2390 zł, Helix Pro+Octopus 2936 zł, XTX50/DST+Octopus 3566 zł. Wyjątek nazwy: 6485 „MTX-RC **Zestaw** z Octopusem" 4999 zł (bez `/` `+`, ale słowo „Zestaw").
- **Luka cenowa: 1214 (najdroższy octopus) → 2390 (najtańszy zestaw). Próg ~2000 zł czysty.**
- Manometry dostępne: TERMO 300bar/60cm 249 zł (21 szt.) = typowy rekreacyjny. Wykluczyć: „pony" (108 zł, do butli zapasowej), tlenowy/O2 (239 zł), krótki wąż 15cm (sidemount).

---

## KROK 0 — PULL / READ

1. `git pull --rebase origin main`, `git status`, gałąź.
2. Przeczytaj **ADR-130** (`_docs/10_decyzje_projektowe.md`, koniec).
3. Przeczytaj OBECNĄ regułę 8b w `src/Chat/SystemPrompt.php` (linie ~625-633, nagłówek „GOTOWY ZESTAW AUTOMATU = ZESTAW Z MANOMETREM"). Zrozum, co zachowujemy.
4. `git log --oneline -5` — konwencja commitów.

## KROK 1 — Przepisz regułę 8b w `SystemPrompt.php`

**ZACHOWAJ (bez zmian merytorycznych):**
- Definicja: „gotowy zestaw" automatu = I stopień + II stopień + octopus + MANOMETR (lub konsola).
- NIGDY nie przedstawiaj zestawu bez manometru jako „gotowego" bez zaznaczenia, że manometr dokupujemy i montujemy przy odbiorze.
- Praktyka sklepu: montaż manometru przy odbiorze (odsyłacz do reguły NOWY AUTOMAT).

**ZMIEŃ / DODAJ:**

**(a) Rozpoznanie intencji (decyzja 161c).** Reguła odpala się nie tylko na „gotowy/kompletny zestaw", ale też na: „kompletny automat", „automat w zestawie", „automat z manometrem", „automat z octopusem", „wszystko gotowe do nurkowania", „potrzebuję automatu żeby zacząć". Jawnie zaznacz: słowo „rekreacyjny" ani „zestaw" NIE jest wymagane — liczy się intencja kompletu do nurkowania.

**(b) Główna ścieżka: składaj z komponentów, NIE ze sklejki (164c).** Kolejność:
1. Znajdź BAZOWY ZESTAW automat+octopus NA STANIE (kryterium niżej), posortowany po cenie/dopasowaniu do budżetu jeśli klient go podał.
2. Dobierz JEDEN manometr — najtańszy PASUJĄCY na stanie (kryterium niżej).
3. Przedstaw jako komplet: „automat X (dostępny) + manometr Y (dostępny), razem [suma]. Manometr montujemy przy odbiorze — odbierasz gotowy do nurkowania zestaw."
4. NIE prowadź klienta na produkt-sklejkę z kat. 416 (te pokazują fałszywe „na zamówienie", bo nie mają SKU — Sklep - 43). Jeśli akurat trafisz na gotowy zestaw z realnym stanem dodatnim, możesz go pokazać, ale domyślnie składaj z komponentów.

**(c) Kryterium BAZOWEGO ZESTAWU (decyzja 166a) — odróżnienie od pojedynczego octopusa.** Produkt jest bazowym zestawem, gdy SPEŁNIA OBA:
- nazwa zawiera `/` lub `+` (oznaczenie dwóch stopni, np. „ATX40 / DS4") LUB słowo „zestaw"/„set",
- ORAZ cena brutto ≥ ~2000 zł.
Sam octopus (np. „APEKS ATX40 Octopus" 710 zł, „SCUBAPRO Octopus R105" 1170 zł) to NIE zestaw — to dodatkowy automat awaryjny. Nie proponuj pojedynczego octopusa jako kompletnego automatu. Wyszukiwanie: `search_products` z `category="Automaty Oddechowe"`, `in_stock_only=true`, `query` opisujący zestaw automatu.

**(d) Kryterium MANOMETRU (decyzja 165a) — jeden, najtańszy pasujący.** Proponuj DOKŁADNIE JEDEN manometr: najtańszy dostępny PASUJĄCY. „Pasujący" = manometr rekreacyjny do głównego zestawu. WYKLUCZ: manometr „pony" (do butli zapasowej), „tlenowy"/„O2"/„oxygen" (do nitroksu), z krótkim wężem 15 cm (sidemount/techniczny). Szukaj: `search_products` z `query="manometr"`, `in_stock_only=true`; z wyników odrzuć wykluczone i weź najtańszy. Dopiero gdy klient dopyta „są inne manometry?" — pokaż resztę dostępnych (bez wykluczonych).

**(e) Fallback (161c).** Jeśli brak bazowego zestawu na stanie w budżecie klienta → dawne obejście: `category="Automaty Oddechowe"` + `exact_keywords=["manometr"]` (druga próba `["konsola"]`), i pokaż co jest. Jeśli i to puste → ucz się, powiedz uczciwie co dostępne i zaproponuj kontakt/dobór indywidualny.

## KROK 2 — Testy

`SystemPrompt` nie ma zwykle testów jednostkowych; jeśli projekt trzyma testy reguł — dopisz. W przeciwnym razie weryfikacja przez realny czat w KROK 4.
`ea-php84 -l src/Chat/SystemPrompt.php` clean.

## KROK 3 — STOP. Deploy (ADR-089)

**Nie wykonuj bez „deployuj".**
- Świat BACKEND. JEDEN plik: `src/Chat/SystemPrompt.php`.
- Backup `_deploy_bak/CHAT-T-152/` (md5 .bak==prod przed), rsync per ścieżka, md5 prod==local, `ea-php84 -l` clean, `/api/health` 200.
- **NIE dotykaj `config/tools.php`** (dryf Chat - 42). Ten task go nie potrzebuje.

## KROK 4 — Test PROD (realny czat, reguła E)

Scenariusze (HMAC, oznacz `[test CHAT-T-152, nie klient]`, verdict/updated_by NULL):
1. „chcę kompletny automat z manometrem" → bazowy zestaw dostępny + JEDEN najtańszy pasujący manometr, suma, wzmianka o montażu. NIE octopus solo, NIE manometr pony.
2. „potrzebuję automatu w zestawie, budżet 3000 zł" → zestaw ≤ budżet + manometr, suma w budżecie.
3. „chcę zacząć nurkować, co potrzebuję do oddychania" (bez słów „zestaw"/„rekreacyjny") → reguła się odpala (intencja).
4. „a są inne manometry?" po propozycji → pokazuje resztę dostępnych bez pony/tlenowego.
5. Kontrola: „ile kosztuje sam octopus APEKS ATX40" → podaje octopus jako octopus, NIE jako zestaw.

## KROK 5 — Status + raport

1. `_docs/21_STATUS_PROJEKTU.md` — NA GÓRZE.
2. git: `git status`, `git add` per ścieżka, commit `CHAT-T-152 backend: przepisanie reguly 8b - skladanie zestawu z komponentow (ADR-130)`, `git push origin main`. Po deployu osobny commit `docs:`.
3. Raport jako recenzja: co zweryfikowane czym, rozbieżności task↔realne dane (ceny/stany mogły się zmienić od 2026-07-18 — sprawdź aktualne przy testach).

---

## CZEGO NIE RUSZAĆ

- `config/tools.php` — dryf Chat - 42, ten task go nie dotyka.
- Żadne narzędzia (`src/Tools/*`) — to zmiana wyłącznie promptu.
- Railway PG, embeddingi — ZERO re-embedu (kategoria już jest w danych).
- `id_category_default`, HTAccess, linki produktów — NIE zmieniamy (cała idea obejścia).
- `standalone/` blanket-rsync; `config/routes.php`; `purge_litespeed.php` (SEKRET).
- ADR-y — pisze architekt.

---

**Instancja: BACKEND (PHP)**
