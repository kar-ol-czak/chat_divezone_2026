# CHAT-T-167 — BACKEND — Kotwica modelu w zapytaniach o akcesoria

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl) — TYLKO ten jeden plik
**ADR:** ADR-134 (do napisania przez architekta przed deployem)

---

## 1. Kontekst — co się stało

Rozmowa produkcyjna `id=817`, session `c81195d5-ef77-4786-830f-3809a7d3b630`,
2026-07-24. Klient (Tomasz Napierała) pytał o maskę TUSA Intega i szkła
korekcyjne +3,5 na oba oczy.

Bot odpowiedział, że do Integi nie ma dedykowanych szkieł i odesłał klienta
na infolinię. **To była nieprawda.** W katalogu są cztery pasujące produkty:

| id | nazwa | skala |
|----|-------|-------|
| 6573 | Szkło korekcyjne PRAWE TUSA MC211 do maski INTEGA | minusy pełne, plusy bez +3,5 |
| 6577 | Szkło korekcyjne LEWE TUSA MC211 do maski INTEGA | minusy pełne, plusy pełne |
| 6993 | Szkło korekcyjne TUSA INTEGA LEWE BF211 "połówki" + | +1 … +4,5 (z +3,5) |
| 6994 | Szkło korekcyjne TUSA INTEGA Prawe BF211 "połówki" + | +1 … +4,5 (z +3,5) |

Prawidłowa odpowiedź: BF211 lewe + prawe, 179 zł/szt., +3,5 dostępne w obu.

## 2. Przyczyna — potwierdzona pomiarem, nie hipoteza

`search_diagnostics` rozmowy 817, drugie wywołanie `search_products`:

```
query_text     = "szkła korekcyjne TUSA maska nurkowa"
exact_keywords = ["TUSA", "korekcyjne"]
```

Słowo **Intega wypadło z zapytania**, mimo że pierwsze wywołanie w tej samej
rozmowie miało `exact_keywords=["TUSA","Intega"]`.

Dowód, że to jedyna przyczyna — rozmowa `id=770` (2026-07-20), ten sam temat:

```
query_text = "szkła korekcyjne TUSA Intega"
→ zwrócone: 6994 (rrf 0.088, track=name), 6573 (rrf 0.087, track=jargon),
            6577 (rrf 0.086, track=desc)
```

Ta sama wyszukiwarka, ten sam indeks, ten sam tydzień. Różnica wyłącznie
w treści `query_text`. **Indeks i embeddingi są sprawne** — produkty 6573/6577
mają `embedding`, `embedding_name` i `fts_vector` niepuste, `is_active=true`.
Nie zmieniaj pipeline'u embeddingów, to nie tam leży problem.

## 3. Przyczyna wtórna

Bot orzekł niekompatybilność na podstawie zamkniętej listy w opisie **innego**
produktu (MC-7500, id 5125: "pasują do Ceos M-212, Geminus M-28, Splendive II,
Splendive IV"). Zadziałała istniejąca reguła z linii ~479 SystemPrompt.php
("opis produktu tego nie potwierdza"). Reguła jest dobra, ale została użyta
wobec produktu, który nie był właściwym produktem. Nowa reguła musi ją
**poprzedzać**, nie zastępować.

## 4. Trzecia luka

W całej rozmowie 817 bot **ani razu** nie wywołał `get_product_combinations`,
mimo że klient podał konkretną moc (+3,5). Bez tego nawet po naprawie punktów
1-2 bot znajdzie BF211, ale nie potwierdzi dostępności +3,5.

---

## KROK 0 — pull i lektura

```
git pull --rebase
```

Przeczytaj:
- `standalone/src/Chat/SystemPrompt.php` — linie 430-520 (sekcja `search_plan`,
  reguła "zanim powiesz że nie mamy", reguła kompatybilności ~479,
  reguły `get_product_combinations` ~490-512)
- `_docs/10_decyzje_projektowe.md` — ADR-134 (architekt dopisze przed deployem)

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, żadnych plików poza
`standalone/src/Chat/SystemPrompt.php`, ADR-ów, `_ops/newtmp2_root/purge_litespeed.php`.

## KROK 1 — reguła KOTWICA MODELU

Wstaw **przed** istniejącą regułą o cechach technicznych (~linia 479).
Treść merytoryczna (sformułowanie dostosuj do stylu sąsiednich reguł):

> **KOTWICA MODELU W ZAPYTANIACH O AKCESORIA (ADR-134).**
> Gdy w rozmowie padła nazwa konkretnego modelu (maski, automatu, komputera,
> pianki), a klient pyta o akcesorium, część zamienną lub dodatek DO TEGO
> MODELU — nazwa modelu jest OBOWIĄZKOWA w `exact_keywords` każdego kolejnego
> `search_products`. Nie zastępuj jej nazwą kategorii ani ogólnym opisem.
>
> ŹLE: klient pyta o szkła do maski Intega →
> `query="szkła korekcyjne TUSA maska nurkowa"`, `exact_keywords=["TUSA","korekcyjne"]`
>
> DOBRZE: `query="szkła korekcyjne TUSA Intega"`, `exact_keywords=["TUSA","Intega"]`
>
> Producenci nazywają akcesoria od modelu docelowego (MC211 do Intega,
> MC-24 do Ceos). Zgubienie nazwy modelu = zwrócenie akcesoriów do INNYCH
> modeli tej samej marki, które do modelu klienta NIE pasują.

## KROK 2 — reguła ZAKAZ ORZEKANIA O BRAKU BEZ KOTWICY

Bezpośrednio po regule z KROKU 1:

> **ZAKAZ ORZEKANIA "NIE MA / NIE PASUJE" BEZ WYSZUKANIA Z NAZWĄ MODELU
> (ADR-134).** Nie wolno Ci powiedzieć, że do modelu X nie ma akcesorium,
> ani że akcesorium Y do modelu X nie pasuje, dopóki nie wykonasz
> `search_products` z nazwą modelu X w `exact_keywords`.
>
> Lista kompatybilności w opisie JEDNEGO produktu opisuje TYLKO ten produkt.
> Brak modelu X na liście produktu Y **nie jest dowodem**, że do X nic nie ma —
> jest dowodem tylko na to, że Y do X nie pasuje. To sygnał, żeby szukać
> DALEJ z nazwą modelu, nie żeby zamykać temat.
>
> Dopiero gdy wyszukanie z nazwą modelu zwróci 0 wyników — możesz powiedzieć,
> że nie znalazłeś, i zaproponować kontakt.

## KROK 3 — reguła MOC / WARIANT PRZED ODPOWIEDZIĄ O DOSTĘPNOŚCI

Wepnij przy istniejących regułach `get_product_combinations` (~490-512),
NIE twórz osobnej sekcji:

> **PARAMETR LICZBOWY = OBOWIĄZKOWE `get_product_combinations`.** Gdy klient
> podaje konkretną wartość parametru wariantowego (moc szkła +3,5, rozmiar M,
> długość węża 80 cm), NIE odpowiadaj o dostępności na podstawie samego
> `search_products` ani opisu. Wywołaj `get_product_combinations` i sprawdź,
> czy ten konkretny wariant istnieje.
>
> Gdy klient potrzebuje KOMPLETU (lewe + prawe, para), sprawdź kombinacje
> OBU produktów — skale mocy bywają różne dla lewego i prawego.

## KROK 4 — walidacja lokalna

```
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```

Sprawdź, czy nowe reguły nie kolidują z regułą "NIE FABRYKUJ AWARII SYSTEMU"
(~450) i z regułą cech technicznych (~479). Jeśli widzisz sprzeczność —
**zgłoś ją w raporcie, nie rozstrzygaj sam.**

## KROK 5 — STOP

**STOP przed rsync (ADR-089).** Czekaj na słowo "deployuj" od Karola.
Nie wysyłaj niczego wcześniej.

## KROK 6 — deploy (po autoryzacji)

Świat 1, JEDEN plik:

```
backup → _deploy_bak/CHAT-T-167/
rsync -e 'ssh -p 5739' standalone/src/Chat/SystemPrompt.php \
  divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/src/Chat/
md5 local ↔ prod
ea-php84 -l na produkcji
smoke: /api/health
```

**Bez `--delete`. Bez blanket-rsync katalogu `standalone/`.**
Repo ma dryf wobec produkcji (`config/tools.php`, `config/routes.php`).

## KROK 7 — status i raport

Dopisz **NA GÓRZE** `_docs/21_STATUS_PROJEKTU.md`.

Git:
```
git status                      # wypisz untracked
git add standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-167 backend: kotwica modelu w zapytaniach o akcesoria (ADR-134)"
git push origin main
```
Po deployu **osobny** commit `docs(...)` na status.

W raporcie podaj: md5 local i prod, wynik lintu, wynik smoke, oraz każdą
zauważoną sprzeczność z istniejącymi regułami.

---

## Kryterium akceptacji (weryfikuje architekt, nie CC)

Replay pytania z rozmowy 817 na produkcji. Oczekiwane:
1. `search_products` z `Intega` w `exact_keywords` przy pytaniu o szkła
2. w wynikach 6993/6994 (BF211) lub 6573/6577 (MC211)
3. wywołanie `get_product_combinations` przed odpowiedzią o dostępności +3,5
4. brak zdania "nie ma dedykowanych szkieł do Integi"
