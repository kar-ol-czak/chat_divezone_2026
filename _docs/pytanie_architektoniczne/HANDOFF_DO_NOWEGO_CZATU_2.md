# HANDOFF DO NOWEGO CZATU — Architekt encyklopedii divezone.pl
# Data: 2026-03-02
# Ostatnie pytanie: 172

---

## STAN PROJEKTU

### Co się zmieniło w tej sesji

1. **ADR-037 zapisany** (`_docs/10_decyzje_projektowe.md`): Rewizja pipeline'u encyklopedii. Deterministyczny Python + minimalny LLM zamiast 4-warstwowej kaskady LLM.

2. **V1 porzucone**: Pliki `data/encyclopedia/raw/*.json` (46 definicji z LLM) NIE SĄ używane jako fundament. 85% miało błędy. Decyzja: wszystko od zera ze źródeł ludzkich.

3. **NotebookLM jako narzędzie generacji**: Zamiast Opus 4.6 extended generującego JSON, Karol wgrywa źródła do NotebookLM Google i generuje encyklopedię w prozie. Człowiek weryfikuje tekst (błędy widoczne), potem parser Python zamienia na JSON.

4. **Prompt v3 gotowy** (`_docs/PROMPT_notebooklm_v3.md`): Przetestowany na 15 pojęciach (grupa A). Jakość dobra, zero błędów klasyfikacyjnych w drugim batchu (8 pojęć).

5. **FAZA1 v2.3** (`_docs/FAZA1_concept_keys_v2.md`): 106 pojęć. DUMP_VALVE split na BCD + DRYSUIT. ZESTAW_SERWISOWY duplikat usunięty z grupy A (zostaje w L).

6. **TASK-ENC-005** (`_docs/TASK-ENC-005_pipeline_v2.md`): Spec wymaga aktualizacji — krok 1 (transform v1→v2) jest nieaktualny, v1 porzucone. Krok 2 (enrich) nadal użyteczny.

### Korekty schematu v2 (ZATWIERDZONE w ADR-037)
- `synonimy_pl.anglicyzmy` — nowy bucket
- DUMP_VALVE_BCD + DUMP_VALVE_DRYSUIT — osobne pojęcia
- Evidence sidecar (`encyclopedia_v2_evidence.json`) — osobny plik

### Hierarchia źródeł (POPRAWIONA)
- Definicje EN: PADI Encyclopedia > reszta
- Definicje PL: nurkomania.pl > IANTD OWD > PADI (nurkomania nowsza, podręczniki mają 20-25 lat)
- Synonimy potoczne PL: all_keywords.csv (DataForSEO) > nurkomania
- Nazwy handlowe: kategorie divezone.pl

### Domenowe ustalenia z Karolem
- "reduktor" — archaiczne (starzy nurkowie mówili na cały automat), nie "bliskie"
- "opona" — zostawić jako potoczne przy SKRZYDLE (typ donut)
- "blacha" — zostawić jako synonim (ale ludzie mówią raczej "płyta")
- ABLJ — tylko archaiczne (nawet Karol nie znał)
- INT/yoke — martwy standard od ~10 lat, nie produkowany, nie równorzędny z DIN (ADR-036)
- Uprząż regulowana / DIR — to podtypy UPRZĘŻY, nie jacketa

---

## CO DALEJ

### Natychmiastowe (Karol robi)
1. Kontynuuje generację w NotebookLM: grupy B-M (91 pojęć pozostało)
2. Daje output do review (sam lub pracownicy)
3. Poprawia błędy domenowe

### Do zrobienia (architekt)
1. **Parser markdown → JSON v2**: skrypt Python konwertujący zweryfikowane hasła NotebookLM do schema v2
2. **Aktualizacja TASK-ENC-005**: usunięcie kroku 1 (v1 porzucone), dostosowanie pipeline'u do nowego flow (NotebookLM → human review → parser → walidacja)
3. **ZESTAW_SERWISOWY**: usunąć z grupy A (#14), zostawić w grupie L (#100)
4. **Pytanie Karola o polskie znaki**: sprawdzić który plik nie ma polskich znaków (pytanie 172, nie zdążyłem zdiagnozować)

### Źródła w NotebookLM Karola (aktualnie wgrane)
- 0 - Książka OWD cała bez okładki (PDF) — IANTD
- sprzet_do_nurkowania.txt — nurkomania.pl artykuły
- all_keywords.csv — 1404 fraz DataForSEO
- synonyms_review_v3.csv — review synonimów
- Encyclopedia of Recreational Diving (PADI) — dodana po teście v1

### Wygenerowane hasła (do parsowania)
- Grupa A: 15 pojęć GOTOWE (3 testowe + 7 batch 1 + 8 batch 2, wklejone w czat)
- Grupy B-M: w trakcie generacji przez Karola

---

## PLIKI KLUCZOWE

| Plik | Rola |
|------|------|
| `_docs/10_decyzje_projektowe.md` | ADR-037 (i ADR-037a) na końcu |
| `_docs/FAZA1_concept_keys_v2.md` | Master lista 106 pojęć v2.3 |
| `_docs/PROMPT_notebooklm_v3.md` | Aktualny prompt do NotebookLM |
| `_docs/TASK-ENC-005_pipeline_v2.md` | Spec pipeline (wymaga aktualizacji) |
| `_instances/generate_encyklopedia/tasks/TASK-ENC-005.md` | Task dla instancji (wymaga aktualizacji) |
| `generate_encyclopedia/v2/` | Kod kroków 1-2 (krok 1 nieaktualny, krok 2 OK) |
| `generate_encyclopedia/v2/output/skeletons/` | 105 szkieletów z v1 (NIEAKTUALNE, nie używać) |

---

## NOWY PIPELINE (po zmianach z tej sesji)

```
Źródła ludzkie (PADI, IANTD, nurkomania, DataForSEO, Luigi's Box, GSC)
    ↓
NotebookLM (generuje hasła w prozie, prompt v3)
    ↓
Human review (Karol + pracownicy poprawiają błędy)
    ↓
Parser Python (markdown → JSON v2 schema)
    ↓
Krok 2: enrich (marki z mapy marek, dodatkowe frazy — istniejący kod)
    ↓
Walidacja automatyczna (krok 4 z TASK-ENC-005, bez zmian)
    ↓
encyclopedia_v2.json + evidence sidecar
```

---

## NUMERACJA PYTAŃ

Ostatnie pytanie: **172**
Następne pytanie w nowym czacie: **173**
