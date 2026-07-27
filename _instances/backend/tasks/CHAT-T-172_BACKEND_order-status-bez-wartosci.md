# CHAT-T-172 — BACKEND — OrderStatus bez wartości zamówienia

**Instancja:** backend
**Pliki:** `standalone/src/Tools/OrderStatus.php` + `standalone/src/Chat/SystemPrompt.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**ADR:** ADR-137
**Karta:** Chat-67

---

## 1. Decyzja (Karol 43b)

Bot NIE podaje wartości zamówienia klientowi. W recenzji conv 767 bot po
weryfikacji mailem podał klientowi wartość zamówienia (555 zł). Karol: to za dużo,
bot ma podawać status i śledzenie, ale NIE kwotę. Zamiast tego kieruje klienta:
(1) zaloguj się i sprawdź, (2) sprawdź e-mail z zamówieniem.

## 2. Przyczyna techniczna — zweryfikowana w kodzie

`OrderStatus.php` linia 59: `SELECT ... o.total_paid ...`. Linia 98:
`'total' => (float) $order['total_paid']`. Pole `total` trafia w tool_result
do bota, więc bot je zna i podaje.

**Naprawa u ŹRÓDŁA, nie w prompcie.** Usunięcie pola z narzędzia jest pewniejsze
niż reguła promptu (prompt można obejść, brakującego pola nie ma jak podać).
Reguła w prompcie jako druga warstwa.

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `OrderStatus.php` (całość) i `SystemPrompt.php` sekcję o statusie
zamówień (grep `zamówien`, `order`, `status`).

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, ADR-ów, innych plików.

## KROK 1 — OrderStatus.php: usuń pole total z wyniku

Usuń z tablicy `$result` klucz `'total'` (~linia 98). Sprawdź `grep -n "total"
OrderStatus.php`, czy `total_paid` nie jest używany w logice poniżej (np. warunek).
Jeśli nigdzie indziej — usuń też z SELECT dla czystości.

## KROK 2 — SystemPrompt: reguła kierująca + zakaz kwoty

W sekcji o statusie zamówień dopisz (dostosuj do stylu):

> **WARTOŚĆ ZAMÓWIENIA — NIE PODAJESZ (ADR-137).** Na pytanie o kwotę/wartość
> zamówienia nie podajesz jej. Kierujesz klienta do dwóch źródeł: zalogowanie się
> na konto (historia zamówień) albo e-mail potwierdzający zamówienie. Status
> realizacji i numer śledzenia możesz podać normalnie — zakaz dotyczy tylko kwoty.

## KROK 3 — walidacja
```
ea-php84 -l standalone/src/Tools/OrderStatus.php
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```
Sprawdź na produkcyjnym MySQL (read-only), że narzędzie nadal zwraca status,
datę, śledzenie — tylko bez `total`. Test realnym zamówieniem.

## KROK 4 — STOP
STOP przed rsync (ADR-089). Czekaj na "deployuj".

## KROK 5 — deploy (po autoryzacji)
Świat 1, dwa pliki osobno:
```
backup → _deploy_bak/CHAT-T-172/
rsync OrderStatus.php → ~/public_html/chat.divezone.pl/src/Tools/
rsync SystemPrompt.php → ~/public_html/chat.divezone.pl/src/Chat/
md5 ↔ prod (oba), ea-php84 -l (oba), smoke /api/health
```
Bez --delete, bez blanket-rsync.

## KROK 6 — status i raport
Dopisz NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`.
```
git add standalone/src/Tools/OrderStatus.php standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-172 backend: OrderStatus bez wartosci zamowienia (ADR-137)"
git push origin main
```
Po deployu osobny commit docs.

---

## Kryterium akceptacji (architekt, replay.py)
1. `get_order_status` nie zwraca pola `total` w tool_result
2. Replay pytania o wartość zamówienia → bot kieruje do logowania/maila, NIE podaje kwoty
3. Status i śledzenie nadal działają
