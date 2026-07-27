# Evidence-first equipment encyclopedia pipeline for

## Context, constraints, and source hierarchy

You have a multi-source corpus with mixed formats (JSON and Markdown) and mixed languages (Polish and English). The immediate need is an MVP: a precise equipment reference (about 40 equipment concepts or categories) that the AI can use as a strict dictionary when generating and validating product synonyms, so that it stops inventing terms and stops merging distinct products into one concept. The longer-term need is a broader Polish diving encyclopedia that can reuse the same extracted knowledge as a foundation.

Two details from your own sources illustrate why an “evidence-first” approach matters:

- Your equipment source contains separate articles for “Kołowrotek” and “Szpulka nurkowa”, and explicitly treats them as distinct tools, which is exactly the kind of “nie_mylić_z” relationship that must be encoded to prevent synonym leakage. fileciteturn0file1  
- Your course style and encyclopedia style sources both describe a regulator as reducing cylinder pressure in stages to ambient pressure, but they use different phrasing and language. That is the normal, expected variation your pipeline must reconcile without making up new wording or new terms. fileciteturn0file2turn0file3  

For MVP precision, the core operational rule should be:

- The LLM is **not** allowed to “know scuba”. It is only allowed to select and rewrite what is present in your evidence, and it must output structured JSON that can be validated automatically.

This aligns well with your “hierarchy on conflict” requirement (technical and safety definitions first, colloquial aliases second, then e-commerce naming conventions, then English naming). This hierarchy becomes a deterministic merge policy, not something the model improvises.

## Recommended architecture

1. **Recommended architecture (main decision)**  
   **Evidence-first “extract then merge then synthesize” pipeline**, with a small internal ontology (your ~40 equipment concepts) as the backbone.

   The critical design choice is to separate your process into three strictly different layers:

   - **Layer A: Chunks and embeddings (retrieval layer).** Store normalized chunks with provenance, language tag, and embeddings in PostgreSQL using `pgvector`. `pgvector` supports exact search by default and approximate indexes via HNSW and IVFFlat, which fits semantic retrieval over a few thousand chunks well. citeturn3search0  
   - **Layer B: Atomic “claims” extracted from chunks (fact layer).** Use structured-output LLM calls to extract only what you need (candidate definitions, synonyms, relations, “do not confuse”) into a normalized claims schema. Structured outputs reduce schema breakage and missing keys compared to free text. citeturn4search0turn4search1turn4search3  
   - **Layer C: Deterministic merge + constrained synthesis (publication layer).** Merge claims into one canonical JSON entry per concept using your source-priority rules, then run a final synthesis step that is forced to cite the claim IDs it used (internal citations), and is allowed to output `null` where evidence is insufficient.

   Why this is the right MVP choice: your specific failure mode is “wrong synonym because the model blended concepts or invented terms”. The only scalable fix is to **force every output token to be grounded in explicit evidence** and to **fail closed** (return unknown) rather than fail open (hallucinate).

   **Alternative architecture (keep as fallback, not MVP):**  
   A manually curated equipment ontology + synonym list first (human authored), with LLM used only to propose additions and to classify customer queries. This can reach very high precision, but it front-loads a lot of expert time and slows iteration across 40 concepts. It can be excellent after MVP, especially for the “final Polish e-commerce naming” layer, but it is not the fastest path to a batch-generated first version.

   **Architectures I am explicitly rejecting for MVP:**  
   - “End-to-end summarization per topic” (feed retrieved chunks and ask for a definition and synonyms with no intermediate claim layer). This is exactly where hallucinations hide, and it makes regression testing and conflict resolution much harder.  
   - “Pure vector search + LLM at query time” (no curated equipment dictionary). This does not solve synonym generation quality, and it cannot enforce “nie_mylić_z” symmetry and term uniqueness offline.

## Pipeline stages

2. **Pipeline diagram (step by step, textual)**  

   2.1. **Ingest sources into a raw document store**  
   - JSON sources: keep `{url, tytul, kategoria, tresc, ...}` as raw, one record = one document. fileciteturn0file1  
   - Markdown sources: keep the original file path, and record line offsets for every extracted section so you can later show “where it came from”. fileciteturn0file2turn0file3  

   2.2. **Normalize into a unified “Document” object**  
   - Mandatory fields: `source_id`, `source_rank_profile` (your hierarchy), `language`, `doc_id`, `title`, `canonical_url_or_path`, `raw_text`.

   2.3. **Chunking (structure-aware first, then length-aware)**  
   - First split by structure (JSON record boundaries, Markdown headings).  
   - Then split long sections into smaller chunks by paragraph boundaries until you hit a target token size.

   2.4. **Assign language per chunk**  
   - Store `lang = pl|en` per chunk. This is a hard constraint used later to prevent field mixing.

   2.5. **Embed every chunk and store in Postgres**  
   - Store `embedding` in a `pgvector` column.  
   - Create an HNSW index for retrieval speed once your chunk set is mostly stable. `pgvector` documents HNSW and IVFFlat as supported approximate index types. citeturn3search0  

   2.6. **Define your ontology backbone (about 40 concepts)**  
   - Seed it from your shop taxonomy and your intended equipment encyclopedia keys. Your current shop category structure already provides a strong skeleton (e.g., “Automaty: 1 stopnie, 2 stopnie, Automaty Oddechowe…”, and “Bezpieczeństwo: Szpulki, Kołowrotki…”). fileciteturn0file0  

   2.7. **For each concept, retrieve candidate evidence chunks**  
   - Query by canonical PL term, canonical EN term, and already-known synonyms.  
   - Retrieve top N chunks per source (so one dominant source does not drown out others).  
   - Keep “hard negatives” for common confusion pairs (e.g., use the “szpulka” concept to retrieve chunks that mention “kołowrotek” and vice versa). fileciteturn0file1  

   2.8. **LLM extraction to claims (structured output)**  
   - For each retrieved chunk, extract only:
     - candidate definition sentences,
     - candidate synonyms with language and “type” hint,
     - explicit “do not confuse with” statements and rationale if present,
     - subcomponents and set membership (e.g., regulator includes stages, alternate source). fileciteturn0file2turn0file3  
   - Enforce schema with structured output. OpenAI Structured Outputs guarantees adherence to a supplied JSON Schema. citeturn4search0  
   - If using Claude, use strict structured outputs / strict tool use to guarantee typed parameters. citeturn4search1  
   - If using Gemini, use structured output with JSON schema support. citeturn4search3  

   2.9. **Deterministic merge with your conflict hierarchy**  
   - Merge by field type, not globally:
     - For `definicja_pl` and safety/technical facts: prioritize PADI equipment encyclopedia (EN) then IANTD OWD (PL) then CMAS.  
     - For colloquial PL synonyms: prioritize nurkomania + your store query logs. fileciteturn0file1  
   - Require at least 2 independent sources for “validated high confidence” where available.

   2.10. **Constrained synthesis into final per-concept JSON**  
   - The LLM composes the final short definition text, but only from the merged claim set.  
   - The output is validated (schema, language checks, uniqueness constraints).  
   - Anything failing validation goes to a review queue.

## Chunk and index data model

3. **Chunk schema and index schema (JSON and DB structure)**  

A practical MVP pattern is: **(a) store chunks in Postgres, (b) store extracted claims in Postgres, (c) store published encyclopedia entries as versioned JSON**.

### Unified chunk object (canonical internal JSON)

```json
{
  "chunk_id": "uuid",
  "source_id": "padi_ch3|iantd_owd|nurkomania_sprzet|nurkomania_teoria|cmas_p1|divezone_internal",
  "source_priority_profile": "technical|colloquial|ecommerce",
  "language": "pl|en",
  "doc_id": "uuid",
  "canonical_ref": {
    "type": "url|file",
    "value": "..."
  },
  "title": "string",
  "section_path": ["string", "string"],
  "text": "string",
  "token_count_est": 0,
  "line_start": 0,
  "line_end": 0,
  "hash": "sha256",
  "embedding_model": "text-embedding-3-large",
  "embedding": [0.0]
}
```

### Claims (atomic extraction output)

Keep claims small so you can merge deterministically and test them.

```json
{
  "claim_id": "uuid",
  "concept_key_candidate": "automat_oddechowy",
  "language": "pl|en",
  "claim_type": "definition|synonym|relation|subcomponent",
  "value": "string",
  "value_type": "techniczny|potoczny|anglicyzm|formalny|niezalecany|bledne_uzycie",
  "relation": {
    "type": "nie_mylic_z|nadrzedny|podrzedny|czesc_zestawu|wariant|alias|bledne_uzycie",
    "target_term_candidate": "szpulka"
  },
  "evidence": {
    "chunk_id": "uuid",
    "quote_start": 0,
    "quote_end": 0
  },
  "source_id": "nurkomania_sprzet",
  "confidence_hint": "high|medium|low"
}
```

### Postgres core tables (minimal)

- `sources(source_id, name, default_language, priority_profile)`  
- `docs(doc_id, source_id, canonical_ref, title, hash)`  
- `chunks(chunk_id, doc_id, language, section_path, text, embedding vector, line_start, line_end, hash)`  
- `claims(claim_id, chunk_id, concept_key_candidate, claim_type, language, value, value_type, relation_type, relation_target_candidate)`  
- `concepts(concept_key, canonical_term_pl, canonical_term_en, status, confidence)`  
- `concept_entries(concept_key, version, entry_json, created_at, validated_by)`  

For `pgvector`, plan HNSW indexing for production retrieval; it is a supported index type in `pgvector`. citeturn3search0  

## Per-category synthesis procedure

4. **Procedure per equipment concept: what goes into the LLM, prompt shape, and expected output**  

The MVP objective is “terminology reference”, not a full article, so the synthesis call should be short and heavily constrained.

### Inputs to the synthesis step (what you send)

You send a “concept packet”:

- Concept metadata:
  - `concept_key`
  - canonical PL term (required)
  - canonical EN term (required)
  - allowed synonym types
- Evidence bundle grouped by source and language:
  - up to K excerpts per source, each excerpt:
    - `chunk_id`
    - `source_id`
    - `language`
    - `excerpt_text` (limited, to reduce noise)
- Already merged claims (optional but recommended):
  - list of candidate synonyms and relations with evidence IDs
  - conflicts explicitly flagged (e.g., same synonym proposed for two concepts)

### The synthesis prompt pattern (provider-agnostic)

Use schema-first output. This works across OpenAI Structured Outputs, Claude strict structured outputs, and Gemini structured output with JSON schema. citeturn4search0turn4search1turn4search3  

Key rules in the prompt:

- “Use only provided evidence excerpts. If not present, set the field to null or an empty list.”
- “Do not translate unless explicitly asked. Do not invent synonyms.”
- “PL fields must be Polish; EN fields must be English. Anglicisms are allowed only in `synonimy_pl` with `typ='anglicyzm'`.”

### Example synthesis prompt (template)

```text
SYSTEM:
You are building a terminology reference for scuba diving equipment.
You MUST follow this JSON Schema exactly.
You MUST NOT add terms that do not appear in EVIDENCE.
If evidence is missing, output null / [] and set confidence lower.

USER:
CONCEPT:
- concept_key: {concept_key}
- canonical_term_pl: {canonical_pl}
- canonical_term_en: {canonical_en}

SOURCE PRIORITY FOR TECHNICAL DEFINITIONS:
1) PADI (EN)
2) IANTD OWD (PL)
3) CMAS (PL)
FOR COLLOQUIAL POLISH ALIASES:
1) nurkomania + divezone search terms

EVIDENCE (grouped):
[{source_id} | {language} | chunk_id={chunk_id}]
{excerpt_text}

TASK:
Return a single JSON object with:
- canonical_term_pl, canonical_term_en
- definicja_pl, definicja_en (short, precise)
- synonimy_pl[], synonimy_en[] with typ labels
- relacje[] with required fields and why (only if evidence supports)
- confidence: high|medium|low
- status: validated|needs_review
- evidence: list of chunk_id used
```

### Output contract

Your output JSON should match the structure you described (definitions, typed synonyms, typed relations, confidence, status, evidence list). Enforce this with schema validation and reject any response that includes unknown keys, wrong enums, or a mixed-language field. Structured output tooling is explicitly designed for predictable, machine-validated data extraction and formatting. citeturn4search0turn4search1turn4search3  

### How this solves your concrete examples

- “Kołowrotek vs szpulka”  
  Your evidence already isolates these as separate documents and describes their different usage. That becomes a required `nie_mylic_z` relation in both entries, and your validator enforces symmetry. fileciteturn0file1  
- “Automat oddechowy” missing “aparat oddechowy”  
  In your course material excerpt, the text uses “automaty” and also references “regulatory” terminology as part of describing the mechanism. This is exactly what the claim layer captures, and then the merge layer decides whether “aparat oddechowy” is a technical synonym or not, based on your hierarchy and your own e-commerce constraints. fileciteturn0file2  
- English “sausage” leaking into Polish synonyms  
  The language validators and the “anglicyzm only if labeled” rule make it impossible for an English token to appear in PL fields unless explicitly tagged as `anglicyzm`, and even then you can apply a deny-list for slang terms you never want in PL. (This is enforced outside the LLM.)

## Validation quality and batch consistency

5. **Validation (automatic + human-in-the-loop) aligned with your acceptance criteria**  

The MVP should treat validation as a first-class pipeline stage, not as a final manual review.

### Automatic validation gates (must pass to publish)

- **Schema conformance**: the entry must match the JSON schema (required keys present, enums valid). This is the easiest win if you use structured outputs. citeturn4search0turn4search1turn4search3  
- **Language isolation checks**:
  - `definicja_pl` must be Polish, `definicja_en` must be English.
  - Every `synonimy_pl[].termin` must be Polish unless `typ="anglicyzm"`.
- **Global uniqueness and no duplicate terms across concepts**:
  - Build a normalized key for every synonym (`lowercase`, whitespace normalized, remove punctuation variants).
  - Enforce a unique constraint `(language, term_norm) -> concept_key` unless it is explicitly declared as an alias mapping.
- **Symmetry for `nie_mylic_z`**:
  - Implement `nie_mylic_z` as a symmetric pair table (concept_a, concept_b). Then generate both directions in the final JSON export, guaranteeing symmetry by design.
- **Minimum evidence rule**:
  - `definicja_pl` should reference at least 2 sources when available; if only 1 exists, auto-set `confidence="medium"` or `low` and `status="needs_review"`.
- **Cross-field consistency checks**:
  - If a concept has `podrzedny: 1. stopień`, then the concept “1. stopień” must exist and must have `nadrzedny: automat_oddechowy` (or your canonical regulator concept).

### Human-in-the-loop review (targeted, not exhaustive)

Focus reviewers only on:
- entries with `status="needs_review"`,
- entries with conflicting claims detected during merge,
- entries that introduce new anglicisms or new “bledne_uzycie” terms,
- top revenue categories first (e.g., automaty, BCD/wypornościowe, exposure protection), consistent with your shop structure. fileciteturn0file0  

A practical review UI can show:
- the final JSON entry,
- the list of evidence chunk IDs,
- the exact excerpt text used (so you can approve or reject quickly),
- the “diff vs previous version” for regression checks.

## Tool stack and models

6. **Concrete tools (names and versions) and where each fits**

### Storage and retrieval

- PostgreSQL + `pgvector` for embeddings and similarity search. `pgvector` supports HNSW and IVFFlat indexes. citeturn3search0  

### Orchestration and extraction frameworks

You can do this with either framework plus custom Python. For MVP, the main deciding factor is: which one makes schema-driven extraction and Postgres integration simpler for you.

- **Recommended for MVP: entity["company","LlamaIndex","llm data framework"] (Python)**
  - Has first-class “structured outputs” / Pydantic program patterns for extraction. citeturn3search10turn3search6  
  - Has a documented Postgres vector store integration (PGVectorStore), including HNSW configuration patterns. citeturn3search28  
  - Latest PyPI release observed: `llama-index 0.14.15` (released 18.02.2026). citeturn7search1  

- **Alternative: entity["company","LangChain","llm app framework"] (Python)**
  - Provides structured output helpers that return JSON or Pydantic-typed objects. citeturn3search2  
  - Latest PyPI release observed: `langchain 1.2.10` (released 10.02.2026). citeturn7search0  

### Data validation

- Pydantic for schema validation and typed models. The Pydantic docs show the current documentation version v2.12.5. citeturn7search9  

### Model capabilities you should rely on (for this pipeline)

- **Structured outputs / schema enforcement**
  - OpenAI Structured Outputs: guaranteed schema adherence to supplied JSON Schema. citeturn4search0  
  - Claude strict structured outputs / strict tool use: schema conformance for tool parameters and structured outputs. citeturn4search1  
  - Gemini structured output: schema-driven structured responses. citeturn4search3  

- **Cost controls**
  - Claude prompt caching: cache read tokens priced at 0.1 times base input price (with multipliers for cache writes). citeturn4search2  

## Cost, time, risks, and two-week MVP plan

7. **Estimated cost and time (MVP vs full), with official pricing checked online**

**Pricing sources and date:** pricing below was checked on 25.02.2026 against official vendor documentation. citeturn2search1turn2search5turn0search1turn5search3turn5search7turn6view0  

### Official per-token prices (selection relevant to your question)

- entity["company","OpenAI","ai model provider"]
  - GPT-4o: $2.50 / 1 000 000 input tokens, $10.00 / 1 000 000 output tokens. citeturn2search1  
  - GPT-4o mini: $0.15 / 1 000 000 input tokens, $0.60 / 1 000 000 output tokens. citeturn2search5  
  - Embeddings:
    - `text-embedding-3-small`: $0.02 / 1 000 000 tokens  
    - `text-embedding-3-large`: $0.13 / 1 000 000 tokens citeturn0search1  

- entity["company","Anthropic","ai model provider"]
  - Claude Sonnet 4.6: $3 / 1 000 000 input tokens, $15 / 1 000 000 output tokens. citeturn5search7  
  - Claude Opus 4.6: $5 / 1 000 000 input tokens, $25 / 1 000 000 output tokens. citeturn5search3  
  - Note: long prompts beyond 200k tokens can trigger premium pricing on some Claude tiers (avoid this by retrieval + small evidence bundles). citeturn5search11turn5search9  

- entity["company","Google","ai model provider"]
  - Gemini 3 Pro Preview (Standard): $2.00 / 1 000 000 input tokens (<= 200k prompt), $12.00 / 1 000 000 output tokens (including thinking tokens). citeturn6view0  
  - Gemini 3 Pro Preview (Batch): $1.00 / 1 000 000 input tokens, $6.00 / 1 000 000 output tokens (<= 200k prompt). citeturn6view0  
  - The pricing table explicitly notes output price includes thinking tokens. citeturn6view0  

### MVP cost estimate (order-of-magnitude)

Because your total corpus is about 650k tokens, the dominant cost is not embeddings, but how many LLM extraction calls you run.

A realistic MVP strategy is:
- embeddings for all chunks once,
- chunk-level extraction once (to claims),
- concept-level synthesis once (to published JSON),
- then reruns only for concepts affected by edits.

**Embeddings cost (one-time, entire corpus):**
- `text-embedding-3-large`: about 0.65M tokens × $0.13/M ≈ $0.08. citeturn0search1  

**LLM cost (example MVP workload):**
- If you use GPT-4o mini for chunk extraction and GPT-4o (or GPT-4.1 class) for final synthesis, you are typically in the **single-digit USD** range for the whole 40-concept MVP, assuming you do not repeatedly re-run the entire batch many times. citeturn2search5turn2search1  

**Time estimate (MVP batch run):**
- The data volume is small enough that the batch itself (embedding + extraction + synthesis) is usually minutes to a few hours end-to-end depending on rate limits, retries, and how many review iterations you do. The human review loop is typically the longer part.

### Full encyclopedia (stage 2) cost and time

Stage 2 cost scales mainly with:
- how many long-form articles you generate,
- how long each article is,
- whether you do multi-pass editing or fact checking.

Practically:
- If you generate 40 long-form entries and keep the same “evidence-first” approach, cost remains moderate because you reuse the same extracted claim layer; you are mainly paying for additional synthesis output tokens. The same official per-token rates above still apply. citeturn2search1turn5search7turn6view0  

8. **Risks and mitigations**

- **Risk: synonym leakage across concepts (kołowrotek vs szpulka type errors)**  
  Mitigation: uniqueness constraints for normalized synonyms, and mandatory symmetric `nie_mylic_z` pairs generated from a single canonical pair table. Your sources already separate these concepts, so the retrieval + claims layer can preserve that separation. fileciteturn0file1  

- **Risk: the model invents terms or “helpfully” translates**  
  Mitigation: (a) structured outputs with schema, (b) “null allowed” fields, (c) explicit “no new terms unless present in evidence” rule, (d) post-validation that every synonym must map to at least one evidence claim. Structured output features exist specifically to keep output machine-validated and predictable. citeturn4search0turn4search1turn4search3  

- **Risk: mixed-language pollution (EN in PL fields, PL in EN fields)**  
  Mitigation: hard language tag per chunk, per-field language validators, and a strict rule that any non-Polish token in PL synonyms must be explicitly labeled `anglicyzm`.

- **Risk: conflict resolution becomes subjective**  
  Mitigation: encode your hierarchy as deterministic merge rules per field type, and store “why this was chosen” metadata in the entry (source count, evidence IDs, priority path).

- **Risk: regressions after re-running the batch**  
  Mitigation: version every published entry, run diffs, and lock “golden test cases” for your most important concepts. Do not “auto publish” if a previously validated entry drops below the evidence threshold.

9. **Two-week MVP plan (equipment encyclopedia JSON)**

- **Days 1 to 2**  
  Define the 40 concept keys and canonical terms (PL and EN), seeded from shop categories and your target encyclopedia scope. fileciteturn0file0  

- **Days 3 to 4**  
  Implement ingestion and unified chunking for JSON and Markdown. Store chunks with language tags and provenance (URL/file + line ranges). fileciteturn0file1turn0file2turn0file3  

- **Days 5 to 6**  
  Embed all chunks and create the `pgvector` index. Choose HNSW or IVFFlat based on your latency needs; both are supported. citeturn3search0  

- **Days 7 to 8**  
  Build per-concept retrieval queries and generate initial evidence packets. Start with 10 highest-value concepts (automaty, BCD/wypornościowe, masks/fins, safety items like reels and spools). fileciteturn0file0turn0file1  

- **Days 9 to 10**  
  Run LLM extraction to claims with structured outputs and publish first JSON entries for those 10 concepts. Validate automatically, and route failures to review. citeturn4search0turn4search1turn4search3  

- **Days 11 to 12**  
  Expand to all 40 concepts. Add symmetry enforcement for `nie_mylic_z`, global synonym uniqueness checks, and language field validators.

- **Days 13 to 14**  
  Human review pass for all `needs_review` entries, then freeze and tag the MVP dictionary version. Integrate it as a hard reference layer for your synonym generator (the generator may propose candidates, but the dictionary must approve or reject them).