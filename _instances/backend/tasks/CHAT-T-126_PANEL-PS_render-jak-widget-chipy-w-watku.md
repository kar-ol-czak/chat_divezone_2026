# CHAT-T-126 — Panel recenzji (moduł PS): render rozmowy jak w widgecie + chipy w wątku + fix licznika

**Instancja:** frontend PS (render, ŚWIAT 2) + backend (licznik, ŚWIAT 1). **Powiązane:** ADR-110 (chip_path), ADR-070 (panel PS = front admina), CHAT-T-124 (reguły markdown widgetu), CHAT-T-125 (breadcrumb ścieżki). **Decyzje Karola:** 42b, 43a, 44a. **Cel nadrzędny:** panel recenzji ma wiernie odwzorować to, co widział klient w widgecie (ten sam markdown), a chipy klikane mają być widoczne w wątku jako akcje klienta.

## Kontekst
Panel PS renderuje rozmowę własnym uproszczonym `formatConvBubbleText` (`AdminDivezoneChatController.php`), rozjazd z widgetem (`renderMarkdown` w widget-bundle.js). Objawy zgłoszone przez Karola: linki `[nazwa](url)` lądują obok nazwy zamiast podpięte pod nazwę; `~~przekreślenie~~` nie renderuje się; breadcrumb za mały i bez polskich znaków; licznik wiadomości w menu zawyżony (liczy tool_use/tool_result); czcionka czatu za mała.

## SPECYFIKACJA REGUŁ MARKDOWN (odwzorować z widgetu, decyzja 42b — ta sama SPEC, nie współdzielony plik)
Kolejność jak w `renderMarkdown` (widget-bundle.js ~311): 1) escape HTML, 2) link `[label](url)` — tylko `https?://` i `mailto:` → `<a href target=_blank rel=noopener>label</a>`, 3) bold `**...**` (bez `*` i `\n` wewnątrz), 4) przekreślenie `~~...~~` (podwójna tylda, `[^~\n]+`, pojedyncza tylda nietknięta), 5) listy: linie od `- ` lub `• ` → `<ul><li>`, 6) paragrafy `<p>...<br>...</p>`. UWAGA: obecny `formatConvBubbleText` ma tylko bold + surowy URL (bez `[label](url)`, bez `~~`, bez list) — rozszerzyć do pełnej SPEC powyżej, zachować sanityzację (htmlspecialchars przed regułami).

## Zakres
### Część A — render markdown jak widget (moduł PS)
1. Rozszerz `formatConvBubbleText` o pełną SPEC: link `[label](url)` podpięty pod label (NIE surowy URL obok), przekreślenie `~~`, listy. Bold zostaje. Zachowaj bezpieczeństwo (escape najpierw). Surowy-URL-autolink można zostawić PO regule `[label](url)` jako fallback dla gołych linków, ale priorytet ma składnia markdown.

### Część B — chipy w wątku jako bąbelki klienta (decyzja 43a)
2. Chipy które klient klikał (z `$resp['chip_path']`, kolejność zejścia) wstaw do wątku „Przebieg rozmowy" jako bąbelki użytkownika (prawa strona, klasa `dz-conv-bubble--user`), NA POCZĄTKU wątku (klient klikał chipy zanim zaczął pisać), w kolejności ścieżki. Etykieta = label chipu („Dobór sprzętu", potem „Komputer nurkowy"). Wizualnie mogą mieć drobny znacznik że to chip (np. prefiks ikony/tekstu „(chip)") — do uznania, ale mają wyglądać jak akcja klienta. `renderConvMessages` przyjmuje dodatkowo chip_path albo wstaw je przed pętlą wiadomości.
3. Breadcrumb „Ścieżka:" ZOSTAJE (szybkie podsumowanie), ale popraw: czcionka 14px, polskie znaki („Ścieżka:" z ś — sprawdź czemu `$this->l('Sciezka:')` gubi diakrytyki: prawdopodobnie brak tłumaczenia/zła enkodacja, użyj poprawnego stringa UTF-8 lub wpisu tłumaczeń).

### Część C — fix licznika wiadomości (backend, ŚWIAT 1)
4. `message_count` ma liczyć TYLKO widoczne wiadomości (role user/assistant, assistant z niepustą treścią), nie całą tablicę (dziś `jsonb_array_length` łapie tool_use/tool_result). Popraw w: `ConversationReviewRepository.php` (linie ~117, ~167) i `ConversationStore.php` (~261). Wzór: `(SELECT count(*) FROM jsonb_array_elements(messages) m WHERE m->>'role' IN ('user','assistant') AND COALESCE(m->>'content','') <> '')`. Ujednolić we wszystkich. Rozważ czy licznik ma obejmować też chipy z chip_path (klient „wysłał" chipy) — DECYZJA: licznik = tylko realne wiadomości tekstowe, chipy nie liczone (spójne z tym co widać jako bąbelki tekstowe; chipy to osobna warstwa). STOP przed rsync (ADR-089).

### Część D — kosmetyka (moduł PS, CSS)
5. Czcionka renderowania czatu w panelu: 13px → 14px (klasa bąbelków `dz-conv-*` w `$css` kontrolera). Breadcrumb `dz-conv-chip-path` → 14px.

## Deploy (dwa światy)
- Część C → chat.divezone.pl (rsync standalone/src). Część A/B/D → newtmp2/modules (rsync modułu, potem var/cache/prod + LSCache). Kolejność: C przed A/B (licznik niezależny, ale trzymać spójność). Test w incognito.

## Definicja ukończenia
- Panel renderuje linki podpięte pod nazwę, przekreślone ceny, listy — jak widget.
- Chipy klikane widoczne w wątku jako bąbelki klienta w kolejności ścieżki.
- Breadcrumb 14px z polskimi znakami; czcionka czatu 14px.
- Licznik w menu = liczba realnych wiadomości widocznych po wejściu (nie zawyżony).

## Wynik (2026-07-02)
**Status: KOD GOTOWY — czeka na 2 rsync STOP-gated (C→chat.divezone.pl/src; A/B/D→newtmp2/modules + var/cache/prod).**

### Część C — fix licznika (backend, ŚWIAT 1)
- `ConversationReviewRepository` (listByStatus + listNewInbox) i `ConversationStore::list`: `jsonb_array_length(messages)` → podzapytanie `(SELECT count(*) FROM jsonb_array_elements(COALESCE(messages,'[]'::jsonb)) mm WHERE mm->>'role' IN ('user','assistant') AND COALESCE(mm->>'content','') <> '')`. Ujednolicone w 3 miejscach. Chipy NIE liczone (osobna warstwa — decyzja z tasku).
- Weryfikacja real-Railway: rozmowa 551 raw_len=5 → **visible_len=2** (user „trimix 90m" + finalna odpowiedź; puste assistant tool-turn i 2× tool_result pominięte). Test `ConversationReviewRepositoryTest` **35/35** (`message_count=1`).

### Część A — markdown jak widget (moduł PS)
- `formatConvBubbleText` przepisany na wierny port `renderMarkdown` (widget-bundle.js ~311): escape → `[label](url)` (http/mailto, podpięty pod label) → `**bold**` → `~~del~~` → listy (`- `/`• ` → `<ul>`) + paragrafy (`<p>…<br>…</p>`). Goły-URL autolink USUNIĘTY (widget go nie ma → wierna parzystość + brak podwójnego opakowania).
- Smoke parzystości: linki, przekreślenie+bold, mailto, listy, XSS (pełny escape), pojedyncza tylda nietknięta, diakrytyki OK.

### Część B — chipy w wątku (moduł PS)
- `renderConvMessages($messages, $chipPath)`: chipy z `chip_path` renderowane jako bąbelki klienta (`dz-conv-bubble--user dz-conv-bubble--chip`) NA POCZĄTKU wątku, w kolejności ścieżki, znacznik „(chip)". Etykieta tylko escapowana (bez markdownu).
- Breadcrumb: literal UTF-8 „Ścieżka:" (zamiast `$this->l()` gubiącego diakrytyki w BO); 14px (część D).

### Część D — kosmetyka (CSS)
- `.dz-conv-bubble` 13→**14px**; `.dz-conv-chip-path` 12→**14px**; nowe `.dz-conv-bubble--chip` (przygaszone tło + dashed) i `.chip-tag`.

### Deploy (STOP — dla Karola, kolejność C→A/B/D)
1. **C** — rsync `standalone/src/Admin/ConversationReviewRepository.php` + `standalone/src/Chat/ConversationStore.php` → chat.divezone.pl (ADR-089: backup+md5+`php -l`+smoke `/api/health`).
2. **A/B/D** — rsync `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php` → newtmp2 (bez `--delete`), potem `var/cache/prod` + LSCache. Test w incognito (twardy refresh BO).
