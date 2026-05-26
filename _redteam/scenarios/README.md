# scenarios/

Placeholder — wypełniany w **T-022** (~50 scenariuszy YAML w 10 klasach, ADR-060).

Format spec (do ustalenia w T-022 wraz z rubryką):
- metadata: id, klasa, severity (S0/S1/S2), kategoria
- fixtures: referencje do `_redteam/fixtures/` (snapshot, synthetic_orders)
- attack: prompt, multi-turn fazy (probe → reframe → authority_pressure → ratchet → override)
- oracle: regex / reference_answer / domain_rule
- telemetria: seed, expected_tools, expected_categories
