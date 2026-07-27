# CHAT-T-102 — INSTANCJA: embeddings — Inwentaryzacja czartów rozmiarowych w katalogu

> **Powiązane:** ADR-100 (krok 8, niezależny od lokalizacji danych), ADR-099. Cel: twarde liczby — które produkty/marki mają czart graficzny, tekstowy, lub nic. Bez tego projektujemy moduł na ślepo.
> **Charakter:** diagnoza READ-ONLY (`divezone_chat_reader`) + analiza opisów. Zero write.

## CEL
Przejść katalog w zakresie kategorii rozmiarowych i zaraportować per produkt: czy ma tabelę rozmiarową jako GRAFIKĘ (img w opisie), jako TEKST (tabela/lista/kalkulator inline), czy NIE MA nic. Wynik = podstawa do planu pozyskania danych (OCR / research / od dostawcy).

## ZAKRES (ADR-100, P62/P65a)
- Marki obecne w kategoriach rozmiarowych: **skafandry suche, skafandry mokre, buty, rękawice** (i powiązane akcesoria z rozmiarami).
- **Bez płetw** kaloszowych/paskowych w tej iteracji (drugie przejście później).
- Kategorie: użyj zweryfikowanych id z CHAT-T-101 (mokre CIEPŁE=337/ZIMNE=367; suche, buty, rękawice — id z diagnozy). Jeśli CHAT-T-101 jeszcze nie skończone, zweryfikuj id samodzielnie z `pr_category_lang`.

## SYGNAŁY KLASYFIKACJI (heurystyki — opisz jak zastosowane)
Dla każdego produktu w zakresie zbadaj `pr_product_lang.description`:
- **GRAFIKA**: obecność `<img>` którego URL/nazwa sugeruje tabelę rozmiarową (np. zawiera „size", „rozmiar", „chart", „tabela", albo to OSTATNI img w opisie a brak tabeli tekstowej). UWAGA: ostatni img bywa lifestyle, nie tabela (lekcja z CHAT-T-099) — oznacz jako „grafika? do weryfikacji" gdy niepewne, nie przesądzaj.
- **TEKST**: obecność `<table>`, listy rozmiarów z wymiarami, albo inline kalkulatora (PHP/JS `dobierzRozmiar`/`tabela=`).
- **NIC**: brak powyższych, choć produkt ma warianty rozmiaru (`pr_product_attribute`).
- **MA WARIANTY?**: niezależnie — czy produkt ma w ogóle warianty rozmiaru (z `pr_product_attribute` + grupa ROZMIAR). Produkt z wariantami ale bez tabeli = priorytet do uzupełnienia.

## OUTPUT
`_reports/CHAT-T-102_inwentaryzacja_czartow.csv`, kolumny:
`product_id, nazwa, marka, kategoria, ma_warianty_rozmiar (T/N), typ_czartu (grafika/tekst/nic/grafika_do_weryfikacji), url_obrazka_jesli_grafika, ma_kalkulator_inline (T/N), uwagi`

Plus podsumowanie agregujące (w raporcie i jako nagłówek/komentarz CSV lub osobny mały plik):
- liczba produktów w zakresie per kategoria,
- rozkład typ_czartu per marka (ile grafik, ile tekstów, ile nic),
- lista marek występujących w zakresie (kandydaci do pozyskania chartów — które już mamy: Scubapro, Bare; których brakuje).

## KROKI
**KROK 0** — `git pull origin main`. Przeczytaj ADR-100, ten task. Jeśli `_docs/40_diagnoza_atrybuty_rozmiary_prestashop.md` (CHAT-T-101) istnieje — użyj zweryfikowanych id kategorii/grup stamtąd.
**KROK 1** — Ustal produkty w zakresie (kategorie rozmiarowe). Bulk, nie N+1 (lekcja z CHAT-T-099 — `IN(...)`, nie pętla).
**KROK 2** — Klasyfikuj każdy wg sygnałów. Zapisuj heurystykę i przykłady brzegowe.
**KROK 3** — Wygeneruj CSV + podsumowanie agregujące.
**KROK 4** — status + raport. `git add` per ścieżka (skrypt inwentaryzacji w `embeddings/`, CSV w `_reports/`) → commit wg konwencji → push. Raport: liczby zbiorcze (ile grafik/tekstów/nic, ile marek, które marki bez chartów) — to wejście do planu pozyskania danych.

## POZA ZAKRESEM
- OCR/pozyskiwanie samych tabel (osobny etap po inwentaryzacji).
- Płetwy (drugie przejście).
- Jakikolwiek write.

## WYNIK (2026-06-19, DONE)
Inwentaryzacja READ-ONLY (`divezone_chat_reader`, zero write). Bulk `IN(...)`, nie N+1.
Id kategorii z CHAT-T-101: mokre 337/367/421, suche 425/424/477, buty 212/208, rękawice 218/210
(205 to umbrella pełen akcesoriów — NIE brany jako parent; ocieplacze/zawory/torby poza zakresem).

**Dostarczone:**
- `embeddings/inventory_size_charts.py` — odtwarzalny skrypt (pymysql, tunel 33060).
- `_reports/CHAT-T-102_inwentaryzacja_czartow.csv` — 287 wierszy (per produkt).
- `_reports/CHAT-T-102_inwentaryzacja_czartow_podsumowanie.md` — agregacja.

**Liczby zbiorcze (287 produktów w zakresie):**
- typ czartu: **grafika 48 | tekst 15 | grafika_do_weryfikacji 104 | nic 120**.
- `tekst` = realne tabele PROGÓW w HTML (≥2 wymiary ciała) — 15 szt., dane już strukturalne
  (BARE Guppy/Tadpole/dziecięce, SANTI suche, FOURTH ELEMENT, URSUIT, Scubapro Rebel dziecięcy).
- `do_weryfikacji` 104 = 46 tabel samych etykiet S/M/L bez wymiarów + 58 wzmianek tekstowych
  (czart prawdopodobnie grafiką — NIE przesądzane, lekcja CHAT-T-099 „ostatni img bywa lifestyle").
- **Kalkulator inline = 0** w całym zakresie (potwierdza CHAT-T-101: progi nie żyją w interaktywnym kalkulatorze w DB).
- Produkty z wariantami rozmiaru ALE bez pewnego czartu (priorytet pozyskania): **209**.

**Marki wymagające pozyskania chartów (poza Scubapro/Bare, które już mamy — ADR-099):**
największe: **SANTI 32, AQUALUNG 20, MARES 19, TECLINE 16, TUSA 14, KWARK 8, Avatar 7, Outlet 7,
TUSA SPORT 6, Si-Tech 5**; pojedyncze: Typhoon, NO GRAVITY, SHOWA, FOURTH ELEMENT, BODY GLOVE,
Aqua Zone, URSUIT, VDS System, WATERPROOF, OMS, SEAL, SSI, EQUES, SCUBATECH, Checkup. Razem ~25 marek.

**Uwaga jakościowa:** „tekst" celowo wąskie (próg ≥2 wymiary ciała) — wczesna wersja łapała spec-tabele
z „Waga" produktu (fałszywe pozytywy, np. AQUALUNG Dynaflex); zaostrzono dla wiarygodności liczby
gotowych danych strukturalnych. Pozostałe charty wymagają OCR/grafiki — stąd duże `do_weryfikacji`/`nic`.
