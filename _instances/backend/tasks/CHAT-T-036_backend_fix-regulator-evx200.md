# CHAT-T-036 — BACKEND: Fix doboru w regulator_recreational (XTX50 2. stopien -> EVX200 zestaw)

**Data:** 2026-06-01
**Instancja:** backend
**Wejscie:** CHAT-T-035 (rekomendacje + panel read-only), przeglad rekomendacji przez Karola na panelu LIVE.
**Pierwszy task z prefiksem CHAT- (konwencja: prefiks projektowy w ID, by uniknac kolizji miedzy projektami w repo).**

---

## PROBLEM (zdiagnozowany przez Karola na panelu)
Kategoria `regulator_recreational` miesza produkty nieporownywalne:
- prio 1: pid 2368 — Apeks ATX40/DS4 + Octopus (KOMPLETNY zestaw: 1. st + 2. st + oktopus) ✓
- prio 2: pid 25 — Apeks XTX50 **2. stopien** (SAM drugi stopien, bez 1. st, bez oktopusa) ✗ BLAD
- prio 3: pid 5983 — Aqualung Legend 3 + Octopus (KOMPLETNY zestaw) ✓

Polecanie samego 2. stopnia miedzy dwoma kompletnymi zestawami jest bez sensu dla klienta pytajacego o automat do rekreacji (analogia Karola: "kompletny rower, sama kierownica, kompletny rower"). To realnie dotyczy BOTA — bot poda te rekomendacje klientowi.

## DECYZJA (zatwierdzona, 47a)
Podmienic pid 25 -> Apeks EVX200 + Octopus (pid 7421) na prio 2. Powod: kompletny zestaw Apeksa z wyzszej polki, przywraca spojna gradacje kategorii: ATX40 (budzet-sprawdzony) -> EVX200 (wyzsza polka) -> Legend 3 (premium). Wszystkie trzy = kompletne zestawy.

## KROK 0 — READ + WERYFIKACJA
- git pull. Przeczytaj CHAT-T-036 (ten plik). Przypomnij schemat divechat_curated_recommendations (sql/016) i seed (sql/017).
- Zweryfikuj w MySQL: pid 7421 (Apeks EVX200 + Octopus) — active=1, visible, availability. W CHAT-T-031 byl in_stock; potwierdz aktualny stan. Pokaz nazwe PS + cene + dostepnosc.
- Jesli pid 7421 okazalby sie nieaktywny/niedostepny -> STOP, zaraportuj (wrocimy do wyboru alternatywy). Jesli OK -> KROK 1.

## KROK 1 — UPDATE rekomendacji
- W divechat_curated_recommendations: usun/zastap wpis category_key='regulator_recreational' AND product_id=25, wstaw product_id=7421 na priority=2.
  Czysto: DELETE starego wpisu (pid 25 w tej kategorii) + INSERT nowego (pid 7421, prio 2) z rationale_pl ponizej. Albo UPDATE product_id (uwaga na UNIQUE(category_key, product_id) — jesli kolidowaloby, DELETE+INSERT bezpieczniejsze).
- rationale_pl dla EVX200 (zatwierdzony tekst):
  "Kompletny zestaw Apeksa z wyzszej polki dla nurka, ktory chce automatu z zapasem na lata, takze do zimniejszej wody. Apeks EVX200 to sprawdzona marka i solidna konstrukcja — krok wyzej niz ATX40, wciaz w rozsadnej cenie."
- recheck_interval_days=180 (jak reszta automatow). verified_at=NOW() (przez trigger lub jawnie).
- Zapisz jako sql/020_fix_regulator_evx200.sql + rollback (rollback przywraca pid 25). Wzorzec naglowka jak sql/017.
- Zastosuj na Railway PG. Pokaz SELECT category_key='regulator_recreational' po zmianie (3 wpisy, prio 1/2/3, z pid 2368/7421/5983).

## KROK 2 — SMOKE
- Wywolaj get_curated_recommendations category='regulator_recreational': potwierdz 3 produkty (2368, 7421, 5983), wszystkie kompletne zestawy, pid 25 NIEOBECNY.
- (opcjonalnie) GET /api/admin/recommendations: potwierdz ze panel pokaze EVX200 na prio 2.

## KROK 3 — GIT + STATE
- git add sql/020_fix_regulator_evx200.sql sql/020_fix_regulator_evx200_rollback.sql
- commit "CHAT-T-036: fix regulator_recreational — EVX200 zestaw zamiast XTX50 2.st (spojna gradacja kompletnych automatow)"
- git push origin main
- Osobny docs: commit ze statusem (_docs/21_STATUS_PROJEKTU.md).
- HANDOFF (lokalny, .gitignore — NIE commituj): dopisz do BACKLOG pozycje:
  "Grupowanie wariantow kolorystycznych dla wskaznika popularnosci: warianty (Zoop Novo, D5, Peregrine) to czesto osobne product_id -> popularnosc per product_id zanizona vs per model. Heurystyka po nazwie ZAWODNA (np. 'D5 Black z transmiterem' — kolor w srodku). Wymaga deterministycznego zrodla grupowania (pr_product_attribute rodzic? reczna mapa model->[product_id]?). NIEPILNE: pasek to pomoc dla pracownikow (nie wpływa na bota). Podjac gdy popularnosc zacznie zasilac cokolwiek automatycznego lub przy pomoscie Subiekt."

## RAPORT
KROK 0: stan pid 7421. Po zmianie: SELECT kategorii (3 kompletne zestawy), wynik smoke (pid 25 nieobecny), potwierdzenie backlog dopisany do handoffu.
