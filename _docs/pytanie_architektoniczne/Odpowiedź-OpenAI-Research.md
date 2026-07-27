# Minimal architecture for a high fidelity diving equipment encyclopedia JSON

## Executive summary

Your current approach fails mainly because it allows a generative step to overwrite extracted facts, so the system is not monotonic: information can disappear, mutate, or get re invented as it passes through layers. fileciteturn0file6turn0file14  
A minimal high fidelity architecture is: deterministic extraction plus deterministic transforms plus deterministic validation, with at most one constrained LLM step that can only classify or paraphrase from provided evidence, never invent or delete. fileciteturn0file10turn0file11 citeturn3search0turn0search3  
In your sample, v2 already shows concrete drift (lost synonyms, broken relations, inconsistent diacritics), which matches the “telephone game” diagnosis. fileciteturn0file5turn0file6turn0file0turn0file2turn0file3  

## What is going wrong in the current approach

The core failure mode is that layer 3 is allowed to “generate v2 from scratch” instead of performing a deterministic transform from a locked, evidence backed representation. When a later model is told to produce a clean v2, it optimizes for plausibility and internal consistency, not for recall of every low level term in earlier outputs, so uncommon but correct synonyms and relations drop out. fileciteturn0file14turn0file11 citeturn0search3  

Your own validation artifact shows this in a way that is actionable: synonyms present in v1 disappear in v2 (for example “opona” for SKRZYDLO, “płyta” and “blacha” for BACKPLATE), and the validator explicitly calls out that these were removed between v1 and v2. fileciteturn0file6turn0file3turn0file2turn0file5  

A second failure mode is logical integrity loss across layers. The validator reports a hard FAIL for DUMP_VALVE because v2 contains a self reference inside nie_mylic_z (“do not confuse DUMP_VALVE with DUMP_VALVE”), which is a pure graph integrity error that deterministic tooling would have caught instantly. fileciteturn0file6  

A third failure mode is that formatting and encoding are not treated as a strict contract. In your v2 sample, several Polish text fields are ASCII only (no diacritics), while at least one concept keeps Polish diacritics, which means the pipeline is not enforcing a single UTF 8 output standard and is not running automated checks for language and encoding consistency. fileciteturn0file5  

Below is a compact “loss audit” computed directly from your provided v1 and v2 samples for the five concepts, showing that the drift is not hypothetical:

| Concept | Examples present in v1 but missing in v2 | Why it matters |
|---|---|---|
| JACKET | “chomąto”, “ABLJ” | You lose historically important terms that appear in authoritative sources (PADI calls out ABLJ in Europe, and “horse collar” history), and you lose a real alias users may still encounter. fileciteturn0file0turn0file13turn0file5 |
| SKRZYDLO | “opona”, “skrzydło nurkowe” as standalone | You lose genuine Polish slang (“opona”) and a natural Polish phrase variant that customers type. fileciteturn0file3turn0file6turn0file5 |
| BACKPLATE | “płyta”, “płyta nurkowa”, “blacha” as standalone | You lose the dominant Polish shorthand used by divers, which hurts query mapping and human readability. fileciteturn0file2turn0file6turn0file5 |
| INFLATOR | “wąż do inflatora”, “zawór dodawczo upustowy” | You lose user language that appears in the extracted v1 and is useful to map search intent to parts. fileciteturn0file1turn0file5 |

These losses are consistent with what the original leading prompt encouraged: “design a pipeline that synthesizes and chunks,” which implicitly treats the goal as summarization and synthesis instead of faithful structured extraction with coverage guarantees. fileciteturn0file14  

## What can be filled deterministically vs what needs language intelligence

You already have a strong map of which v1 fields map into v2, and it estimates that roughly 40 percent is direct mapping and a further 15 percent is restructure, both deterministic. fileciteturn0file11  
The target schema is also clear about required fields and constraints, which makes JSON Schema validation straightforward. fileciteturn0file10 citeturn3search1turn3search20  

A practical high fidelity split, using your exact schema fields, is:

| v2 field | Deterministic fill without LLM | What “intelligence” is actually needed |
|---|---|---|
| id | Direct from FAZA1 concept keys, enforced UPPER_SNAKE_CASE, uniqueness checks. fileciteturn0file7turn0file10 | None. |
| nazwa_pl | Direct from FAZA1 (canonical Polish per concept), plus normalization rules. fileciteturn0file7turn0file10 | Optional, only if you want auto rewriting into consistent e commerce style. |
| nazwa_en | Direct from PADI fragments where available, otherwise deterministic lookup table, otherwise translation with human review. fileciteturn0file13turn0file10 | Translation quality control is linguistic. |
| definicja | Deterministic extraction of one authoritative definition span plus light trimming, with evidence anchoring. fileciteturn0file13turn0file12turn0file10 | If you require a paraphrased definition suitable for publication, that is linguistic and must be evidence grounded to avoid drift. citeturn0search3 |
| synonimy_pl, synonimy_en | Deterministic candidate harvesting from sources, product text, and query logs; deterministic normalization (case, diacritics, whitespace), plus dedup. fileciteturn0file11turn0file12turn0file13 | The hard part is classification into exact vs near vs potoczne vs archaiczne vs błędne ale popularne, because it depends on usage context and intent. A deterministic approach can do some, but will fail on edge cases and slang. fileciteturn0file6 |
| nie_mylic_z | Deterministic extraction of explicit relations plus deterministic enforcement of bidirectionality and “no self reference.” fileciteturn0file10turn0file6 | The “dlaczego” text is linguistic, but can be templated deterministically from curated disambiguation clues. |
| podtypy | Partly deterministic if subtypes appear as enumerations in sources (for example BCD styles), otherwise you need controlled generation or a curated list. fileciteturn0file13turn0file12turn0file10 | Selecting the useful subtypes for shopping and avoiding taxonomy mistakes is semantic. fileciteturn0file6 |
| parametry_zakupowe | Often deterministic from shop data: filters, features, attribute groups, and recurring spec patterns in product descriptions. fileciteturn0file10turn0file11 | Turning raw feature names into a clean short list that matches customer language is partly linguistic. |
| marki_w_sklepie | Deterministic from your product database per category, optionally intersected with your brand whitelist rules. fileciteturn0file8turn0file10 | None. |
| powiazane_produkty | Deterministic from curated relation rules plus “parts of” relations extracted from sources. fileciteturn0file11turn0file10 | Choosing useful relations for customers is semantic, but you can gate it by a small curated rule set. |
| faq | Not present in sources as structured Q and A, so this is generative. fileciteturn0file10turn0file11 | Requires linguistic generation, but must be constrained by domain rules to avoid unsafe or misleading advice. fileciteturn0file9 |
| uwagi_dla_ai | Mostly derivable from your domain rules plus a catalog of common confusions and error modes, with deterministic templates. fileciteturn0file9turn0file10 | Small amount of linguistic phrasing and prioritization. |

Two important implications follow from this split:

First, you do not need an LLM to “create v2.” You need deterministic transforms to preserve coverage, and you need an LLM only for a bounded subset of fields where you want human like phrasing or classification. fileciteturn0file11turn0file6  

Second, the right constraint mechanism is not “more validation LLMs.” It is strict structured output plus business rule tests. Structured JSON Schema output can force shape compliance, but you still need deterministic business rules to prevent logical errors like self references and asymmetry. citeturn3search0turn3search1turn3search20 fileciteturn0file6  

## Recommended minimal architecture

This is one concrete end to end flow that is minimal in moving parts and maximal in fidelity. It assumes your raw human sources are the only ground truth, and every generated string must be traceable back to evidence.

### Source of truth and intermediate data model

Step A: Create an immutable “source bundle.” Store every raw document with stable ids and metadata: source name, language, date, checksum, and licensing flags. This prevents silent drift when a document changes. fileciteturn0file12turn0file13  

Step B: Create an immutable “concept registry” from FAZA1. This registry is the only authority for which concepts exist and what their canonical Polish name is. Nothing downstream can create or delete concepts. fileciteturn0file7  

Step C: Build an evidence store in Python. Split documents into “evidence chunks” with ids, language tags, and character spans (paragraph level is usually enough). Then build a text index and optionally a vector index for retrieval, but do not let retrieval change content. The goal is to fetch relevant evidence, not to synthesize new truth. citeturn0search3turn0search7  

### Deterministic construction of a draft v2

Step D: Build a draft per concept using deterministic rules only.

1. id, nazwa_pl come from the concept registry. fileciteturn0file7turn0file10  
2. nazwa_en comes from a deterministic lookup table seeded from the English sources you own (PADI fragments), with explicit “missing” markers where you have no coverage. fileciteturn0file13turn0file10  
3. Candidate synonyms are harvested deterministically from three places:  
   a. Human sources using pattern based extraction (“X also called Y”, “znany również jako”, parentheses expansions, acronym patterns). fileciteturn0file12turn0file13  
   b. Your query logs (DataForSEO, internal search, Search Console) as raw candidate strings, with frequency counts attached. fileciteturn0file6  
   c. Your product corpus for recurring terms in titles, attributes, and descriptions. fileciteturn0file11  
4. Candidate nie_mylic_z edges are harvested deterministically from:  
   a. Explicit “do not confuse” statements in sources and your domain rules. fileciteturn0file9turn0file12turn0file13  
   b. Your existing curated cases, like “szpulka vs kołowrotek.” fileciteturn0file14  
5. marki_w_sklepie is filled deterministically from your database and brand map, never by an LLM. fileciteturn0file8turn0file10  

The output of Step D is not the final v2. It is a “draft v2 with candidates,” plus an evidence sidecar.

### The single constrained LLM step

Step E: Call an LLM once per concept, but only after Step D has produced:

1. A bounded set of candidate synonyms per language.  
2. A bounded set of evidence chunks for definitions and confusions.  
3. A bounded set of allowed output slots and rules.

You then ask the model to do only these operations:

1. Classify candidate synonyms into synonimy_pl and synonimy_en buckets. The prompt must state: you may not invent new synonyms, you may not delete candidates, you may only assign them or mark them as reject. citeturn3search0turn0search3  
2. Draft podtypy and parametry_zakupowe, but only from an allowed list you provide (seeded from shop data), and you require an evidence reference or an explicit “shop derived” tag for each item. fileciteturn0file10turn0file11turn0file9  
3. Draft faq and uwagi_dla_ai using only the domain rules and the most common confusion patterns, and require the model to cite which rule ids it used. fileciteturn0file9turn0file10  

This design removes the main cause of your drift: the model cannot “rewrite the world.” It can only assign and phrase within a deterministic cage.

If you are on entity["company","OpenAI","ai model provider"], use Structured Outputs with your JSON Schema so the model cannot omit keys or change types. citeturn3search0  
You still must run business rule tests afterward, because schema compliance does not prevent semantic mistakes like self references or wrong links. fileciteturn0file6 citeturn3search20  

### Human review as a small, targeted gate

Step F: Generate a review queue, not a full manual review.

A concept goes to human review only if any of these triggers fire:

1. New synonym that appears in logs but not in sources.  
2. Any nie_mylic_z that is not symmetric after auto enforcement.  
3. Any language detection failure.  
4. Any safety or compliance rule flagged (for example “butla z tlenem” confusion). fileciteturn0file9  

The human reviewer edits only the flagged items, and the edits are committed as deterministic overrides so they are stable on the next rebuild.

## Validation strategy and source hierarchy

Your acceptance criteria are well suited to deterministic validation, but two of them cannot be met with the current schema as written.

First, “each definition has evidence” is not representable because the target schema does not include any evidence field. fileciteturn0file10  
Second, “anglicisms in PL are allowed but marked as type” is also not representable because the v2 schema stores synonyms as plain strings without per synonym metadata. fileciteturn0file10turn0file0  

The minimal fix that keeps your public v2 schema unchanged is to add a sidecar file:

- encyclopedia_v2.json follows the target schema exactly. fileciteturn0file10  
- encyclopedia_v2_evidence.json maps concept id plus field name plus item value to evidence chunk ids and source ids.  
- encyclopedia_v2_synmeta.json maps each synonym string to tags like loanword, acronym, misspelling, deprecated.

This preserves your v2 contract while allowing your internal AI system to use the missing provenance and synonym typing.

For automated validation, run two layers:

Schema validation. Use a strict JSON Schema validator so missing keys and wrong types fail fast. citeturn3search1turn3search20  

Business rule validation. Implement your acceptance criteria as tests:

- Duplicate term leakage: build an inverted index synonym string to concept ids. Allow duplicates only if explicitly declared as alias policy.  
- nie_mylic_z symmetry: enforce A references B implies B references A, and fail if B does not exist. The DUMP_VALVE self reference bug is exactly what this catches. fileciteturn0file6  
- Language purity: run language detection on each PL and EN field, plus a whitelist for accepted loanwords. Your own domain rules already assume that customer language can be wrong (“maska do nurkowania z tlenem”), so you need a defined mechanism to store “wrong but popular” forms. fileciteturn0file9turn0file10  
- Encoding: enforce UTF 8 and enforce that Polish fields preserve diacritics. Your v2 sample shows this is currently not enforced. fileciteturn0file5  

For source hierarchy on conflicts, your proposed ordering is reasonable and it matches what your corpus implies: English equipment naming and categories are best grounded in the PADI encyclopedia chapters, while Polish customer phrasing and slang is best grounded in your store and Polish sources. fileciteturn0file13turn0file12turn0file6  

Implement hierarchy deterministically as “pick a winning evidence span” rather than “merge prose”:

- For definicja, select from the highest ranked source that mentions the concept and passes a completeness test.  
- For synonyms, take union across sources, then classify into buckets. Never drop a synonym without an explicit rule or human decision. fileciteturn0file6turn0file11  

## Cost, time, and risk profile

### Model cost

Because the recommended design calls an LLM only for classification and controlled drafting, you can use a low cost model for the bulk run.

If you stay with entity["company","OpenAI","ai model provider"], the official pricing page lists GPT 5 mini at $0.250 per 1M input tokens and $2.000 per 1M output tokens. citeturn1view0  
For comparison, the same page lists GPT 5.2 at $1.750 per 1M input tokens and $14.000 per 1M output tokens, which is the bracket you referenced. citeturn1view0  
If you consider entity["company","Anthropic","ai model provider"], the Claude pricing page lists Claude Haiku 4.5 at $1 per MTok input and $1.25 per MTok output, and Claude Opus tier models far higher. citeturn0search1  
If you consider entity["company","Google","technology company"] Gemini Developer API, Gemini Flash price points can be well under $1 per MTok input and around $1.25 per MTok output depending on tier and batching, while Pro tiers are higher. citeturn2view0  

Given your scale, token cost should be a minor line item if you do retrieval and keep context small. What made your previous attempt expensive is repeated full generation across multiple layers, plus high output token rates for long prose. fileciteturn0file14turn0file6 citeturn1view0turn0search3  

If you use OpenAI for offline batch builds, the pricing page also notes a Batch API that discounts both inputs and outputs by 50 percent, which fits your use case because encyclopedia builds are not latency critical. citeturn1view0  

### Engineering time

The time cost is dominated by building the deterministic parts:

- Evidence store, indexing, and candidate harvesting.  
- Validators for your acceptance criteria.  
- A review queue workflow and override storage.

Your existing artifacts already contain most of the domain rules and concept keys, which reduces initial effort, but the deterministic “candidate harvesting” from heterogeneous sources is still the main build task. fileciteturn0file7turn0file9turn0file12turn0file13  

### Risk

The main residual risks after this redesign are:

Concept boundary ambiguity. The validator’s DUMP_VALVE FAIL is a strong signal that some ids are too generic and should be split into separate concepts, or the schema must support scoped subtypes. fileciteturn0file6  

Customer language noise. Your domain rules show that customers use fundamentally wrong phrases (“butla z tlenem”, “maska z tlenem”), so synonym handling must explicitly support wrong but popular forms to avoid unsafe recommendations. fileciteturn0file9  

Copyright and publishing. If the “encyclopedia for people” will be published, definitions derived from training materials and commercial encyclopedias must be handled as paraphrases with internal evidence, not as copied text spans. Your PADI fragments are clearly proprietary textbook style content. fileciteturn0file13  

## Blind spots to address explicitly

Your current mental model is strong on “telephone game,” but three structural blind spots matter for correctness and for long term maintenance.

Your acceptance criteria require data your schema cannot hold. Evidence per definition and anglicism typing per synonym are required by your criteria, but the target schema stores only plain strings without provenance. This should be solved by a sidecar, not by cramming metadata into strings. fileciteturn0file10turn0file0  

A single v2 JSON is serving two incompatible products: internal grounding for an AI assistant versus public encyclopedia content. High fidelity internal grounding prefers short, precise, evidence tethered phrasing, while public content needs original writing and often broader context, and it must respect licensing constraints. Treat these as separate outputs produced from the same evidence store. fileciteturn0file13turn0file10  

The “zero mixing languages” rule conflicts with real Polish diving usage. Your own examples and v1 data show that Polish divers use English loanwords like wing, backplate, BCD, LPI. The correct requirement is not “no English in PL fields,” but “loanwords allowed and explicitly tagged,” otherwise you will either violate your own rules or lose crucial mapping coverage. fileciteturn0file0turn0file2turn0file3turn0file10