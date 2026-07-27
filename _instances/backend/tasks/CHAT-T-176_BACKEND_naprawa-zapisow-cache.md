# CHAT-T-176 — BACKEND — Diagnoza i naprawa nadmiarowych zapisów cache (35% wejścia)

**Instancja:** backend
**Plik:** `standalone/src/AI/ClaudeProvider.php` + `standalone/src/Chat/SystemPrompt.php` (diagnoza, potem naprawa)
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**ADR:** ADR-138 nota (korekta kierunku — architekt dopisze)
**Karta:** Chat-70 (przeformułowana z "cache historii" na "napraw zapisy cache")

---

## 1. Dlaczego zmiana kierunku (nie cache historii)

T-175 miał dołożyć cache historii. POMIAR baseline (KROK 1 T-175 + weryfikacja
architekta na 318 rozmowach, 30 dni) pokazał, że to błędny cel:
- **cache-miss: tylko 6,7%** wejścia (to jedyne, co cache historii mogłoby przenieść)
- **cache_read: 58,1%** (już oszczędzane)
- **cache_write (zapis 1,25×): 35,1%** ← TU jest palnik kosztu

Dokładanie historii do cache pomnożyłoby zapisy 1,25×, nie zmniejszyło koszt.
Realny problem: 35% wejścia idzie na PONOWNE ZAPISY cache. To trzeba naprawić.
T-175 wstrzymany (kod w repo, nie wdrażać).

## 2. Diagnoza wstępna architekta (zweryfikowana, nie domysł)

Dwie współprzyczyny nadmiarowych zapisów, obie potwierdzone:

**A. Zmienna treść w prefiksie system promptu.** `SystemPrompt.php` wstrzykuje
per rozmowa: `{$todayLabel}`/`{$tomorrowLabel}` (linia 61, blisko POCZĄTKU),
`{$brands}`/`{$banned}` (1038-1039), `{$emojiRule}` (1073). Cache działa na
PREFIKSIE — zmienna data wysoko w promptcie unieważnia cache całego ogona (>1000
linii) przy KAŻDEJ zmianie daty (codziennie). Dowód: średni cache_creation na
rozmowę wysoki każdego dnia (34k-194k tok), nie jednorazowy.

**B. TTL 5 minut kontra rozproszony ruch.** Cache ephemeral żyje 5 min. Ruch
divezone to kilkanaście-kilkadziesiąt rozmów/dzień, rzadko dwie w 5 min. Każda
rozmowa po wygaśnięciu TTL płaci pełny zapis 1,25× za system prompt, nie
odzyskuje odczytem. Dowód: zapisy codzienne i wielokrotne, nie raz dziennie.

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `SystemPrompt.php` (jak budowany prompt, gdzie zmienne),
`ClaudeProvider.php` linie 105-113 (cache_control system).

**NIE RUSZAJ:** tools.php, routes.php, ChatService.php, ADR-ów, migracji,
kodu T-175 (wstrzymany, nie wdrażać).

## KROK 1 — potwierdź diagnozę pomiarem

Zmierz, ile z cache_creation to system prompt. Odtwórz 2 rozmowy w odstępie
>5 min tego samego dnia. Jeśli DRUGA płaci pełny cache_creation mimo tego samego
system promptu → potwierdza TTL (przyczyna B). Jeśli zmiana daty (test: symuluj
inny todayLabel) unieważnia cache → potwierdza przyczynę A.

## KROK 2 — NAPRAWA A: przenieś zmienną treść z prefiksu

Cache'owalny prefiks musi być STAŁY. Przenieś zmienne (`{$todayLabel}`,
`{$brands}`, `{$emojiRule}`) z system promptu do... rozważ dwie opcje, wybierz
w raporcie z uzasadnieniem:
- **opcja 1:** zmienne do OSTATNIEJ części promptu (za stałym cache'owanym
  blokiem) — wtedy stały prefiks się cache'uje, zmienny ogon nie. Wymaga rozbicia
  system na 2 bloki: stały (cache_control) + zmienny (bez cache).
- **opcja 2:** data/kontekst do pierwszej wiadomości user zamiast system promptu.
Opcja 1 jest czystsza (nie miesza ról). Rekomendacja architekta: opcja 1.

## KROK 3 — NAPRAWA B: wydłuż TTL cache (jeśli KROK 1 potwierdzi)

Anthropic wspiera cache 1-godzinny (`cache_control: {type: ephemeral, ttl: "1h"}`)
obok domyślnego 5-min. Zapis 1h kosztuje 2× (drożej niż 1,25× dla 5min), ale
jeśli ruch jest rozproszony w godzinach, 1h TTL łapie znacznie więcej odczytów.
POLICZ w raporcie: przy profilu ruchu divezone (rozkład odstępów między
rozmowami) czy 1h TTL się opłaca mimo droższego zapisu. To wymaga danych o
rozkładzie odstępów — sprawdź `started_at` kolejnych rozmów.

## KROK 4 — POMIAR PO + walidacja
```
ea-php84 -l (oba pliki)
```
Odtwórz te same rozmowy co KROK 1. Udowodnij spadek udziału cache_write.
Cel: cache_write z 35% w dół, cache_read w górę.

## KROK 5 — STOP przed rsync (ADR-089).

## KROK 6 — deploy (po autoryzacji), pomiar po, docs, 2 commity.

---

## Kryterium akceptacji (architekt, pomiar na 318-rozmowowej próbce po tygodniu)
1. Udział cache_write (dziś 35,1%) wyraźnie spada
2. Zero regresji w treści promptu (bot nadal zna datę, marki, reguły)
3. Naprawa oparta na potwierdzonej przyczynie (A, B, lub obie), nie na zgadywaniu
4. Jeśli TTL 1h nie opłaca się przy tym profilu ruchu → zostaw 5min, napraw tylko A

---

## Wynik (CC, 2026-07-27) — WDROŻONE, z jednym kryterium nierozstrzygniętym

### KROK 1 — pomiar: diagnoza wstępna potwierdzona co do mechanizmu, obalona co do skali

Baseline odtworzony (30 dni, 319 rozmów, 1631 wywołań): miss 6,8% / read 58,3% /
**write 34,9%**. Koszt $126,83, z czego **sam zapis cache $93,29 = 74% rachunku**.
Prefiks cache = narzędzia (7,0 tys. tok) + system prompt (**44,9 tys. tok**, 121 KB).

**Przyczyna A (zmienna data/chipy w prefiksie) — REALNA, ale kosztuje ~1%.**
Z 65 rozmów startujących <5 min po poprzedniej tylko **7** zapłaciło zimny zapis
(282 tys. tok = 1,1% zapisów). Data nie zdąża zaszkodzić: TTL 5 min wygasa na długo
przed północą. Diagnoza wstępna („średni cache_creation wysoki każdego dnia")
mierzyła skutek innej przyczyny (patrz C).

**Przyczyna B (TTL 5 min) — mechanizm potwierdzony (59 wywołań z przerwą >5 min
płaci pełny zapis, śr. 35 927 tok), ale TTL 1h SIĘ NIE OPŁACA.** Odstępy między
rozmowami: <5 min 20%, 5min–1h 33%, 1h–4h 32%, >4h 14%. TTL 1h podnosi trafienia
z 20% na 54%, ale zapis drożeje 1,25× → 2×: **31,1M vs 30,2M jednostek — w granicach
błędu.** Zgodnie z kryterium 4 zostaje 5 min.

**Przyczyna C — NIEROZPOZNANA W DIAGNOZIE WSTĘPNEJ, to ona jest palnikiem.**
Prod ma włączony `thinking` (`reasoning_effort=minimal` → `budget_tokens=1024`).
Anthropic dokłada wtedy ~22-tokenowy wstrzyk **przed blokiem narzędzi**, ale tylko
gdy ostatnia wiadomość jest od użytkownika — po `tool_result` wstrzyku nie ma.
Skutek: **każda rozmowa tworzy DWA prefiksy cache zamiast jednego.**

| sonda | kształt „user" | kształt „po tool_result" |
|---|---|---|
| z `thinking` (jak prod) | 51 577 | 51 555 |
| bez `thinking` | 51 555 | 51 555 |

Na PROD ten sam wzorzec: +22 w **292/323** wywołań pierwszych w turze, delta 0
w **315/320** wywołań w pętli narzędzi. To **47% wszystkich zapisów cache**
(11,67M z 24,88M). Sprawdzone też, czy da się to obejść breakpointem na narzędziach
(pomysł breakpointu #2 z T-175): **nie da się** — wstrzyk leży przed narzędziami,
sam segment narzędzi też rozjeżdża się o 22 (6689 vs 6667).

### KROK 2 — naprawa A (opcja 1, rekomendacja architekta) — WDROŻONA

- `SystemPrompt::buildStatic()` (cache'owany prefiks) + `buildVolatile()` (kotwica
  daty); `build()` zostaje jako sklejka dla zgodności wstecznej.
- `ChatService` wysyła DWA bloki systemowe: stały (`cacheable=true`) i zmienny
  (data + kontekst chipów tej tury, `cacheable=false`).
- `ClaudeProvider::buildSystemBlocks()` stawia `cache_control` na **ostatnim bloku
  cacheable**, nie na ostatnim w ogóle. OpenAI nie wymagał zmian (i tak przepuszcza
  wiele bloków system po kolei). Kontrakt opisany w `AIProviderInterface`.
- Przy okazji: usunięty render „piątek **poniedziałek 2026-07-27**" w przykładzie
  dialogu (linia 201 wstrzykiwała `$todayLabel` w zdanie, które już miało dzień
  tygodnia) oraz zamiana arytmetyki `1 + count($trimmedHistory)` na realną długość
  tablicy (dwa bloki system rozjechałyby zapis nowych wiadomości o jedną pozycję).

Walidacja na żywym API **wdrażanym kodem, przed deployem**:
```
dzis 27.07  (zimny start)      write=51562  read=0
JUTRO 28.07 (inna doba)        write=0      read=51562   <- wcześniej pełny zapis
28.07 + kontekst chipow        write=0      read=51562   <- wcześniej pełny zapis
```

### KROK 3 — TTL 1h: ODRZUCONE świadomie, na liczbach (kryterium 4)

### KROK 4/6 — deploy i pomiar po

Wdrożone rsync plikowym (NIE całego `standalone/` — w drzewie roboczym leżały cudze
zmiany CHAT-T-090). Kopia zapasowa: `_deploy_bak/CHAT-T-176/`. md5 local↔prod zgodne
4/4, `ea-php84 -l` czysto, `/api/health` 200, testy na PROD 20/20.

Realny czat przez PROD (rozmowa 884, oznaczona regułą E): bot odpowiedział
„Dziś jest **poniedziałek, 27 lipca 2026**", jutro „**wtorek, 28 lipca**",
oba przez `get_shop_schedule` — **kryterium 2 spełnione, zero regresji**.
Prefiksy po deployu: 51562 / 51540, druga tura czyta oba (`write=0`).

### Stan kryteriów

| # | kryterium | stan |
|---|---|---|
| 1 | udział cache_write wyraźnie spada | **NIE spełnione samą naprawą A** (~1%). Lewar to przyczyna C — decyzja Karola |
| 2 | zero regresji treści promptu | spełnione (rozmowa 884) |
| 3 | naprawa na potwierdzonej przyczynie | spełnione — A potwierdzona pomiarem i naprawiona, C zdiagnozowana i zmierzona |
| 4 | TTL 1h jeśli się nie opłaca → zostaw 5 min | spełnione, zostaje 5 min |

### Przyczyna C — przekazana architektowi, decyzja podjęta

CC zgłosił C wraz z liczbami (ok. −50% zapisów cache ≈ $43–46/30 dni ≈ 35% całego
rachunku AI). Karol rozstrzygnął kierunkiem 62 → **ADR-139 + CHAT-T-177**: zamiast
twardego wyłączenia thinking — migracja na `claude-sonnet-5` z **adaptive thinking**
(`budget_tokens` jest deprecated na 4.6 i zwraca 400 na Sonnet 5). Adaptive thinking
zachowuje breakpointy cache, więc znosi podwójny prefiks, a jednocześnie nie odbiera
modelowi myślenia tam, gdzie jest potrzebne. **Domknięcie kryterium 1 należy do T-177,
nie do tego taska.**

Pozostaje otwarty drugi lewar strukturalny: **skrócenie system promptu** (44,9 tys.
tokenów) tnie koszt proporcjonalnie. Uwaga: T-177 mierzy nowy tokenizer Sonnet 5
(+do 35% tokenów), więc wartość tego lewara po migracji rośnie.

### Uwaga o T-175

Kod T-175 leżał **niezacommitowany** w drzewie roboczym tego samego `ClaudeProvider.php`
(ADR-138 mówi „kod zostaje w repo" — w repo go nie było). Odłożony bez straty do
`_diag_local/t175_odlozone/` (patch + kopia pliku), plik cofnięty do HEAD, żeby T-176
nie przemycił T-175 na produkcję. Powrót: `git apply
_diag_local/t175_odlozone/CHAT-T-175_ClaudeProvider.patch`. Pomiar z KROKU 1 dodatkowo
pokazuje, że breakpoint #2 (narzędzia) z T-175 nie mógłby pomóc — wstrzyk thinking
leży przed narzędziami.
