"""Pydantic schemas dla encyklopedii sprzętowej (TASK-014)."""

from __future__ import annotations

from typing import List, Literal, Optional

from pydantic import BaseModel, Field, model_validator


class Synonym(BaseModel):
    term: str = Field(..., min_length=1, max_length=100)
    type: Literal[
        "exact_synonym",
        "near_synonym",
        "colloquial",
        "legacy_name",
        "brand_term",
        "anglicyzm",
        "misleading_term",
        "niezalecany",
    ]
    language: Literal["pl", "en"]
    note: Optional[str] = None


class Relation(BaseModel):
    type: Literal[
        "nie_mylic_z",
        "nadrzedny",
        "podrzedny",
        "czesc_zestawu",
        "wariant",
    ]
    target_concept_key: str = Field(..., pattern=r"^[a-z][a-z0-9_]*$")
    why: Optional[str] = None
    disambiguation_clues: list[str] = []

    @model_validator(mode="after")
    def require_why_for_nie_mylic_z(self) -> Relation:
        if self.type == "nie_mylic_z" and not self.why:
            raise ValueError("Relacja 'nie_mylic_z' wymaga pola 'why'")
        return self


class Evidence(BaseModel):
    source_id: str = Field(
        ...,
        description="Identyfikator źródła: padi_ch3, padi_ch5, iantd_owd, cmas, nurkomania_sprzet, nurkomania_teoria, divezone",
    )
    quote: str = Field(..., min_length=10)
    language: Literal["pl", "en"]


class ConceptEntry(BaseModel):
    concept_key: str = Field(..., pattern=r"^[a-z][a-z0-9_]*$")
    canonical_term_pl: str = Field(..., min_length=1)
    canonical_term_en: str = Field(..., min_length=1)
    definition_operational_pl: str = Field(
        ...,
        min_length=20,
        description="1-2 zdania odróżniające od najbliższego mylonego pojęcia",
    )
    definition_pl: Optional[str] = None
    definition_en: Optional[str] = None
    synonyms: list[Synonym] = []
    relations: list[Relation] = []
    evidence: list[Evidence] = []
    confidence: Literal["high", "medium", "low"] = "medium"
    status: Literal["validated", "needs_review"] = "needs_review"
    version: int = 1

    @model_validator(mode="after")
    def check_self_reference(self) -> ConceptEntry:
        for rel in self.relations:
            if rel.target_concept_key == self.concept_key:
                raise ValueError(f"Relacja nie może wskazywać na siebie: {self.concept_key}")
        return self


class ConceptKeyEntry(BaseModel):
    """Wpis w concept_keys.json."""

    concept_key: str = Field(..., pattern=r"^[a-z][a-z0-9_]*$")
    canonical_term_pl: str
    canonical_term_en: str
    category_group: str = Field(
        ...,
        description="Grupa kategorii: breathing, buoyancy, protection, navigation, tools, specialty",
    )


class ContrastPair(BaseModel):
    """Para myląca w golden test set."""

    concept_a: str
    concept_b: str
    disambiguation: str
    test_queries: list[str] = Field(..., min_length=1)


class CustomerQuery(BaseModel):
    """Zapytanie klienckie w golden test set."""

    query: str
    expected_concept: str
    expected_flag: Optional[str] = None
    forbidden_concepts: list[str] = []


class GoldenTestSet(BaseModel):
    """Pełny golden test set."""

    contrast_pairs: list[ContrastPair]
    customer_queries: list[CustomerQuery]
