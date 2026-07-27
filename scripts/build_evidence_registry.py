#!/usr/bin/env python3
"""
TASK-ENC-011a: Completeness Gate + Evidence Builder.

Krok 1: Buduje kompletne mapowania (keywords, PAA, ekspert, crosssell) dla 106 hasel.
Krok 2: Buduje evidence registry per haslo.

Uzycie:
    python3 scripts/build_evidence_registry.py
"""

import csv
import json
import re
import sys
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DOCS = PROJECT_ROOT / "_docs"
DATA = PROJECT_ROOT / "data"
OUTPUT = DATA / "encyclopedia" / "v3" / "gen_v2"
MAPPINGS = OUTPUT / "mappings"
EVIDENCE = OUTPUT / "evidence"

CONCEPT_KEYS_FILE = DOCS / "FAZA1_concept_keys_v2.md"
KEYWORDS_FILE = DATA / "dataforseo" / "processed" / "all_keywords.csv"
PAA_FILE = DATA / "dataforseo" / "questions" / "atp_questions_all.csv"
CROSSSELL_FILE = DOCS / "dane_sprzedazowe_crosssell_12m.md"
BESTSELLERS_FILE = DOCS / "dane_sprzedazowe_bestsellery_12m.md"
EXPERT_FILE = DOCS / "wiedza_nurkowa" / "transkrypt_kwestionariusza_eksperta.md"

# -------------------------------------------------------------------
# KROK 0: Ekstrakcja listy 106 concept keys
# -------------------------------------------------------------------

def extract_concept_keys() -> list[dict]:
    """Parsuje FAZA1_concept_keys_v2.md i zwraca liste concept keys."""
    text = CONCEPT_KEYS_FILE.read_text(encoding="utf-8")
    concepts = []
    # Szukaj wierszy tabeli: | N | CONCEPT_KEY | nazwa PL | ...
    pattern = re.compile(
        r"^\|\s*(\d+b?)\s*\|\s*(\w+)\s*\|\s*(.+?)\s*\|",
        re.MULTILINE,
    )
    for m in pattern.finditer(text):
        num = m.group(1)
        key = m.group(2).strip()
        name_pl_raw = m.group(3).strip()
        # nazwa PL moze zawierac " / angielska" lub " (opis)"
        name_pl = name_pl_raw.split("/")[0].strip() if "/" in name_pl_raw else name_pl_raw.split("(")[0].strip()
        # angielska nazwa
        name_en = name_pl_raw.split("/")[1].strip() if "/" in name_pl_raw else ""
        concepts.append({
            "num": num,
            "key": key,
            "name_pl": name_pl,
            "name_en": name_en,
            "full_name": name_pl_raw,
        })
    return concepts


# -------------------------------------------------------------------
# KROK 1a-1e: Budowanie mapowań
# -------------------------------------------------------------------

# Synonimy/seed keywords per concept key — do matchowania w all_keywords.csv
CONCEPT_SEEDS: dict[str, list[str]] = {
    "AUTOMAT_ODDECHOWY": ["automat oddechowy", "automat nurkowy", "regulator nurkowy", "automaty oddechowe", "regulatory nurkowe"],
    "PIERWSZY_STOPIEN": ["pierwszy stopień", "i stopień automatu", "first stage"],
    "DRUGI_STOPIEN": ["drugi stopień", "ii stopień automatu", "second stage"],
    "OCTOPUS": ["octopus", "oktopus", "alternatywne źródło gazu"],
    "ZESTAW_AUTOMATU_REKR": ["zestaw automatu", "zestaw automatów rekreacyjny"],
    "ZESTAW_AUTOMATU_TWIN": ["zestaw automatów do twinsetu", "automat twinset"],
    "ZESTAW_AUTOMATU_STAGE": ["zestaw automatu do stage", "automat stage"],
    "ZESTAW_AUTOMATU_SM": ["zestaw automatów sidemount", "automat sidemount"],
    "WAZ_LP": ["wąż lp", "węże lp", "wąż do automatu", "węże do automatów"],
    "WAZ_HP": ["wąż hp", "węże hp", "wąż do manometru", "węże do manometr"],
    "REBREATHER": ["rebreather", "obieg zamknięty", "ccr", "scr"],
    "NITROX": ["nitrox", "eanx", "wzbogacone powietrze", "enriched air"],
    "ANALIZATOR_TLENU": ["analizator tlenu", "analizator tlenowy", "oxygen analyzer"],
    "ZESTAW_SERWISOWY": ["zestaw serwisowy", "serwis automatu", "service kit"],
    "MANOMETR": ["manometr", "spg", "ciśnieniomierz", "manometry"],
    "BUTLA_NURKOWA": ["butla nurkowa", "butla stalowa", "butla aluminiowa", "butle nurkowe", "cylinder nurkowy"],
    "BUTLA_STAGE": ["butla stage", "stage cylinder"],
    "BUTLA_ARGONU": ["butla argonu", "butla do argonu", "argon"],
    "TWINSET": ["twinset", "zestaw podwójny", "twin set", "podwójne butle"],
    "MANIFOLD": ["manifold", "mostek", "obejmy", "izolator manifoldu"],
    "ZAWOR_BUTLOWY": ["zawór butlowy", "zawory do butli", "zawór nurkowy"],
    "ZLACZE_DIN": ["złącze din", "din", "gwint din"],
    "ZLACZE_INT": ["złącze int", "yoke", "kabłąk"],
    "ADAPTER_DIN_INT": ["adapter din", "adapter int", "adapter din/int"],
    "JACKET": ["jacket", "kamizelka nurkowa", "bcd", "jacket nurkowy", "kamizelka wypornościowa"],
    "SKRZYDLO": ["skrzydło", "wing", "skrzydło nurkowe", "wing nurkowy"],
    "BACKPLATE": ["backplate", "płyta tylna", "stalowa płyta"],
    "UPRZAZ": ["uprząż", "harness", "uprzęże"],
    "ZESTAW_SKRZYDLO_SINGLE": ["skrzydło z uprzężą", "single tank wing", "skrzydło do pojedynczej"],
    "ZESTAW_SKRZYDLO_TWIN": ["skrzydło do twina", "twinset wing", "skrzydło twin"],
    "INFLATOR": ["inflator", "głowica inflatora", "inflator bcd"],
    "WAZ_INFLATORA": ["wąż inflatora", "węże do inflatorów", "inflator hose"],
    "WAZ_KARBOWANY": ["wąż karbowany", "corrugated hose"],
    "DUMP_VALVE_BCD": ["zawór upustowy bcd", "dump valve bcd", "zawór zrzutowy"],
    "SIDEMOUNT": ["sidemount", "side mount", "nurkowanie boczne"],
    "BALAST": ["balast", "ciężarki", "balast nurkowy", "ciężarki nurkowe"],
    "PAS_BALASTOWY": ["pas balastowy", "weight belt", "pas z ciężarkami"],
    "KIESZENIE_ZINTEGROWANE": ["kieszenie na balast", "kieszenie balastowe", "zintegrowany balast"],
    "TRYMOWKA": ["trymówka", "trim weight", "trym"],
    "SZELKI_STAGE": ["szelki stage", "rigging kit", "uprząż stage"],
    "KOMPUTER_NURKOWY": ["komputer nurkowy", "komputer do nurkowania", "dive computer"],
    "TRANSMITER": ["transmiter", "transmiter ciśnienia", "pressure transmitter", "nadajnik ciśnienia"],
    "KONSOLA": ["konsola nurkowa", "konsola", "dive console"],
    "KOMPAS": ["kompas nurkowy", "kompas", "dive compass"],
    "ZEGAREK_NURKOWY": ["zegarek nurkowy", "dive watch", "zegarek do nurkowania"],
    "TABLICZKA_PODWODNA": ["tabliczka", "tabliczka podwodna", "underwater slate"],
    "MASKA_JEDNOSZYBOWA": ["maska jednoszybowa", "maska do nurkowania", "maska nurkowa", "single lens mask"],
    "MASKA_DWUSZYBOWA": ["maska dwuszybowa", "maska do nurkowania", "dual lens mask"],
    "MASKA_PELNOTWARZOWA": ["maska pełnotwarzowa", "full face mask"],
    "MASKA_KOREKCYJNA": ["maska korekcyjna", "maska z korekcją", "szkła korekcyjne"],
    "MASKA_PANORAMICZNA": ["maska panoramiczna", "panoramic mask"],
    "MASKA_DLA_DZIECI": ["maska dla dzieci", "maska do nurkowania dla dzieci", "maska dziecięca"],
    "ZESTAW_MASKA_FAJKA": ["zestaw maska", "maska + fajka", "maska i fajka", "zestaw maska+fajka"],
    "FAJKA": ["fajka", "fajka nurkowa", "snorkel"],
    "PIANKA_MOKRA": ["pianka mokra", "pianka nurkowa", "pianka do nurkowania", "wetsuit"],
    "PIANKA_ZIMNE_WODY": ["pianka na zimne wody", "pianka zimna", "pianka 5mm", "pianka 7mm"],
    "PIANKA_CIEPLE_WODY": ["pianka na ciepłe wody", "pianka ciepła", "pianka 2mm", "pianka 3mm"],
    "PIANKA_SHORTY": ["pianka shorty", "shorty", "krótka pianka"],
    "KOMPLET_PIANEK": ["komplet pianek", "zestaw pianek"],
    "PIANKA_POLSUCHA": ["pianka półsucha", "semi-dry", "semidry"],
    "DOCIEPLACZ": ["docieplacz", "hooded vest", "kamizelka pod piankę"],
    "KAPTUR": ["kaptur nurkowy", "kaptur", "dive hood"],
    "SUCHY_SKAFANDER": ["suchy skafander", "drysuit", "skafander suchy"],
    "ZAWOR_SUCHEGO_SKAFANDRA": ["zawory suchego skafandra", "zawór suchego", "drysuit valve"],
    "DUMP_VALVE_DRYSUIT": ["zawór upustowy suchego", "dump valve drysuit", "dump valve suchy"],
    "WAZ_SUCHY_SKAFANDER": ["wąż do suchego", "węże do skafandrów", "drysuit hose"],
    "MANSZETY_SUCHEGO": ["manszety", "manszety suchego", "drysuit seals"],
    "SYSTEM_SUCHYCH_REKAWIC": ["system suchych rękawic", "rękawice suche", "pierścienie", "dry glove"],
    "BUTY_DO_SUCHEGO": ["buty do suchego", "drysuit boots"],
    "OGRZEWANIE_NURKOWE": ["ogrzewanie nurkowe", "ogrzewanie elektryczne", "dive heating"],
    "OCIEPLACZ": ["ocieplacz", "undersuit", "ocieplacz do suchego"],
    "ODZIEZ_TERMOAKTYWNA": ["odzież termoaktywna", "bielizna termoaktywna", "base layer"],
    "REKAWICE": ["rękawice nurkowe", "rękawice neoprenowe", "dive gloves"],
    "BUTY_NEOPRENOWE": ["buty nurkowe", "buty neoprenowe", "neoprene boots"],
    "PLETWY_PASKOWE": ["płetwy paskowe", "płetwy nurkowe", "open heel fins"],
    "PLETWY_KALOSZOWE": ["płetwy kaloszowe", "płetwy do snorkelingu", "full foot fins"],
    "PLETWY_JET": ["płetwy jet", "jet fins", "płetwy gumowe"],
    "SPREZYNY_DO_PLETW": ["sprężyny do płetw", "fin springs", "sprężyny"],
    "RASHGUARD": ["rashguard", "koszulka uv", "rash guard"],
    "BOJA_NURKOWA": ["boja nurkowa", "dsmb", "smb", "bojka", "bojka dekompresyjna"],
    "SZPULKA": ["szpulka", "finger spool", "spool"],
    "KOLOWROTEK": ["kołowrotek", "reel", "kołowrotek nurkowy"],
    "WOREK_PODNOSZACY": ["worek podnoszący", "lift bag"],
    "NOZ_NURKOWY": ["nóż nurkowy", "nóż do nurkowania", "dive knife"],
    "LINE_CUTTER": ["sekator", "line cutter", "nożyczki nurkowe"],
    "SWIATLO_CHEMICZNE": ["światło chemiczne", "cyalume", "lightstick"],
    "LATARKA_NURKOWA": ["latarka nurkowa", "latarka do nurkowania", "dive light"],
    "LAMPA_FOTO_VIDEO": ["lampa foto", "lampa video", "oświetlenie video"],
    "OBUDOWA_PODWODNA": ["obudowa podwodna", "housing", "obudowa do zdjęć"],
    "KAMERA_GOPRO": ["gopro", "kamera gopro", "kamera podwodna"],
    "KARABINEK": ["karabinek", "karabinek nurkowy", "dive clip", "karabińczyk"],
    "RETRACTOR": ["retractor", "retraktor"],
    "TORBA_NURKOWA": ["torba nurkowa", "torba do nurkowania", "dive bag"],
    "SKRZYNIA_NURKOWA": ["skrzynia transportowa", "skrzynia nurkowa", "transport case"],
    "ANTIFOG": ["antifog", "anti-fog", "środek na parowanie"],
    "PASEK_DO_MASKI": ["pasek do maski", "mask strap"],
    "SMAR_SILIKONOWY": ["smar silikonowy", "silicone grease"],
    "KLEJ_NEOPRENOWY": ["klej neoprenowy", "klej do pianki", "neoprene glue", "aquasure"],
    "O_RING": ["o-ring", "oring", "uszczelka"],
    "SUSZARKA_WIESZAK": ["suszarka", "wieszak do skafandra"],
    "ODZIEZ_NURKOWA": ["odzież nurkowa", "koszulka nurkowa", "bluza nurkowa"],
    "KSIAZKI_NURKOWE": ["książki nurkowe", "książka nurkowa"],
    "LOGBOOK": ["logbook", "logbook nurkowy", "dziennik nurka"],
    "MORSOWANIE": ["morsowanie", "sprzęt do morsowania", "ice swimming"],
    "ZESTAW_DO_NURKOWANIA": ["zestaw do nurkowania", "zestaw nurkowy", "dive set"],
}


# Mapowanie concept key → grupy PAA w atp_questions_all.csv
CONCEPT_TO_PAA: dict[str, list[str]] = {
    # Grupa A: Oddychanie
    "AUTOMAT_ODDECHOWY": ["automaty"],
    "PIERWSZY_STOPIEN": ["automaty"],
    "DRUGI_STOPIEN": ["automaty"],
    "OCTOPUS": ["automaty"],
    "ZESTAW_AUTOMATU_REKR": ["automaty"],
    "ZESTAW_AUTOMATU_TWIN": ["automaty"],
    "ZESTAW_AUTOMATU_STAGE": ["automaty"],
    "ZESTAW_AUTOMATU_SM": ["automaty", "sidemount"],
    "WAZ_LP": ["weze"],
    "WAZ_HP": ["weze"],
    "REBREATHER": ["automaty"],
    "NITROX": ["automaty"],
    "ANALIZATOR_TLENU": ["automaty"],
    "ZESTAW_SERWISOWY": ["automaty", "akcesoria"],
    "MANOMETR": ["kompasy_manometry"],
    # Grupa B: Butle i zawory
    "BUTLA_NURKOWA": ["butle"],
    "BUTLA_STAGE": ["butle"],
    "BUTLA_ARGONU": ["butle"],
    "TWINSET": ["butle"],
    "MANIFOLD": ["zawory"],
    "ZAWOR_BUTLOWY": ["zawory"],
    "ZLACZE_DIN": ["zawory"],
    "ZLACZE_INT": ["zawory"],
    "ADAPTER_DIN_INT": ["zawory"],
    # Grupa C: Kontrola plywalnisci
    "JACKET": ["bcd"],
    "SKRZYDLO": ["bcd"],
    "BACKPLATE": ["bcd"],
    "UPRZAZ": ["bcd"],
    "ZESTAW_SKRZYDLO_SINGLE": ["bcd"],
    "ZESTAW_SKRZYDLO_TWIN": ["bcd"],
    "INFLATOR": ["bcd"],
    "WAZ_INFLATORA": ["bcd"],
    "WAZ_KARBOWANY": ["bcd"],
    "DUMP_VALVE_BCD": ["bcd"],
    "SIDEMOUNT": ["sidemount", "bcd"],
    "BALAST": ["balast"],
    "PAS_BALASTOWY": ["balast"],
    "KIESZENIE_ZINTEGROWANE": ["balast"],
    "TRYMOWKA": ["balast"],
    "SZELKI_STAGE": ["bcd"],
    # Grupa D: Instrumenty
    "KOMPUTER_NURKOWY": ["komputery"],
    "TRANSMITER": ["komputery"],
    "KONSOLA": ["kompasy_manometry"],
    "KOMPAS": ["kompasy_manometry"],
    "ZEGAREK_NURKOWY": ["komputery"],
    "TABLICZKA_PODWODNA": ["komputery"],
    # Grupa E: Maski
    "MASKA_JEDNOSZYBOWA": ["maski"],
    "MASKA_DWUSZYBOWA": ["maski"],
    "MASKA_PELNOTWARZOWA": ["maski"],
    "MASKA_KOREKCYJNA": ["maski"],
    "MASKA_PANORAMICZNA": ["maski"],
    "MASKA_DLA_DZIECI": ["maski"],
    "ZESTAW_MASKA_FAJKA": ["maski"],
    "FAJKA": ["maski"],
    # Grupa F: Pianki
    "PIANKA_MOKRA": ["pianki", "ochrona_termiczna"],
    "PIANKA_ZIMNE_WODY": ["pianki", "ochrona_termiczna"],
    "PIANKA_CIEPLE_WODY": ["pianki", "ochrona_termiczna"],
    "PIANKA_SHORTY": ["pianki", "ochrona_termiczna"],
    "KOMPLET_PIANEK": ["pianki"],
    "PIANKA_POLSUCHA": ["pianki", "ochrona_termiczna"],
    "DOCIEPLACZ": ["pianki", "ochrona_termiczna"],
    "KAPTUR": ["pianki", "ochrona_termiczna"],
    # Grupa G: Suchy
    "SUCHY_SKAFANDER": ["ochrona_termiczna"],
    "ZAWOR_SUCHEGO_SKAFANDRA": ["ochrona_termiczna"],
    "DUMP_VALVE_DRYSUIT": ["ochrona_termiczna"],
    "WAZ_SUCHY_SKAFANDER": ["ochrona_termiczna"],
    "MANSZETY_SUCHEGO": ["ochrona_termiczna"],
    "SYSTEM_SUCHYCH_REKAWIC": ["ochrona_termiczna"],
    "BUTY_DO_SUCHEGO": ["ochrona_termiczna"],
    "OGRZEWANIE_NURKOWE": ["ogrzewanie", "ochrona_termiczna"],
    "OCIEPLACZ": ["ochrona_termiczna", "ogrzewanie"],
    "ODZIEZ_TERMOAKTYWNA": ["ochrona_termiczna"],
    "REKAWICE": ["ochrona_termiczna"],
    # Grupa H: Buty i pletwy
    "BUTY_NEOPRENOWE": ["ochrona_termiczna"],
    "PLETWY_PASKOWE": ["pletwy"],
    "PLETWY_KALOSZOWE": ["pletwy"],
    "PLETWY_JET": ["pletwy"],
    "SPREZYNY_DO_PLETW": ["pletwy"],
    "RASHGUARD": ["pianki"],
    # Grupa I: Bezpieczenstwo
    "BOJA_NURKOWA": ["bezpieczenstwo"],
    "SZPULKA": ["bezpieczenstwo"],
    "KOLOWROTEK": ["bezpieczenstwo"],
    "WOREK_PODNOSZACY": ["bezpieczenstwo"],
    "NOZ_NURKOWY": ["bezpieczenstwo"],
    "LINE_CUTTER": ["bezpieczenstwo"],
    "SWIATLO_CHEMICZNE": ["bezpieczenstwo"],
    # Grupa J: Oświetlenie + foto
    "LATARKA_NURKOWA": ["latarki"],
    "LAMPA_FOTO_VIDEO": ["latarki", "fotografia"],
    "OBUDOWA_PODWODNA": ["fotografia"],
    "KAMERA_GOPRO": ["fotografia"],
    # Grupa K: Klipsy, transport
    "KARABINEK": ["akcesoria"],
    "RETRACTOR": ["akcesoria"],
    "TORBA_NURKOWA": ["torby"],
    "SKRZYNIA_NURKOWA": ["torby"],
    # Grupa L: Konserwacja
    "ANTIFOG": ["akcesoria"],
    "PASEK_DO_MASKI": ["maski"],
    "SMAR_SILIKONOWY": ["akcesoria"],
    "KLEJ_NEOPRENOWY": ["akcesoria"],
    "O_RING": ["akcesoria"],
    "SUSZARKA_WIESZAK": ["akcesoria"],
    # Grupa M: Lifestyle
    "ODZIEZ_NURKOWA": ["ogolne"],
    "KSIAZKI_NURKOWE": ["ogolne"],
    "LOGBOOK": ["ogolne"],
    "MORSOWANIE": ["ogolne"],
    "ZESTAW_DO_NURKOWANIA": ["ogolne"],
}


# Mapowanie concept key → grupy eksperta
CONCEPT_TO_EXPERT: dict[str, list[str]] = {
    "AUTOMAT_ODDECHOWY": ["Grupa 1: Automaty oddechowe"],
    "PIERWSZY_STOPIEN": ["Grupa 1: Automaty oddechowe"],
    "DRUGI_STOPIEN": ["Grupa 1: Automaty oddechowe"],
    "OCTOPUS": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW_AUTOMATU_REKR": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW_AUTOMATU_TWIN": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW_AUTOMATU_STAGE": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW_AUTOMATU_SM": ["Grupa 1: Automaty oddechowe", "Grupa 21: Sidemount"],
    "WAZ_LP": ["Grupa 3: Weze"],
    "WAZ_HP": ["Grupa 3: Weze"],
    "REBREATHER": ["Grupa 1: Automaty oddechowe"],
    "NITROX": ["Grupa 1: Automaty oddechowe"],
    "ANALIZATOR_TLENU": ["Grupa 1: Automaty oddechowe"],
    "ZESTAW_SERWISOWY": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "MANOMETR": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "BUTLA_NURKOWA": ["Grupa 4: Butle"],
    "BUTLA_STAGE": ["Grupa 4: Butle"],
    "BUTLA_ARGONU": ["Grupa 4: Butle"],
    "TWINSET": ["Grupa 4: Butle"],
    "MANIFOLD": ["Grupa 7: Zawory i zlacza"],
    "ZAWOR_BUTLOWY": ["Grupa 7: Zawory i zlacza"],
    "ZLACZE_DIN": ["Grupa 7: Zawory i zlacza"],
    "ZLACZE_INT": ["Grupa 7: Zawory i zlacza"],
    "ADAPTER_DIN_INT": ["Grupa 7: Zawory i zlacza"],
    "JACKET": ["Grupa 2: BCD, jackety, skrzydla"],
    "SKRZYDLO": ["Grupa 2: BCD, jackety, skrzydla"],
    "BACKPLATE": ["Grupa 2: BCD, jackety, skrzydla"],
    "UPRZAZ": ["Grupa 2: BCD, jackety, skrzydla"],
    "ZESTAW_SKRZYDLO_SINGLE": ["Grupa 2: BCD, jackety, skrzydla"],
    "ZESTAW_SKRZYDLO_TWIN": ["Grupa 2: BCD, jackety, skrzydla"],
    "INFLATOR": ["Grupa 2: BCD, jackety, skrzydla"],
    "WAZ_INFLATORA": ["Grupa 2: BCD, jackety, skrzydla"],
    "WAZ_KARBOWANY": ["Grupa 2: BCD, jackety, skrzydla"],
    "DUMP_VALVE_BCD": ["Grupa 2: BCD, jackety, skrzydla"],
    "SIDEMOUNT": ["Grupa 2: BCD, jackety, skrzydla", "Grupa 21: Sidemount"],
    "BALAST": ["Grupa 6: Balast"],
    "PAS_BALASTOWY": ["Grupa 6: Balast"],
    "KIESZENIE_ZINTEGROWANE": ["Grupa 6: Balast"],
    "TRYMOWKA": ["Grupa 6: Balast"],
    "SZELKI_STAGE": ["Grupa 2: BCD, jackety, skrzydla"],
    "KOMPUTER_NURKOWY": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "TRANSMITER": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "KONSOLA": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "KOMPAS": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "ZEGAREK_NURKOWY": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "TABLICZKA_PODWODNA": ["Grupa 5: Komputery nurkowe i instrumenty"],
    "MASKA_JEDNOSZYBOWA": ["Grupa 9: Maski i fajki"],
    "MASKA_DWUSZYBOWA": ["Grupa 9: Maski i fajki"],
    "MASKA_PELNOTWARZOWA": ["Grupa 9: Maski i fajki"],
    "MASKA_KOREKCYJNA": ["Grupa 9: Maski i fajki"],
    "MASKA_PANORAMICZNA": ["Grupa 9: Maski i fajki"],
    "MASKA_DLA_DZIECI": ["Grupa 9: Maski i fajki"],
    "ZESTAW_MASKA_FAJKA": ["Grupa 9: Maski i fajki"],
    "FAJKA": ["Grupa 9: Maski i fajki"],
    "PIANKA_MOKRA": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA_ZIMNE_WODY": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA_CIEPLE_WODY": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA_SHORTY": ["Grupa 10: Pianki mokre i polsuche"],
    "KOMPLET_PIANEK": ["Grupa 10: Pianki mokre i polsuche"],
    "PIANKA_POLSUCHA": ["Grupa 10: Pianki mokre i polsuche"],
    "DOCIEPLACZ": ["Grupa 10: Pianki mokre i polsuche"],
    "KAPTUR": ["Grupa 10: Pianki mokre i polsuche", "Grupa 11: Rekawice, buty, kaptury"],
    "SUCHY_SKAFANDER": ["Grupa 8: Suche skafandry i akcesoria"],
    "ZAWOR_SUCHEGO_SKAFANDRA": ["Grupa 8: Suche skafandry i akcesoria"],
    "DUMP_VALVE_DRYSUIT": ["Grupa 8: Suche skafandry i akcesoria"],
    "WAZ_SUCHY_SKAFANDER": ["Grupa 8: Suche skafandry i akcesoria"],
    "MANSZETY_SUCHEGO": ["Grupa 8: Suche skafandry i akcesoria"],
    "SYSTEM_SUCHYCH_REKAWIC": ["Grupa 8: Suche skafandry i akcesoria"],
    "BUTY_DO_SUCHEGO": ["Grupa 8: Suche skafandry i akcesoria"],
    "OGRZEWANIE_NURKOWE": ["Grupa 8: Suche skafandry i akcesoria"],
    "OCIEPLACZ": ["Grupa 8: Suche skafandry i akcesoria"],
    "ODZIEZ_TERMOAKTYWNA": ["Grupa 8: Suche skafandry i akcesoria"],
    "REKAWICE": ["Grupa 11: Rekawice, buty, kaptury"],
    "BUTY_NEOPRENOWE": ["Grupa 11: Rekawice, buty, kaptury"],
    "PLETWY_PASKOWE": ["Grupa 12: Pletwy"],
    "PLETWY_KALOSZOWE": ["Grupa 12: Pletwy"],
    "PLETWY_JET": ["Grupa 12: Pletwy"],
    "SPREZYNY_DO_PLETW": ["Grupa 12: Pletwy"],
    "RASHGUARD": ["Grupa 10: Pianki mokre i polsuche"],
    "BOJA_NURKOWA": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "SZPULKA": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "KOLOWROTEK": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "WOREK_PODNOSZACY": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "NOZ_NURKOWY": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "LINE_CUTTER": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "SWIATLO_CHEMICZNE": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "LATARKA_NURKOWA": ["Grupa 14: Latarki"],
    "LAMPA_FOTO_VIDEO": ["Grupa 14: Latarki", "Grupa 16: Fotografia podwodna"],
    "OBUDOWA_PODWODNA": ["Grupa 16: Fotografia podwodna"],
    "KAMERA_GOPRO": ["Grupa 16: Fotografia podwodna"],
    "KARABINEK": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "RETRACTOR": ["Grupa 13: Bezpieczenstwo i sygnalizacja"],
    "TORBA_NURKOWA": ["Grupa 17: Torby i transport"],
    "SKRZYNIA_NURKOWA": ["Grupa 17: Torby i transport"],
    "ANTIFOG": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "PASEK_DO_MASKI": ["Grupa 9: Maski i fajki"],
    "SMAR_SILIKONOWY": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "KLEJ_NEOPRENOWY": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "O_RING": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "SUSZARKA_WIESZAK": ["Grupa 15: Konserwacja i akcesoria drobne"],
    "ODZIEZ_NURKOWA": ["Grupa 20: Lifestyle, morsowanie, odziez"],
    "KSIAZKI_NURKOWE": ["Grupa 19: Edukacja i ksiazki"],
    "LOGBOOK": ["Grupa 19: Edukacja i ksiazki"],
    "MORSOWANIE": ["Grupa 20: Lifestyle, morsowanie, odziez"],
    "ZESTAW_DO_NURKOWANIA": [],
}


# Mapowanie concept key → kategorie sklepowe (do matchowania crosssell/bestsellers)
CONCEPT_TO_SHOP: dict[str, list[str]] = {
    "AUTOMAT_ODDECHOWY": ["automaty", "zestawy", "regulator"],
    "PIERWSZY_STOPIEN": ["automaty", "akcesoria do automatów", "pierwszy stopień"],
    "DRUGI_STOPIEN": ["automaty", "akcesoria do automatów", "drugi stopień"],
    "OCTOPUS": ["automaty", "octopus", "akcesoria do automatów"],
    "ZESTAW_AUTOMATU_REKR": ["automaty", "zestawy"],
    "ZESTAW_AUTOMATU_TWIN": ["automaty", "zestawy", "twinset"],
    "ZESTAW_AUTOMATU_STAGE": ["automaty", "stage"],
    "ZESTAW_AUTOMATU_SM": ["automaty", "sidemount", "side mount"],
    "WAZ_LP": ["węże do automatów"],
    "WAZ_HP": ["węże do manometr"],
    "REBREATHER": ["rebreather"],
    "NITROX": ["nitrox"],
    "ANALIZATOR_TLENU": ["analizator"],
    "ZESTAW_SERWISOWY": ["akcesoria do automatów", "serwis"],
    "MANOMETR": ["manometr"],
    "BUTLA_NURKOWA": ["butl"],
    "BUTLA_STAGE": ["butl", "stage"],
    "BUTLA_ARGONU": ["butl", "argon"],
    "TWINSET": ["twinset", "manifold"],
    "MANIFOLD": ["manifold", "obejm"],
    "ZAWOR_BUTLOWY": ["zawor", "zawór", "butl"],
    "ZLACZE_DIN": ["din"],
    "ZLACZE_INT": ["int", "yoke"],
    "ADAPTER_DIN_INT": ["adapter"],
    "JACKET": ["jacket", "bcd", "kamizelk"],
    "SKRZYDLO": ["skrzydł", "wing"],
    "BACKPLATE": ["płyt", "uprzęż", "backplate"],
    "UPRZAZ": ["płyt", "uprzęż", "harness"],
    "ZESTAW_SKRZYDLO_SINGLE": ["skrzydł", "systemy balast"],
    "ZESTAW_SKRZYDLO_TWIN": ["skrzydł", "twinset"],
    "INFLATOR": ["inflator"],
    "WAZ_INFLATORA": ["węże do inflator"],
    "WAZ_KARBOWANY": ["karbowany", "inflator"],
    "DUMP_VALVE_BCD": ["upustow"],
    "SIDEMOUNT": ["side mount", "sidemount"],
    "BALAST": ["balast", "ciężar"],
    "PAS_BALASTOWY": ["balast", "pas"],
    "KIESZENIE_ZINTEGROWANE": ["systemy balast", "kieszen"],
    "TRYMOWKA": ["trym", "balast"],
    "SZELKI_STAGE": ["stage", "szelki", "uprząż"],
    "KOMPUTER_NURKOWY": ["komputer"],
    "TRANSMITER": ["transmit"],
    "KONSOLA": ["konsol"],
    "KOMPAS": ["kompas"],
    "ZEGAREK_NURKOWY": ["zegarek"],
    "TABLICZKA_PODWODNA": ["tabliczk"],
    "MASKA_JEDNOSZYBOWA": ["mask", "jednoszybowe"],
    "MASKA_DWUSZYBOWA": ["mask", "dwuszybowe"],
    "MASKA_PELNOTWARZOWA": ["mask", "pełnotwarzow"],
    "MASKA_KOREKCYJNA": ["korekcyj"],
    "MASKA_PANORAMICZNA": ["panoram"],
    "MASKA_DLA_DZIECI": ["mask"],
    "ZESTAW_MASKA_FAJKA": ["maska+fajka", "zestawy maska", "fajk"],
    "FAJKA": ["fajk"],
    "PIANKA_MOKRA": ["piank", "neopren"],
    "PIANKA_ZIMNE_WODY": ["zimne", "piank"],
    "PIANKA_CIEPLE_WODY": ["ciepłe", "piank"],
    "PIANKA_SHORTY": ["shorty"],
    "KOMPLET_PIANEK": ["piank"],
    "PIANKA_POLSUCHA": ["półsuch"],
    "DOCIEPLACZ": ["docieplacz"],
    "KAPTUR": ["kaptur"],
    "SUCHY_SKAFANDER": ["suchy", "skafandr", "drysuit"],
    "ZAWOR_SUCHEGO_SKAFANDRA": ["suchy", "zawor"],
    "DUMP_VALVE_DRYSUIT": ["suchy", "upustow", "dump"],
    "WAZ_SUCHY_SKAFANDER": ["suchy", "wąż", "skafandr"],
    "MANSZETY_SUCHEGO": ["manszet"],
    "SYSTEM_SUCHYCH_REKAWIC": ["rękawic", "pierścien", "suche rękawic"],
    "BUTY_DO_SUCHEGO": ["buty", "suchy"],
    "OGRZEWANIE_NURKOWE": ["ogrzewan", "grzew"],
    "OCIEPLACZ": ["ocieplacz", "ocieplacze do suchych"],
    "ODZIEZ_TERMOAKTYWNA": ["termoaktywn"],
    "REKAWICE": ["rękawic"],
    "BUTY_NEOPRENOWE": ["buty"],
    "PLETWY_PASKOWE": ["płetw", "paskow"],
    "PLETWY_KALOSZOWE": ["płetw", "kaloszow"],
    "PLETWY_JET": ["płetw", "jet", "gumow"],
    "SPREZYNY_DO_PLETW": ["sprężyn"],
    "RASHGUARD": ["rashguard"],
    "BOJA_NURKOWA": ["boj", "smb"],
    "SZPULKA": ["szpulk"],
    "KOLOWROTEK": ["kołowrot", "reel"],
    "WOREK_PODNOSZACY": ["worek"],
    "NOZ_NURKOWY": ["noż", "nóż"],
    "LINE_CUTTER": ["sekator", "cutter"],
    "SWIATLO_CHEMICZNE": ["światło", "cyalume", "sygnaliz"],
    "LATARKA_NURKOWA": ["latark", "oświetl"],
    "LAMPA_FOTO_VIDEO": ["lamp", "foto", "video"],
    "OBUDOWA_PODWODNA": ["obudow", "housing", "divevolk"],
    "KAMERA_GOPRO": ["gopro", "kamer"],
    "KARABINEK": ["karabink", "karabińcz"],
    "RETRACTOR": ["retractor", "retraktor"],
    "TORBA_NURKOWA": ["torb"],
    "SKRZYNIA_NURKOWA": ["skrzyn", "transport"],
    "ANTIFOG": ["antifog", "anti-fog", "parowanie"],
    "PASEK_DO_MASKI": ["pasek"],
    "SMAR_SILIKONOWY": ["smar", "silikon"],
    "KLEJ_NEOPRENOWY": ["klej", "aquasure"],
    "O_RING": ["o-ring", "oring"],
    "SUSZARKA_WIESZAK": ["suszark", "wieszak"],
    "ODZIEZ_NURKOWA": ["odzież"],
    "KSIAZKI_NURKOWE": ["książ"],
    "LOGBOOK": ["logbook"],
    "MORSOWANIE": ["morsow"],
    "ZESTAW_DO_NURKOWANIA": ["zestaw do nurkowania"],
}


# -------------------------------------------------------------------
# Ladowanie danych zrodlowych
# -------------------------------------------------------------------

def load_keywords() -> list[dict]:
    """Laduje all_keywords.csv."""
    rows = []
    with open(KEYWORDS_FILE, encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for i, row in enumerate(reader, start=2):
            row["_line"] = i
            rows.append(row)
    return rows


def load_paa() -> list[dict]:
    """Laduje atp_questions_all.csv."""
    rows = []
    with open(PAA_FILE, encoding="utf-8") as f:
        reader = csv.DictReader(f)
        for i, row in enumerate(reader, start=2):
            row["_line"] = i
            rows.append(row)
    return rows


def load_sales_lines(filepath: Path) -> list[dict]:
    """Laduje linie z pliku sprzedazowego (md) z numerami linii."""
    lines = []
    text = filepath.read_text(encoding="utf-8")
    for i, line in enumerate(text.split("\n"), start=1):
        if line.strip():
            lines.append({"text": line.strip(), "line": i, "file": filepath.name})
    return lines


def match_keywords(concept_key: str, all_kw: list[dict]) -> list[dict]:
    """Matchuje wiersze z all_keywords.csv po seedach."""
    seeds = CONCEPT_SEEDS.get(concept_key, [])
    if not seeds:
        return []
    matched = []
    for row in all_kw:
        kw = row.get("keyword", "").lower()
        for seed in seeds:
            if seed.lower() in kw:
                matched.append(row)
                break
    return matched


def match_paa(concept_key: str, all_paa: list[dict]) -> list[dict]:
    """Matchuje wiersze z atp_questions_all.csv po grupach PAA."""
    groups = CONCEPT_TO_PAA.get(concept_key, [])
    if not groups:
        return []
    matched = []
    for row in all_paa:
        if row.get("group", "") in groups:
            matched.append(row)
    return matched


def match_sales(concept_key: str, all_lines: list[dict]) -> list[dict]:
    """Matchuje linie z danych sprzedazowych po kategoriach sklepowych."""
    cats = CONCEPT_TO_SHOP.get(concept_key, [])
    if not cats:
        return []
    matched = []
    for item in all_lines:
        line_lower = item["text"].lower()
        for cat in cats:
            if cat.lower() in line_lower:
                matched.append(item)
                break
    return matched


# -------------------------------------------------------------------
# KROK 2: Evidence Builder
# -------------------------------------------------------------------

def build_evidence(concept_key: str, kw_matches: list[dict],
                   paa_matches: list[dict], sales_matches: list[dict]) -> dict:
    """Buduje evidence registry per haslo."""
    evidence = {}
    ev_k_idx = 1
    ev_p_idx = 1
    ev_a_idx = 1
    ev_s_idx = 1
    ev_b_idx = 1

    # Keywords (GSC)
    for row in kw_matches:
        eid = f"EV-K-{ev_k_idx:03d}"
        evidence[eid] = {
            "text": row.get("keyword", ""),
            "source": "GSC",
            "volume": int(row.get("search_volume", 0) or 0),
            "file": "all_keywords.csv",
            "line": row.get("_line", 0),
        }
        ev_k_idx += 1

    # PAA / autocomplete
    for row in paa_matches:
        source_type = row.get("source", "PAA")
        if source_type.lower() in ("paa", "people also ask"):
            eid = f"EV-P-{ev_p_idx:03d}"
            ev_p_idx += 1
            source_label = "PAA"
        else:
            eid = f"EV-A-{ev_a_idx:03d}"
            ev_a_idx += 1
            source_label = "autocomplete"
        evidence[eid] = {
            "text": row.get("question_or_phrase", ""),
            "source": source_label,
            "file": "atp_questions_all.csv",
            "line": row.get("_line", 0),
        }

    # Crosssell / bestsellers
    for item in sales_matches:
        if item["file"] == "dane_sprzedazowe_crosssell_12m.md":
            eid = f"EV-S-{ev_s_idx:03d}"
            ev_s_idx += 1
            source_label = "crosssell"
        else:
            eid = f"EV-B-{ev_b_idx:03d}"
            ev_b_idx += 1
            source_label = "bestsellers"
        evidence[eid] = {
            "text": item["text"],
            "source": source_label,
            "file": item["file"],
            "line": item["line"],
        }

    return evidence


# -------------------------------------------------------------------
# MAIN
# -------------------------------------------------------------------

def main():
    print("=== TASK-ENC-011a: Completeness Gate + Evidence Builder ===\n")

    # KROK 0: Ekstrakcja concept keys
    concepts = extract_concept_keys()
    print(f"Znaleziono {len(concepts)} concept keys\n")

    # Zapisz concept_list.json
    # Deduplikacja (ZESTAW_SERWISOWY jest w Grupie A i L)
    seen = set()
    deduped = []
    for c in concepts:
        if c["key"] not in seen:
            seen.add(c["key"])
            deduped.append(c)
    if len(deduped) < len(concepts):
        print(f"Usunięto {len(concepts) - len(deduped)} duplikatów")
        concepts = deduped

    concept_list = [c["key"] for c in concepts]
    (OUTPUT / "concept_list.json").write_text(
        json.dumps(concept_list, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    print(f"Zapisano: concept_list.json ({len(concept_list)} keys)")

    # Ladowanie danych zrodlowych
    all_kw = load_keywords()
    all_paa = load_paa()
    crosssell_lines = load_sales_lines(CROSSSELL_FILE)
    bestseller_lines = load_sales_lines(BESTSELLERS_FILE)
    all_sales = crosssell_lines + bestseller_lines
    print(f"Zaladowano: {len(all_kw)} keywords, {len(all_paa)} PAA, {len(all_sales)} sales lines\n")

    # KROK 1b-1e: Budowanie mapowan
    keywords_mapping = {}
    paa_mapping = {}
    expert_mapping = {}
    crosssell_mapping = {}

    warnings = []
    evidence_stats_by_source = {"GSC": 0, "PAA": 0, "autocomplete": 0, "crosssell": 0, "bestsellers": 0}
    total_evidence = 0
    counts_per_concept = {}
    fully_covered = 0

    for c in concepts:
        key = c["key"]

        # Keywords
        kw_matches = match_keywords(key, all_kw)
        keywords_mapping[key] = {
            "seeds": CONCEPT_SEEDS.get(key, []),
            "matched_rows": len(kw_matches),
        }

        # PAA
        paa_matches = match_paa(key, all_paa)
        paa_mapping[key] = {
            "groups": CONCEPT_TO_PAA.get(key, []),
            "matched_rows": len(paa_matches),
        }

        # Expert
        expert_groups = CONCEPT_TO_EXPERT.get(key, [])
        expert_mapping[key] = {
            "groups": expert_groups,
            "has_content": len(expert_groups) > 0,
        }

        # Crosssell
        sales_matches = match_sales(key, all_sales)
        shop_cats = CONCEPT_TO_SHOP.get(key, [])
        crosssell_mapping[key] = {
            "categories": shop_cats,
            "matched_lines": len(sales_matches),
        }

        # Walidacja kompletnosci
        missing = []
        if len(kw_matches) < 3:
            missing.append(f"keywords: {len(kw_matches)} rows")
        if len(paa_matches) < 2:
            missing.append(f"PAA: {len(paa_matches)} rows")
        if not expert_groups:
            missing.append("expert: 0 fragments")
        if len(sales_matches) < 1:
            missing.append("crosssell: 0 lines")

        is_fully_covered = len(missing) == 0
        if is_fully_covered:
            fully_covered += 1
        elif missing:
            # Czy blocking? = 0 we WSZYSTKICH zrodlach
            all_zero = (len(kw_matches) == 0 and len(paa_matches) == 0
                       and not expert_groups and len(sales_matches) == 0)
            warnings.append({
                "concept": key,
                "missing": missing,
                "blocking": all_zero,
            })

        # KROK 2: Evidence Builder
        evidence = build_evidence(key, kw_matches, paa_matches, sales_matches)

        # Zapisz evidence per haslo
        (EVIDENCE / f"{key}.json").write_text(
            json.dumps(evidence, indent=2, ensure_ascii=False), encoding="utf-8"
        )

        # Statystyki
        count = len(evidence)
        total_evidence += count
        counts_per_concept[key] = count
        for ev in evidence.values():
            src = ev["source"]
            if src in evidence_stats_by_source:
                evidence_stats_by_source[src] += 1

    # Zapisz mapowania
    (MAPPINGS / "keywords_mapping.json").write_text(
        json.dumps(keywords_mapping, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    (MAPPINGS / "paa_mapping.json").write_text(
        json.dumps(paa_mapping, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    (MAPPINGS / "expert_mapping.json").write_text(
        json.dumps(expert_mapping, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    (MAPPINGS / "crosssell_mapping.json").write_text(
        json.dumps(crosssell_mapping, indent=2, ensure_ascii=False), encoding="utf-8"
    )
    print("Zapisano mapowania: keywords, paa, expert, crosssell")

    # Completeness report
    blocked = [w for w in warnings if w["blocking"]]
    non_blocked = [w for w in warnings if not w["blocking"]]
    report = {
        "total_concepts": len(concepts),
        "fully_covered": fully_covered,
        "warnings": non_blocked,
        "blocked": blocked,
        "summary": f"{fully_covered} full, {len(non_blocked)} warnings, {len(blocked)} blocked",
    }
    (OUTPUT / "completeness_report.json").write_text(
        json.dumps(report, indent=2, ensure_ascii=False), encoding="utf-8"
    )

    # Evidence stats
    min_concept = min(counts_per_concept, key=counts_per_concept.get)
    max_concept = max(counts_per_concept, key=counts_per_concept.get)
    avg = total_evidence / len(concepts) if concepts else 0
    stats = {
        "total_evidence_ids": total_evidence,
        "avg_per_concept": round(avg, 1),
        "min": {"concept": min_concept, "count": counts_per_concept[min_concept]},
        "max": {"concept": max_concept, "count": counts_per_concept[max_concept]},
        "by_source": evidence_stats_by_source,
    }
    (OUTPUT / "evidence_stats.json").write_text(
        json.dumps(stats, indent=2, ensure_ascii=False), encoding="utf-8"
    )

    # Podsumowanie
    print(f"\n{'='*60}")
    print(f"COMPLETENESS REPORT")
    print(f"{'='*60}")
    print(f"Total concepts: {len(concepts)}")
    print(f"Fully covered:  {fully_covered}")
    print(f"Warnings:       {len(non_blocked)}")
    print(f"Blocked:        {len(blocked)}")

    if blocked:
        print(f"\n!!! ABORT: {len(blocked)} concepts blocked (0 w WSZYSTKICH zrodlach) !!!")
        for b in blocked:
            print(f"  - {b['concept']}: {b['missing']}")
        sys.exit(1)

    if non_blocked:
        print(f"\nWarnings ({len(non_blocked)}):")
        for w in non_blocked:
            print(f"  - {w['concept']}: {', '.join(w['missing'])}")

    print(f"\n{'='*60}")
    print(f"EVIDENCE STATS")
    print(f"{'='*60}")
    print(f"Total evidence IDs: {total_evidence}")
    print(f"Avg per concept:    {avg:.1f}")
    print(f"Min: {min_concept} ({counts_per_concept[min_concept]})")
    print(f"Max: {max_concept} ({counts_per_concept[max_concept]})")
    print(f"By source: {evidence_stats_by_source}")
    print(f"\nGotowe. Zapisano {len(concepts)} evidence files.")


if __name__ == "__main__":
    main()
