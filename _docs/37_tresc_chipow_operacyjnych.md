# 37 — Treść chipów operacyjnych (deterministyczna, ZERO LLM)

**Data:** 2026-06-12 | **Powiązane:** ADR-071 (drzewo chipów, decyzja Q231a — fakty operacyjne jako warstwa deterministyczna), decyzja 18a (treść żyje w węźle drzewa), CHAT-T-088 (fundament drzewa). Źródło: strony LIVE divezone.pl (fetch 2026-06-12).

## Cel
Gotowy, zatwierdzony tekst dla węzłów chipów faktów operacyjnych. Chip zwraca to WPROST (typ akcji `static` / tekst węzła), bez LLM. To rdzeń, który ma być niezmienny i poprawny — wysoki koszt błędu (klient dostaje złą informację operacyjną).

**Status: DRAFT DO ZATWIERDZENIA PRZEZ KAROLA / eksperta sklepu.** Liczby (ceny serwisu, terminy) wymagają potwierdzenia, że są aktualne — pochodzą ze stron LIVE, ale strona też bywa nieaktualna. NIE seedować do drzewa przed zatwierdzeniem.

---

## CHIP: Zwroty i wymiana
Źródło: https://divezone.pl/zwroty-produktow

**Tekst węzła (propozycja):**

> Masz 30 dni na zwrot towaru (nasza „Gwarancja zwrotu w ciągu 30 dni"), niezależnie od ustawowych 14 dni na odstąpienie od umowy.
>
> Jak zwrócić:
>
>Jeśli korzystasz z 30 dniowego prawa do zwrotu: 
>1. Wypełnij formularz zwrotu (był w paczce; jest też w mailu z potwierdzeniem i po zalogowaniu w historii zakupów) — podaj dane, numer konta i numer zamówienia.
> 2. Włóż formularz do paczki z towarem i dowodem zakupu, naklej naklejkę zwrotu z numerem zamówienia.
> 3. Nadaj na: Divezone.pl Sp. z o.o., ul. Storczykowa 5, 87-100 Toruń.
>
> Ważne: towar musi być pełnowartościowy (bez uszkodzeń, rys, zabrudzeń), sprzęt mierzymy „na sucho". Zwrotów NIE obsługujemy w paczkomatach ani punktach odbioru — paczka musi dotrzeć do siedziby. Koszt odesłania (do ok. 30 zł) po stronie klienta. Środki zwracamy zwykle szybko po otrzymaniu paczki.
>
>Jeśli korzystasz z ustawowego 14 dniowego prawa do zwrotu,
>
>Wejdź w panel swojego konta i wybierz opcję zwrotu produktu / odstąpienia od umowy sprzedaży.
>
> Chcesz wymienić na inny rozmiar/kolor? Procedura jest taka sama: zwrot + nowy zakup. Możesz złożyć nowe zamówienie od razu, nie czekając aż paczka do nas wróci.

**Przyciski (propozycja):** [Formularz / kontakt → link_zwroty] [Mam inne pytanie → AI]

**Uwaga zgodności:** spójne z hotfixem promptowym CHAT-T-077 (30 dni, nie 14) — ten chip to docelowa gwarancja dla ścieżki „klient kliknął", prompt zostaje bezpiecznikiem dla „klient wpisał tekst".

---

## CHIP: Serwis automatów
Źródło: https://divezone.pl/serwis-automatow-oddechowych-i-innego-sprzetu-nurkowego

**Tekst węzła (propozycja):**

> Serwisujemy automaty oddechowe marek: Apeks, Aqualung, Scubapro, Scubatech, Tecline. Każdy automat czyścimy w myjce ultradźwiękowej i regulujemy na urządzeniu pomiarowym Magnahelic.
>
> Ceny usługi serwisu (1. + 2. stopień):
> - Apeks, Aqualung, Scubatech, Tecline — 190 zł
> - Scubapro — 220 zł
> - Octopus: Apeks/Aqualung/Scubatech/Tecline — 80 zł, Scubapro — 90 zł
>
> Do ceny usługi doliczamy koszt oryginalnego kompletu serwisowego producenta (Service KIT). Stosujemy wyłącznie fabryczne zestawy Apeks, Aqualung, Scubapro, Scubatech.
>
> Termin ustalamy indywidualnie — skontaktuj się wcześniej (serwis@divezone.pl), żeby umówić serwis. Automat zapakuj w karton, dołącz kartkę z danymi (imię, nazwisko, telefon, adres zwrotny) i wyślij dowolnym kurierem na adres sklepu.

**Przyciski (propozycja):** [Pełny cennik / kontakt → link_serwis] [Umów serwis → kontakt] [Mam inne pytanie → AI]

**KRYTYCZNA UWAGA ZGODNOŚCI (SCOPE-004):** strona serwisu publikuje też cennik samych Service KIT per model. SystemPrompt ma twardą regułę: części/zestawów serwisowych NIE sprzedajemy na wolnym rynku (tylko autoryzowanym technikom). Chip serwisowy NIE może sugerować sprzedaży kitu osobno — podaje cennik USŁUGI serwisu (to oferujemy), kit tylko jako składnik doliczany do usługi. Nie wciągać pełnej listy cen kitów do tekstu chipa (to materiał techniczny, nie oferta dla klienta). Jeśli klient pyta o sam kit → reguła SCOPE-004 z promptu (kierunek: autoryzowany serwis).

---

## Reguła dla wszystkich chipów operacyjnych
- Tekst jest ZATWIERDZONY i niezmienny do następnej erraty (edytowalny w panelu pracownika, ADR-070).
- Chip podaje rdzeń faktu; dopytania mogą iść do AI (hybryda z ADR-071), ale rdzeń zawsze deterministyczny.
- Liczby (ceny, terminy) wymagają okresowej weryfikacji ze stroną LIVE / Subiektem — to dług utrzymaniowy (kto aktualizuje cennik serwisu w drzewie, gdy zmieni się na stronie).
