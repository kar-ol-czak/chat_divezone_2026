# CHAT-T-174 — BACKEND — Wzmocnienie reguły bestsellerów (limit 3 + zakaz "lider sprzedaży")

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**Karta:** Chat-66 (T-173 nie spełnił kryterium, to poprawka)

---

## 1. Dlaczego poprawka

T-173 wdrożył regułę "NIE UJAWNIAJ LICZB SPRZEDAŻY" (linia ~673). Replay
weryfikacyjny (conv 862, 2026-07-26) pokazał, że reguła działa POŁOWICZNIE:
- ✅ bot nie podał liczb sprzedaży
- ❌ bot podał **5 produktów**, mimo reguły "maksymalnie 3"
- ❌ bot użył **"lider sprzedaży"** przy pierwszym produkcie — forma odnosząca się
  do sprzedaży, nie popularności, dokładnie to co decyzja 43a miała wyeliminować

Kryterium akceptacji T-173 (max 3 produkty) NIE spełnione. To nie błąd wdrożenia
(md5 zgodne, marker obecny) — reguła jest za słaba.

## 2. Przyczyna słabości — zweryfikowana w kodzie

Linia 673: limit "Maksymalnie 3 produkty" jest wciśnięty na końcu długiego zdania
z wieloma instrukcjami, więc model go gubi. "Lider sprzedaży" nie jest jawnie
zakazany (reguła wymienia tylko "najlepiej się sprzedaje"), a to naturalny synonim.
Dodatkowo linia 668 opisuje sekcję jako "top sprzedaży", co podsuwa język sprzedażowy.

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `SystemPrompt.php` linie 668-673 (cała sekcja POPULARNE PRODUKTY).

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, narzędzie, ADR-ów,
innych plików. TYLKO ta sekcja promptu.

## KROK 1 — wydziel limit 3 do osobnego, mocnego zdania

Obecnie limit jest na końcu zdania o sold_qty. Wydziel go na osobną linię
z naciskiem (dostosuj do stylu):

> **MAKSYMALNIE 3 PRODUKTY.** W rekomendacji popularności wymieniasz DOKŁADNIE
> do 3 produktów, nigdy więcej — nawet jeśli narzędzie zwróci ich więcej. Bierzesz
> 3 pierwsze z listy bestsellers.

## KROK 2 — rozszerz zakaz form sprzedażowych

W regule o sold_qty rozszerz listę zakazanych sformułowań (dostosuj):

> Zakazane sformułowania (odnoszą się do WOLUMENU SPRZEDAŻY, nie popularności):
> "najlepiej się sprzedaje", "lider sprzedaży", "bestseller sprzedaży",
> "sprzedaliśmy X", "hit sprzedaży", "top sprzedaży". Dozwolone: "najpopularniejsze",
> "najczęściej wybierane przez naszych klientów", "cieszy się dużym zainteresowaniem".

## KROK 3 — złagodź "top sprzedaży" w opisie sekcji (linia ~668)

Linia 668 opisuje `bestsellers` jako "top sprzedaży, z sold_qty". To wewnętrzny
opis dla modelu, ale podsuwa język. Zmień na neutralny, np. "najpopularniejsze
(kolejność wg sold_qty, pole wewnętrzne)". Sens techniczny bez znaczenia — chodzi
o to, by model nie kopiował "sprzedaży" do odpowiedzi.

## KROK 4 — walidacja
```
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```

## KROK 5 — STOP
STOP przed rsync (ADR-089). Czekaj na "deployuj".

## KROK 6 — deploy (po autoryzacji)
Świat 1, jeden plik:
```
backup → _deploy_bak/CHAT-T-174/
rsync SystemPrompt.php → ~/public_html/chat.divezone.pl/src/Chat/
md5 ↔ prod, ea-php84 -l, smoke /api/health
```

## KROK 7 — status i raport
Dopisz NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`.
```
git add standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-174 backend: wzmocnienie regul bestsellerow - limit 3 + zakaz lider sprzedazy (Chat-66)"
git push origin main
```
Po deployu osobny commit docs.

---

## Kryterium akceptacji (architekt, replay.py — TWARDE, bo T-173 zawiódł)
Replay "co się najlepiej sprzedaje z płetw paskowych":
1. DOKŁADNIE max 3 produkty (nie 5)
2. ZERO form: "lider sprzedaży", "najlepiej się sprzedaje", "top sprzedaży"
3. Użyte "najpopularniejsze" lub równoważne
Jeśli którykolwiek punkt nie przejdzie — reguła nadal za słaba, NIE zamykać.
