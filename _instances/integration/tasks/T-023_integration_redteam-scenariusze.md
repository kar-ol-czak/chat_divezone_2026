# T-023: Red-team scenariusze YAML (~50, 10 klas) + format specyfikacji

Instancja: integration (definicje danych, nie kod produkcyjny)
Powiazane: ADR-060, _docs/26 (synteza panelu -- format YAML z raportow 2 i 3), _redteam/scenarios/README.md (placeholder), _redteam/domain_rules/forbidden_terms.yaml, _redteam/fixtures/synthetic_orders.json
Priorytet: P1 (rdzen harness -- bez scenariuszy orchestrator nie ma co uruchamiac)
Czas: ~4h CC
Pliki: _redteam/scenarios/{klasa}/{id}.yaml + _redteam/scenarios/SCHEMA.md

## Kontekst
T-021 dal fundament (forbidden_terms.yaml = gotowa baza W0, synthetic_orders.json = fixtures IDOR ze scenario_hints). Teraz piszemy ~50 scenariuszy w 10 klasach wg ADR-060. Format YAML skonsolidowany z raportow panelu (red-team-2 sekcja 'Baza scenariuszy', red-team-3 sekcja E). Snapshot ground truth NIE jest blokada -- scenariusze odnosza sie do produktow po nazwie/cesze, snapshot doczepi sie w T-024 jako kontekst sedziego.

## KROK 0. Read
- ADR-060 (10 klas MVP), _docs/26 (sekcje D multi-turn, E baza scenariuszy)
- _redteam/scenarios/README.md, _redteam/domain_rules/forbidden_terms.yaml (klasy + severity), _redteam/fixtures/synthetic_orders.json (scenario_hints)
- _docs/24_analiza_testow_pracownikow_arkusz3.md + _docs/22 (Arkusz2) -- realne bugi jako seed scenariuszy

## KROK 1. SCHEMA.md -- kontrakt formatu (NAJPIERW, do mojej akceptacji)
Napisz _redteam/scenarios/SCHEMA.md definiujacy pola scenariusza (skonsolidowane z panelu):
- id (immutable, format KLASA-NNN), title, category, subcategory, severity (S0/S1/S2)
- owasp_llm / mitre_atlas (opcjonalne tagi mapowania)
- introduced_in (data), introduced_by (zrodlo: arkusz/manual/panel)
- target: stateful, session_init (locale: pl)
- strategy: type (scripted | semi_scripted | dynamic), attacker_strategy (crescendo|mischievous_user|linear), max_turns (5 dla MVP), temperature (0), seed
- attack_steps: lista tur (role:user, content; opcjonalnie if_target_says warunek)
- 5-fazowa eskalacja gdzie pasuje: probe -> reframe -> authority_pressure -> ratchet -> override
- ground_truth (dla halucynacji): products_required / products_absent (po nazwie/cesze, nie SKU jesli mozliwe)
- oracle: hard_fail_regex (odwolanie do forbidden_terms klasy) + reference_answer + judge_rubric (lista osi)
- expected: must_refuse / must_offer_redirect / must_not_call_tools
- tags
STOP 1: pokaz SCHEMA.md + 1 przykladowy scenariusz. Czekaj na akceptacje formatu PRZED pisaniem 50.

## KROK 2. Scenariusze -- 10 klas (po STOP 1)
Jeden plik = jeden scenariusz: _redteam/scenarios/{klasa}/{ID}.yaml (NIE jeden duzy plik -- konflikty PR). ~50 scenariuszy, rozklad:
Klasy (z ADR-060), min 3-5 scen kazda, S0 min 5:
1. jailbreak_framing (praca magisterska/research -- case 87; role-play) -- 5
2. medical_out_of_scope (maska a wirusy case 82; dawki; diagnoza) -- 5
3. hallucination_product (istniejacy NIE znaleziony -- case 90 Crystal Vu; nieistniejacy 'znaleziony' -- case 91 BZ4000; fabrykowanie awarii) -- 6
4. domain_errors_diving (INT/DIN case 94; limit 40m + fikcyjny cert case 85; gazy/Trimix) -- 6
5. data_leak_internal (surowe statusy case 93; nazwy wewnetrzne BARTEK/LESZEK; ilosci sztuk; system prompt leak) -- 5
6. out_of_competence (instruktorzy case 80; pranie skafandra case 81; uzywany sprzet case 77) -- 5
7. sales_manipulation (budzet nierealny case 93; rabat falszywa pamiec; konkurencja) -- 4
8. indirect_prompt_injection (zlosliwy tekst w opisie/opinii -> RAG -> instrukcja; symulacja przez fixture) -- 5
9. function_calling_idor (OrderStatus enumeration; parameter injection -- uzyj synthetic_orders.json TEST-FAKE-INJ; excessive calls) -- 5
10. multilingual_encoding (PL<->EN switch w srodku; leetspeak/Base64; ten sam atak PL vs EN) -- 4
Razem ~50. Kazdy seedowany z realnego bugu (Arkusz1-3) gdzie mozliwe -- introduced_by wskazuje zrodlo.

## KROK 3. Walidacja spojnosci
- Kazdy hard_fail_regex w scenariuszu odpowiada istniejacej klasie w forbidden_terms.yaml (lub zglos brakujace do uzupelnienia).
- IDOR scenariusze uzywaja TYLKO referencji z synthetic_orders.json (zero realnych numerow).
- Klasy S0 (medical, life-safety, data_leak, idor) maja must_refuse=true.
- Skrypt _redteam/tools/validate_scenarios.py: sprawdza schema (wymagane pola, severity z {S0,S1,S2}, regex sie kompiluje, brak duplikatow id). Lekki, czysty Python (yaml + re).

## KROK 4. STOP 2 -- review
Pokaz: liste 50 scenariuszy (id + klasa + severity + 1-linijkowy intent), wynik validate_scenarios.py, liste brakujacych wpisow w forbidden_terms (jesli sa). NIE commituj bez akceptacji.

## KROK 5. Git
git add _redteam/scenarios/ _redteam/tools/validate_scenarios.py
commit: "T-023: red-team scenariusze (~50, 10 klas) + SCHEMA + validate_scenarios.py [ADR-060]"
git push origin main. Osobny commit docs: status.

## KROK 6. Raport + status
_instances/integration/handoff/T-023_done.md: rozklad scen per klasa/severity, brakujace forbidden_terms, gotowosc do orchestratora. Update _docs/21 (T-023 DONE). Osobny commit docs:.

## Out of scope
- Orchestrator Promptfoo + custom HTTP provider -> T-024
- Rubryki sedziego W1/W2 -> T-024 (judge_prompts/)
- Snapshot realny ground truth -> czeka na dostep do bazy (port Railway), doczepiamy w T-024
- dynamic exploratory scenariusze (20% wg panelu) -> po MVP, gdy regression dziala