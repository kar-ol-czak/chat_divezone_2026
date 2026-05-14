# TASK-CHAT-007c: Spójność formatowania nazw produktów i linków (P2)

**Instancja:** frontend
**Powiązany ADR:** ADR-053 pkt 12 + bug N4 z `_docs/23_red_team_konsolidacja.md`
**Priorytet:** P2 (bug UX, ale ważny dla wiarygodności rekomendacji)

## Cel

Naprawić bug zaobserwowany w produkcji: w jednej konwersacji bot prezentuje produkty niespójnie:
- 1. odpowiedź: nazwy produktów wyboldowane + podlinkowane do sklepu (poprawnie)
- 2. odpowiedź: te same produkty wyboldowane, ale BEZ linków
- 3. odpowiedź: nazwy produktów bez bolda

Klient widzi niespójność która podważa profesjonalizm. Po N-tej odpowiedzi traci możliwość kliknięcia w produkt.

Screenshoty: rozmowa #(do uzupełnienia przez Karola jeśli ma ID), pytania klienta "Chce kupić maskę i rurkę do nurkowania" → "lubie ponurkować ja kila m pod wode, lepsza rurka osobno będzie?" → "poproszę, mężczyzna twarz normalna".

## Diagnoza (do wykonania przez CC frontend)

Trzy potencjalne źródła problemu, do zbadania w tej kolejności:

### Hipoteza A: backend nie zwraca pola `url` w kolejnych tool calls

ProductSearch może zwracać `url` tylko w pierwszym wywołaniu, a w kolejnych przy tych samych produktach pomijać. Mało prawdopodobne ale do sprawdzenia.

Krok diagnozy: w narzędziach deweloperskich przeglądarki / w logach backendu sprawdzić odpowiedź `search_products` w 2. i 3. tool call w tej samej konwersacji. Sprawdzić czy pole `url` jest obecne.

Jeśli `url` jest obecne w response toola → hipoteza A odpada, problem w renderingu (B) lub w prompcie modelu (C).

### Hipoteza B: model generuje Markdown niespójnie

Może to być problem z SystemPrompt który nie wzmacnia reguły wystarczająco. Po wdrożeniu TASK-CHAT-007a (reguła "Reguła obowiązuje w KAŻDEJ odpowiedzi w konwersacji") problem powinien zniknąć.

Krok diagnozy: po deployu 007a Karol odpala test rozmowy maska+rurka i sprawdza czy linki utrzymują się przez 3+ odpowiedzi. Jeśli tak → hipoteza B była główną przyczyną, bug zamknięty.

### Hipoteza C: postprocessing wycina lub modyfikuje Markdown

W pipeline wyświetlania odpowiedzi może być parser/sanitizer który w niektórych przypadkach gubi linki. Sprawdź pliki:
- `standalone/public/js/chat.js` (lub główny plik renderingu konwersacji)
- każdy plik który robi `marked.parse()`, `DOMPurify.sanitize()`, regex na response
- ChatService PHP — czy nie ma trim/replace na response przed wysłaniem do frontu

Krok diagnozy: porównać raw response z modelu (przed renderingiem) vs co wyświetla się klientowi. Jeśli raw zawiera `[**X**](url)` a frontend pokazuje tylko `**X**` bez linku → bug w renderingu.

## Plan działania

### Faza 1: diagnoza (1-2h pracy CC)

1. Sprawdzić logi konwersacji #668a2cb4 lub podobnej (Karol może udostępnić)
2. Wykonać hipotezy A, B, C w kolejności
3. Wyprodukować raport diagnostyczny: `_instances/frontend/handoff/TASK-CHAT-007c_diagnoza.md`

### STOP point 1 (po diagnozie)

Skierować raport diagnostyczny do Karola. Karol decyduje:
- jeśli problem rozwiązuje się przez 007a (hipoteza B) → task zamknięty bez kodowania
- jeśli problem jest w renderingu (hipoteza C) → przejście do Fazy 2 z konkretnym fix
- jeśli problem w backendzie (hipoteza A) → ten task zamknąć, otworzyć osobny task dla backendu

### Faza 2: fix renderingu (jeśli potrzebne)

W zależności od diagnozy:

**Jeśli sanitizer wycina linki:**
- Zaktualizować whitelist tagów/atrybutów aby `<a href>` było dozwolone
- Upewnić się że `target="_blank" rel="noopener noreferrer"` jest dodawane do wszystkich linków do sklepu (SEO i bezpieczeństwo)

**Jeśli Markdown nie jest parsowany w kolejnych wiadomościach:**
- Sprawdzić czy `marked.parse()` lub odpowiednik jest wywoływany dla każdej wiadomości
- Sprawdzić czy nie ma jakiegoś flaga "isFirstMessage" który włącza/wyłącza parser

**Jeśli bold gubi się losowo:**
- Sprawdzić czy CSS dla `<strong>` jest spójny we wszystkich kontekstach (różne klasy wiadomości?)
- Sprawdzić czy nie ma styli inline które nadpisują

## Acceptance criteria

Test ręczny w produkcji (po deploy):

1. Klient pisze: "Chce kupić maskę"
2. Bot odpowiada z 3+ produktami, każdy bold + link
3. Klient pisze: "a lepsza będzie z fajką osobno?"
4. Bot odpowiada z 3+ produktami, każdy bold + link
5. Klient pisze: "poproszę dla mężczyzny"
6. Bot odpowiada z 1-3 produktami, każdy bold + link

Każda z 3 odpowiedzi musi mieć ten sam standard: **nazwa produktu** jest linkiem do divezone.pl.

## Out of scope

- Wyróżnik kolorystyczny "dostępne od ręki" (sugestia N5 Karola) — wymaga decyzji UI/UX, osobny ADR (design system)
- Zmiana ikon, marginesów, typografii — wyłącznie spójność formatowania nazw produktów
- Lazy loading obrazów produktów, image previews — out of scope, osobny temat
