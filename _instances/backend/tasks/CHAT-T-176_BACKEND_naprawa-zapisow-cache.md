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
