# CHAT-T-070 — BACKEND: daty względne w get_shop_schedule (HOTFIX halucynacji daty)

**Status:** DONE (2026-06-04, deploy PROD OK, smoke #55931 PASS). Raport: `_instances/backend/handoff/CHAT-T-070_done.md`.

**Instancja:** backend (standalone). CC deployuje SAM (standalone). ZERO modułu PS.
**Priorytet:** P0 — błąd klientowski (bot podaje fałszywy dzień/datę otwarcia).
**Powiązany ADR:** ADR-085. **Powiązane:** TASK-CHAT-007b, TASK-CHAT-011 (triggery wołania — ZOSTAJĄ, nie nadpisujemy).
**Pliki:** standalone/src/Tools/GetShopSchedule.php, standalone/src/Chat/SystemPrompt.php. (ShopCalendar.php NIE zmieniany — jest poprawny.)

## Problem (potwierdzony logami divechat_messages, #55931)
Model defaultuje do knowledge cutoff (~styczeń 2025) gdy sam ustala "dziś". WYWOŁUJE get_shop_schedule, ale z błędnym argumentem `date`:
- "Jutro sklep pracuje?" → model wysłał date=2025-01-25 (jego "jutro"). Narzędzie policzyło poprawnie (sobota, next=2025-01-27 pon). Model wiernie oddał wynik → klient dostał "poniedziałek 27 stycznia".
- "Jutro jest piątek 5 czerwca" → model wysłał date=2025-06-05 (zły ROK).
- Kontrast #55811 (działa): model wołał BEZ argumentu → narzędzie użyło today serwerowe (2026-06-04) → poprawnie Boże Ciało.

Wniosek: model nie może liczyć ŻADNEJ daty. Backend (deterministyczny, Europe/Warsaw) liczy wszystko.

---

## KROK 1 — GetShopSchedule.php: parametr `relative` (enum) + walidacja + server_today

### 1a. getParametersSchema() — dodać `relative`, doprecyzować `date`
Nowy parametr `relative` (enum, opcjonalny):
```
today, tomorrow, day_after_tomorrow,
this_monday, this_tuesday, this_wednesday, this_thursday, this_friday, this_saturday, this_sunday,
next_monday, next_tuesday, next_wednesday, next_thursday, next_friday, next_saturday, next_sunday
```
Opis `relative` (dla LLM, KRYTYCZNE — to steruje zachowaniem modelu):
"Dla dat WZGLĘDNYCH użyj TEGO parametru, NIE licz daty samodzielnie. today=dziś, tomorrow=jutro, day_after_tomorrow=pojutrze. this_<dzień>=najbliższe wystąpienie tego dnia (dziś włącznie). next_<dzień>=ten dzień w PRZYSZŁYM tygodniu. Jeśli klient użył samej nazwy dnia (bez 'następny/przyszły') a dziś JEST tym dniem — NIE zgaduj, dopytaj klienta dziś czy za tydzień, dopiero potem wołaj."

Opis `date` (zmienić): "Konkretna data kalendarzowa YYYY-MM-DD (np. '15 lipca' → 2026-07-15). UŻYWAJ TYLKO dla dat bezwzględnych. Dla 'jutro/dziś/w piątek' użyj relative. NIE licz daty względnej sam — defaultujesz do złego roku."

Priorytet gdy podane oba: `relative` wygrywa, `date` ignorowany.

### 1b. execute() — translacja relative→DateTimeImmutable
- tz = Europe/Warsaw. today = new DateTimeImmutable('today', tz).
- Jeśli `relative` podane i niepuste → policz $date deterministycznie:
  - today → today; tomorrow → today+1d; day_after_tomorrow → today+2d.
  - this_<day>: najbliższe wystąpienie <day> licząc od dziś WŁĄCZNIE (jeśli dziś=<day> → dziś). Wzór: delta = (targetDow - todayDow + 7) % 7; data = today + delta dni.
  - next_<day>: this_<day> + 7 dni JEŚLI this_<day>==today (czyli dziś=ten dzień), w przeciwnym razie... ujednolicenie wg ADR-085: next_<day> = pierwsze wystąpienie ŚCIŚLE w przyszłym tygodniu. Implementacja prosta i jednoznaczna: next_delta = ((targetDow - todayDow + 7) % 7); if (next_delta == 0) next_delta = 7; data = today + next_delta + (7 jeśli chcemy zawsze przyszły tydzień gdy this już dziś)... 
    DOPRECYZOWANIE (żeby nie było dwuznaczności w implementacji): 
    * this_<day>: delta=(target-today+7)%7 (0 gdy dziś=day) → dziś włącznie.
    * next_<day>: delta_this=(target-today+7)%7; jeśli delta_this==0 → 7 (dziś=day → za tydzień); w innym wypadku delta_this+7 (zawsze PRZYSZŁY tydzień, nie ten). Tzn. gdy dziś=środa a target=piątek: this_friday=za 2 dni; next_friday=za 9 dni (piątek przyszłego tygodnia). To zgodne z intuicją "następny piątek" = piątek przyszłego tygodnia.
  - Mapowanie nazwa→ISO dnia tygodnia (N: pon=1..niedz=7) ze stałej tablicy w klasie (NIE locale).
  - Nieznana wartość relative → zwróć ['error' => "Nieznana wartość relative: {$relative}"].
- Jeśli `relative` puste/brak → ścieżka `date` jak dotąd, ALE z walidacją 1c.
- Jeśli i relative i date puste → today (zachowanie obecne, nie psuć #55811).

### 1c. Walidacja `date` ISO (ADR-085 204c)
- Parsuj jak dotąd (!Y-m-d, getLastErrors). Błąd formatu → obecny komunikat.
- DODATKOWO: jeśli sparsowany rok < (int)today.format('Y') → zwróć:
  ['error' => "Podana data jest w przeszłym roku ({$rok}). Dla dat względnych użyj parametru relative; dla konkretnej daty podaj rok bieżący lub przyszły.", 'server_today' => today->format('Y-m-d')]
  (Rok w przeszłości = zawsze błąd cutoffu; sklep nie odpowiada o przeszłość. NIE clampować — przełom roku bywa uprawniony, więc rok bieżący/przyszły przepuszczamy.)

### 1d. server_today w KAŻDEJ odpowiedzi (sukces i błąd)
- Do tablicy wynikowej (i do każdego return z error) dodać: 'server_today' => today->format('Y-m-d').
- To jawna kotwica w treści tool-result — działa nawet gdy model zignorował prompt; pozwala wykryć rozjazd i skorygować w następnej turze.

Wynik sukcesu (bez zmian pól istniejących + server_today):
date, working_day, is_currently_open, holiday_name, closed_reason, opens_at, closes_at, next_working_day, **server_today**.

## KROK 2 — SystemPrompt.php: kotwica daty + reguła "zawsze narzędzie, nie licz"

### 2a. Sygnatura build()
`public static function build(bool $emojiEnabled = true, ?\DateTimeImmutable $now = null): string`
- W ciele: `$now ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw'));`
- Dni tygodnia PL ze stałej mapy w klasie (1=>poniedziałek … 7=>niedziela). NIE strftime/IntlDateFormatter (locale serwera niepewne).
- $todayLabel = "{$dniPL[N]} {$now->format('Y-m-d')}"; $tomorrow = $now->modify('+1 day'); analogicznie $tomorrowLabel.

### 2b. Blok na POCZĄTKU promptu (przed "DANE FIRMY" albo tuż po pierwszym zdaniu)
```
AKTUALNA DATA: {$todayLabel} (Europe/Warsaw). Jutro: {$tomorrowLabel}.
```
+ reguła (zwięźle, w tym samym miejscu):
- Dla pytań o "dziś/jutro/pojutrze" oraz o bieżący stan otwarcia → wywołaj get_shop_schedule z parametrem relative (today/tomorrow/day_after_tomorrow). NIE licz daty sam.
- Dla "w <dzień tygodnia>" / "następny <dzień>" → relative=this_<dzień> lub next_<dzień>. Jeśli klient użył samej nazwy dnia a dziś JEST tym dniem — NIE zgaduj, dopytaj (dziś czy za tydzień), potem wołaj.
- Dla konkretnej daty kalendarzowej ("15 lipca", "6 czerwca") → policz rok z AKTUALNEJ DATY i podaj przez parametr date.
- NIGDY nie podawaj dnia tygodnia ani statusu otwarcia bez wywołania get_shop_schedule. Odpowiadaj WYŁĄCZNIE z wyniku narzędzia (pole server_today potwierdza dzisiejszą datę).

### 2c. Zaktualizować few-shot schedule (sekcja istniejąca)
- "Pracujecie jutro?" → zmienić na: Bot wywołuje get_shop_schedule(relative="tomorrow") (BYŁO: date="YYYY-MM-DD gdzie jutrzejsza data" — to właśnie generowało błąd, model liczył sam).
- "Chciałbym wpaść 6 czerwca" (data bezwzględna) → zostaje date="2026-06-06" (przykład bezwzględnej; rok z kotwicy).
- Dodać krótki few-shot dwuznaczności: klient "Pracujecie w piątek?" gdy dziś piątek → Bot: "Masz na myśli dziś (piątek 2026-06-XX), czy piątek za tydzień?" (NIE woła narzędzia dopóki nie ustali).

### 2d. ChatService — sprawdzić wywołanie build()
- ChatService.php:71 woła `SystemPrompt::build($settings['emoji_enabled'])`. Default $now wystarcza — NIE trzeba zmieniać ChatService funkcjonalnie. (Opcjonalnie można przekazać now dla testowalności, ale nie jest wymagane.) Zweryfikować że nie ma drugiego miejsca budującego prompt.

## Granice
- ShopCalendar.php NIE dotykany (poprawny — dowód #55811).
- Triggery "kiedy wołać narzędzie" z TASK-CHAT-011 ZOSTAJĄ — dokładamy tylko "z czym wołać".
- Strefa zawsze Europe/Warsaw. Dni tygodnia PL z mapy stałej, nie locale.
- ZERO modułu PS. Tylko standalone.
- PHP 8.4 (standalone), PSR-12, type hints, declare(strict_types=1).

## Kryteria akceptacji
1. get_shop_schedule(relative="tomorrow") gdy today=2026-06-04 → date=2026-06-05, working_day=true, server_today=2026-06-04.
2. get_shop_schedule(relative="today") → date=2026-06-04, closed_reason zawiera Boże Ciało (holiday), server_today=2026-06-04.
3. this_friday gdy dziś czwartek 2026-06-04 → 2026-06-05. next_friday gdy dziś czwartek → 2026-06-12. this_thursday gdy dziś czwartek → 2026-06-04 (dziś). next_thursday gdy dziś czwartek → 2026-06-11.
4. date="2025-01-25" (rok przeszły) → error o przeszłym roku + server_today obecne; NIE zwraca harmonogramu.
5. date="2026-07-15" (rok bieżący) → przechodzi, liczy normalnie, server_today obecne.
6. relative i date podane jednocześnie → relative wygrywa.
7. Brak relative i date → today (regresja #55811 niezłamana).
8. SystemPrompt::build() zawiera blok "AKTUALNA DATA: czwartek 2026-06-04" + "Jutro: piątek 2026-06-05" (dla now=2026-06-04). Dni tygodnia po polsku.
9. Few-shot "Pracujecie jutro?" w prompcie pokazuje relative="tomorrow" (nie date=...).
10. php -l clean (oba pliki). Istniejące testy (jeśli są dla ShopCalendar/GetShopSchedule) przechodzą.
11. SMOKE na PROD po deploy: powtórzyć scenariusz #55931 ("Jutro sklep pracuje?") → bot odpowiada poprawnie z datą 2026 (piątek 5 czerwca otwarty), bez "27 stycznia".

## KROK FINALNY — deploy + raport + status + git
- Deploy standalone wg konwencji projektu (CC robi sam — sprawdź jak deployowane były poprzednie taski backend, np. CHAT-T-068; standardowo rsync/git na chat.divezone.pl + restart jeśli wymagany). STOP-point: przed deployem krótko zraportuj wynik php -l + kryteriów 1-10, potem deploy, potem smoke 11.
- Raport: _instances/backend/handoff/CHAT-T-070_done.md (zmiany, wyniki 1-11, potwierdzenie smoke #55931).
- Status: dopisać CHAT-T-070 do _docs/21_STATUS_PROJEKTU.md.
- Git: git status; git add per ścieżka (standalone/src/Tools/GetShopSchedule.php, standalone/src/Chat/SystemPrompt.php, _docs/10_decyzje_projektowe.md, _instances/backend/tasks/CHAT-T-070_*.md, _instances/backend/handoff/CHAT-T-070_done.md); commit wg konwencji (sprawdź git log); git push origin main. Osobny commit "docs:" dla _docs/21_STATUS_PROJEKTU.md po deploy.
