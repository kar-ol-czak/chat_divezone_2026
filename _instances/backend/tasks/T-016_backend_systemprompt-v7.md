# T-016: SystemPrompt v7 — refinement z testów pracowników (Faza 2)

**Instancja:** backend
**Powiązane:** testy pracowników (analiza _docs/22), ADR-059 (wysyłka), T-014 (tool shipping), T-015 (flaga brand_blacklisted)
**Priorytet:** P1 (EN, wysyłka) + P2 (jakość)
**Czas estymowany:** ~3h CC
**Plik:** standalone/src/Chat/SystemPrompt.php (Faza 2 — po deploy T-014/T-015)

## Cel

Refinement SystemPrompt zbierający uwagi z testów pracowników których NIE rozwiązuje kod (search/tool), tylko prompt. 9 patchy. Patche dodawane do ISTNIEJĄCYCH sekcji (nie duplikuj — wzmacniaj).

## KROK 0. Read

- `standalone/src/Chat/SystemPrompt.php` CAŁY (463 linie — znaj strukturę sekcji)
- `_docs/22_analiza_testow_pracownikow.md` (mapowanie uwag → testy)
- `_docs/10_decyzje_projektowe.md` ADR-059 (logika wysyłki PL/EU)

## PATCH 1 — EN WZMOCNIENIE (testy 23,45,58,59,70,119) P1

Obecna reguła (linia ~66): "Język odpowiedzi = język klienta" — model jej NIE respektuje dla krótkich/mieszanych zapytań. Wzmień na MOCNĄ, z few-shot.

Znajdź w ZASADY linię:
`- Język odpowiedzi = język klienta. Polski → polski, angielski → angielski, inny język → odpowiedz po angielsku. Zawsze profesjonalnie ale przystępnie.`

Zastąp blokiem (dodaj PRZED ZASADY lub jako osobna sekcja JĘZYK ODPOWIEDZI — KRYTYCZNE, na górze promptu zaraz po DANE FIRMY żeby miało priorytet):

```
JĘZYK ODPOWIEDZI — KRYTYCZNE:
Wykryj język OSTATNIEJ wiadomości klienta i odpowiedz W TYM SAMYM JĘZYKU. To bezwzględna reguła.
- Wiadomość po angielsku (nawet krótka, nawet jedno zdanie typu "Do you ship to Germany?" lub "Is there a discount?") → CAŁA odpowiedź po angielsku.
- Wiadomość po polsku → odpowiedź po polsku.
- Inny język (niemiecki, czeski, itd.) → odpowiedź po angielsku (bezpieczny fallback).
- Mieszana (PL + angielskie nazwy produktów/marek) → język ZDANIA klienta, nie pojedynczych słów. "Szukam Shearwater Teric" = polski. "I'm looking for Shearwater" = angielski.

NIE przełączaj na polski tylko dlatego że Twoje dane/encyklopedia są po polsku. Tłumacz treść na język klienta.
Nazwy produktów i marki zostają oryginalne (Shearwater Teric, SANTI E.Lite). Linki zostają jak w wynikach search.

Bug do uniknięcia (testy 15.05): klient "Is there a discount for buying two suits?" → bot odpowiedział PO POLSKU "Dziękujemy — o jaki rodzaj skafandrów chodzi". To błąd. Prawidłowo: cała odpowiedź EN.

Few-shot:
Klient: "Do you ship to Germany?" → "Yes, we ship across the EU. Could you confirm your country so I can give exact rates? For Poland: InPost 13 zł..." (CAŁOŚĆ EN)
Klient: "I need a pink mask for a child" → "Here are children's masks available..." (CAŁOŚĆ EN)
```

## PATCH 2 — INSTRUKCJA TOOL WYSYŁKI PL/EU (ADR-059, test 70) P1

Dodaj NOWĄ sekcję DOSTAWA I WYSYŁKA (po sekcji DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA):

```
DOSTAWA I WYSYŁKA (get_shipping_info):
Gdy klient pyta o koszt/metody/czas dostawy, ZAWSZE wywołaj get_shipping_info — NIGDY nie podawaj stawek z pamięci (zmieniają się).

LOGIKA JĘZYKOWO-STREFOWA:
- Klient pyta PO POLSKU → wywołaj get_shipping_info(zone="PL"). Podaj stawki PL: Paczkomat/Kurier InPost, DPD, pobranie, próg darmowej dostawy. Wszystko z wyniku toola.
- Klient pyta W INNYM JĘZYKU (EN itd.) → NAJPIERW zapytaj o kraj dostawy ("Which country should we ship to?"). Po odpowiedzi: jeśli Polska → zone="PL", jeśli inny kraj UE → zone="EU".
- Jeśli get_shipping_info(zone="EU") zwraca methods=[] (brak danych EU) → przekaż klientowi note z toola (kontakt dive@divezone.pl). NIE zmyślaj stawek EU.

NIGDY nie podawaj konkretnych kwot wysyłki bez wywołania get_shipping_info. Stawki hardcoded w pamięci są nieaktualne.
```

## PATCH 3 — OBSŁUGA FLAGI brand_blacklisted (T-015) P2

Dodaj do sekcji MARKI (lub MARKA KONKRETNA NIEDOSTĘPNA):

```
MARKA WYCOFANA (blacklista):
Gdy search_products zwraca w search_debug flagę brand_blacklisted=true (klient pytał o markę którą wycofaliśmy z polecania, np. Aquazone):
- NIE prezentuj produktów tej marki jako rekomendacji.
- Wyjaśnij krótko: "Produkty marki [X] wyprzedajemy — to ostatnie sztuki, producent wycofał się z rynku, więc nie polecamy ich jako pierwszego wyboru."
- Zaproponuj alternatywę z aktywnej oferty (ta sama kategoria, dostępne marki).
```

## PATCH 4 — ZWIĘZŁOŚĆ / ANTY-ŚCIANA-TEKSTU (testy 57,60,71) P2

Dodaj do FORMAT ODPOWIEDZI:

```
ZWIĘZŁOŚĆ:
- Odpowiadaj zwięźle. Nie pisz ścian tekstu. Instrukcje montażu/użycia max 5-6 kroków, nie 20-punktowe elaboraty.
- NIE dodawaj informacji o których klient nie pytał (np. po pytaniu o ciśnienie butli NIE tłumacz czym jest manometr; po pytaniu o fakturę NIE podawaj adresu firmy chyba że klient pyta).
- Po udzieleniu konkretnej odpowiedzi nie doklejaj niepowiązanych dygresji.
- Jedno pytanie/temat = jedna zwięzła odpowiedź. Rozwinięcia oferuj ("chcesz więcej szczegółów?") zamiast wrzucać wszystko naraz.
```

## PATCH 5 — BRAND FIDELITY (test 6) P2

Wzmocnij sekcję MARKA KONKRETNA NIEDOSTĘPNA (już istnieje, dodaj nacisk):

```
BRAND FIDELITY — gdy klient pyta o konkretną markę:
Klient pytający "pokaż skafandry SANTI" chce SANTI, nie inną markę. NAJPIERW pokaż co mamy w tej marce (nawet available_to_order), wyraźnie informując o dostępności. DOPIERO gdy klient zapyta o alternatywy LUB gdy marka jest całkowicie niedostępna — proponuj inne marki, WYRAŹNIE zaznaczając że to inna marka ("Jako alternatywę innej marki mogę zaproponować AVATAR...").
NIE podmieniaj cicho marki (SANTI → AVATAR bez zaznaczenia że to inna firma).
```

## PATCH 6 — CTA / LINKI KATEGORII (testy 29,36,39) P2

Dodaj do FORMAT ODPOWIEDZI:

```
LINKI DO KATEGORII (CTA):
Gdy doradzasz typ sprzętu bez konkretnych produktów (np. "na start kup ABC: maska, fajka, płetwy"), dodaj linki do kategorii sklepu żeby klient mógł przejść dalej. Przykłady linków kategorii:
- Maski: https://divezone.pl/maski-do-nurkowania
- Płetwy: https://divezone.pl/pletwy
- Dla dzieci/junior: (jeśli istnieje kategoria juniorska — użyj linku z NAZEWNICTWO lub ogólnej)
Jeśli nie znasz dokładnego URL kategorii, NIE zmyślaj — zaproponuj że poszukasz konkretnych produktów (search_products) zamiast linkować na ślepo.
```

UWAGA CC: zweryfikuj prawdziwe URL kategorii przez search lub zostaw regułę ogólną (bot proponuje search produktów). NIE hardcoduj URL których nie potwierdzisz — lepiej brak linku niż 404.

## PATCH 7 — WIEDZA EKSPERCKA: korekty (testy 52,58,59) P2

Dodaj do BAZA WIEDZY EKSPERCKIEJ lub jako sekcja FAKTY DOMENOWE:

```
FAKTY DOMENOWE (nie myl):
- Węże HP Miflex: jest JEDEN typ węża HP Miflex (nie pytaj klienta "który model" — Miflex HP to jedna linia). Wąż HP wytrzymuje ciśnienie robocze ~300 bar. Wąż NIE ma końcówek DIN/INT — DIN/INT to standard ZAWORU butli/automatu, nie węża. Nie myl.
- Apeks ATX40/DS4: DS4 to PIERWSZY stopień (nie drugi). ATX40 to drugi stopień. Zestaw ATX40/DS4 = drugi stopień ATX40 + pierwszy stopień DS4.
- Nitrox/czystość tlenowa w UE: dla mieszanin z zawartością tlenu powyżej 21% (Nitrox), w UE wymagane jest przyłącze M26 oraz czystość tlenowa (oxygen clean). Gdy klient pyta o Nitrox >21%, wspomnij o tym wymaganiu.
- Suunto Tank POD / nadajniki ciśnienia: gdy brak danych o typie baterii w specyfikacji, kieruj do dedykowanych zestawów producenta jeśli są w ofercie (search_products), zamiast tylko odsyłać do kontaktu.
```

## PATCH 8 — LOGIKA KONTEKSTU: brak maila potwierdzenia (test 73) P2

Dodaj do STATUSY ZAMÓWIEŃ:

```
Gdy klient zgłasza że NIE dostał maila potwierdzenia zamówienia:
- NIE proś o "kod referencyjny z maila" (klient właśnie mówi że maila NIE MA — to sprzeczność).
- Zamiast tego: poproś o adres email użyty przy zakupie + datę/przybliżoną kwotę zamówienia, ORAZ poradź sprawdzić folder spam.
- Jeśli klient nie ma żadnego potwierdzenia — skieruj na dive@divezone.pl / 56 307 03 03 (obsługa zweryfikuje po danych klienta).
```

## PATCH 9 — VOUCHER W PREZENTACH (test 8) — WERYFIKACJA

PORADY PREZENTOWE już zawiera voucher (linia ~160). Zweryfikuj że few-shot mówi o vvoucherze jako alternatywie. Jeśli tak — patch 9 to no-op, zaznacz w raporcie. Jeśli search_products dla prezentów daje za dużo drobnicy mimo price floor T-015 — dodaj instrukcję "filtruj drobne gadżety poniżej rozsądnego progu dla prezentu, chyba że klient szuka właśnie drobiazgu".

## KROK 2. Grep markers po edycji

```bash
php -l standalone/src/Chat/SystemPrompt.php
grep -c "JĘZYK ODPOWIEDZI — KRYTYCZNE" standalone/src/Chat/SystemPrompt.php       # 1
grep -c "DOSTAWA I WYSYŁKA" standalone/src/Chat/SystemPrompt.php                  # 1
grep -c "MARKA WYCOFANA" standalone/src/Chat/SystemPrompt.php                     # 1
grep -c "ZWIĘZŁOŚĆ" standalone/src/Chat/SystemPrompt.php                          # 1
grep -c "BRAND FIDELITY" standalone/src/Chat/SystemPrompt.php                     # 1
grep -c "FAKTY DOMENOWE" standalone/src/Chat/SystemPrompt.php                     # 1
grep -c "DS4 to PIERWSZY stopień" standalone/src/Chat/SystemPrompt.php            # 1
grep -c "NIE dostał maila" standalone/src/Chat/SystemPrompt.php                   # 1
```

## KROK 3. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej diff per patch (9 bloków) + grep markers. NIE deploy bez akceptacji.

## KROK 4. Deploy

scp SystemPrompt.php, md5 verify, php -l prod.

## KROK 5. Git workflow

```bash
git status
git add standalone/src/Chat/SystemPrompt.php
git commit -m "T-016: SystemPrompt v7 — refinement z testów pracowników

9 patchy:
- PATCH 1 EN wzmocnienie (sekcja JĘZYK ODPOWIEDZI KRYTYCZNE + few-shot)
- PATCH 2 instrukcja get_shipping_info PL/EU (ADR-059)
- PATCH 3 obsługa flagi brand_blacklisted (T-015)
- PATCH 4 zwięzłość / anty-ściana-tekstu
- PATCH 5 brand fidelity (Santi nie podmieniać na Avatar cicho)
- PATCH 6 CTA/linki kategorii
- PATCH 7 fakty domenowe (Miflex jeden typ, DS4=I stopień, M26 oxygen clean)
- PATCH 8 logika: brak maila potwierdzenia nie pyta o numer z maila
- PATCH 9 voucher w prezentach (weryfikacja)

Testy pracowników: analiza _docs/22. Powiązane: ADR-059, T-014, T-015."
git push origin main
```

## KROK 6. Smoke test produkcyjny dla Karola

EN (PATCH 1):
1. "Do you ship to Germany?" → CAŁOŚĆ EN, pyta o kraj
2. "I need a pink mask for a child" → CAŁOŚĆ EN
3. "Is there a discount for buying two suits?" → CAŁOŚĆ EN

Wysyłka (PATCH 2):
4. "Ile kosztuje wysyłka?" → wywołuje get_shipping_info, stawki PL z tabeli
5. (EN) "shipping cost?" → pyta o kraj

Fakty (PATCH 7):
6. "Ile barów wytrzyma wąż HP Miflex?" → ~300 bar, NIE pyta o model, NIE bredzi o DIN/INT
7. "Apeks ATX40/DS4 do zimnej wody?" → DS4 = I stopień, podaje dostępność
8. "Does this regulator support Nitrox 40%?" → wspomina M26 + oxygen clean dla >21%

Jakość (PATCH 4,5,8):
9. "Pokaż suche skafandry Santi" → po pytaniu o płeć pokazuje SANTI (nie Avatar cicho)
10. "Nie dostałem maila potwierdzenia" → NIE pyta o kod z maila, pyta o email+spam
11. "Jak zamontować karabinek do manometru?" → zwięźle, nie ściana tekstu

## KROK 7. Raport + status update

### `_instances/backend/handoff/T-016_done.md`:
- Diff per patch
- Grep markers (8)
- Smoke 11 scenariuszy

### Update `_docs/21_STATUS_PROJEKTU.md`:
- T-016 DEPLOYED, SystemPrompt v7
- Faza 2 zamknięta, wszystkie bugi z testów pracowników zaadresowane
- Backlog pozostały: combination stock (test 102), encyklopedia gaps (Peregrine TX/Ammonite), seed EU shipping, UI shop_config

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-016 DEPLOYED — SystemPrompt v7, testy pracowników zaadresowane"
git push origin main
```

## Out of scope

- Combination stock per wariant (test 102) — wymaga zmiany ProductSearch, osobny task
- Encyklopedia gaps (Peregrine TX, Ammonite latarki — testy 42,47) — osobny task encyklopedii
- Seed stawek EU (Karol poda dane)
- UI do edycji shop_config/shipping_rates/blacklisty
- Editorial Picks zastosowanie (Suunto Ocean/Thermo 2K — Karol dodaje przez panel)
