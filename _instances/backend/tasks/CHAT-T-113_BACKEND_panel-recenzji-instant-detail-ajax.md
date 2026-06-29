# CHAT-T-113 — BACKEND: panel recenzji — ładowanie rozmowy „instant" (AJAX zamiast przeładowania strony)

**Instancja:** backend (moduł PS `modules/divezone_chat/` PHP 7.2 + standalone API PHP 8.4).
**Powiązane:** ADR-102 (panel recenzji), CHAT-T-104/105/106/110, CHAT-T-111 (źródło prawdy `divechat_messages`). Decyzja Karola 2026-06-29: obecne ~4s/klik jest niedopuszczalne, ma być „instant".
**Status:** READY-FOR-CC (po potwierdzeniu źródła detalu — patrz zależność CHAT-T-111).

## PROBLEM (zdiagnozowany)
Klik w rozmowę = ~4s + przeładowanie całej strony. Przyczyny strukturalne:
1. **Pełny reload powłoki back-office PrestaShop** na każdy klik — panel to server-side HTML w `AdminDivezoneChatController.php` (`renderConversationsSection` l.1077, `renderReviewListItem` l.1237 → zwykły `<a href>` do nowego URL admina). Zero AJAX.
2. **4 sekwencyjne, blokujące wywołania cross-domain HMAC** divezone.pl → chat.divezone.pl na każdy klik (`callBackend` l.3031, `file_get_contents` + świeży TLS, timeout 10s):
   - `GET /api/whoami` (l.165, na każdy render),
   - `GET /api/admin/review?status=…` — **cała lista, re-fetch na każdy klik** (l.1177),
   - `GET /api/conversations/{session_id}` — detal (l.1486),
   - `GET /api/admin/review/{conversationId}` — stan recenzji, **osobny round-trip** (l.1803).
3. Lista przebudowywana po stronie serwera przy każdym kliku (redundantna praca).
4. `SELECT *` + `json_decode` całego `messages` JSONB w detalu.

To NIE jest wolne SQL — to round-tripy + bootstrap PS. Stąd realna wykonalność „instant".

## CEL
Klik w rozmowę → detal+recenzja podmieniają się **w miejscu** (bez reloadu powłoki PS, bez re-fetchu listy), sub-sekundowo. „Zapisz recenzję" również bez pełnego reloadu.

## IMPLEMENTACJA
1. **AJAX detail load (rdzeń):** klik w element listy → `fetch()` do `ajaxProcess*` w `AdminDivezoneChatController` (PS ajax endpoint, ten sam kontroler, `&ajax=1&action=…`), który zwraca TYLKO HTML detalu+recenzji (lub JSON do złożenia po stronie JS). JS podmienia panel detalu. Brak nawigacji = brak bootstrapu PS, brak re-fetchu listy, brak `whoami`.
2. **Zbij detal + stan recenzji w 1 round-trip:** nowy/rozszerzony endpoint standalone, np. `GET /api/admin/review/{conversationId}/full` (albo `/api/conversations/{sid}?include=review`) zwracający detal rozmowy + stan recenzji razem. Z 2 wywołań cross-domain → 1.
3. **`whoami` tylko raz** — przy pierwszym ładowaniu panelu, nie na każdy detal.
4. **Detal z `divechat_messages`** (spójne z CHAT-T-111, wariant A) — zamiast `json_decode` całego JSONB; pobranie per-wiadomość, lekkie.
5. **Zapis recenzji AJAX:** `submitDivezoneChatReview` → `fetch()` POST, aktualizacja statusu/badge w miejscu, bez reloadu.
6. **(Opcjonalnie) keep-alive/reuse** kanału serwerowego + cache `whoami` w sesji admina; prefetch detalu na `hover` elementu listy (perceived-instant).

## KRYTERIA AKCEPTACJI
- [ ] Klik w rozmowę aktualizuje panel detalu BEZ pełnego przeładowania strony.
- [ ] Detal + stan recenzji = 1 wywołanie cross-domain (nie 2); brak re-fetchu listy i `whoami` na klik.
- [ ] Czas do treści < ~800 ms w typowym przypadku (z prefetch/hover — perceived instant).
- [ ] „Zapisz recenzję" bez reloadu; lista/badge odświeżają się w miejscu.
- [ ] Filtry/segmenty (CHAT-T-106/110) działają jak dotąd; brak regresji uprawnień (HMAC/role).
- [ ] Degradacja: błąd fetch → czytelny komunikat w panelu detalu, nie psuje listy.

## POZA ZAKRESEM
Przepisanie panelu na SPA standalone (zakładka „Rozmowy" w chat.divezone.pl/admin jest „Wkrótce"); to osobna, większa decyzja architektoniczna. Tu: minimalny, wysokoefektowny lifting istniejącego panelu PS.
