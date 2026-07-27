"""
Schema v2 encyklopedii nurkowej — modele Pydantic.
ADR-037: deterministyczny Python + minimalny LLM.
"""

from __future__ import annotations

from typing import Optional
from pydantic import BaseModel, Field


# --- Synonimy ---

class SynonymsPL(BaseModel):
    """Synonimy polskie, rozbite na buckety."""
    exact: list[str] = Field(default_factory=list)
    near: list[str] = Field(default_factory=list)
    potoczne: list[str] = Field(default_factory=list)
    anglicyzmy: list[str] = Field(default_factory=list)
    archaiczne: list[str] = Field(default_factory=list)
    bledne_ale_popularne: list[str] = Field(default_factory=list)


class SynonymsEN(BaseModel):
    """Synonimy angielskie."""
    exact: list[str] = Field(default_factory=list)
    near: list[str] = Field(default_factory=list)


# --- Relacje ---

class NieMylic(BaseModel):
    """Relacja 'nie mylić z' — odróżnienie od pokrewnego pojęcia."""
    concept: str
    dlaczego: str


class FAQItem(BaseModel):
    """Pozycja FAQ — pytanie i odpowiedź."""
    pytanie: str
    odpowiedz: str


# --- Główny model pojęcia ---

class ConceptV2(BaseModel):
    """Pojedyncze pojęcie encyklopedii v2."""
    id: str
    nazwa_pl: str
    nazwa_en: Optional[str] = None
    definicja: Optional[str] = None
    podtypy: Optional[list[str]] = None
    synonimy_pl: SynonymsPL = Field(default_factory=SynonymsPL)
    synonimy_en: SynonymsEN = Field(default_factory=SynonymsEN)
    nie_mylic_z: list[NieMylic] = Field(default_factory=list)
    parametry_zakupowe: Optional[list[str]] = None
    marki_w_sklepie: Optional[list[str]] = None
    powiazane_produkty: list[str] = Field(default_factory=list)
    faq: Optional[list[FAQItem]] = None
    uwagi_dla_ai: Optional[str] = None

    # Pole tymczasowe — kandydaci synonimów z danych klientów (krok 2)
    _candidate_synonyms: list[dict] = []

    class Config:
        # pozwól na _candidate_synonyms jako prywatne pole
        underscore_attrs_are_private = True


# --- Evidence sidecar ---

class EvidenceSource(BaseModel):
    """Źródło evidence dla konkretnego pola."""
    source_id: str
    fragment: Optional[str] = None
    page: Optional[str] = None
    article: Optional[str] = None
    language: Optional[str] = None


class EvidenceField(BaseModel):
    """Evidence dla jednej wartości pola."""
    value: Optional[str] = None
    sources: list[EvidenceSource] = Field(default_factory=list)


# Evidence sidecar to dict[concept_id, dict[field_path, EvidenceField]]
# np. {"JACKET": {"definicja": EvidenceField(...)}}
