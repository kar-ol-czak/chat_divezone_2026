# CHAT-T-091 — BACKEND: serwis@divezone.pl jako domyślny adres serwisowy (SystemPrompt + config)

**Instancja:** backend (PHP). Mały task: SystemPrompt + seed configu.
**Powiązane:** decyzja 27 (serwis@divezone.pl = domyślny adres dla spraw serwisowych), `_docs/37_` (treść chipa serwis już używa serwis@), CHAT-T-088b (chip serwisowy w drzewie). 
**Status:** ZAMKNIĘTY — WCHŁONIĘTY przez CHAT-T-114 (2026-06-29). Założenie o `sql/035` było nieaktualne (035 zajęte przez sizing); serwis@divezone.pl w SystemPrompt (ścieżka tekstowa, 3 konteksty) + link strony serwisu PL/EN wdrożone w ramach CHAT-T-114 (commit `ca71d43`, deploy na prod). Decyzja 27 zrealizowana. NIE wykonywać osobno. Patrz ADR-105.

---

## CEL
Chip serwisowy w drzewie podaje już serwis@divezone.pl (treść z _docs/37_). Ale ścieżka "klient WPISAŁ pytanie o serwis tekstem" (nie kliknął chipa) nadal kieruje na dive@divezone.pl w SystemPrompt. Ujednolicić: sprawy serwisowe → serwis@divezone.pl w OBU ścieżkach.

## KLUCZOWE ROZRÓŻNIENIE (NIE rób globalnego find-replace!)
`dive@divezone.pl` występuje w SystemPrompt ~14 razy. Decyzja 27 dotyczy TYLKO kontekstów SERWISOWYCH. Ogólny kontakt (zamówienia, dobór sprzętu, godziny, reklamacje, modyfikacje zamówień, dziennikarz) ZOSTAJE dive@divezone.pl. Zmiana TYLKO tam, gdzie chodzi o serwis automatów/sprzętu.

## ZAKRES — DOKŁADNE LINIE (zweryfikowane w SystemPrompt.php)
Zmień dive@divezone.pl → serwis@divezone.pl TYLKO w tych kontekstach serwisowych:
1. **l.86** — "Termin serwisu ustalamy INDYWIDUALNIE — klient kontaktuje się wcześniej mailowo (dive@divezone.pl)..." → serwis@divezone.pl. (czysto serwisowe)
2. **l.289 (SCOPE-004)** — "...Jeśli sprzęt wymaga serwisu, napisz na dive@divezone.pl — wskażemy autoryzowany punkt." → serwis@divezone.pl.
3. **l.304** — "...Dla serwisu zaworów/automatów skontaktuj się z autoryzowanym serwisem — możemy podać kontakt mailowy dive@divezone.pl." → serwis@divezone.pl.

ZOSTAW dive@divezone.pl bez zmian w: l.73 (ogólny email kontaktowy), l.110/114 (dobór/przymiarki), l.151/157 (godziny/odbiór), l.175/178 (ogólne nie-wiem/powiadomienia), l.235 (dziennikarz), l.295 (ogólna odmowa poza-scope), l.334/340 (modyfikacje/weryfikacja zamówień).

UWAGA: jeśli któraś linia miesza serwis z ogólnym (np. l.295 wymienia "serwis" w liście kompetencji, ale kieruje na ogólny dive@) — ZOSTAW dive@, bo to ogólny fallback, nie dedykowana ścieżka serwisowa. W razie wątpliwości: serwis@ tylko tam, gdzie zdanie dotyczy WYŁĄCZNIE serwisu automatu/sprzętu.

## CONFIG (opcjonalnie, spójność z get_shop_links)
Dodać do divechat_shop_config (migracja sql/035 — UWAGA 031/032/033 zajęte; 034 = CHAT-T-090; następny wolny dla 091 = 035): `email_serwis` = 'serwis@divezone.pl', `email_ogolny` = 'dive@divezone.pl'. Wtedy get_shop_links może je zwracać (topic=service/contact). NIE wymagane dla tego taska — decyzja Karola czy teraz czy później (patrz pytanie otwarte).

## PYTANIE OTWARTE (do Karola)
1. Czy dodać email_serwis/email_ogolny do configu (get_shop_links) teraz, czy tylko SystemPrompt? Rekomendacja: dodać do configu też (spójność — bot przez get_shop_links może podać właściwy mail per kontekst).

## KRYTERIA AKCEPTACJI
- [ ] 3 konteksty serwisowe (l.86/289/304) → serwis@divezone.pl.
- [ ] Pozostałe ~11 wystąpień dive@ NIETKNIĘTE.
- [ ] php -l clean. (Brak testów jednostkowych dla promptu — weryfikacja przez grep: serwis@ występuje 3x w kontekstach serwisowych, dive@ w pozostałych.)

## DEPLOY
rsync SystemPrompt.php (ADR-089, 1 plik) — Karol zatwierdza. Jeśli config: migracja na Railway (Karol aplikuje).

## RAPORT
Commit per ścieżka (standalone/src/Chat/SystemPrompt.php, ewent. sql/035*), wg konwencji "CHAT-T-091 backend: serwis@divezone.pl dla ścieżki serwisowej w SystemPrompt (decyzja 27)", push, docs: status.
