# CHAT-T-081 — FRONTEND: Wariant v2 ekranu zachęty (nudge) + przełącznik v1/v2 w panelu

**Instancja:** frontend
**Powiązane:** ADR-090 (decyzja), CHAT-T-056/058/060 (mechanizm nudge), ADR-087 (gating runtime, cache-safe HTML), ADR-061 (Shadow DOM), 116b (moduł PS wgrywa Karol ręcznie)
**Draft designu (źródło prawdy wizualnej):** `_drafts/design_handoff_welcome_prompt/` — `README.md` (tokeny, wymiary, copy) + `Divezone Chat Widget - Hi-Fi.html` (dokładny markup, gradient, bąble, SVG paper-plane)
**Status:** DO ZROBIENIA (faza 1 z ADR-090; A/B + CTR to osobne taski CHAT-T-082/083/084 — NIE w zakresie tego tasku)

---

## CEL

Dodać DRUGI wygląd proaktywnego dymka (nudge): v2 = karta "Welcome Prompt" z draftu (gradient głębi wody, 384px). Wybór v1/v2 sterowany jednym polem w panelu PS. v1 (obecny dymek) zostaje NIETKNIĘTY — rollback = przełączenie pola na `v1`.

**KRYTYCZNE ROZUMIENIE (decyzja 245a):** v2 to NOWY WYGLĄD ISTNIEJĄCEGO DYMKA, który pojawia się auto po X sekundach (`nudge_delay`). To NIE jest nowy ekran po kliknięciu launchera. Cały mechanizm wyzwalania zostaje: ten sam `setupNudge()`, ten sam delay z configu, te same guardy sessionStorage (`dz_nudge_dismissed`/`dz_chat_opened`), to samo dziedziczenie gatingu z launchera. Zmienia się WYŁĄCZNIE funkcja renderująca dymek.

## ZAKRES — co dotykamy

1. `modules/divezone_chat/views/js/widget-loader.js` — dodać `renderNudgeCard()` (v2) obok istniejącego `renderNudge()` (v1, bez zmian); w `setupNudge()` wybór renderu wg `BOOT.nudge.variant`.
2. `modules/divezone_chat/divezone_chat.php` — nowy klucz configu `DIVEZONE_CHAT_NUDGE_VARIANT` (lazy init default `v1`), pole select w panelu (sekcja 5 "Proaktywny dymek"), zapis w submit, dołożenie `variant` do `$boot['nudge']`.

**NIE dotykamy:** `widget-bundle.js` (okno czatu bez zmian), `transport.js`, drabiny ekspozycji, `/token`, gatingu. Zero A/B, zero telemetrii w tym tasku.

---

## CZĘŚĆ A — `widget-loader.js` (v2 render)

### A1. Stała wariantu i odczyt z BOOT
W `setupNudge()` odczytać `var variant = (BOOT.nudge && BOOT.nudge.variant === 'v2') ? 'v2' : 'v1';`. Default twardo `v1` (każda inna/brakująca wartość = v1).

### A2. Rozgałęzienie renderu
W miejscu gdzie dziś `setupNudge()` woła `renderNudge(text)` — rozgałęzić:
```
if (variant === 'v2') { renderNudgeCard(text); } else { renderNudge(text); }
```
Guardy (sessionStorage, recheck po setTimeout), delay, walidacja tekstu — BEZ ZMIAN, wspólne dla obu wariantów.

### A3. `renderNudgeCard(text)` — nowa funkcja (v2)
Karta wg draftu. Reguły wiążące:

- **Pozycja/montaż:** ten sam shadow `root`, ta sama logika `if (nudgeEl) return;` + przypisanie do `nudgeEl` (żeby `hideNudge()` działało identycznie). Pozycjonowanie jak dymek: `position:fixed; right:20px; bottom:88px` (nad launcherem), na mobile zjazd jak v1 (`right:12px; bottom:84px`, szerokość `calc(100vw - 32px)`, max 384px, safe-area).
- **Wymiary/tokeny:** szerokość 384px, radius 18px, `overflow:hidden`, cień `0 18px 60px rgba(0,0,0,0.22),0 3px 10px rgba(0,0,0,0.08)`. Dwie sekcje pionowo: górna gradient `linear-gradient(165deg,#2a8585 0%,#1e6363 34%,#103f4f 70%,#0a2438 100%)` (padding `20px 24px 30px`), dolna biała karta CTA (padding 16px). Pełne wartości: `README.md` sekcja "Design Tokens" + markup w Hi-Fi.html (linie ~610–740).
- **Bąble (BubbleField):** 7 okręgów absolutnie pozycjonowanych, `rgba(255,255,255,0.05–0.14)`, `pointer-events:none`. Pozycje: tablica w Hi-Fi.html (`{x,y,s,o}`, linie ~618+). Czysto dekoracyjne, `aria-hidden`.
- **Mini-nagłówek:** kółko 30px amber z białą ikoną maski (użyć istniejącego `maskIconSvg(15,'#ffffff')` z loadera — NIE duplikować SVG) + "DIVEZONE.PL" (13/700 biały) po lewej; przycisk X po prawej (`aria-label="Zamknij"`).
- **Nagłówek + opis:** teksty STAŁE w kodzie loadera (NIE z configu — patrz niżej rozróżnienie). Nagłówek "Nie wiesz, jaki sprzęt wybrać?" (27/700 biały, `text-wrap:balance`). Opis 2 akapity z `README.md` sekcja "Copy".
- **CTA:** biała karta klikalna w całości (`role="button"`, focus-visible ring `2px solid #1e6363` offset 2px) + okrągły przycisk 46px teal z białym paper-plane. Paper-plane SVG: `viewBox="0 0 24 24"`, path `M21.5 2.5 L2.8 11.2 ...` + linia zagięcia `M9 14.2 L21.5 2.5` opacity 0.35 — DOKŁADNY path skopiować z Hi-Fi.html (linie ~731–734). Tytuł CTA "Porozmawiajmy na czacie" (17/700 #1a1a1a), podpis "Odpowiadamy 24/7" (13/400 #b8b8b8).

### A4. Zachowania (identyczne z v1, inny wygląd)
- Klik w kartę CTA LUB przycisk paper-plane → `openChatFlow()` (ta sama funkcja co v1: ustawia `dz_chat_opened`, `hideNudge()`, ładuje bundle, otwiera czat).
- Klik X → `e.stopPropagation()`, `ssSet('dz_nudge_dismissed','1')`, `hideNudge()`. NIE otwiera czatu.
- `@media (prefers-reduced-motion: reduce)` → bez animacji wejścia.

### A5. Rozróżnienie: co STAŁE w kodzie vs co z configu (WAŻNE)
- **v1 (`renderNudge`):** treść z configu (`nudge_text`), emoji `NUDGE_EMOJI` z kodu. BEZ ZMIAN.
- **v2 (`renderNudgeCard`):** nagłówek, opis, tytuł/podpis CTA = STAŁE w kodzie loadera (to dopracowany copywriting z draftu, nie ma sensu wystawiać go do configu w fazie 1). Pole `nudge_text` z panelu w v2 jest IGNOROWANE (zostaje dla v1). To świadoma decyzja — udokumentować komentarzem w kodzie. Jeśli kiedyś v2 ma brać tekst z configu → osobny task.
- Powód trzymania tekstów w kodzie, nie configu: (1) copywriting finalny z draftu, (2) `pr_configuration` to `utf8` 3-bajt — gdyby v2 miało emoji, i tak musiałoby iść z kodu (jak `NUDGE_EMOJI`). W fazie 1 v2 nie ma emoji w treści.

### A6. CSS v2
Style v2 dołożyć do istniejącego `baseStyle.textContent` w loaderze (ten sam blok co `.dz-nudge`), pod własnymi klasami `.dz-card*` (NIE nadpisywać `.dz-nudge*` v1). Font `"DM Sans",Arial,sans-serif` jak reszta.

---

## CZĘŚĆ B — `divezone_chat.php` (config + panel)

### B1. Stała klucza
Dodać `const KEY_NUDGE_VARIANT = 'DIVEZONE_CHAT_NUDGE_VARIANT';` przy pozostałych `KEY_NUDGE_*`.

### B2. Lazy init / odczyt
W `renderConfigForm()` odczyt z fallbackiem: `$nudgeVariant = Configuration::get(self::KEY_NUDGE_VARIANT); if ($nudgeVariant !== 'v1' && $nudgeVariant !== 'v2') { $nudgeVariant = 'v1'; }`. Wzorować na istniejących odczytach nudge w tej metodzie.

### B3. Pole w panelu (sekcja 5 "Proaktywny dymek")
Dodać NA POCZĄTKU sekcji 5 (przed checkboxem "Wlacz dymek"), select:
```
Wyglad zachety: ( ) Klasyczny dymek (v1)  ( ) Karta premium (v2)
```
Implementacja jako `<select name="nudge_variant">` z dwoma `<option>` (`v1` selected gdy `$nudgeVariant==='v1'`, analogicznie `v2`). Krótki `<small>` opis: v1 = obecny prosty dymek, v2 = nowa karta z gradientem. Zachować styl inline jak reszta sekcji (`$output .= ...`).

### B4. Zapis w submit
W bloku zapisu nudge (`submitDivezoneChatConfig`) dodać:
```
$variant = Tools::getValue('nudge_variant') === 'v2' ? 'v2' : 'v1';
Configuration::updateValue(self::KEY_NUDGE_VARIANT, $variant);
```

### B5. Przekazanie do BOOT
W `hookDisplayFooter()`, w budowaniu `$boot['nudge']` (tam gdzie dziś `enabled`/`delay`/`text`), dodać:
```
'variant' => ($nudgeVariant === 'v2' ? 'v2' : 'v1'),
```
(odczytać `$nudgeVariant` w hooku tak jak czytane są pozostałe pola nudge). To jedna stała wartość — HTML pozostaje cache-safe (ADR-087), identyczny dla wszystkich.

---

## KRYTERIA AKCEPTACJI

1. Panel: nowy select "Wyglad zachety" w sekcji 5, zapisuje się, po reloadzie pokazuje zapisaną wartość.
2. Config `v1`: dymek wygląda i działa DOKŁADNIE jak dziś (pixel-identycznie, regresja zero).
3. Config `v2`: po `nudge_delay` sekundach pojawia się karta gradientowa z draftu nad launcherem; klik karty/paper-plane otwiera czat; X zamyka i nie pokazuje ponownie w sesji.
4. v2 respektuje te same guardy co v1 (raz na sesję, dziedziczy gating launchera, recheck po delay).
5. Tekst nagłówka/opisu/CTA w v2 = stałe z draftu; `nudge_text` z panelu nie wpływa na v2.
6. Brak błędów w konsoli; Shadow DOM izolacja zachowana; mobile (≤599px) karta mieści się w viewport z marginesami i safe-area.
7. Brak jakichkolwiek zmian w `widget-bundle.js`, `transport.js`, `/token`, drabinie ekspozycji.

## WDROŻENIE MODUŁU PS (116b — UWAGA)
Zmiany są w module PS (`modules/divezone_chat/`). CC NIE wgrywa modułu samodzielnie. CC po implementacji odczytuje `.env` i podaje KOMPLETNĄ komendę rsync (port 5739, ścieżka `~/public_html/newtmp2`, `--exclude config_pl.xml`, bez `--delete`), bez placeholderów — Karol wgrywa ręcznie. STOP-point: CC pokazuje komendę i czeka.

## GIT
Konwencja z `git log`. `git add` per ścieżka (tylko 2 zmienione pliki modułu). Po zatwierdzeniu deployu osobny commit `docs:` ze statusem projektu (`_docs/21_STATUS_PROJEKTU.md`).
