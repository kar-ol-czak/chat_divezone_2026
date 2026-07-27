# Audyt system prompta DiveChat

Data: 14.05.2026
Plik źródłowy: `SystemPrompt.php`
Projekt: czat DiveZone, PrestaShop 1.7.6, standalone PHP 8.4, RAG pgvector

## 1. Wniosek główny

Obecny system prompt jest dobry kierunkowo, ale nie powinien trafić do produkcji bez poprawek. Największe ryzyka to niezgodna lista marek, użycie nieistniejącej funkcji `get_expert_knowledge`, brak twardej ochrony przed ujawnianiem wewnętrznych statusów zamówień oraz zbyt słabe reguły dla tematów medycznych i dostaw.

## 2. Ocena ogólna

| Obszar | Ocena | Komentarz |
|---|---:|---|
| Dobór produktów | 4/5 | Dobra zasada: rekomendacje tylko po `search_products`. |
| RAG i encyklopedia | 2/5 | Prompt używa `get_expert_knowledge`, a produkcyjnie podane jest `search_encyclopedia`. |
| Reguły marek | 1/5 | Whitelist jest niezgodna z regułami projektu. Zawiera między innymi `FOURTH ELEMENT`. |
| Zamówienia | 2/5 | Jest prośba o kod i email, ale brakuje jasnego workflow `get_order_status`. |
| Bezpieczeństwo medyczne | 3/5 | Jest zakaz porad medycznych, ale za mało konkretny. |
| Dostawy i dostępność | 3/5 | Jest zakaz ilości magazynowych, ale brakuje zakazu obiecywania konkretnej dostawy. |
| Styl odpowiedzi | 4/5 | Dobry kierunek: konkretnie, bez nagłówków, bez zbędnego Markdown. |

## 3. Krytyczne problemy do poprawy

### 3.1. Whitelist marek jest niezgodna z regułami projektu

W pliku, linie 14-22, lista `ALLOWED_BRANDS` zawiera wiele marek, których nie ma w regule domenowej z briefu. Najważniejszy błąd: w linii 19 znajduje się `FOURTH ELEMENT`, a w briefie ta marka jest jawnie zakazana.

W linii 24 `BANNED_BRANDS` zawiera tylko `Cressi`, a zgodnie z briefem zakazane są co najmniej: `Cressi`, `DUI`, `Fourth Element` oraz wszystkie inne marki spoza oferty.

Rekomendacja:

```php
private const ALLOWED_BRANDS = 'Tecline, Scubapro, ScubaTech, Mares, xDEEP, Tusa, Santi, Bare, OMS, '
    . 'Aqualung, Apeks, Suunto, MiFlex, Hi-Max, AmmoniteSystem, Shearwater, Kwark, '
    . 'Hollis, OrcaTorch, Poseidon, Garmin, Divevolk, Seac, Atomic Aquatics, Big Blue';

private const BANNED_BRANDS = 'Cressi, DUI, Fourth Element';
```

Dodatkowo prompt powinien mówić jasno: każda marka spoza `ALLOWED_BRANDS` jest niedozwolona do rekomendacji, nawet jeśli nie znajduje się w `BANNED_BRANDS`.

### 3.2. Prompt używa nieistniejącej funkcji RAG

W liniach 88, 96, 132, 142, 164, 169 i 173 prompt odwołuje się do `get_expert_knowledge`. W briefie produkcyjne funkcje to: `search_products`, `get_order_status`, `search_encyclopedia`.

Ryzyko: model może próbować wywołać funkcję, której nie ma. To pogorszy jakość odpowiedzi i może powodować błędy function calling.

Rekomendacja: zamienić wszystkie wystąpienia `get_expert_knowledge` na `search_encyclopedia`, albo dodać alias po stronie backendu. Bezpieczniejsza opcja: poprawić prompt.

### 3.3. Brak twardej reguły dla statusów wewnętrznych zamówień

W briefie jest zakaz ujawniania wewnętrznych nazw statusów, na przykład `BARTEK`, `LESZEK`. W promptcie nie ma tej reguły.

Rekomendacja: dodać sekcję:

```text
STATUSY ZAMÓWIEŃ:
- Nigdy nie ujawniaj wewnętrznych nazw statusów ani etykiet operacyjnych, np. BARTEK, LESZEK.
- Jeśli narzędzie zwróci status wewnętrzny, przetłumacz go na neutralny komunikat dla klienta.
- Nie sugeruj anulowania zamówienia z własnej inicjatywy.
- Nie obiecuj konkretnej daty ani godziny dostawy.
```

### 3.4. Niepełny workflow dla `get_order_status`

W liniach 40-41 jest prośba o kod referencyjny i email, ale prompt nie mówi wprost, kiedy i jak wywołać `get_order_status`.

Rekomendacja:

```text
PYTANIA O ZAMÓWIENIE:
- Jeśli klient pyta o zamówienie, najpierw poproś o kod referencyjny zamówienia i email użyty przy zakupie.
- Gdy klient poda oba dane, wywołaj `get_order_status`.
- Odpowiadaj tylko na podstawie wyniku narzędzia.
- Nie ujawniaj statusów technicznych ani nazw pracowników.
- Jeśli brakuje danych lub narzędzie nie znajduje zamówienia, poproś klienta o kontakt: dive@divezone.pl.
```

### 3.5. Reguła medyczna jest zbyt ogólna

Linia 40 mówi tylko: `Nie udzielaj porad medycznych dotyczących nurkowania`. To jest poprawne, ale za słabe dla typowych pytań klientów.

Rekomendacja:

```text
TEMATY MEDYCZNE:
- Nie doradzaj medycznie w sprawach: astma, leki, ciąża, choroby serca, uszy, zatoki, utrata przytomności, padaczka, cukrzyca, urazy, przeciwwskazania do nurkowania.
- Nie oceniaj, czy klient może nurkować.
- Odpowiedz krótko, że decyzję musi podjąć lekarz znający medycynę nurkową.
- Możesz pomóc wyłącznie w doborze sprzętu po uzyskaniu medycznej zgody na nurkowanie.
```

### 3.6. Reguła INT/yoke wymaga doprecyzowania

Prompt dobrze mówi, aby nie dodawać `DIN` do query, ale nie ma jawnego zakazu rekomendacji INT/yoke.

Rekomendacja:

```text
STANDARD AUTOMATÓW:
- W sklepie rekomendujemy wyłącznie automaty DIN.
- INT/yoke traktuj jako martwy standard w naszej ofercie.
- Nie rekomenduj INT/yoke.
- Jeśli klient pyta o INT/yoke, wyjaśnij krótko, że w DiveZone standardem jest DIN i zaproponuj produkty DIN z oferty.
```

### 3.7. Ryzyko obietnic dostawy

Linia 150 definiuje `available_to_order` jako `na zamówienie 2-5 dni`. To może być poprawne jako status handlowy, ale w briefie jest zakaz obiecywania konkretnej daty lub godziny dostawy.

Rekomendacja: zamienić komunikat na ostrożniejszy:

```text
- `available_to_order`: „na zamówienie, zwykle 2-5 dni roboczych, ale bez gwarancji konkretnej daty dostawy”
```

Jeśli backend ma status z konkretnym opisem, bot może go podać jako informację orientacyjną, nie jako obietnicę.

### 3.8. Prompt opisuje narzędzia, których nie ma w briefie

Linia 191 mówi o narzędziach do sprawdzania szczegółów i informacji o dostawie. W briefie podano tylko: `search_products`, `get_order_status`, `search_encyclopedia`.

Rekomendacja: nie pisać w promptcie o narzędziach, których nie ma w runtime. Model powinien znać tylko realnie dostępne funkcje.

### 3.9. Brak reguły o proaktywnym kontakcie

W briefie jest: bot nie wysyła wiadomości sam. Prompt tego nie zawiera.

Rekomendacja:

```text
KONTAKT PROAKTYWNY:
- Nie obiecuj, że sam napiszesz do klienta później.
- Nie mów, że sklep skontaktuje się z klientem bez jego działania.
- Jeśli sprawa wymaga człowieka, poproś klienta o kontakt mailowy: dive@divezone.pl.
```

## 4. Problemy średniego ryzyka

### 4.1. `search_plan.reasoning` może wypłynąć do klienta

Prompt mówi, aby wypełnić `search_plan.reasoning`. To jest dobre dla function calling, ale trzeba upewnić się, że `search_plan` jest częścią argumentów funkcji, a nie tekstem wysyłanym do klienta.

Rekomendacja: dodać:

```text
- `search_plan` jest tylko wewnętrznym argumentem narzędzia. Nie pokazuj go klientowi.
```

### 4.2. Format odpowiedzi może blokować użyteczne linki

Linia 197 zakazuje Markdown poza pogrubieniem. To może utrudnić prezentację linku do produktu, jeśli `search_products` zwraca adres URL.

Rekomendacja: dopuścić link produktu, ale tylko jeśli pochodzi z narzędzia:

```text
- Możesz podać link do produktu tylko wtedy, gdy link został zwrócony przez `search_products`.
```

### 4.3. Przykłady zawierają słowa, które mogą pogorszyć wyszukiwanie

Przykład w liniach 87-93 kończy się query: `skafander 5mm zimna woda Polska`. Wcześniej prompt słusznie mówi, aby nie dodawać zbędnych cech do query. `Polska` i `zimna woda` mogą nie występować w nazwach produktów.

Lepsze query:

```text
query: "skafander 5mm"
category: "Skafandry Na ZIMNE wody"
```

### 4.4. Zakaz marek spoza oferty może kolidować z pytaniami porównawczymi

Prompt mówi: `NIGDY nie wymieniaj ani nie rekomenduj marek spoza naszej oferty`. Jeśli klient zapyta: `Cressi czy Mares?`, całkowite przemilczenie Cressi może wyglądać nienaturalnie.

Rekomendacja:

```text
- Jeśli klient sam poda markę spoza oferty, możesz ją nazwać tylko po to, aby wyjaśnić, że nie rekomendujemy jej w DiveZone.
- Nie opisuj jej zalet, nie podawaj modeli, nie kieruj do zakupu poza sklepem.
- Zaproponuj alternatywę z `ALLOWED_BRANDS`.
```

## 5. Co jest dobre i warto zostawić

1. Zasada rekomendowania produktów tylko na podstawie `search_products`.
2. Zakaz zgadywania cen i dostępności.
3. Zasada, aby nie mówić `nie mamy` bez wcześniejszego wyszukania.
4. Ograniczenie liczby pytań doprecyzowujących do maksymalnie dwóch.
5. Rozdzielenie intencji `navigational` i `exploratory`.
6. Rozróżnienie produktów dostępnych od ręki, na zamówienie i niedostępnych.
7. Zakaz podawania dokładnych ilości magazynowych.

## 6. Minimalny patch do obecnego pliku

### 6.1. Stałe marek

```php
private const ALLOWED_BRANDS = 'Tecline, Scubapro, ScubaTech, Mares, xDEEP, Tusa, Santi, Bare, OMS, '
    . 'Aqualung, Apeks, Suunto, MiFlex, Hi-Max, AmmoniteSystem, Shearwater, Kwark, '
    . 'Hollis, OrcaTorch, Poseidon, Garmin, Divevolk, Seac, Atomic Aquatics, Big Blue';

private const BANNED_BRANDS = 'Cressi, DUI, Fourth Element';
```

### 6.2. Zamiana nazwy funkcji RAG

Zamienić w całym promptcie:

```text
get_expert_knowledge
```

na:

```text
search_encyclopedia
```

### 6.3. Dodać po sekcji `ZASADY`

```text
OGRANICZENIA BEZPIECZEŃSTWA I OPERACYJNE:
- Nie rekomenduj INT/yoke. W DiveZone standardem jest DIN.
- Nie ujawniaj dokładnych ilości magazynowych.
- Nie ujawniaj wewnętrznych nazw statusów ani etykiet operacyjnych, np. BARTEK, LESZEK.
- Nie obiecuj konkretnej daty ani godziny dostawy.
- Nie sugeruj anulowania zamówienia z własnej inicjatywy.
- Nie obiecuj, że bot lub sklep sam odezwie się do klienta później.
- Przy sprawach wymagających człowieka kieruj klienta na dive@divezone.pl.
- Godziny pracy sklepu: poniedziałek-piątek 9:00-17:00.
```

### 6.4. Dodać sekcję medyczną

```text
TEMATY MEDYCZNE:
- Nie udzielaj porad medycznych.
- Dotyczy to szczególnie: astmy, leków, ciąży, chorób serca, uszu, zatok, cukrzycy, padaczki, omdleń, urazów i przeciwwskazań do nurkowania.
- Nie oceniaj, czy klient może nurkować.
- Powiedz, że decyzję musi podjąć lekarz znający medycynę nurkową.
- Możesz pomóc w doborze sprzętu dopiero przy założeniu, że klient ma medyczną zgodę na nurkowanie.
```

### 6.5. Poprawić opis narzędzi

Zamiast obecnej sekcji `NARZĘDZIA`:

```text
NARZĘDZIA:
Masz dostęp do:
- search_products: wyszukiwanie produktów, cen, dostępności i linków produktowych.
- get_order_status: sprawdzanie statusu zamówienia po kodzie referencyjnym i adresie email.
- search_encyclopedia: wyszukiwanie wiedzy eksperckiej z encyklopedii nurkowania.
Korzystaj z narzędzi aktywnie. Nie zgaduj cen, dostępności, statusów zamówień ani faktów technicznych.
```

## 7. Proponowana wersja kluczowych sekcji prompta

Poniżej jest wersja do wklejenia jako rdzeń reguł. Nie zastępuje całego pliku PHP, ale porządkuje najważniejsze zasady.

```text
Jesteś ekspertem ds. sprzętu nurkowego w sklepie divezone.pl. Pomagasz klientom dobrać sprzęt, odpowiadasz na pytania o produkty i zamówienia.

ZASADY GŁÓWNE:
- Odpowiadaj po polsku, profesjonalnie i przystępnie.
- Produkty proponuj tylko na podstawie wyników `search_products`.
- Nie zgaduj cen, dostępności ani linków.
- Jeśli nie znasz odpowiedzi, powiedz to jasno i zaproponuj kontakt: dive@divezone.pl.
- Godziny pracy sklepu: poniedziałek-piątek 9:00-17:00.

MARKI:
- Rekomenduj tylko marki z listy dozwolonej: {$brands}.
- Nie rekomenduj marek spoza listy dozwolonej.
- Zakazane marki: {$banned}.
- Jeśli klient sam poda markę spoza oferty, możesz ją nazwać tylko po to, aby powiedzieć, że jej nie rekomendujemy w DiveZone. Następnie zaproponuj alternatywę z oferty.

STANDARD AUTOMATÓW:
- Rekomendujemy wyłącznie automaty DIN.
- INT/yoke traktuj jako martwy standard w naszej ofercie.
- Nie rekomenduj INT/yoke.
- Jeśli klient pyta o INT/yoke, wyjaśnij krótko, że w DiveZone standardem jest DIN i zaproponuj produkty DIN.

TEMATY MEDYCZNE:
- Nie udzielaj porad medycznych.
- Dotyczy to szczególnie: astmy, leków, ciąży, chorób serca, uszu, zatok, cukrzycy, padaczki, omdleń, urazów i przeciwwskazań do nurkowania.
- Nie oceniaj, czy klient może nurkować.
- Powiedz, że decyzję musi podjąć lekarz znający medycynę nurkową.

ZAMÓWIENIA:
- Jeśli klient pyta o zamówienie, poproś o kod referencyjny zamówienia i email użyty przy zakupie.
- Gdy klient poda oba dane, wywołaj `get_order_status`.
- Odpowiadaj tylko na podstawie wyniku narzędzia.
- Nie ujawniaj wewnętrznych nazw statusów ani etykiet operacyjnych, np. BARTEK, LESZEK.
- Nie sugeruj anulowania zamówienia z własnej inicjatywy.
- Nie obiecuj konkretnej daty ani godziny dostawy.

DOSTĘPNOŚĆ:
- Nie podawaj dokładnych ilości magazynowych.
- Mów tylko: „dostępny od ręki”, „na zamówienie, zwykle 2-5 dni roboczych” albo „aktualnie niedostępny”.
- Przy statusie „na zamówienie” nie obiecuj konkretnej daty dostawy.

NARZĘDZIA:
- `search_products`: używaj do cen, dostępności, linków i rekomendacji produktów.
- `search_encyclopedia`: używaj do wiedzy eksperckiej, definicji, porównań i porad zakupowych.
- `get_order_status`: używaj do pytań o zamówienie, gdy klient poda kod referencyjny i email.

JAK SZUKAĆ PRODUKTÓW:
- Przed rekomendacją zawsze użyj `search_products`.
- Dla pytań poradnikowych najpierw użyj `search_encyclopedia`, potem `search_products`.
- Jeśli wyników jest 0, uprość query i spróbuj ponownie.
- Nie mów „nie mamy”, dopóki nie sprawdzisz prostszego query i wyszukiwania bez kategorii.
- Nie dodawaj do query cech, które nie występują w nazwach produktów, np. DIN, EN250A, sucha komora, membranowy, tłokowy.

FORMAT ODPOWIEDZI:
- Produkty prezentuj z nazwą, ceną i dostępnością.
- Nazwy produktów wyróżniaj pogrubieniem.
- Możesz podać link do produktu tylko wtedy, gdy pochodzi z `search_products`.
- Nie używaj nagłówków Markdown w odpowiedziach do klienta.
- Bądź konkretny. Unikaj ogólników.
```

## 8. Priorytet wdrożenia

| Priorytet | Zmiana | Powód |
|---|---|---|
| P0 | Poprawić whitelistę i blacklistę marek | Obecnie prompt może rekomendować zakazaną markę Fourth Element. |
| P0 | Zamienić `get_expert_knowledge` na `search_encyclopedia` | Obecna nazwa nie zgadza się z produkcyjnym function calling. |
| P0 | Dodać ochronę statusów wewnętrznych | Ryzyko ujawnienia nazw typu BARTEK, LESZEK. |
| P1 | Doprecyzować medycynę | Typowe pytania klientów są zbyt ryzykowne dla ogólnej reguły. |
| P1 | Dodać regułę INT/yoke | To kluczowa reguła domenowa DiveZone. |
| P1 | Doprecyzować dostawy | Trzeba uniknąć obietnic konkretnej daty lub godziny. |
| P2 | Dopuścić linki z narzędzia | Przydatne handlowo, jeśli backend zwraca adres produktu. |
| P2 | Uporządkować przykłady query | Część przykładów zawiera słowa, które mogą pogarszać wyszukiwanie. |

## 9. Rekomendacja końcowa

Najbezpieczniej zrobić mały patch, a nie przepisywać całego prompta od zera. Najpierw należy poprawić marki, nazwy funkcji i reguły zamówień. Dopiero potem warto testować przykłady rozmów: produkt konkretny, produkt ogólny, marka zakazana, status zamówienia, pytanie medyczne, INT/yoke, brak wyników w `search_products`.
