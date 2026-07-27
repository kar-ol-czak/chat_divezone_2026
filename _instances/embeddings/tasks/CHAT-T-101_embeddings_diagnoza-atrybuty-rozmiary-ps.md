# CHAT-T-101 — INSTANCJA: embeddings — Diagnoza schematu atrybutów rozmiarów w PrestaShop (przed projektem modułu)

> **Powiązane:** ADR-100 (źródło prawdy → PrestaShop, krok 2), ADR-099 (long format), ADR-098. Cel: NIE stworzyć trzeciego bytu obok natywnych atrybutów Presty — najpierw zobacz z REALNEJ bazy, jak Presta trzyma rozmiary.
> **Charakter:** czysta diagnoza READ-ONLY (`divezone_chat_reader`). Zero write. Wynik = dokument analityczny, na którym oprzemy projekt modułu.

## CEL
Zdiagnozować z REALNEJ bazy PrestaShop, jak obecnie przechowywane są rozmiary/warianty produktów rozmiarowych, żeby zaprojektować mini moduł rozmiarów (ADR-100 pkt 2) spójnie z natywną strukturą Presty, a nie obok niej.

## ⚠️ ZASADA
Diagnoza z DANYCH, nie z założeń (zasada projektu). Każdy wniosek poparty realnym zapytaniem i przykładem. Read-only — jeśli kuszą zmiany, to nie ten task.

## PYTANIA DIAGNOSTYCZNE (na każde odpowiedz danymi + przykładem)

**1. Jak Presta trzyma rozmiar jako atrybut/wariant?**
- Grupy atrybutów rozmiarowych: `pr_attribute_group` + `pr_attribute_group_lang` (znamy z CHAT-T-099: ROZMIAR id=27, ROZMIAR MĘSKI=29, ROZMIAR DAMSKI=30). Potwierdź pełną listę grup związanych z rozmiarem.
- Wartości: `pr_attribute` + `pr_attribute_lang` — jakie etykiety realnie występują (S/M/L, liczby Bare, „MT", „6 Plus" itd.). Wypisz dystynktne wartości per grupa.
- Powiązanie z produktem: `pr_product_attribute` (kombinacje) + `pr_product_attribute_combination`. Jak rozmiar konkretnego produktu jest reprezentowany.

**2. Czy jest gdziekolwiek miejsce na PROGI (liczby), czy tylko etykiety?**
- Sprawdź `pr_feature` / `pr_feature_value` (cechy) — czy ktoś używał ich do wymiarów.
- Sprawdź czy w opisach (`pr_product_lang.description`) są inline tabele/skrypty (znamy: PHP/JS kalkulatory inline — ilu produktów dotyczy, z grubsza).
- Wniosek: czy progi mają JAKIEKOLWIEK natywne miejsce (oczekiwane: NIE — stąd potrzeba modułu).

**3. Jak wygląda mapowanie marka→rozmiary?**
- `pr_manufacturer` (znamy BARE=11, SCUBAPRO=18) — pełna lista marek W KATEGORIACH ROZMIAROWYCH (skafandry suche/mokre, buty, rękawice).
- Czy różne produkty tej samej marki dzielą te same wartości atrybutu rozmiaru (potwierdzenie „system wspólny per marka" z ADR-099).

**4. Identyfikacja kategorii rozmiarowych.**
- Potwierdź id kategorii: skafandry mokre (znamy CIEPŁE=337, ZIMNE=367), skafandry SUCHE (handoff wspominał 205/477 — zweryfikuj realnie), buty, rękawice. Podaj realne id + nazwy.

**5. Rekomendacja integracyjna (na podstawie 1–4).**
- Czy moduł powinien: (a) mieć własne tabele `divezone_size_*` luźno powiązane z product_id, czy (b) rozszerzać natywne mechanizmy. Uzasadnij z tego co zobaczyłeś.
- Jak strona produktu mogłaby renderować tabelę (gdzie wstrzykiwać) — co Presta udostępnia.
- Jak czat (read-only) ma czytać progi z nowego modułu — przez bezpośredni SELECT na tabele modułu czy endpoint.

## KROKI
**KROK 0** — `git pull origin main`. Przeczytaj ADR-100, ADR-099 w `_docs/10_decyzje_projektowe.md`. Potwierdź dostęp `divezone_chat_reader`.
**KROK 1** — Wykonaj zapytania diagnostyczne (pytania 1–4). Zapisuj zapytania + wyniki.
**KROK 2** — Napisz dokument `_docs/40_diagnoza_atrybuty_rozmiary_prestashop.md`: per pytanie odpowiedź + przykładowe dane + zapytanie. Sekcja 5 = rekomendacja integracyjna.
**KROK 3** — status + raport. `git status` → `git add` per ścieżka (dokument diagnozy + ewentualne skrypty SQL diagnostyczne w `embeddings/` lub `scripts/`) → commit wg konwencji (sprawdź `git log`) → `git push origin main`. Raport: kluczowe wnioski (zwłaszcza pkt 2 i 5) — to one zdeterminują projekt modułu.

## POZA ZAKRESEM
- Projekt schematu modułu (osobny task PO tej diagnozie).
- Jakikolwiek write/migracja.

## WYNIK (2026-06-19, DONE)
Diagnoza READ-ONLY wykonana z realnej bazy `divezone_2025` (MariaDB 10.11.18) kontem
`divezone_chat_reader` (GRANT SELECT, SHOW VIEW — zero write potwierdzone).

**Dostarczone:**
- `_docs/40_diagnoza_atrybuty_rozmiary_prestashop.md` — pełny dokument (Q1–Q5).
- `embeddings/diagnose_size_attributes.py` — odtwarzalny skrypt diagnostyczny (pymysql, tunel 33060).
- `sql/diag_101_atrybuty_rozmiary.sql` — surowe zapytania do recenzji/odtworzenia.

**Kluczowe wnioski:**
- **(pkt 2) Progi liczbowe NIE mają natywnego miejsca w Preście** — potwierdzone danymi:
  atrybuty = czyste etykiety (S/M/L, „MT", liczby Bare), cechy = skalar na produkt (nie macierz
  rozmiar×wymiar), opisy = grafiki (`<img` 548) + statyczne tabele (1203) + proza; **interaktywny
  kalkulator w DB = 0** (0× `<input>/<select>/<form>/<script>/getElement/„kalkulator"`). Korekta
  założenia ADR-099 o kalkulatorze inline w opisach.
- **(pkt 5) Kierunek ADR-100 (własne tabele modułu OBOK natywnych atrybutów) potwierdzony.**
  Etykiety atrybutów są globalne/agnostyczne marki (jeden `id_attribute` „L" współdzielony przez
  ~69 produktów Scubapro), więc mapowanie marka+płeć→chart i aliasy etykiet MUSZĄ żyć w module.
  Model long-format z ADR-099 bez rewizji. Styk: marka+płeć jako klucz mapowania, `size_label`
  ↔ `pr_attribute_lang.name` jako spinacz, render przez hook `displayProductExtraContent`, czat
  czyta przez bezpośredni read-only SELECT (nowe tabele w `divezone_2025` automatycznie w zasięgu readera).
- **Kategorie:** mokre 211 (CIEPŁE 337, ZIMNE 367), suche **205** (425/424; nie 477), buty 212/208,
  rękawice 218/210, kaptury 213.
