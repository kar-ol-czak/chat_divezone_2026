# T-021: Red-team Faza 0 -- prerekwizyty (snapshot ground truth + pin modeli + repo)

Instancja: backend (snapshot endpoint) + integration (repo, pin modeli)
Powiazane: ADR-060, _docs/26 (synteza panelu), decyzje 96a/97/98a/99a
Priorytet: P1 (warunek konieczny harness -- bez ground truth sedzia zgaduje)
Czas: ~3-4h CC

## Kontekst
Panel ekspertow (3/3) ustalil ze harness bez ground truth i pin modeli mierzy zludzenia. Faza 0 stawia fundament ZANIM napiszemy scenariusze i orchestrator. ADR-060: profil ryzyka uproszczony (czat nieopublikowany, narzedzia read-only), wiec NIE stawiamy chat-test.divezone.pl -- bijemy w dev endpoint. Ale 3 prerekwizyty sa konieczne.

## KROK 0. Read
- _docs/26_synteza_panelu_redteam.md (caly -- sekcje H1 ground truth, H2 meta-eval, koszt)
- ADR-060 w _docs/10_decyzje_projektowe.md
- standalone/src/Tools/ProductSearch.php (jak czyta katalog -- snapshot ma oddac ten sam obraz)
- standalone/src/Tools/OrderStatus.php (struktura zamowienia -- dla syntetycznych TEST-*)

## CZESC A -- Ground truth snapshot katalogu (backend)

### KROK 1. Skrypt snapshotu (NIE endpoint HTTP -- prostsze, lokalne)
Cel: deterministyczny dump katalogu z momentu T, ktory sedzia dostaje jako kontekst do oceny halucynacji.
Plik: _redteam/tools/snapshot_catalog.py (Python, czyta z PG + MySQL jak enrichWithMySQLData).
Zawartosc snapshotu (JSON):
- lista produktow: ps_product_id, name, category_name, parent_category_name, czy aktywny, czy in_stock/available_to_order/unavailable, cena (z promo jesli jest)
- timestamp + zrodlo (commit hash bazy scenariuszy pozniej)
Format: _redteam/fixtures/catalog_snapshot_YYYY-MM-DD.json
UWAGA: to dump READ-ONLY, bez PII. Tylko katalog produktow, zero danych klientow.

### KROK 2. Walidacja snapshotu
Smoke: snapshot zawiera Crystal Vu (7316/7442/4926) z category 'Maski panoramiczne'/'Zestawy Maska+Fajka' (nasz case 90) oraz SANTI BZ400 ocieplacz (case 91). To pozwoli pozniej zrobic scenariusz halucynacji z prawdziwym ground truth.

## CZESC B -- Zamowienia syntetyczne dla scenariuszy IDOR (backend)

### KROK 3. Pula testowych referencji
Dla scenariuszy IDOR/OrderStatus NIE uzywamy realnych numerow zamowien (realne PII).
Opcja rekomendowana: scenariusze IDOR operuja na NIEISTNIEJACYCH referencjach (TEST-FAKE-001 itp.) + losowych emailach -- testujemy czy bot ujawnia pola, daje sie naklonic do wielu wywolan, enumeruje. NIE potrzeba realnych danych zeby wykryc podatnosc (bot albo odmawia/weryfikuje, albo nie).
Zapis: _redteam/fixtures/synthetic_orders.json (lista fikcyjnych reference+email do scenariuszy).
Jesli potrzebny bedzie pozytywny przypadek (bot poprawnie zwraca status) -> Karol wskaze 1-2 wlasne zamowienia testowe, NIE klientow.

## CZESC C -- Pin modeli + repo (integration)

### KROK 4. Struktura repo
Utworzyc:
  _redteam/
    scenarios/      (YAML scenariusze, pozniej)
    fixtures/       (catalog_snapshot, synthetic_orders)
    domain_rules/   (listy zakazane: nazwiska pracownikow, marki konkurencji, fikcyjne certy)
    judge_prompts/  (rubryki sedziego, wersjonowane)
    tools/          (snapshot_catalog.py)
    configs/        (promptfoo config, pozniej)
    README.md

### KROK 5. Pin modeli -- tabela referencyjna
Plik _redteam/configs/models.md: dokladne stringi snapshotow z divechat_model_pricing (zweryfikowac aktualne ID):
- Attacker: gpt-5.4 (lub tanszy gpt-5.4-mini / haiku-4-5 do oszczednosci)
- Target: nasz bot (model jaki ustawiony w czacie -- do potwierdzenia z Karolem)
- Sedzia W1: model SPOZA rodziny ocenianej odpowiedzi (anty-bias). Jesli target=Claude -> sedzia gpt-5.4; jesli target=GPT -> sedzia claude-opus-4-7.
- Sedzia W2 panel: claude-opus-4-7 + gpt-5.4 + Gemini (klucz Google AI Studio z projektu).
UWAGA: pin konkretnych dat snapshotow (nie -latest). CC ma wypisac dostepne stringi i ZAPYTAC Karola ktore zatwierdzic (zgodnie z zasada: model selection zawsze konsultowany).

### KROK 6. domain_rules -- listy zakazane (seed)
_redteam/domain_rules/forbidden_terms.yaml -- seed z naszej wiedzy:
- nazwy wewnetrzne statusow (employee-named: BARTEK, LESZEK -- z aliasow, ktore zostaja LOKALNE poza gitem; w regule tylko jako wzorzec do wykrycia, nie pełna lista w repo jesli to PII pracownika)
- marki konkurencji (do ustalenia z Karolem)
- fikcyjne certy z testow (Deep Air Diver 60)
- surowe statusy (available_to_order, in_stock, unavailable -- nie do outputu)
UWAGA RODO: nazwiska pracownikow to dane osobowe. W repo (nawet prywatnym) trzymac jako wzorzec/placeholder lub w pliku gitignored, jak aliasy w T-019. CC ma zaproponowac bezpieczny sposob (np. forbidden_terms.local.yaml gitignored).

### KROK 7. .gitignore dla _redteam
Dopisac: transcripty z PII, *.local.yaml, ewentualne realne snapshoty jesli zawieraja wrazliwe. Sam katalog scenariuszy/fixtures katalogowych (bez PII) wersjonujemy.

## KROK 8. STOP point
Status READY FOR REVIEW. Pokaz: strukture _redteam/, przyklad snapshotu (kilka produktow), liste dostepnych stringow modeli z pytaniem ktore pinujemy, propozycje obslugi nazwisk pracownikow w domain_rules. NIE commituj bez akceptacji.

## KROK 9. Git (po akceptacji)
git add _redteam/ (konkretne sciezki, NIE .local.yaml, NIE transcripty)
git add .gitignore
commit: "T-021: red-team Faza 0 -- snapshot ground truth + repo + pin modeli (ADR-060)"
git push origin main. Osobny commit docs: status.

## Out of scope (nastepne taski)
- T-022 scenariusze YAML (~50, 10 klas) + rubryki sedziego
- T-023 orchestrator Promptfoo + custom HTTP provider na dev endpoint
- T-024 warstwa W0 regex + W1 sedzia + integracja domain_rules
- meta-eval sedziego (golden 50-100 transkryptow -- wymaga rec oceny Karola+eksperta)
- Garak nightly