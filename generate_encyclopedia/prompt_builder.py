"""Budowanie promptow z szablonow Jinja2 i metadanych grupy."""

import json
from datetime import datetime
from pathlib import Path

from jinja2 import Environment, FileSystemLoader

from config import TEMPLATES_DIR
from group_metadata import GroupMetadata

# Jinja2 env
_env = Environment(
    loader=FileSystemLoader(str(TEMPLATES_DIR)),
    trim_blocks=True,
    lstrip_blocks=True,
    keep_trailing_newline=True,
)

# Grupy wymagajace pola bledne_ale_popularne w synonimy_pl
GROUPS_WITH_BLEDNE = {"B", "E", "F"}

# Extra self-check items per grupa
GROUP_EXTRA_CHECKS: dict[str, list[str]] = {
    "A": [
        "Czy WAZ_LP i WAZ_HP maja ostrzezenie o niekompatybilnosci i zagrozeniu bezpieczenstwa?",
        "Czy REBREATHER rozroznia CCR i SCR?",
        "Czy PIERWSZY_STOPIEN zawiera info o DIN jako jedynym standardzie?",
    ],
    "B": [
        "Czy BUTLA_ARGONU ma ostrzezenie: NIE do oddychania?",
        "Czy MANIFOLD rozroznia izolujacy vs nieizolujacy?",
        "Czy ZLACZE_INT jest opisany jako martwy standard (nie opcja zakupowa)?",
        "Czy ZLACZE_DIN NIE wspomina INT jako alternatywy?",
        "Czy 'butla z tlenem' jest w synonimy.bledne_ale_popularne (nie w exact/near)?",
        "Czy butla do snorkelingu jest w 'nie_mylic_z' dla BUTLA_NURKOWA?",
    ],
    "C": [
        "Czy INFLATOR, WAZ_INFLATORA i WAZ_KARBOWANY sa opisane jako 3 ROZNE SKU?",
        "Czy SIDEMOUNT jest opisany jako konfiguracja, nie produkt?",
    ],
    "D": [
        "Czy KOMPUTER_NURKOWY != zegarek sportowy jest jasno komunikowane?",
        "Czy TRANSMITER wymaga kompatybilnego komputera tej samej marki?",
    ],
    "E": [
        "Czy 'maska do nurkowania z tlenem' jest obsluzona jako blad terminologiczny?",
        "Czy 'okulary do nurkowania' jest mapowane na maske?",
    ],
    "F": [
        "Czy PIANKA_POLSUCHA != suchy skafander jest jasno komunikowane?",
        "Czy DOCIEPLACZ jest opisany jako kamizelka pod pianke (hooded vest)?",
    ],
    "G": [
        "Czy BUTLA_ARGONU jest wspomniana jako powiazany produkt (nie do oddychania)?",
        "Czy OCIEPLACZ (undersuit) != DOCIEPLACZ (hooded vest)?",
    ],
    "H": [
        "Czy PLETWY_KALOSZOWE != PLETWY_PASKOWE jest jasno opisane?",
        "Czy SPREZYNY_DO_PLETW to zamiennik paska, nie osobna kategoria?",
    ],
    "I": [
        "Czy SZPULKA != KOLOWROTEK (szpulka bez korby, kolowrotek z korba)?",
        "Czy BOJA_NURKOWA: SMB vs DSMB rozroznienie?",
        "Czy WOREK_PODNOSZACY != BOJA jest jasne?",
    ],
    "J": [
        "Czy OBUDOWA_PODWODNA jest modelowa (do konkretnego aparatu)?",
        "Czy lumeny != jakosc oswietlenia jest wspomniane?",
    ],
}

# Safety checks per grupa (do walidacji)
GROUP_SAFETY_CHECKS: dict[str, list[str]] = {
    "A": [
        "Czy WAZ_LP i WAZ_HP maja wyrazne ostrzezenie o niekompatybilnosci?",
    ],
    "B": [
        "Czy BUTLA_ARGONU ma ostrzezenie: NIE do oddychania (smiertelnie niebezpieczne)?",
        "Czy MANIFOLD rozroznia izolujacy (bezpieczny) vs nieizolujacy?",
        "Czy cisnienia robocze (200 vs 300 bar) sa jasno rozroznione?",
    ],
    "C": [
        "Czy sila wypornosci (lift) musi byc dobrana do konfiguracji?",
    ],
    "F": [
        "Czy pianka polsucha != suchy skafander jest jasne?",
    ],
    "G": [
        "Czy argon NIE do oddychania jest wspomniane?",
    ],
    "I": [
        "Czy szpulka != kolowrotek jest jasno rozroznione?",
    ],
}


def build_generation_prompt(
    metadata: GroupMetadata,
    *,
    sub_batch_concepts: list | None = None,
    batch_number: int = 0,
    total_batches: int = 0,
) -> str:
    """Buduje prompt generacyjny z szablonu Jinja2 i metadanych grupy.

    Args:
        metadata: metadane grupy
        sub_batch_concepts: jesli sub-batch, lista Concept do wygenerowania
        batch_number: numer batcha (1-based)
        total_batches: laczna liczba batchy
    """
    template = _env.get_template("generation.md.j2")

    has_bledne = metadata.group_id in GROUPS_WITH_BLEDNE
    extra_checks = GROUP_EXTRA_CHECKS.get(metadata.group_id, [])

    is_sub_batch = sub_batch_concepts is not None
    concepts = sub_batch_concepts if is_sub_batch else metadata.concepts
    concept_count = len(concepts) if is_sub_batch else metadata.concept_count

    return template.render(
        group_id=metadata.group_id,
        group_name=metadata.group_name,
        concept_count=concept_count,
        concepts=concepts,
        v1_errors=metadata.v1_known_errors,
        brands_allowed=metadata.brands_allowed,
        brands_forbidden=metadata.brands_forbidden,
        dataforseo_phrases=metadata.dataforseo_phrases,
        critical_rules=metadata.critical_rules,
        domain_rules=metadata.domain_rules,
        has_bledne_ale_popularne=has_bledne,
        extra_checks=extra_checks,
        timestamp=datetime.now().strftime("%Y-%m-%d"),
        enumerate=enumerate,
        # Sub-batch
        is_sub_batch=is_sub_batch,
        all_concepts=metadata.concepts if is_sub_batch else [],
        batch_number=batch_number,
        total_batches=total_batches,
    )


def build_validation_prompt(metadata: GroupMetadata, generated_json: str) -> str:
    """Buduje prompt walidacyjny z szablonu Jinja2 i metadanych grupy."""
    template = _env.get_template("validation.md.j2")

    has_bledne = metadata.group_id in GROUPS_WITH_BLEDNE
    safety_checks = GROUP_SAFETY_CHECKS.get(metadata.group_id, [])

    # Oblicz offset dla numerowania (po sekcji bezpieczenstwo)
    base = 12 if has_bledne else 11
    safety_check_offset = base + len(safety_checks)

    return template.render(
        group_id=metadata.group_id,
        group_name=metadata.group_name,
        concept_count=metadata.concept_count,
        v1_errors=metadata.v1_known_errors,
        brands_allowed=metadata.brands_allowed,
        brands_forbidden=metadata.brands_forbidden,
        dataforseo_phrases=metadata.dataforseo_phrases,
        domain_rules=metadata.domain_rules,
        generated_json=generated_json,
        has_bledne_ale_popularne=has_bledne,
        safety_checks=safety_checks,
        safety_check_offset=safety_check_offset,
        timestamp=datetime.now().strftime("%Y-%m-%d"),
    )
