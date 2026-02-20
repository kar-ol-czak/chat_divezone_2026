# Analiza problemów wyszukiwania i wiedzy eksperckiej
# Data: 2026-02-21
# Status: AKTYWNY

## 1. Problem synonimów produktowych

### Objaw
Zapytanie "szukam pianki" → 0 wyników. AI nie znajduje produktów.

### Przyczyna
W bazie produkty to "skafander mokry", "skafander neoprenowy", "wetsuit" itp.
Klient mówi "pianka" — embedding nie matchuje nazw produktów.

### Mapa synonimów (do zaimplementowania)
| Klient mówi | Produkty w bazie |
|---|---|
| pianka | skafander mokry, skafander neoprenowy, wetsuit |
| pianka sucha | skafander suchy, drysuit |
| jacket | kamizelka wyrównawcza, BCD, jacket |
| skrzydło | wing, skrzydło nurkowe |
| automat | automat oddechowy, regulator |
| komputer | komputer nurkowy |
| latarka | lampa nurkowa, latarka nurkowa |

### Rozwiązanie
Opcje (do decyzji):
a) **Query expansion** — przed embeddingiem rozszerzaj zapytanie o synonimy (słownik w PHP/JSON)
b) **Embedding text wzbogacony** — do tekstu embeddingu produktu dodaj synonimy klienckie
c) **Drugie zapytanie** — jeśli 0 wyników, przeformułuj query i szukaj ponownie
d) **Hybrid search** — trigram/fulltext na nazwach + embedding na opisach

## 2. Problem braku wiedzy kontekstowej

### Objaw
AI pyta "w jakiej temperaturze wody nurkujesz?" zamiast wywnioskować z "nurkuję w Polsce".

### Brakująca wiedza: region → temperatura → grubość pianki

| Region | Temperatura | Grubość pianki | Uwagi |
|---|---|---|---|
| Polska (jeziora, pod termokliną) | 4-10°C | 7mm semidry lub suchy | Standard dla nurków w PL |
| Polska (lato, płytko) | 15-22°C | 5mm może wystarczyć | Tylko nurkowania płytkie latem |
| Morze Czerwone, Egipt | 22-28°C | 3mm lub shorty | Najpopularniejszy kierunek PL |
| Chorwacja, Grecja (lato) | 18-25°C | 5mm | Pod termokliną chłodniej |
| Maltoza, Gozo | 15-22°C | 5-7mm | Zależy od sezonu |
| Tropiki (Malediwy, Filipiny) | 26-30°C | 3mm lub lycra | |
| Norwegia, Islandia | 2-8°C | suchy skafander | Pianka niewystarczająca |

### Brakująca wiedza: grubość pianki → typ
- 3mm: ciepłe wody, shorty lub full
- 5mm: umiarkowane, full suit
- 7mm: zimne wody, semidry (uszczelnione zamki, mankiety)
- Suchy: bardzo zimne wody, wielokrotne nurkowania, nurkowanie techniczne

## 3. Problem: kiedy poziom zaawansowania MA znaczenie

### Objaw
AI pyta o poziom zaawansowania przy piankach — a nie ma to znaczenia przy wyborze pianki.

### Mapa: produkt vs istotność zaawansowania
| Kategoria | Zaawansowanie istotne? | Dlaczego |
|---|---|---|
| Pianki/skafandry | **NIE** | Dobór zależy od temperatury wody i budowy ciała, nie umiejętności |
| Płetwy | **TAK** | Początkujący: miększe; zaawansowani: sztywne (frog kick, techniki) |
| Automaty oddechowe | **TAK** | Początkujący: prosty, tani; tech: zimna woda, etap, sidemount |
| Komputery nurkowe | **TAK** | Początkujący: prosty (air/nitrox); zaawansowani: trimix, CCR, multi-gaz |
| Maski | **NIE** | Dopasowanie do twarzy, nie do umiejętności |
| BCD/wing | **TAK** | Początkujący: jacket; zaawansowani: wing backplate |
| Latarki | **CZĘŚCIOWO** | Backup vs primary, jasność dla jaskiń/wraków |
| Buty neoprenowe | **NIE** | Grubość zależy od temperatury, nie poziomu |

### Brakująca wiedza: kiedy pytać o płeć
| Kategoria | Płeć istotna? | Dlaczego |
|---|---|---|
| Pianki/skafandry | **TAK** | Krój damski vs męski (biodra, biust, talia) |
| Maski | **NIE** | Dopasowanie do twarzy, nie płci |
| Płetwy | **NIE** | Rozmiar buta, nie płeć |
| BCD | **TAK** | Damskie mają inny kształt pasa biodrowego |
| Automaty | **NIE** | |

## 4. Problem: in_stock_only domyślnie true

### Objaw
Filtr `in_stock_only=true` odcinał 1499 z 2556 produktów (59%).
Pianki/skafandry to głównie produkty na zamówienie.

### Fix (zrobiony)
Zmieniono default na `false`. AI powinno w odpowiedzi rozróżniać:
- "dostępny od ręki" (in_stock=true)
- "na zamówienie" (in_stock=false, is_active=true)

## 5. Plan naprawczy

### Priorytet 1: Wpisy QA do bazy wiedzy
- QA-041: Regiony nurkowe → temperatura → grubość pianki
- QA-042: Kiedy pytać o zaawansowanie (mapa kategorii)
- QA-043: Kiedy pytać o płeć (mapa kategorii)
- QA-044: Synonimy produktowe (pianka = skafander mokry itd.)

### Priorytet 2: Query expansion (TASK-010 lub nowy task)
- Słownik synonimów klienckich → terminów produktowych
- Przed generowaniem embeddingu: rozszerz query o synonimy
- Alternatywnie: wzbogać tekst embeddingu produktu o synonimy

### Priorytet 3: System prompt update
- Dodaj instrukcję: NIE pytaj o zaawansowanie przy piankach/maskach/butach
- Dodaj instrukcję: PYTAJ o płeć przy piankach i BCD
- Dodaj instrukcję: "Polska" = zimna woda = 7mm semidry minimum

## 6. Decyzja architektoniczna do podjęcia

**Pytanie 7:** Jak rozwiązać problem synonimów?
a) Query expansion w PHP (słownik JSON, przed embeddingiem)
b) Wzbogacenie tekstu embeddingu produktu o synonimy klienckie
c) Oba (belt and suspenders)
d) Inne podejście

**Pytanie 8:** Gdzie umieścić wiedzę "region → temperatura → pianka"?
a) Tylko w QA knowledge base (get_expert_knowledge)
b) W system prompcie (zawsze w kontekście)
c) Oba (skrócona wersja w prompcie + szczegóły w QA)
