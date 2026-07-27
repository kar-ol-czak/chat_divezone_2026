# TASK-ENC-011d: Markdown Renderer + Master Report
# Data: 2026-03-06
# Status: CZEKA NA 011c
# Instancja: embeddings
# Zależność: TASK-ENC-011c DONE (walidacja zakończona, 0 RED)

---

## CEL

Dwa komponenty: (1) Python renderuje markdown z JSON-ów — tagi źródłowe budowane
deterministycznie z evidence registry, ZERO tagów od Gemini.
(2) Master Report z semaforami GREEN/YELLOW/RED — punkt startowy review.

## KROK 1: MARKDOWN RENDERER

Nowy plik: `scripts/render_encyclopedia.py`

### Logika tagowania (KRYTYCZNA)

Tagi NIGDY nie pochodzą od Gemini. Python buduje je z evidence registry:

```python
def render_tag(evidence_id: str, evidence_registry: dict) -> str:
    """Buduje tag źródłowy z evidence registry. Gemini tego NIE robi."""
    ev = evidence_registry["evidence"].get(evidence_id)
    if not ev:
        return ""  # brak tagu jeśli brak evidence
    
    source = ev["source"]
    if source == "GSC":
        return f"[GSC, {ev.get('volume', '?')} vol]"
    elif source == "PAA":
        return "[PAA]"
    elif source == "autocomplete":
        return "[AC]"
    elif source == "crosssell":
        return "[dane sprzedażowe]"
    elif source == "bestsellers":
        return "[dane sprzedażowe]"
    else:
        return f"[{source}]"
```

### Template markdown per hasło

Zachowaj IDENTYCZNY format jak stary pipeline (żeby human review mógł porównać):

```python
def render_concept(data: dict, evidence: dict) -> str:
    md = []
    md.append(f"## {data['concept_number']}. {data['name_pl']} / {data['name_en']}")
    md.append("")
    md.append("### Definicja i zasada działania")
    md.append(data["definition"])
    md.append("")
    
    # Podtypy klienckie
    md.append("### Podtypy i konstrukcje")
    md.append("**Podtypy klienckie (decyzje zakupowe):**")
    for st in data.get("subtypes_client", []):
        md.append(f"* **{st['name']}:** {st['description']}")
    md.append("")
    md.append("**Podtypy techniczne (edukacyjne):**")
    for st in data.get("subtypes_technical", []):
        md.append(f"* **{st['name']}:** {st['description']}")
    md.append("")
    
    # Synonimy z tagami z evidence
    md.append("### Synonimy i słowa kluczowe")
    for category, label in [
        ("official", "Oficjalne"), ("close", "Bliskie"),
        ("slang", "Potoczne / Slang"), ("anglicisms", "Anglicyzmy w polskim użyciu"),
        ("misspelled", "Błędne, ale popularne zapytania klientów")
    ]:
        items = data.get("synonyms", {}).get(category, [])
        if items:
            rendered = []
            for item in items:
                tag = render_tag(item.get("evidence_id", ""), evidence)
                rendered.append(f"{item['text']} {tag}".strip())
            md.append(f"* **{label}:** {', '.join(rendered)}")
        else:
            md.append(f"* **{label}:** Brak danych w źródłach.")
    md.append("")
    
    # Long-tail z tagami
    md.append("**Frazy long-tail z wyszukiwarek:**")
    for phrase in data.get("longtail_phrases", []):
        tag = render_tag(phrase.get("evidence_id", ""), evidence)
        md.append(f"* `{phrase['text']}` {tag}".strip())
    md.append("")
    
    # Nie mylić z
    md.append("### Nie mylić z")
    for item in data.get("not_to_confuse", []):
        md.append(f"* **{item['concept_key']}:** {item['explanation']}")
    md.append("")
    
    # Parametry zakupowe
    md.append("### Parametry zakupowe")
    for param in data.get("purchase_parameters", []):
        md.append(f"* **{param['name']}:** {param['description']}")
    md.append("")
    
    # Cross-sell z tagami
    md.append("### Powiązane produkty (Cross-selling)")
    for item in data.get("cross_sell", []):
        tag = render_tag(item.get("evidence_id", ""), evidence)
        concept_link = f"(→ {item['concept_key']})" if item.get("concept_key") else ""
        md.append(f"* **{item['product']} {concept_link}:** {item['description']} {tag}".strip())
    md.append("")
    
    # FAQ
    md.append("### FAQ klienta")
    for faq in data.get("faq", []):
        md.append(f"* **{faq['question']}**")
        md.append(faq["answer"])
    md.append("")
    
    # Uwagi
    md.append("### Uwagi dla sprzedawcy")
    md.append(data.get("seller_notes", ""))
    md.append("")
    
    return "\n".join(md)
```

### Output per hasło

Renderuj TYLKO hasła ze statusem GREEN lub YELLOW (nie RED).
RED hasła → `gen_v2/quarantine/{KEY}.md` (z adnotacją o powodzie)

Per hasło: `gen_v2/rendered/{CONCEPT_KEY}.md`

### Master file

Scal wszystkie rendered/*.md w kolejności concept_number:
`gen_v2/encyclopedia_v3_all.md`

## KROK 2: MASTER REPORT

`gen_v2/MASTER_REPORT.md` — PUNKT STARTOWY REVIEW:

```markdown
# ENCYCLOPEDIA V3 — GENERATION REPORT
# Pipeline: v2 (evidence registry + JSON Schema)
# Date: {timestamp}
# Model: gemini-3.1-pro-preview

## PODSUMOWANIE
- Haseł: 105
- 🟢 GREEN (gotowe do publikacji): {N}
- 🟡 YELLOW (wymaga review): {N}
- 🔴 RED (zablokowane): {N}
- Koszt: ${total}
- Czas: {total}s

## 🔴 ZABLOKOWANE (napraw przed publikacją)
{lista RED haseł z powodami}

## 🟡 DO SPRAWDZENIA
{lista YELLOW haseł z ostrzeżeniami}

## POKRYCIE EVIDENCE
- Łącznie evidence IDs: {N}
- Avg użytych per hasło: {N}
- Hasła z <3 keywords: {lista}
- Hasła z 0 PAA: {lista}

## KOMPLETNOŚĆ TREŚCI
- Hasła z <4 subtypes: {lista}
- Hasła z <4 FAQ: {lista}
- Hasła z <6 longtail: {lista}

## BRAKI ZGŁOSZONE PRZEZ MODEL
{lista missing_data ze wszystkich haseł}

## UZUPEŁNIENIA BEZ DOWODÓW (ungrounded)
{lista ungrounded_additions — do human review}

## REPRODUKOWALNOŚĆ
- Prompt: PROMPT_gemini_encyklopedia_v4_json.md ({hash})
- Model: gemini-3.1-pro-preview
- Source hashes:
  - all_keywords.csv: {sha256}
  - atp_questions_all.csv: {sha256}
  - transkrypt_eksperta.md: {sha256}
  - crosssell_12m.md: {sha256}
  - bestsellery_12m.md: {sha256}

## SPIS TREŚCI (105 haseł)
{numerowana lista wszystkich haseł z statusem}
```

## TRYBY

```bash
# Renderuj wszystkie
python3 scripts/render_encyclopedia.py --all

# Renderuj jedno (debug)
python3 scripts/render_encyclopedia.py --concept AUTOMAT_ODDECHOWY

# Generuj tylko raport
python3 scripts/render_encyclopedia.py --report-only
```

→ STOP. Architekt otwiera MASTER_REPORT.md i od niego zaczyna review.

## NIE RÓB

- Nie modyfikuj surowych JSON-ów z Gemini (gen_v2/raw/)
- Nie modyfikuj evidence registry (gen_v2/evidence/)
- Nie wywołuj Gemini API
- RED hasła NIE trafiają do encyclopedia_v3_all.md
