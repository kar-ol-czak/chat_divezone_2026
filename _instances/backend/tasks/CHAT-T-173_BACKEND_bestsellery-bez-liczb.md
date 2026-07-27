# CHAT-T-173 — BACKEND — Bestsellery bez liczb sprzedaży

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php` (tylko prompt, bez ADR)
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**Karta:** Chat-66

---

## 1. Decyzja (Karol 43a)

Bestsellery ZOSTAJĄ jako rekomendacja (standardowe narzędzie sprzedażowe, "nasze
bestsellery" jak w każdym sklepie), ale:
- BEZ liczb sprzedaży (nie ujawniamy `sold_qty`),
- sformułowanie "najpopularniejsze w naszym sklepie są X, Y, Z" (3 produkty),
  NIE "najlepiej się sprzedaje".

Z recenzji conv 660: klient pytał "co się najlepiej sprzedaje", bot odpowiedział
listą bestsellerów. Karol: zostaje, ale bez liczb i z lepszym sformułowaniem.

## 2. Stan obecny — zweryfikowany w kodzie

`SystemPrompt.php` sekcja "POPULARNE PRODUKTY (get_popular_products)" ~linia 666:
- narzędzie zwraca `bestsellers` z polem `sold_qty` (~667),
- wzór narracji (~671) JUŻ brzmi "Najpopularniejsze płetwy paskowe to X, Y i Z" —
  dobre sformułowanie już jest,
- ALE brak jawnego ZAKAZU podawania `sold_qty`. W conv 660 bot użył formy
  "najlepiej się sprzedaje", bo nic tego nie zabrania.

Luka wąska: dodać zakaz ujawniania liczb i utrwalić "najpopularniejsze".

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `SystemPrompt.php` linie ~666-672 (sekcja POPULARNE PRODUKTY).

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, narzędzie
`get_popular_products` (zostaje — `sold_qty` może dalej wracać do bota, chodzi
tylko o to, żeby bot go NIE UJAWNIAŁ), ADR-ów, innych plików.

## KROK 1 — reguła w sekcji POPULARNE PRODUKTY

Dopisz (~672, dostosuj do stylu):

> **NIE UJAWNIAJ LICZB SPRZEDAŻY.** Pole `sold_qty` służy TYLKO do ustalenia
> kolejności bestsellerów — NIGDY nie podawaj go klientowi ("sprzedaliśmy 200
> sztuk", "najlepiej sprzedający się"). Klientowi mówisz "najpopularniejsze
> w naszym sklepie są X, Y, Z" — bez liczb, bez formy "najlepiej się sprzedaje".
> Maksymalnie 3 produkty w rekomendacji popularności.

## KROK 2 — walidacja
```
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```
Brak kolizji z istniejącym wzorem narracji (~671) — nowa reguła go wzmacnia.

## KROK 3 — STOP
STOP przed rsync (ADR-089). Czekaj na "deployuj".

## KROK 4 — deploy (po autoryzacji)
Świat 1, jeden plik:
```
backup → _deploy_bak/CHAT-T-173/
rsync SystemPrompt.php → ~/public_html/chat.divezone.pl/src/Chat/
md5 ↔ prod, ea-php84 -l, smoke /api/health
```

## KROK 5 — status i raport
Dopisz NA GÓRZE `_docs/21_STATUS_PROJEKTU.md`.
```
git add standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-173 backend: bestsellery bez liczb sprzedazy (Chat-66, decyzja 43a)"
git push origin main
```
Po deployu osobny commit docs.

---

## Kryterium akceptacji (architekt, replay.py)
1. Replay "co się najlepiej sprzedaje w płetwach" → bot mówi "najpopularniejsze są X, Y, Z"
2. Brak liczb sprzedaży w odpowiedzi
3. Maksymalnie 3 produkty
