# T-019: Git hygiene — uporządkowanie repo przed produkcją

**Instancja:** integration
**Powiązane:** decyzje Karola 89a (repo prywatne), 90a (task specs wersjonować), 91b (dane sprzedażowe lokalnie)
**Priorytet:** P1 (kod produkcyjny poza gitem grozi utratą + wrażliwe dane do wykluczenia)
**Czas:** ~1h CC

## Cel

Uporządkować repo: (1) rozszerzyć .gitignore o wrażliwe/śmieci, (2) dodać nieśledzony KOD (grozi utratą), (3) dodać dokumentację + task specs. Historii NIE ruszamy (czysta po filter-repo). Repo prywatne.

## KRYTYCZNE OSTRZEŻENIA

1. **NIGDY `git add .`** — tylko konkretne ścieżki z tego pliku.
2. **NIE tykaj** `standalone/src/Tools/ProductSearch.php` ani `standalone/src/Chat/SystemPrompt.php` — to zmodyfikowane pliki czekające na osobny deploy T-017/T-018. NIE dodawaj ich w tym tasku.
3. **Kolejność:** NAJPIERW .gitignore, POTEM git add. Po .gitignore zweryfikuj `git status` że wrażliwe NIE są w stagingu.
4. Po `git add` i PRZED commit — STOP, pokaż `git status`, czekaj na akceptację Karola.

## KROK 0. Read + stan wyjściowy

```bash
cd <repo>
git status --short
cat .gitignore
```

## KROK 1. Rozszerzyć .gitignore

Dopisz na końcu .gitignore (sekcja "Dane wrażliwe — lokalne"):

```
# ============================================
# T-019: dane wrażliwe i śmieci (repo prywatne, ale te NIGDY do gita)
# ============================================
# FreeFileSync cache
.sync.ffs_db

# Aliasy statusów — zawierają wewnętrzne nazwy pracowników (BARTEK) i komunikaty operacyjne
_docs/aliasy*

# Dane sprzedażowe (decyzja 91b — wrażliwe biznesowo, lokalnie)
_docs/dane_sprzedazowe_*

# Dane zewnętrzne wyszukiwań (GSC, Luigi's Box) — dane biznesowe + binarne zip
_docs/dane_zewnetrzne_wyszukiwania/
```

Weryfikacja:

```bash
git status --short | grep -iE 'aliasy|dane_sprzedazowe|dane_zewnetrzne|ffs_db' || echo "OK — wrażliwe wykluczone"
```

Powinno NIE pokazać tych plików (są teraz ignorowane).

## KROK 2. git add — GRUPA KOD (priorytet anty-utrata)

Per ścieżka:

```bash
git add scripts/build_evidence_registry.py
git add scripts/dataforseo_questions.py
git add scripts/embed_encyclopedia.py
git add scripts/generate_encyclopedia.py
git add scripts/generate_encyclopedia_v2.py
git add scripts/render_encyclopedia.py
git add scripts/validate_encyclopedia.py
git add scripts/validate_encyclopedia_v2.py
git add scripts/dataforseo/
git add scripts/encyclopedia/
git add embeddings/generate_synonyms.py
git add generate_encyclopedia/setup.sh
git add generate_encyclopedia/v2/README.md generate_encyclopedia/v2/config.py generate_encyclopedia/v2/enrich_from_external.py
git add sql/006_synonyms.sql
git add standalone/tests/
```

UWAGA: `generate_encyclopedia/v2/` ma też `__pycache__` (ignorowany globalnie) i `output` (ignorowany) — dodawaj TYLKO pliki .py + README, NIE cały katalog (żeby nie złapać output/cache). Jeśli `git add generate_encyclopedia/v2/` złapałby output — użyj jawnych ścieżek plików (jak wyżej). Zweryfikuj `git status` że output/pycache nie w stagingu.

## KROK 3. git add — GRUPA DOKUMENTY

```bash
git add _docs/11_mapa_marek-reviewed.md
git add _docs/15_raport_adversarial_review.md
git add _docs/17_reguly_domenowe_grupy_C-M.md
git add _docs/18_master_checklist_search_v3.md
git add _docs/19_synteza_analiz_security.md
git add _docs/20_synteza_encyklopedia_openai_vs_gemini.md
git add _docs/24_analiza_testow_pracownikow_arkusz3.md
git add _docs/30_pliki_do_audytu_seo.md
git add _docs/31_brief_plik_seo_dla_nowego_claude.md
git add _docs/FAZA1_concept_keys_v2.md
git add _docs/KWESTIONARIUSZ_eksperta_v1.md
git add _docs/LISTA_POJEC_do_NotebookLM.md
git add _docs/review_misleading_terms.csv
git add _docs/research_attachments/
git add _docs/synonyms/
git add _docs/prompts/
git add _docs/pytanie_architektoniczne/
```

UWAGA: jeśli któryś z tych plików jest już złapany przez istniejący .gitignore (np. `_docs/PROMPT_*` mogłoby kolidować z _docs/prompts/PROMPT_*.md — sprawdź czy `git add _docs/prompts/` faktycznie dodaje pliki, czy są ignorowane). Jeśli ignorowane a powinny być dodane — zgłoś w raporcie, NIE używaj `git add -f` bez konsultacji.

## KROK 4. git add — GRUPA TASK SPECS (decyzja 90a)

```bash
git add _instances/backend/tasks/
git add _instances/embeddings/tasks/
git add _instances/frontend/tasks/
git add _instances/generate_encyklopedia/tasks/
git add _instances/integration/tasks/
git add TASK-012b_multi_vector.md
```

UWAGA: .gitignore ma `_instances/*/handoff/` (ignorowane — OK, zostają lokalne) oraz `_instances/*/tasks/TASK-*-completed.md` (completed handoffy ignorowane). Zwykłe task specs (T-*.md, TASK-*.md bez -completed) powinny się dodać. Zweryfikuj że handoffy NIE trafiły do stagingu:

```bash
git status --short | grep -iE 'handoff|-completed' || echo "OK — handoffy wykluczone"
```

## KROK 5. STOP point — git status review przez Karola

Status: "READY FOR REVIEW v1". Wklej:

```bash
git status --short
git status --short | grep -c '^A'   # ile plików w stagingu
git status --short | grep '^??'      # co NADAL nieśledzone (powinny zostać tylko świadomie ignorowane + ProductSearch/SystemPrompt jako M)
```

Pokaż też że ProductSearch.php i SystemPrompt.php są nadal `M` (modified, nietknięte — czekają na T-017/T-018 deploy).

NIE commituj bez akceptacji Karola.

## KROK 6. Commit (po akceptacji) — 3 logiczne commity

```bash
# Commit 1: .gitignore
git add .gitignore
git commit -m "chore: rozszerzenie .gitignore — wrazliwe dane i smieci

Wykluczenie: aliasy statusow (nazwiska pracownikow BARTEK + komunikaty
wewnetrzne), dane sprzedazowe (decyzja biznesowa), dane zewnetrzne
wyszukiwan (GSC/Luigi's Box + zip), .sync.ffs_db (FreeFileSync cache)."

# Commit 2: kod
git commit -m "chore: dodanie kodu pipeline do repo (grozilo utrata)

Pipeline encyklopedii (scripts/, generate_encyclopedia/v2/), generator
synonimow (embeddings/), migracja sql/006_synonyms.sql, testy (standalone/tests/).
Wczesniej poza wersjonowaniem — przy awarii dysku origin by tego nie mial."

# Commit 3: dokumentacja + task specs
git commit -m "docs: dokumentacja projektowa + task specs

Analizy (adversarial review, security, reguly domenowe), research widgetu,
prompty generacyjne, task specs T-001..T-018 (decyzja 90a — historia decyzji)."
```

UWAGA: commity 2 i 3 wymagają że pliki są już w stagingu z KROK 2-4. `git add .gitignore` w commit 1 dodaje tylko .gitignore. Commit 2/3 commitują resztę stagingu — ale `git commit` bez ścieżki commituje CAŁY staging. Żeby rozdzielić, commituj per ścieżka:

```bash
# Commit 2 — jawnie ścieżki kodu:
git commit scripts/ embeddings/generate_synonyms.py generate_encyclopedia/ sql/006_synonyms.sql standalone/tests/ -m "..."
# Commit 3 — reszta (dokumenty + tasks):
git commit _docs/ _instances/ TASK-012b_multi_vector.md -m "..."
```

Jeśli rozdzielenie jest problematyczne (staging mieszany) — zrób JEDEN commit "chore: uporzadkowanie repo (gitignore + kod + dokumentacja)" zamiast trzech. Czytelność historii ważna, ale poprawność ważniejsza.

## KROK 7. Push

```bash
git push origin main
```

## KROK 8. Weryfikacja końcowa

```bash
git status --short
# Powinno zostać tylko: M ProductSearch.php, M SystemPrompt.php (T-017/T-018), + świadomie ignorowane untracked
git ls-files | wc -l   # powinno znacząco wzrosnąć z 215
```

## KROK 9. Raport + status

_instances/integration/handoff/T-019_done.md:
- Ile plików dodano (per grupa)
- Potwierdzenie że aliasy/dane_sprzedazowe/dane_zewnetrzne NIE w repo
- Potwierdzenie że ProductSearch/SystemPrompt nietknięte
- Commit hashe

Update _docs/21_STATUS_PROJEKTU.md (T-019 DONE, repo uporządkowane). Osobny commit docs: (lub dołącz do commit 3 jeśli jeden commit).

## Out of scope

- Ruszanie historii git (czysta po filter-repo — NIE dotykać)
- Deploy T-017/T-018 (osobny, ProductSearch/SystemPrompt zostają M)
- Migracja aliasów statusów do bazy/implementacja order status alias system (osobny task — dane zostają lokalne)
- Decyzja o GitHub branch protection / CI (przyszłość)
