#!/usr/bin/env python3
"""
TASK-ENC-008: Generacja encyklopedii sprzetu nurkowego via API.

Faza 1 (test): 3 hasla x 3 modele (Gemini, Claude, OpenAI)
Faza 2 (batch): 106 hasel na wybranym modelu

Uzycie:
    python3 scripts/generate_encyclopedia.py --phase test
    python3 scripts/generate_encyclopedia.py --phase batch --model gemini
    python3 scripts/generate_encyclopedia.py --phase batch --model gemini --start-batch 5
"""

import argparse
import csv
import json
import os
import re
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

import httpx
from dotenv import load_dotenv

# -------------------------------------------------------------------
# Sciezki
# -------------------------------------------------------------------
PROJECT_ROOT = Path(__file__).resolve().parent.parent
DOCS = PROJECT_ROOT / "_docs"
DATA = PROJECT_ROOT / "data"

PROMPT_FILE = DOCS / "PROMPT_gemini_encyklopedia_v3.md"
CONCEPT_KEYS_FILE = DOCS / "FAZA1_concept_keys_v2.md"
EXPERT_FILE = DOCS / "wiedza_nurkowa" / "transkrypt_kwestionariusza_eksperta.md"
NOTEBOOK_FILE = DOCS / "wiedza_nurkowa" / "Encyklopedia_Nurkowania_NotebookLM_v2.md"
BRANDS_FILE = DOCS / "11_mapa_marek-reviewed.md"
DOMAIN_RULES_FILE = DOCS / "17_reguly_domenowe_grupy_C-M.md"
CROSSSELL_FILE = DOCS / "dane_sprzedazowe_crosssell_12m.md"
BESTSELLERS_FILE = DOCS / "dane_sprzedazowe_bestsellery_12m.md"
PAA_FILE = DATA / "dataforseo" / "questions" / "atp_questions_all.csv"
KEYWORDS_FILE = DATA / "dataforseo" / "processed" / "all_keywords.csv"

OUTPUT_DIR = DATA / "encyclopedia" / "v3"
TEST_DIR = OUTPUT_DIR / "test"
TEST_V2_DIR = OUTPUT_DIR / "test_v2"

load_dotenv(PROJECT_ROOT / ".env")


def strip_markdown_wrapper(text: str) -> str:
    """Usuwa ```markdown wrapper jesli Gemini go dodal.

    Gemini czesto dodaje intro tekst + ```markdown ... ``` wrapper.
    Wyciagamy zawartosc z srodka bloku (lub blokow).
    """
    text = text.strip()
    # Jesli caly tekst zaczyna sie od ```markdown
    if text.startswith("```markdown"):
        text = text[len("```markdown"):].lstrip("\n")
        if text.endswith("```"):
            text = text[:-3].rstrip("\n")
        return text.strip()
    # Jesli jest intro tekst + bloki ```markdown ... ```
    if "```markdown" in text:
        # Wyciagnij zawartosc wszystkich blokow markdown
        blocks = re.findall(r"```markdown\s*\n(.*?)```", text, re.DOTALL)
        if blocks:
            return "\n\n---\n\n".join(b.strip() for b in blocks)
    return text.strip()


# -------------------------------------------------------------------
# Modele
# -------------------------------------------------------------------
MODELS = {
    "gemini": {
        "provider": "google",
        "model": "gemini-3.1-pro-preview",
        "api_key_env": "GOOGLE_GEMINI_API",
    },
    "claude": {
        "provider": "anthropic",
        "model": "claude-opus-4-6",
        "api_key_env": "ANTHROPIC_API_KEY",
    },
    "openai": {
        "provider": "openai",
        "model": "gpt-5.2",
        "api_key_env": "OPENAI_API_KEY",
    },
}


# -------------------------------------------------------------------
# Hasla testowe
# -------------------------------------------------------------------
TEST_CONCEPTS = [
    "AUTOMAT ODDECHOWY / REGULATOR",
    "JACKET (BCD) / JACKET (BCD)",
    "SUCHY SKAFANDER / DRYSUIT",
]


# -------------------------------------------------------------------
# Mapowania hasel → zrodla kontekstu
# -------------------------------------------------------------------
# Mapowanie concept name → grupy kwestionariusza eksperta
CONCEPT_TO_EXPERT_GROUP: dict[str, list[str]] = {
    "AUTOMAT ODDECHOWY": ["Grupa 1: Automaty oddechowe"],
    "PIERWSZY STOPIEŃ AUTOMATU": ["Grupa 1: Automaty oddechowe"],
    "DRUGI STOPIEŃ AUTOMATU": ["Grupa 1: Automaty oddechowe"],
    "OCTOPUS": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW AUTOMATÓW REKREACYJNY": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW AUTOMATÓW DO TWINSETU": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW AUTOMATU DO STAGE": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW AUTOMATÓW SIDEMOUNT": ["Grupa 1: Automaty oddechowe", "Grupa 21: Sidemount"],
    "WĄŻ LP": ["Grupa 3: Weze"],
    "WĄŻ HP": ["Grupa 3: Weze"],
    "MANOMETR": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "BUTLA NURKOWA": ["Grupa 4: Butle"],
    "BUTLA STAGE": ["Grupa 4: Butle"],
    "BUTLA DO ARGONU": ["Grupa 4: Butle"],
    "TWINSET": ["Grupa 4: Butle"],
    "MANIFOLD": ["Grupa 7: Zawory i zlacza"],
    "ZAWÓR BUTLOWY": ["Grupa 7: Zawory i zlacza"],
    "ZŁĄCZE DIN": ["Grupa 7: Zawory i zlacza"],
    "ZŁĄCZE INT": ["Grupa 7: Zawory i zlacza"],
    "ADAPTER DIN/INT": ["Grupa 7: Zawory i zlacza"],
    "JACKET": ["Grupa 2: BCD, jackety, skrzydla"],
    "SKRZYDŁO": ["Grupa 2: BCD, jackety, skrzydla"],
    "BACKPLATE": ["Grupa 2: BCD, jackety, skrzydla"],
    "UPRZĄŻ": ["Grupa 2: BCD, jackety, skrzydla"],
    "SKRZYDŁO Z UPRZĘŻĄ DO POJEDYNCZEJ BUTLI": ["Grupa 2: BCD, jackety, skrzydla"],
    "SKRZYDŁO Z UPRZĘŻĄ DO TWINA": ["Grupa 2: BCD, jackety, skrzydla"],
    "INFLATOR BCD": ["Grupa 2: BCD, jackety, skrzydla"],
    "WĄŻ INFLATORA BCD": ["Grupa 2: BCD, jackety, skrzydla"],
    "WĄŻ KARBOWANY": ["Grupa 2: BCD, jackety, skrzydla"],
    "ZAWÓR UPUSTOWY BCD": ["Grupa 2: BCD, jackety, skrzydla"],
    "SIDEMOUNT": ["Grupa 2: BCD, jackety, skrzydla", "Grupa 21: Sidemount"],
    "BALAST": ["Grupa 6: Balast"],
    "PAS BALASTOWY": ["Grupa 6: Balast"],
    "KIESZENIE NA BALAST": ["Grupa 6: Balast"],
    "TRYMÓWKA": ["Grupa 6: Balast"],
    "KOMPUTER NURKOWY": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "TRANSMITER CIŚNIENIA": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "KONSOLA NURKOWA": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "KOMPAS NURKOWY": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "ZEGAREK NURKOWY": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "TABLICZKA DO PISANIA": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "MASKA JEDNOSZYBOWA": ["Grupa 9: Maski i fajki"],
    "MASKA DWUSZYBOWA": ["Grupa 9: Maski i fajki"],
    "MASKA PEŁNOTWARZOWA": ["Grupa 9: Maski i fajki"],
    "MASKA KOREKCYJNA": ["Grupa 9: Maski i fajki"],
    "MASKA PANORAMICZNA": ["Grupa 9: Maski i fajki"],
    "MASKA DO NURKOWANIA DLA DZIECI": ["Grupa 9: Maski i fajki"],
    "ZESTAW MASKA + FAJKA": ["Grupa 9: Maski i fajki"],
    "FAJKA NURKOWA": ["Grupa 9: Maski i fajki"],
    "PIANKA MOKRA": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA NA ZIMNE WODY": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA NA CIEPŁE WODY": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA SHORTY": ["Grupa 10: Pianki mokre i polsuche"],
    "KOMPLET PIANEK": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA PÓŁSUCHA": ["Grupa 10: Pianki mokre i polsuche"],
    "DOCIEPLACZ": ["Grupa 10: Pianki mokre i polsuche"],
    "KAPTUR NURKOWY": ["Grupa 10: Pianki mokre i polsuche", "Grupa 11: Rekawice, buty, kaptury"],
    "SUCHY SKAFANDER": ["Grupa 8: Suche skafandry i akcesoria"],
    "ZAWORY SUCHEGO SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "ZAWÓR UPUSTOWY SUCHEGO SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "WĄŻ DO SUCHEGO SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "MANSZETY DO SUCHEGO SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "SYSTEM SUCHYCH RĘKAWIC": ["Grupa 8: Suche skafandry i akcesoria"],
    "BUTY DO SUCHEGO SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "OGRZEWANIE NURKOWE": ["Grupa 8: Suche skafandry i akcesoria"],
    "OCIEPLACZ": ["Grupa 8: Suche skafandry i akcesoria"],
    "ODZIEŻ TERMOAKTYWNA": ["Grupa 8: Suche skafandry i akcesoria"],
    "RĘKAWICE NURKOWE": ["Grupa 11: Rekawice, buty, kaptury"],
    "BUTY NURKOWE NEOPRENOWE": ["Grupa 11: Rekawice, buty, kaptury"],
    "PŁETWY PASKOWE": ["Grupa 12: Pletwy"],
    "PŁETWY KALOSZOWE": ["Grupa 12: Pletwy"],
    "PŁETWY JET FINS": ["Grupa 12: Pletwy"],
    "SPRĘŻYNY DO PŁETW": ["Grupa 12: Pletwy"],
    "RASHGUARD": ["Grupa 10: Pianki mokre i polsuche"],
    "BOJA NURKOWA": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "SZPULKA": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "KOŁOWROTEK": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "WOREK PODNOSZĄCY": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "NÓŻ NURKOWY": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "SEKATOR": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "ŚWIATŁO CHEMICZNE": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "LATARKA NURKOWA": ["Grupa 14: Latarki"],
    "LAMPA FOTO/VIDEO": ["Grupa 14: Latarki", "Grupa 16: Fotografia podwodna"],
    "OBUDOWA PODWODNA": ["Grupa 16: Fotografia podwodna"],
    "KAMERA GOPRO": ["Grupa 16: Fotografia podwodna"],
    "TORBA NURKOWA": ["Grupa 17: Torby i transport"],
    "SKRZYNIA TRANSPORTOWA": ["Grupa 17: Torby i transport"],
    "ANTIFOG": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "PASEK DO MASKI": ["Grupa 9: Maski i fajki"],
    "SMAR SILIKONOWY": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "KLEJ NEOPRENOWY": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "O-RING": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "SUSZARKA / WIESZAK": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "ZESTAW SERWISOWY AUTOMATU": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "ODZIEŻ NURKOWA": ["Grupa 20: Lifestyle, morsowanie, odziez"],
    "KSIĄŻKI NURKOWE": ["Grupa 19: Edukacja i ksiazki"],
    "LOGBOOK NURKOWY": ["Grupa 19: Edukacja i ksiazki"],
    "SPRZĘT DO MORSOWANIA": ["Grupa 20: Lifestyle, morsowanie, odziez"],
    "ZESTAW DO NURKOWANIA": [],
}

# Mapowanie concept name → seed keywords (do filtrowania PAA i all_keywords)
CONCEPT_TO_SEEDS: dict[str, list[str]] = {
    "AUTOMAT ODDECHOWY": ["automat nurkowy", "regulator nurkowy", "automat oddechowy"],
    "PIERWSZY STOPIEŃ AUTOMATU": ["automat nurkowy", "pierwszy stopień"],
    "DRUGI STOPIEŃ AUTOMATU": ["automat nurkowy", "drugi stopień"],
    "OCTOPUS": ["octopus nurkowy", "automat nurkowy"],
    "JACKET": ["jacket nurkowy", "kamizelka nurkowa", "BCD nurkowanie", "bcd nurkowy"],
    "SKRZYDŁO": ["skrzydło nurkowe", "wing nurkowy", "bcd nurkowy"],
    "SUCHY SKAFANDER": ["suchy skafander", "suchy skafander nurkowy", "drysuit"],
    "PIANKA MOKRA": ["pianka nurkowa", "pianka do nurkowania"],
    "MASKA JEDNOSZYBOWA": ["maska nurkowa", "maska do nurkowania"],
    "MASKA DWUSZYBOWA": ["maska nurkowa", "maska do nurkowania"],
    "PŁETWY PASKOWE": ["płetwy nurkowe", "płetwy do nurkowania"],
    "PŁETWY KALOSZOWE": ["płetwy nurkowe", "płetwy do nurkowania"],
    "KOMPUTER NURKOWY": ["komputer nurkowy", "komputer do nurkowania"],
    "LATARKA NURKOWA": ["latarka nurkowa", "latarka do nurkowania"],
    "BUTLA NURKOWA": ["butla nurkowa", "butla do nurkowania"],
    "BOJA NURKOWA": ["boja nurkowa", "smb nurkowy", "bojka nurkowa"],
    "BALAST": ["balast nurkowy", "ciężarki nurkowe"],
}

# Mapowanie concept name → grupy w atp_questions_all.csv
CONCEPT_TO_PAA_GROUP: dict[str, list[str]] = {
    "AUTOMAT ODDECHOWY": ["automaty"],
    "PIERWSZY STOPIEŃ AUTOMATU": ["automaty"],
    "DRUGI STOPIEŃ AUTOMATU": ["automaty"],
    "OCTOPUS": ["automaty"],
    "JACKET": ["bcd"],
    "SKRZYDŁO": ["bcd"],
    "BACKPLATE": ["bcd"],
    "UPRZĄŻ": ["bcd"],
    "INFLATOR BCD": ["bcd"],
    "SIDEMOUNT": ["sidemount"],
    "BALAST": ["balast"],
    "KOMPUTER NURKOWY": ["komputery"],
    "TRANSMITER CIŚNIENIA": ["komputery"],
    "KONSOLA NURKOWA": ["kompasy_manometry"],
    "KOMPAS NURKOWY": ["kompasy_manometry"],
    "MASKA JEDNOSZYBOWA": ["maski"],
    "MASKA DWUSZYBOWA": ["maski"],
    "MASKA PEŁNOTWARZOWA": ["maski"],
    "MASKA KOREKCYJNA": ["maski"],
    "PIANKA MOKRA": ["pianki", "ochrona_termiczna"],
    "PIANKA NA ZIMNE WODY": ["pianki", "ochrona_termiczna"],
    "PIANKA NA CIEPŁE WODY": ["pianki", "ochrona_termiczna"],
    "KAPTUR NURKOWY": ["pianki", "ochrona_termiczna"],
    "SUCHY SKAFANDER": ["ochrona_termiczna"],
    "OCIEPLACZ": ["ochrona_termiczna", "ogrzewanie"],
    "OGRZEWANIE NURKOWE": ["ogrzewanie"],
    "RĘKAWICE NURKOWE": ["ochrona_termiczna"],
    "BUTY NURKOWE NEOPRENOWE": ["ochrona_termiczna"],
    "PŁETWY PASKOWE": ["pletwy"],
    "PŁETWY KALOSZOWE": ["pletwy"],
    "PŁETWY JET FINS": ["pletwy"],
    "LATARKA NURKOWA": ["latarki"],
    "LAMPA FOTO/VIDEO": ["latarki", "fotografia"],
    "OBUDOWA PODWODNA": ["fotografia"],
    "BUTLA NURKOWA": ["butle"],
    "BUTLA STAGE": ["butle"],
    "TWINSET": ["butle"],
    "MANIFOLD": ["zawory"],
    "ZAWÓR BUTLOWY": ["zawory"],
    "BOJA NURKOWA": ["bezpieczenstwo"],
    "SZPULKA": ["bezpieczenstwo"],
    "KOŁOWROTEK": ["bezpieczenstwo"],
    "NÓŻ NURKOWY": ["bezpieczenstwo"],
    "TORBA NURKOWA": ["torby"],
    "ANTIFOG": ["akcesoria"],
    "WĄŻ LP": ["weze"],
    "WĄŻ HP": ["weze"],
}


# -------------------------------------------------------------------
# Batche (106 hasel pogrupowane tematycznie, max 5 na batch)
# -------------------------------------------------------------------
BATCHES: list[list[str]] = [
    # Batch 1-3: Grupa A - Oddychanie
    ["AUTOMAT ODDECHOWY / REGULATOR", "PIERWSZY STOPIEŃ AUTOMATU / FIRST STAGE",
     "DRUGI STOPIEŃ AUTOMATU / SECOND STAGE", "OCTOPUS / OCTOPUS",
     "ZESTAW AUTOMATÓW REKREACYJNY / RECREATIONAL REGULATOR SET"],
    ["ZESTAW AUTOMATÓW DO TWINSETU / TWINSET REGULATOR SET",
     "ZESTAW AUTOMATU DO STAGE / STAGE REGULATOR SET",
     "ZESTAW AUTOMATÓW SIDEMOUNT / SIDEMOUNT REGULATOR SET",
     "WĄŻ LP / LP HOSE", "WĄŻ HP / HP HOSE"],
    ["REBREATHER / REBREATHER", "NITROX (EANx) / ENRICHED AIR NITROX",
     "ANALIZATOR TLENOWY / OXYGEN ANALYZER", "ZESTAW SERWISOWY AUTOMATU / REGULATOR SERVICE KIT",
     "MANOMETR / SPG"],
    # Batch 4-5: Grupa B - Butle i zawory
    ["BUTLA NURKOWA / STEEL/ALUMINUM CYLINDER", "BUTLA STAGE / STAGE CYLINDER",
     "BUTLA DO ARGONU / ARGON CYLINDER", "TWINSET / DOUBLE CYLINDERS",
     "MANIFOLD / MANIFOLD"],
    ["ZAWÓR BUTLOWY / CYLINDER VALVE", "ZŁĄCZE DIN / DIN CONNECTOR",
     "ZŁĄCZE INT/YOKE / YOKE CONNECTOR", "ADAPTER DIN/INT / DIN TO YOKE ADAPTER"],
    # Batch 6-9: Grupa C - Kontrola plywalnisci
    ["JACKET (BCD) / JACKET (BCD)", "SKRZYDŁO / WING",
     "BACKPLATE / BACKPLATE", "UPRZĄŻ / HARNESS",
     "SKRZYDŁO Z UPRZĘŻĄ DO POJEDYNCZEJ BUTLI / SINGLE TANK WING SYSTEM"],
    ["SKRZYDŁO Z UPRZĘŻĄ DO TWINA / TWINSET WING SYSTEM",
     "INFLATOR BCD / BCD INFLATOR", "WĄŻ INFLATORA BCD / INFLATOR HOSE",
     "WĄŻ KARBOWANY / CORRUGATED HOSE", "ZAWÓR UPUSTOWY BCD / EXHAUST VALVE"],
    ["SIDEMOUNT / SIDEMOUNT", "BALAST / WEIGHTS",
     "PAS BALASTOWY / WEIGHT BELT",
     "KIESZENIE NA BALAST (ZINTEGROWANE) / INTEGRATED WEIGHT POCKETS",
     "TRYMÓWKA / TRIM WEIGHT"],
    ["SZELKI STAGE / STAGE RIGGING KIT"],
    # Batch 10: Grupa D - Instrumenty i nawigacja
    ["KOMPUTER NURKOWY / DIVE COMPUTER", "TRANSMITER CIŚNIENIA / PRESSURE TRANSMITTER",
     "KONSOLA NURKOWA / DIVE CONSOLE", "KOMPAS NURKOWY / DIVE COMPASS",
     "ZEGAREK NURKOWY / DIVE WATCH"],
    # Batch 11: Grupa D cd.
    ["TABLICZKA DO PISANIA / UNDERWATER SLATE"],
    # Batch 12-13: Grupa E - Maski i fajki
    ["MASKA JEDNOSZYBOWA / SINGLE LENS MASK", "MASKA DWUSZYBOWA / DUAL LENS MASK",
     "MASKA PEŁNOTWARZOWA / FULL FACE MASK", "MASKA KOREKCYJNA / PRESCRIPTION MASK",
     "MASKA PANORAMICZNA / PANORAMIC MASK"],
    ["MASKA DO NURKOWANIA DLA DZIECI / KIDS DIVE MASK",
     "ZESTAW MASKA + FAJKA / MASK AND SNORKEL SET",
     "FAJKA NURKOWA / SNORKEL"],
    # Batch 14-15: Grupa F - Pianki
    ["PIANKA MOKRA / WETSUIT", "PIANKA NA ZIMNE WODY / COLD WATER WETSUIT",
     "PIANKA NA CIEPŁE WODY / WARM WATER WETSUIT",
     "PIANKA SHORTY / SHORTY WETSUIT", "KOMPLET PIANEK / WETSUIT SET"],
    ["PIANKA PÓŁSUCHA / SEMI-DRY WETSUIT", "DOCIEPLACZ / HOODED VEST",
     "KAPTUR NURKOWY / DIVE HOOD"],
    # Batch 16-18: Grupa G - Suchy skafander
    ["SUCHY SKAFANDER / DRYSUIT", "ZAWORY SUCHEGO SKAFANDRA / DRYSUIT VALVES",
     "ZAWÓR UPUSTOWY SUCHEGO SKAFANDRA / DRYSUIT DUMP VALVE",
     "WĄŻ DO SUCHEGO SKAFANDRA / DRYSUIT HOSE",
     "MANSZETY DO SUCHEGO SKAFANDRA / DRYSUIT SEALS"],
    ["SYSTEM SUCHYCH RĘKAWIC / DRY GLOVE SYSTEM",
     "BUTY DO SUCHEGO SKAFANDRA / DRYSUIT BOOTS",
     "OGRZEWANIE NURKOWE / DIVE HEATING SYSTEM",
     "OCIEPLACZ / UNDERSUIT", "ODZIEŻ TERMOAKTYWNA / BASE LAYER"],
    ["RĘKAWICE NURKOWE / DIVE GLOVES"],
    # Batch 19: Grupa H - Obuwie i pletwy
    ["BUTY NURKOWE NEOPRENOWE / NEOPRENE DIVE BOOTS",
     "PŁETWY PASKOWE / OPEN HEEL FINS", "PŁETWY KALOSZOWE / FULL FOOT FINS",
     "PŁETWY JET FINS / JET FINS", "SPRĘŻYNY DO PŁETW / FIN SPRINGS"],
    # Batch 20: Grupa H cd.
    ["RASHGUARD / RASHGUARD"],
    # Batch 21: Grupa I - Bezpieczenstwo
    ["BOJA NURKOWA / DSMB", "SZPULKA / FINGER SPOOL",
     "KOŁOWROTEK / REEL", "WOREK PODNOSZĄCY / LIFT BAG",
     "NÓŻ NURKOWY / DIVE KNIFE"],
    # Batch 22: Grupa I cd + J
    ["SEKATOR / LINE CUTTER", "ŚWIATŁO CHEMICZNE / CYALUME",
     "LATARKA NURKOWA / DIVE LIGHT", "LAMPA FOTO/VIDEO / PHOTO/VIDEO LIGHT"],
    # Batch 23: Grupa J cd + K
    ["OBUDOWA PODWODNA / UNDERWATER HOUSING", "KAMERA GOPRO / GOPRO CAMERA",
     "KARABINEK NURKOWY / DIVE CLIP", "RETRACTOR / RETRACTOR"],
    # Batch 24: Grupa K cd + L
    ["TORBA NURKOWA / DIVE BAG", "SKRZYNIA TRANSPORTOWA / TRANSPORT CASE",
     "ANTIFOG / ANTI-FOG", "PASEK DO MASKI / MASK STRAP",
     "SMAR SILIKONOWY / SILICONE GREASE"],
    # Batch 25: Grupa L cd + M (duplikat ZESTAW SERWISOWY usuniety - jest w batch 3)
    ["KLEJ NEOPRENOWY / NEOPRENE GLUE", "O-RING / O-RING",
     "SUSZARKA / WIESZAK DO SKAFANDRA / DRYSUIT HANGER"],
    # Batch 26: Grupa M
    ["ODZIEŻ NURKOWA / DIVE APPAREL", "KSIĄŻKI NURKOWE / DIVE BOOKS",
     "LOGBOOK NURKOWY / DIVE LOGBOOK", "SPRZĘT DO MORSOWANIA / ICE SWIMMING GEAR",
     "ZESTAW DO NURKOWANIA / DIVE SET"],
]


# -------------------------------------------------------------------
# Ladowanie plikow zrodlowych
# -------------------------------------------------------------------

def load_file(path: Path) -> str:
    """Laduje plik tekstowy. Zwraca pusty string jesli nie istnieje."""
    if not path.exists():
        print(f"  [WARN] Brak pliku: {path}")
        return ""
    return path.read_text(encoding="utf-8")


def load_paa(groups: list[str]) -> str:
    """Filtruje atp_questions_all.csv po grupach."""
    if not PAA_FILE.exists():
        return ""
    rows = []
    with open(PAA_FILE, encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            if row.get("group", "") in groups:
                rows.append(row)
    if not rows:
        return ""
    lines = ["seed_keyword | pytanie | zrodlo | grupa"]
    for r in rows:
        lines.append(f"{r['seed_keyword']} | {r['question_or_phrase']} | {r['source']} | {r['group']}")
    return "\n".join(lines)


def load_keywords_for_seeds(seeds: list[str]) -> str:
    """Filtruje all_keywords.csv po frazach zawierajacych seed."""
    if not KEYWORDS_FILE.exists():
        return ""
    rows = []
    with open(KEYWORDS_FILE, encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for row in reader:
            kw = row.get("keyword", "").lower()
            for seed in seeds:
                if seed.lower() in kw:
                    rows.append(row)
                    break
    if not rows:
        return ""
    lines = ["keyword | search_volume | cpc | competition"]
    for r in rows[:30]:  # max 30 fraz
        lines.append(f"{r['keyword']} | {r.get('search_volume', '')} | {r.get('cpc', '')} | {r.get('competition', '')}")
    return "\n".join(lines)


def extract_expert_sections(group_names: list[str]) -> str:
    """Wyciaga sekcje z transkryptu eksperta po '## Grupa N:'."""
    text = load_file(EXPERT_FILE)
    if not text:
        return ""
    sections = []
    for gname in group_names:
        # szukaj sekcji zaczynającej sie od "## Grupa"
        # gname moze byc np. "Grupa 1: Automaty oddechowe"
        pattern = re.compile(
            r"(## " + re.escape(gname) + r".*?)(?=\n## Grupa |\Z)",
            re.DOTALL
        )
        match = pattern.search(text)
        if match:
            sections.append(match.group(1).strip())
    return "\n\n---\n\n".join(sections)


def extract_notebook_draft(concept_name: str) -> str:
    """Wyciaga draft hasla z Encyklopedia_Nurkowania_NotebookLM_v2.md."""
    text = load_file(NOTEBOOK_FILE)
    if not text:
        return ""
    # szukaj po nazwie hasla (w naglowku ## **N. NAZWA**)
    # concept_name moze byc "AUTOMAT ODDECHOWY / REGULATOR"
    short_name = concept_name.split("/")[0].strip()
    pattern = re.compile(
        r"(## \*?\*?\d+\.\s+" + re.escape(short_name) + r".*?)(?=\n## \*?\*?\d+\.\s+|\Z)",
        re.DOTALL | re.IGNORECASE
    )
    match = pattern.search(text)
    if match:
        return match.group(1).strip()
    return ""


def extract_crosssell_for_categories(category_keywords: list[str]) -> str:
    """Wyciaga linie z crosssell i bestsellers matching kategorie."""
    result_lines = []
    for fpath in [CROSSSELL_FILE, BESTSELLERS_FILE]:
        text = load_file(fpath)
        if not text:
            continue
        for line in text.split("\n"):
            line_lower = line.lower()
            for kw in category_keywords:
                if kw.lower() in line_lower:
                    result_lines.append(line)
                    break
    return "\n".join(result_lines[:60]) if result_lines else ""


# -------------------------------------------------------------------
# Mapowanie hasla do kategorii sklepowych (do crosssell/bestsellers)
# -------------------------------------------------------------------
CONCEPT_TO_SHOP_CATEGORIES: dict[str, list[str]] = {
    # Grupa A: Oddychanie
    "AUTOMAT ODDECHOWY": ["automaty", "zestawy", "regulator"],
    "PIERWSZY STOPIEŃ AUTOMATU": ["automaty", "akcesoria do automatów"],
    "DRUGI STOPIEŃ AUTOMATU": ["automaty", "akcesoria do automatów"],
    "OCTOPUS": ["automaty", "akcesoria do automatów"],
    "ZESTAW AUTOMATÓW REKREACYJNY": ["automaty", "zestawy", "akcesoria do automatów"],
    "ZESTAW AUTOMATÓW DO TWINSETU": ["automaty", "zestawy"],
    "ZESTAW AUTOMATU DO STAGE": ["automaty", "stage"],
    "ZESTAW AUTOMATÓW SIDEMOUNT": ["automaty", "side mount", "sidemount"],
    "WĄŻ LP": ["węże do automatów", "węże do inflator"],
    "WĄŻ HP": ["węże do manometr", "manometr"],
    "MANOMETR": ["manometr", "węże do manometr"],
    "ZESTAW SERWISOWY AUTOMATU": ["akcesoria do automatów", "serwis"],
    "REBREATHER": ["rebreather"],
    "NITROX": ["nitrox"],
    "ANALIZATOR TLENOWY": ["analizator"],
    # Grupa B: Butle i zawory
    "BUTLA NURKOWA": ["butl", "cylinder"],
    "BUTLA STAGE": ["butl", "stage"],
    "BUTLA DO ARGONU": ["butl", "argon"],
    "TWINSET": ["butl", "twinset", "manifold"],
    "MANIFOLD": ["manifold", "obejm"],
    "ZAWÓR BUTLOWY": ["zawor", "zawór", "butl"],
    "ZŁĄCZE DIN": ["din"],
    "ZŁĄCZE INT/YOKE": ["int", "yoke"],
    "ADAPTER DIN/INT": ["adapter"],
    # Grupa C: Kontrola pływalności
    "JACKET": ["jacket", "bcd", "kamizelk"],
    "SKRZYDŁO": ["skrzydł", "wing"],
    "BACKPLATE": ["płyt", "uprzęż", "backplate"],
    "UPRZĄŻ": ["płyt", "uprzęż", "harness"],
    "SKRZYDŁO Z UPRZĘŻĄ DO POJEDYNCZEJ BUTLI": ["skrzydł", "systemy balast"],
    "SKRZYDŁO Z UPRZĘŻĄ DO TWINA": ["skrzydł", "twinset"],
    "INFLATOR BCD": ["inflator", "węże do inflator"],
    "WĄŻ INFLATORA BCD": ["węże do inflator"],
    "WĄŻ KARBOWANY": ["karbowany", "inflator"],
    "ZAWÓR UPUSTOWY BCD": ["upustow"],
    "SIDEMOUNT": ["side mount", "sidemount"],
    "BALAST": ["balast", "ciężar"],
    "PAS BALASTOWY": ["balast", "pas"],
    "KIESZENIE NA BALAST": ["systemy balast", "balast"],
    "TRYMÓWKA": ["trym", "balast"],
    "SZELKI STAGE": ["stage", "szelki"],
    # Grupa D: Instrumenty
    "KOMPUTER NURKOWY": ["komputer", "computer"],
    "TRANSMITER CIŚNIENIA": ["transmit", "komputer"],
    "KONSOLA NURKOWA": ["konsol", "manometr"],
    "KOMPAS NURKOWY": ["kompas"],
    "ZEGAREK NURKOWY": ["zegarek"],
    "TABLICZKA DO PISANIA": ["tabliczk"],
    # Grupa E: Maski
    "MASKA JEDNOSZYBOWA": ["mask"],
    "MASKA DWUSZYBOWA": ["mask", "korekcyj"],
    "MASKA PEŁNOTWARZOWA": ["mask"],
    "MASKA KOREKCYJNA": ["mask", "korekcyj"],
    "MASKA PANORAMICZNA": ["mask", "panoram"],
    "MASKA DO NURKOWANIA DLA DZIECI": ["mask"],
    "ZESTAW MASKA + FAJKA": ["maska+fajka", "zestawy maska", "fajk"],
    "FAJKA NURKOWA": ["fajk"],
    # Grupa F: Pianki
    "PIANKA MOKRA": ["piank", "wetsuit", "neopren"],
    "PIANKA NA ZIMNE WODY": ["piank"],
    "PIANKA NA CIEPŁE WODY": ["piank"],
    "PIANKA SHORTY": ["piank", "shorty"],
    "KOMPLET PIANEK": ["piank"],
    "PIANKA PÓŁSUCHA": ["piank", "półsuch"],
    "DOCIEPLACZ": ["docieplacz", "ocieplacz"],
    "KAPTUR NURKOWY": ["kaptur"],
    # Grupa G: Suchy skafander
    "SUCHY SKAFANDER": ["suchy", "skafandr", "drysuit"],
    "ZAWORY SUCHEGO SKAFANDRA": ["suchy", "zawor"],
    "WĄŻ DO SUCHEGO SKAFANDRA": ["suchy", "wąż"],
    "MANSZETY DO SUCHEGO SKAFANDRA": ["manszet", "suchy"],
    "SYSTEM SUCHYCH RĘKAWIC": ["rękawic", "pierścien"],
    "BUTY DO SUCHEGO SKAFANDRA": ["buty", "suchy"],
    "OGRZEWANIE NURKOWE": ["ogrzewan", "grzew"],
    "OCIEPLACZ": ["ocieplacz", "ocieplacze do suchych"],
    "ODZIEŻ TERMOAKTYWNA": ["termoaktywn", "bielizn"],
    "RĘKAWICE NURKOWE": ["rękawic"],
    # Grupa H: Płetwy, buty
    "BUTY NURKOWE NEOPRENOWE": ["buty"],
    "PŁETWY PASKOWE": ["płetw", "fins"],
    "PŁETWY KALOSZOWE": ["płetw", "fins"],
    "PŁETWY JET FINS": ["płetw", "jet"],
    "SPRĘŻYNY DO PŁETW": ["sprężyn", "płetw"],
    "RASHGUARD": ["rashguard"],
    # Grupa I: Bezpieczeństwo
    "BOJA NURKOWA": ["boj", "smb", "szpulk"],
    "SZPULKA": ["szpulk", "bojk"],
    "KOŁOWROTEK": ["kołowrot", "reel"],
    "WOREK PODNOSZĄCY": ["worek", "podnosz"],
    "NÓŻ NURKOWY": ["noż", "nóż"],
    "SEKATOR": ["sekator", "cutter"],
    "ŚWIATŁO CHEMICZNE": ["światło", "cyalume"],
    # Grupa J: Oświetlenie + foto
    "LATARKA NURKOWA": ["latark", "oświetl", "light"],
    "LAMPA FOTO/VIDEO": ["lamp", "foto", "video"],
    "OBUDOWA PODWODNA": ["obudow", "housing"],
    "KAMERA GOPRO": ["gopro", "kamer"],
    # Grupa K+L: Akcesoria, transport
    "KARABINEK NURKOWY": ["karabink"],
    "RETRACTOR": ["retractor"],
    "TORBA NURKOWA": ["torb", "bag"],
    "SKRZYNIA TRANSPORTOWA": ["skrzyn", "transport"],
    "ANTIFOG": ["antifog", "anti-fog"],
    "PASEK DO MASKI": ["pasek", "mask"],
    "SMAR SILIKONOWY": ["smar", "silikon"],
    "KLEJ NEOPRENOWY": ["klej"],
    "O-RING": ["o-ring", "oring"],
    "SUSZARKA / WIESZAK": ["suszark", "wieszak"],
    # Grupa M: Zestawy, inne
    "ODZIEŻ NURKOWA": ["odzież"],
    "LOGBOOK NURKOWY": ["logbook"],
    "ZESTAW DO NURKOWANIA": ["zestaw"],
}


# -------------------------------------------------------------------
# Budowanie kontekstu per partia
# -------------------------------------------------------------------

def build_context_for_batch(concepts: list[str]) -> str:
    """Buduje kontekst z fragmentow zrodel relevantnych dla danej partii hasel."""
    parts = []

    # 1. Lista wszystkich 106 pojeć (do linkowania)
    concept_keys_text = load_file(CONCEPT_KEYS_FILE)
    if concept_keys_text:
        # wyciagnij tylko tabele z pojęciami (linie z |)
        table_lines = [l for l in concept_keys_text.split("\n") if "|" in l and "#" not in l[:3]]
        parts.append("## PEŁNA LISTA 106 POJĘĆ ENCYKLOPEDII (do linkowania wewnętrznego)\n" + "\n".join(table_lines[:120]))

    # 2. Fragmenty kwestionariusza eksperta
    all_groups: set[str] = set()
    for c in concepts:
        short = c.split("/")[0].strip()
        for key, groups in CONCEPT_TO_EXPERT_GROUP.items():
            if key in short:
                all_groups.update(groups)
                break
    if all_groups:
        expert_text = extract_expert_sections(list(all_groups))
        if expert_text:
            parts.append(f"## WIEDZA EKSPERTA (transkrypcja kwestionariusza)\n\n{expert_text}")

    # 3. PAA pytania
    all_paa_groups: set[str] = set()
    for c in concepts:
        short = c.split("/")[0].strip()
        for key, groups in CONCEPT_TO_PAA_GROUP.items():
            if key in short:
                all_paa_groups.update(groups)
                break
    if all_paa_groups:
        paa_text = load_paa(list(all_paa_groups))
        if paa_text:
            parts.append(f"## PYTANIA KLIENTÓW Z GOOGLE (People Also Ask + autocomplete)\n\n{paa_text}")

    # 4. Keywords z GSC/DataForSEO
    all_seeds: set[str] = set()
    for c in concepts:
        short = c.split("/")[0].strip()
        for key, seeds in CONCEPT_TO_SEEDS.items():
            if key in short:
                all_seeds.update(seeds)
                break
    if all_seeds:
        kw_text = load_keywords_for_seeds(list(all_seeds))
        if kw_text:
            parts.append(f"## FRAZY KLUCZOWE Z WYSZUKIWAREK (GSC / DataForSEO)\n\n{kw_text}")

    # 5. Crosssell i bestsellers
    all_shop_cats: set[str] = set()
    for c in concepts:
        short = c.split("/")[0].strip()
        for key, cats in CONCEPT_TO_SHOP_CATEGORIES.items():
            if key in short:
                all_shop_cats.update(cats)
                break
    if all_shop_cats:
        sales_text = extract_crosssell_for_categories(list(all_shop_cats))
        if sales_text:
            parts.append(f"## DANE SPRZEDAŻOWE (ostatnie 12 mies.)\n\n{sales_text}")

    # 6. Drafty z NotebookLM
    drafts = []
    for c in concepts:
        draft = extract_notebook_draft(c)
        if draft:
            drafts.append(draft)
    if drafts:
        parts.append("## DRAFTY Z NOTEBOOKLM (do przebudowy)\n\n" + "\n\n---\n\n".join(drafts))

    # 7. Reguly domenowe
    domain_rules = load_file(DOMAIN_RULES_FILE)
    if domain_rules:
        parts.append(f"## REGUŁY DOMENOWE\n\n{domain_rules}")

    return "\n\n" + "=" * 60 + "\n\n".join(parts)


# -------------------------------------------------------------------
# Wywolania API
# -------------------------------------------------------------------

@dataclass
class APIResult:
    model: str
    content: str
    input_tokens: int = 0
    output_tokens: int = 0
    duration_s: float = 0.0
    error: Optional[str] = None


def call_gemini(system_prompt: str, user_message: str, api_key: str,
                model: str = "gemini-3.1-pro-preview") -> APIResult:
    """Wywolanie Google Gemini API."""
    url = f"https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
    payload = {
        "contents": [
            {"role": "user", "parts": [{"text": user_message}]}
        ],
        "systemInstruction": {"parts": [{"text": system_prompt}]},
        "generationConfig": {
            "temperature": 0.4,
            "maxOutputTokens": 16384,
        },
    }
    t0 = time.time()
    try:
        resp = httpx.post(
            url, params={"key": api_key}, json=payload,
            timeout=300.0
        )
        resp.raise_for_status()
        data = resp.json()
        text = data["candidates"][0]["content"]["parts"][0]["text"]
        usage = data.get("usageMetadata", {})
        return APIResult(
            model=model,
            content=text,
            input_tokens=usage.get("promptTokenCount", 0),
            output_tokens=usage.get("candidatesTokenCount", 0),
            duration_s=time.time() - t0,
        )
    except Exception as e:
        return APIResult(model=model, content="", duration_s=time.time() - t0, error=str(e))


def call_claude(system_prompt: str, user_message: str, api_key: str,
                model: str = "claude-opus-4-6") -> APIResult:
    """Wywolanie Anthropic Claude API."""
    url = "https://api.anthropic.com/v1/messages"
    headers = {
        "x-api-key": api_key,
        "anthropic-version": "2023-06-01",
        "content-type": "application/json",
    }
    payload = {
        "model": model,
        "max_tokens": 16384,
        "temperature": 0.4,
        "system": system_prompt,
        "messages": [{"role": "user", "content": user_message}],
    }
    t0 = time.time()
    try:
        resp = httpx.post(url, headers=headers, json=payload, timeout=360.0)
        resp.raise_for_status()
        data = resp.json()
        text = data["content"][0]["text"]
        usage = data.get("usage", {})
        return APIResult(
            model=model,
            content=text,
            input_tokens=usage.get("input_tokens", 0),
            output_tokens=usage.get("output_tokens", 0),
            duration_s=time.time() - t0,
        )
    except Exception as e:
        return APIResult(model=model, content="", duration_s=time.time() - t0, error=str(e))


def call_openai(system_prompt: str, user_message: str, api_key: str,
                model: str = "gpt-5.2") -> APIResult:
    """Wywolanie OpenAI API."""
    url = "https://api.openai.com/v1/chat/completions"
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json",
    }
    payload = {
        "model": model,
        "temperature": 0.4,
        "max_completion_tokens": 16384,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_message},
        ],
    }
    t0 = time.time()
    try:
        resp = httpx.post(url, headers=headers, json=payload, timeout=180.0)
        resp.raise_for_status()
        data = resp.json()
        text = data["choices"][0]["message"]["content"]
        usage = data.get("usage", {})
        return APIResult(
            model=model,
            content=text,
            input_tokens=usage.get("prompt_tokens", 0),
            output_tokens=usage.get("completion_tokens", 0),
            duration_s=time.time() - t0,
        )
    except Exception as e:
        return APIResult(model=model, content="", duration_s=time.time() - t0, error=str(e))


def call_model(model_key: str, system_prompt: str, user_message: str) -> APIResult:
    """Dispatches to correct API based on model key."""
    cfg = MODELS[model_key]
    api_key = os.getenv(cfg["api_key_env"])
    if not api_key:
        return APIResult(model=cfg["model"], content="",
                         error=f"Brak klucza API: {cfg['api_key_env']}")

    if cfg["provider"] == "google":
        return call_gemini(system_prompt, user_message, api_key, cfg["model"])
    elif cfg["provider"] == "anthropic":
        return call_claude(system_prompt, user_message, api_key, cfg["model"])
    elif cfg["provider"] == "openai":
        return call_openai(system_prompt, user_message, api_key, cfg["model"])
    else:
        return APIResult(model=cfg["model"], content="", error=f"Unknown provider: {cfg['provider']}")


# -------------------------------------------------------------------
# Faza 1: Test porownawczy
# -------------------------------------------------------------------

def run_test_phase(model_filter: Optional[str] = None, output_dir: Optional[Path] = None):
    """Generuje 3 hasla testowe. Opcjonalnie filtruje po modelu."""
    target_dir = output_dir or TEST_DIR
    models_to_run = {model_filter: MODELS[model_filter]} if model_filter else MODELS

    print("=" * 60)
    print(f"FAZA 1: Test ({', '.join(models_to_run.keys())})")
    print("=" * 60)

    system_prompt = load_file(PROMPT_FILE)
    context = build_context_for_batch(TEST_CONCEPTS)
    concept_list = "\n".join(f"- {c}" for c in TEST_CONCEPTS)
    user_message = f"""Wygeneruj hasła encyklopedii dla następujących pojęć:

{concept_list}

{context}"""

    target_dir.mkdir(parents=True, exist_ok=True)
    results: list[dict] = []

    for model_key in models_to_run:
        print(f"\n>>> Generuję na modelu: {model_key} ({MODELS[model_key]['model']})")
        result = call_model(model_key, system_prompt, user_message)

        if result.error:
            print(f"  [ERROR] {result.error}")
        else:
            print(f"  OK: {result.input_tokens} in / {result.output_tokens} out / {result.duration_s:.1f}s")
            result.content = strip_markdown_wrapper(result.content)

        # zapisz output
        out_file = target_dir / f"{model_key}_test_3_concepts.md"
        header = f"# Test encyklopedii: {model_key} ({result.model})\n"
        header += f"# Input tokens: {result.input_tokens}, Output tokens: {result.output_tokens}\n"
        header += f"# Czas: {result.duration_s:.1f}s\n"
        if result.error:
            header += f"# ERROR: {result.error}\n"
        header += f"\n---\n\n"
        out_file.write_text(header + result.content, encoding="utf-8")
        print(f"  Zapisano: {out_file}")

        results.append({
            "model": model_key,
            "model_id": result.model,
            "input_tokens": result.input_tokens,
            "output_tokens": result.output_tokens,
            "duration_s": round(result.duration_s, 1),
            "error": result.error,
            "output_chars": len(result.content),
        })

        # rate limit
        time.sleep(3)

    # raport porownawczy
    report = f"# Test porownawczy: {', '.join(models_to_run.keys())}\n\n"
    report += "| Model | Model ID | Input tok | Output tok | Czas (s) | Output chars | Error |\n"
    report += "|-------|----------|-----------|------------|----------|-------------|-------|\n"
    for r in results:
        report += f"| {r['model']} | {r['model_id']} | {r['input_tokens']:,} | {r['output_tokens']:,} | {r['duration_s']} | {r['output_chars']:,} | {r['error'] or '-'} |\n"
    report += f"\n## Hasła testowe\n"
    for c in TEST_CONCEPTS:
        report += f"- {c}\n"
    report += f"\n## Pliki wyjściowe\n"
    for model_key in models_to_run:
        report += f"- `{model_key}_test_3_concepts.md`\n"

    report_file = target_dir / "test_comparison_report.md"
    report_file.write_text(report, encoding="utf-8")
    print(f"\nRaport: {report_file}")
    print("DONE.")


# -------------------------------------------------------------------
# Faza 2: Batch generation
# -------------------------------------------------------------------

def run_batch_phase(model_key: str, start_batch: int = 0,
                    end_batch: Optional[int] = None, round_name: Optional[str] = None):
    """Generuje hasla na wybranym modelu. end_batch jest inclusive, 0-indexed."""
    last_batch = end_batch if end_batch is not None else len(BATCHES) - 1
    batch_dir = OUTPUT_DIR / "batch"
    batch_dir.mkdir(parents=True, exist_ok=True)

    print("=" * 60)
    print(f"FAZA 2: Batch generation ({model_key})")
    print(f"Batche: {start_batch}-{last_batch} (0-indexed)")
    if round_name:
        print(f"Runda: {round_name}")
    print("=" * 60)

    system_prompt = load_file(PROMPT_FILE)
    report_entries: list[dict] = []
    all_content: list[str] = []
    total_input = 0
    total_output = 0
    total_concepts = 0

    for i, batch in enumerate(BATCHES):
        if i < start_batch or i > last_batch:
            continue

        batch_num = i + 1
        print(f"\n--- Batch {batch_num}/{len(BATCHES)} ({len(batch)} hasel) ---")
        for c in batch:
            print(f"    {c}")

        context = build_context_for_batch(batch)
        concept_list = "\n".join(f"- {c}" for c in batch)
        user_message = f"""Wygeneruj hasła encyklopedii dla następujących pojęć:

{concept_list}

{context}"""

        result = call_model(model_key, system_prompt, user_message)

        if result.error:
            print(f"  [ERROR] {result.error}")
            report_entries.append({
                "batch": batch_num,
                "concepts": len(batch),
                "input_tokens": 0,
                "output_tokens": 0,
                "duration_s": round(result.duration_s, 1),
                "chars": 0,
                "error": result.error,
            })
            continue

        print(f"  OK: {result.input_tokens} in / {result.output_tokens} out / {result.duration_s:.1f}s")
        result.content = strip_markdown_wrapper(result.content)
        total_input += result.input_tokens
        total_output += result.output_tokens
        total_concepts += len(batch)

        all_content.append(f"<!-- BATCH {batch_num} -->\n{result.content}")

        report_entries.append({
            "batch": batch_num,
            "concepts": len(batch),
            "input_tokens": result.input_tokens,
            "output_tokens": result.output_tokens,
            "duration_s": round(result.duration_s, 1),
            "chars": len(result.content),
            "error": None,
        })

        # rate limit
        time.sleep(5)

    # zapisz round file
    round_label = round_name or f"batch_{start_batch}-{last_batch}"
    round_file = batch_dir / f"{round_label}.md"
    round_file.write_text("\n\n" + ("\n\n" + "=" * 60 + "\n\n").join(all_content), encoding="utf-8")
    print(f"\nRound file: {round_file}")

    # raport
    total_chars = sum(r["chars"] for r in report_entries)
    avg_chars = total_chars // total_concepts if total_concepts > 0 else 0
    total_time = sum(r["duration_s"] for r in report_entries)

    report = f"# Raport rundy: {round_label}\n"
    report += f"# Model: {model_key} ({MODELS[model_key]['model']})\n"
    report += f"# Total input tokens: {total_input:,}\n"
    report += f"# Total output tokens: {total_output:,}\n"
    report += f"# Total chars: {total_chars:,}\n"
    report += f"# Avg chars/haslo: {avg_chars:,}\n"
    report += f"# Total time: {total_time:.1f}s\n"
    report += f"# Concepts: {total_concepts}\n\n"
    report += "| Batch | Concepts | Input tok | Output tok | Czas (s) | Chars | Error |\n"
    report += "|-------|----------|-----------|------------|----------|-------|-------|\n"
    for r in report_entries:
        report += f"| {r['batch']} | {r['concepts']} | {r['input_tokens']:,} | {r['output_tokens']:,} | {r['duration_s']} | {r['chars']:,} | {r['error'] or '-'} |\n"

    report_file = batch_dir / f"{round_label.split('_')[0]}_report.md"
    report_file.write_text(report, encoding="utf-8")
    print(f"Raport: {report_file}")
    print(f"Total: {total_input:,} in / {total_output:,} out / {total_chars:,} chars / {avg_chars:,} avg chars/haslo")
    print("DONE.")


# -------------------------------------------------------------------
# Main
# -------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(description="Generacja encyklopedii sprzetu nurkowego")
    parser.add_argument("--phase", choices=["test", "batch"], required=True,
                        help="test = 3 hasla x 3 modele, batch = pelna generacja")
    parser.add_argument("--model", choices=list(MODELS.keys()),
                        help="Model do batch (wymagany dla --phase batch)")
    parser.add_argument("--start-batch", type=int, default=0,
                        help="Nr batcha od ktorego zaczac (0-indexed, domyslnie 0)")
    parser.add_argument("--end-batch", type=int, default=None,
                        help="Nr batcha na ktorym zakonczyc (inclusive, 0-indexed)")
    parser.add_argument("--round-name", type=str, default=None,
                        help="Nazwa rundy (np. R1_oddychanie)")
    parser.add_argument("--test-dir", type=str, default=None,
                        help="Katalog wyjsciowy dla test phase (domyslnie test/)")
    args = parser.parse_args()

    if args.phase == "test":
        out_dir = Path(args.test_dir) if args.test_dir else None
        run_test_phase(model_filter=args.model, output_dir=out_dir)
    elif args.phase == "batch":
        if not args.model:
            parser.error("--model jest wymagany dla --phase batch")
        run_batch_phase(args.model, args.start_batch, args.end_batch, args.round_name)


if __name__ == "__main__":
    main()
