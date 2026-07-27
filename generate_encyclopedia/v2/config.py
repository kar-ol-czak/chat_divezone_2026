"""
Konfiguracja ścieżek i stałych dla pipeline'u encyklopedii v2.
"""

from pathlib import Path

# --- Ścieżki bazowe ---
PROJECT_ROOT = Path(__file__).resolve().parent.parent.parent
GENERATE_ROOT = Path(__file__).resolve().parent

# --- Pliki wejściowe ---
RAW_V1_DIR = PROJECT_ROOT / "data" / "encyclopedia" / "raw"
CONCEPT_KEYS_FILE = PROJECT_ROOT / "_docs" / "FAZA1_concept_keys_v2.md"
BRAND_MAP_FILE = PROJECT_ROOT / "_docs" / "11_mapa_marek-reviewed.md"

# DataForSEO
DATAFORSEO_CSV = PROJECT_ROOT / "data" / "dataforseo" / "processed" / "all_keywords.csv"

# Luigi's Box
LUIGISBOX_DIR = PROJECT_ROOT / "_docs" / "dane_zewnetrzne_wyszukiwania"
LUIGISBOX_ALL = LUIGISBOX_DIR / "luigisbox_416288_trending_searches-all_1771832038.csv"
LUIGISBOX_SEARCH = LUIGISBOX_DIR / "luigisbox_416288_trending_searches-search_results_1771832038.csv"

# Google Search Console
GSC_DIR = LUIGISBOX_DIR / "GSC-Performance-on-Search-2026-02-23"
GSC_QUERIES = GSC_DIR / "Zapytania.csv"

# --- Pliki wyjściowe ---
OUTPUT_DIR = GENERATE_ROOT / "output" / "skeletons"
REPORT_FILE = GENERATE_ROOT / "output" / "completeness_report.json"

# --- Mapowanie v1 concept_key → v2 concept_key ---
# Większość: v1_key.upper() == v2_key
# Wyjątki (v1 inna nazwa niż v2):
V1_TO_V2_KEY_MAP: dict[str, str] = {
    "waz_do_automatu": "WAZ_LP",
    "boja_dekompresyjna": "BOJA_NURKOWA",
    "buty_nurkowe": "BUTY_NEOPRENOWE",
}

# --- Whitelist akronimów: zostają w exact przy detekcji ukrytych anglicyzmów ---
# Dotyczy TYLKO implicit detection (PL exact_synonym bez diakrytyków).
# Jawnie otagowane type=anglicyzm w v1 ZAWSZE idą do anglicyzmów.
ACRONYM_WHITELIST = {
    "KRW",  # polski akronim (kamizelka ratowniczo-wyrównawcza)
    "krw",
}

# --- Mapowanie typów synonimów v1 → bucket v2 ---
# v1 types: exact_synonym, near_synonym, colloquial, anglicyzm, legacy_name
SYNONYM_TYPE_MAP: dict[str, str] = {
    "exact_synonym": "exact",
    "near_synonym": "near",
    "colloquial": "potoczne",
    "anglicyzm": "anglicyzmy",
    "legacy_name": "archaiczne",
    "bledne_ale_popularne": "bledne_ale_popularne",
    "archaic": "archaiczne",
}

# --- Relacje v1 które mapują się na powiazane_produkty ---
RELATION_TYPES_POWIAZANE = {"czesc_zestawu", "wariant"}

# --- Master lista 106 concept keys v2.3 ---
# Parsowana z FAZA1_concept_keys_v2.md, ale dla szybkości hardkodowana tutaj
MASTER_CONCEPTS: dict[str, dict[str, str | None]] = {
    # GRUPA A: Oddychanie
    "AUTOMAT_ODDECHOWY": {"nazwa_pl": "automat oddechowy / regulator", "nazwa_en": "regulator", "grupa": "A"},
    "PIERWSZY_STOPIEN": {"nazwa_pl": "pierwszy stopień automatu", "nazwa_en": "first stage regulator", "grupa": "A"},
    "DRUGI_STOPIEN": {"nazwa_pl": "drugi stopień automatu", "nazwa_en": "second stage regulator", "grupa": "A"},
    "OCTOPUS": {"nazwa_pl": "octopus (alternatywne źródło gazu)", "nazwa_en": "octopus regulator", "grupa": "A"},
    "ZESTAW_AUTOMATU_REKR": {"nazwa_pl": "zestaw automatu rekreacyjny", "nazwa_en": "recreational regulator set", "grupa": "A"},
    "ZESTAW_AUTOMATU_TWIN": {"nazwa_pl": "zestaw automatu do twinsetu", "nazwa_en": "twinset regulator set", "grupa": "A"},
    "ZESTAW_AUTOMATU_STAGE": {"nazwa_pl": "zestaw automatu do stage", "nazwa_en": "stage regulator set", "grupa": "A"},
    "ZESTAW_AUTOMATU_SM": {"nazwa_pl": "zestaw automatu sidemount", "nazwa_en": "sidemount regulator set", "grupa": "A"},
    "WAZ_LP": {"nazwa_pl": "wąż LP (do automatów)", "nazwa_en": "LP hose", "grupa": "A"},
    "WAZ_HP": {"nazwa_pl": "wąż HP (do manometrów)", "nazwa_en": "HP hose", "grupa": "A"},
    "REBREATHER": {"nazwa_pl": "rebreather", "nazwa_en": "rebreather", "grupa": "A"},
    "NITROX": {"nazwa_pl": "nitrox (EANx)", "nazwa_en": "nitrox (EANx)", "grupa": "A"},
    "ANALIZATOR_TLENU": {"nazwa_pl": "analizator tlenu", "nazwa_en": "oxygen analyzer", "grupa": "A"},
    "ZESTAW_SERWISOWY": {"nazwa_pl": "zestaw serwisowy automatu", "nazwa_en": "regulator service kit", "grupa": "A"},
    "MANOMETR": {"nazwa_pl": "manometr / SPG", "nazwa_en": "SPG / submersible pressure gauge", "grupa": "A"},
    # GRUPA B: Butle i zawory
    "BUTLA_NURKOWA": {"nazwa_pl": "butla nurkowa (stalowa / aluminiowa)", "nazwa_en": "scuba tank / cylinder", "grupa": "B"},
    "BUTLA_STAGE": {"nazwa_pl": "butla stage", "nazwa_en": "stage cylinder", "grupa": "B"},
    "BUTLA_ARGONU": {"nazwa_pl": "butla do argonu", "nazwa_en": "argon cylinder", "grupa": "B"},
    "TWINSET": {"nazwa_pl": "twinset (zestaw podwójny)", "nazwa_en": "twinset / doubles", "grupa": "B"},
    "MANIFOLD": {"nazwa_pl": "manifold (mostek) / obejmy", "nazwa_en": "manifold / isolation valve", "grupa": "B"},
    "ZAWOR_BUTLOWY": {"nazwa_pl": "zawór butlowy", "nazwa_en": "cylinder valve", "grupa": "B"},
    "ZLACZE_DIN": {"nazwa_pl": "złącze DIN (jedyny aktualny standard)", "nazwa_en": "DIN fitting", "grupa": "B"},
    "ZLACZE_INT": {"nazwa_pl": "złącze INT / yoke (archaiczne, martwy standard)", "nazwa_en": "INT / yoke fitting", "grupa": "B"},
    "ADAPTER_DIN_INT": {"nazwa_pl": "adapter DIN/INT", "nazwa_en": "DIN/INT adapter", "grupa": "B"},
    # GRUPA C: Kontrola pływalności
    "JACKET": {"nazwa_pl": "jacket (BCD)", "nazwa_en": "jacket BCD", "grupa": "C"},
    "SKRZYDLO": {"nazwa_pl": "skrzydło / wing (worek BCD)", "nazwa_en": "wing BCD", "grupa": "C"},
    "BACKPLATE": {"nazwa_pl": "backplate (płyta tylna)", "nazwa_en": "backplate", "grupa": "C"},
    "UPRZAZ": {"nazwa_pl": "uprząż / harness", "nazwa_en": "harness", "grupa": "C"},
    "ZESTAW_SKRZYDLO_SINGLE": {"nazwa_pl": "skrzydło z uprzężą do poj. butli", "nazwa_en": "single tank wing set", "grupa": "C"},
    "ZESTAW_SKRZYDLO_TWIN": {"nazwa_pl": "skrzydło z uprzężą do twina", "nazwa_en": "twinset wing set", "grupa": "C"},
    "INFLATOR": {"nazwa_pl": "inflator BCD (głowica)", "nazwa_en": "BCD inflator / power inflator", "grupa": "C"},
    "WAZ_INFLATORA": {"nazwa_pl": "wąż inflatora BCD (LP z QD)", "nazwa_en": "BCD inflator hose", "grupa": "C"},
    "WAZ_KARBOWANY": {"nazwa_pl": "wąż karbowany (BCD ↔ inflator)", "nazwa_en": "corrugated hose", "grupa": "C"},
    "DUMP_VALVE_BCD": {"nazwa_pl": "zawór upustowy BCD (ręczny)", "nazwa_en": "BCD dump valve", "grupa": "C"},
    "SIDEMOUNT": {"nazwa_pl": "sidemount", "nazwa_en": "sidemount diving", "grupa": "C"},
    "BALAST": {"nazwa_pl": "balast / ciężarki", "nazwa_en": "ballast / dive weights", "grupa": "C"},
    "PAS_BALASTOWY": {"nazwa_pl": "pas balastowy", "nazwa_en": "weight belt", "grupa": "C"},
    "KIESZENIE_ZINTEGROWANE": {"nazwa_pl": "kieszenie na balast (zintegrowane)", "nazwa_en": "integrated weight pockets", "grupa": "C"},
    "TRYMOWKA": {"nazwa_pl": "trymówka (trim weight)", "nazwa_en": "trim weight", "grupa": "C"},
    "SZELKI_STAGE": {"nazwa_pl": "szelki stage / rigging kit", "nazwa_en": "stage rigging kit", "grupa": "C"},
    # GRUPA D: Instrumenty i nawigacja
    "KOMPUTER_NURKOWY": {"nazwa_pl": "komputer nurkowy", "nazwa_en": "dive computer", "grupa": "D"},
    "TRANSMITER": {"nazwa_pl": "transmiter ciśnienia (AI)", "nazwa_en": "air-integrated transmitter", "grupa": "D"},
    "KONSOLA": {"nazwa_pl": "konsola nurkowa", "nazwa_en": "dive console", "grupa": "D"},
    "KOMPAS": {"nazwa_pl": "kompas nurkowy", "nazwa_en": "dive compass", "grupa": "D"},
    "ZEGAREK_NURKOWY": {"nazwa_pl": "zegarek nurkowy", "nazwa_en": "dive watch", "grupa": "D"},
    "TABLICZKA_PODWODNA": {"nazwa_pl": "tabliczka do pisania", "nazwa_en": "underwater slate", "grupa": "D"},
    # GRUPA E: Maski i fajki
    "MASKA_JEDNOSZYBOWA": {"nazwa_pl": "maska jednoszybowa", "nazwa_en": "single lens mask", "grupa": "E"},
    "MASKA_DWUSZYBOWA": {"nazwa_pl": "maska dwuszybowa", "nazwa_en": "dual lens mask", "grupa": "E"},
    "MASKA_PELNOTWARZOWA": {"nazwa_pl": "maska pełnotwarzowa", "nazwa_en": "full face mask", "grupa": "E"},
    "MASKA_KOREKCYJNA": {"nazwa_pl": "maska korekcyjna / z korekcją", "nazwa_en": "prescription dive mask", "grupa": "E"},
    "MASKA_PANORAMICZNA": {"nazwa_pl": "maska panoramiczna", "nazwa_en": "panoramic mask", "grupa": "E"},
    "MASKA_DLA_DZIECI": {"nazwa_pl": "maska do nurkowania dla dzieci", "nazwa_en": "kids dive mask", "grupa": "E"},
    "ZESTAW_MASKA_FAJKA": {"nazwa_pl": "zestaw maska + fajka", "nazwa_en": "mask and snorkel set", "grupa": "E"},
    "FAJKA": {"nazwa_pl": "fajka nurkowa", "nazwa_en": "snorkel", "grupa": "E"},
    # GRUPA F: Ochrona termiczna — mokra
    "PIANKA_MOKRA": {"nazwa_pl": "pianka mokra (pojęcie ogólne)", "nazwa_en": "wetsuit", "grupa": "F"},
    "PIANKA_ZIMNE_WODY": {"nazwa_pl": "pianka na zimne wody (5-7mm + docieplacz)", "nazwa_en": "cold water wetsuit", "grupa": "F"},
    "PIANKA_CIEPLE_WODY": {"nazwa_pl": "pianka na ciepłe wody (2-3mm)", "nazwa_en": "warm water wetsuit", "grupa": "F"},
    "PIANKA_SHORTY": {"nazwa_pl": "pianka shorty", "nazwa_en": "shorty wetsuit", "grupa": "F"},
    "KOMPLET_PIANEK": {"nazwa_pl": "komplet pianek do nurkowania", "nazwa_en": "wetsuit set", "grupa": "F"},
    "PIANKA_POLSUCHA": {"nazwa_pl": "pianka półsucha", "nazwa_en": "semi-dry suit", "grupa": "F"},
    "DOCIEPLACZ": {"nazwa_pl": "docieplacz (kamizelka pod piankę mokrą)", "nazwa_en": "hooded vest", "grupa": "F"},
    "KAPTUR": {"nazwa_pl": "kaptur nurkowy", "nazwa_en": "dive hood", "grupa": "F"},
    # GRUPA G: Ochrona termiczna — sucha
    "SUCHY_SKAFANDER": {"nazwa_pl": "suchy skafander (trylaminat / neopren)", "nazwa_en": "drysuit", "grupa": "G"},
    "ZAWOR_SUCHEGO_SKAFANDRA": {"nazwa_pl": "zawory suchego skafandra", "nazwa_en": "drysuit valves", "grupa": "G"},
    "DUMP_VALVE_DRYSUIT": {"nazwa_pl": "zawór upustowy suchego skafandra (sprężynowy)", "nazwa_en": "drysuit dump valve", "grupa": "G"},
    "WAZ_SUCHY_SKAFANDER": {"nazwa_pl": "wąż do suchego skafandra", "nazwa_en": "drysuit inflator hose", "grupa": "G"},
    "MANSZETY_SUCHEGO": {"nazwa_pl": "manszety do suchego skafandra", "nazwa_en": "drysuit seals", "grupa": "G"},
    "SYSTEM_SUCHYCH_REKAWIC": {"nazwa_pl": "system suchych rękawic / pierścienie", "nazwa_en": "dry glove system", "grupa": "G"},
    "BUTY_DO_SUCHEGO": {"nazwa_pl": "buty do suchego skafandra", "nazwa_en": "drysuit boots", "grupa": "G"},
    "OGRZEWANIE_NURKOWE": {"nazwa_pl": "ogrzewanie nurkowe (elektryczne)", "nazwa_en": "dive heating system", "grupa": "G"},
    "OCIEPLACZ": {"nazwa_pl": "ocieplacz / undersuit (do suchego)", "nazwa_en": "undersuit", "grupa": "G"},
    "ODZIEZ_TERMOAKTYWNA": {"nazwa_pl": "odzież termoaktywna (pod ocieplacz)", "nazwa_en": "thermal base layer", "grupa": "G"},
    "REKAWICE": {"nazwa_pl": "rękawice nurkowe (neoprenowe)", "nazwa_en": "dive gloves", "grupa": "G"},
    # GRUPA H: Obuwie i płetwy
    "BUTY_NEOPRENOWE": {"nazwa_pl": "buty nurkowe neoprenowe", "nazwa_en": "neoprene dive boots", "grupa": "H"},
    "PLETWY_PASKOWE": {"nazwa_pl": "płetwy paskowe (na buta)", "nazwa_en": "open heel fins", "grupa": "H"},
    "PLETWY_KALOSZOWE": {"nazwa_pl": "płetwy kaloszowe (na stopę)", "nazwa_en": "full foot fins", "grupa": "H"},
    "PLETWY_JET": {"nazwa_pl": "płetwy jet fins / płetwy gumowe", "nazwa_en": "jet fins", "grupa": "H"},
    "SPREZYNY_DO_PLETW": {"nazwa_pl": "sprężyny do płetw", "nazwa_en": "fin spring straps", "grupa": "H"},
    "RASHGUARD": {"nazwa_pl": "rashguard (koszulka UV)", "nazwa_en": "rashguard", "grupa": "H"},
    # GRUPA I: Bezpieczeństwo i sygnalizacja
    "BOJA_NURKOWA": {"nazwa_pl": "boja nurkowa / DSMB / SMB", "nazwa_en": "DSMB / surface marker buoy", "grupa": "I"},
    "SZPULKA": {"nazwa_pl": "szpulka / finger spool", "nazwa_en": "finger spool", "grupa": "I"},
    "KOLOWROTEK": {"nazwa_pl": "kołowrotek / reel", "nazwa_en": "reel", "grupa": "I"},
    "WOREK_PODNOSZACY": {"nazwa_pl": "worek podnoszący / lift bag", "nazwa_en": "lift bag", "grupa": "I"},
    "NOZ_NURKOWY": {"nazwa_pl": "nóż nurkowy", "nazwa_en": "dive knife", "grupa": "I"},
    "LINE_CUTTER": {"nazwa_pl": "sekator / line cutter", "nazwa_en": "line cutter", "grupa": "I"},
    "SWIATLO_CHEMICZNE": {"nazwa_pl": "światło chemiczne / cyalume", "nazwa_en": "glow stick / cyalume", "grupa": "I"},
    # GRUPA J: Oświetlenie i foto/video
    "LATARKA_NURKOWA": {"nazwa_pl": "latarka nurkowa", "nazwa_en": "dive light", "grupa": "J"},
    "LAMPA_FOTO_VIDEO": {"nazwa_pl": "lampa foto/video", "nazwa_en": "underwater video light", "grupa": "J"},
    "OBUDOWA_PODWODNA": {"nazwa_pl": "obudowa podwodna / housing", "nazwa_en": "underwater housing", "grupa": "J"},
    "KAMERA_GOPRO": {"nazwa_pl": "kamera GoPro (podwodna)", "nazwa_en": "GoPro camera", "grupa": "J"},
    # GRUPA K: Klipsy, mocowania, transport
    "KARABINEK": {"nazwa_pl": "karabinek nurkowy", "nazwa_en": "dive bolt snap", "grupa": "K"},
    "RETRACTOR": {"nazwa_pl": "retractor", "nazwa_en": "retractor", "grupa": "K"},
    "TORBA_NURKOWA": {"nazwa_pl": "torba nurkowa", "nazwa_en": "dive bag", "grupa": "K"},
    "SKRZYNIA_NURKOWA": {"nazwa_pl": "skrzynia transportowa", "nazwa_en": "dive equipment case", "grupa": "K"},
    # GRUPA L: Konserwacja i akcesoria
    "ANTIFOG": {"nazwa_pl": "antifog (środek na parowanie)", "nazwa_en": "antifog", "grupa": "L"},
    "PASEK_DO_MASKI": {"nazwa_pl": "pasek do maski", "nazwa_en": "mask strap", "grupa": "L"},
    "SMAR_SILIKONOWY": {"nazwa_pl": "smar silikonowy", "nazwa_en": "silicone grease", "grupa": "L"},
    "KLEJ_NEOPRENOWY": {"nazwa_pl": "klej neoprenowy", "nazwa_en": "neoprene glue", "grupa": "L"},
    "O_RING": {"nazwa_pl": "o-ring (uszczelka)", "nazwa_en": "o-ring", "grupa": "L"},
    "SUSZARKA_WIESZAK": {"nazwa_pl": "suszarka / wieszak do skafandra", "nazwa_en": "wetsuit hanger / dryer", "grupa": "L"},
    # ZESTAW_SERWISOWY jest już w GRUPA A (nr 100 w master liście to duplikat — nr 14)
    # GRUPA M: Lifestyle, edukacja, inne
    "ODZIEZ_NURKOWA": {"nazwa_pl": "odzież nurkowa (koszulki, bluzy, czapki)", "nazwa_en": "dive apparel", "grupa": "M"},
    "KSIAZKI_NURKOWE": {"nazwa_pl": "książki nurkowe", "nazwa_en": "dive books", "grupa": "M"},
    "LOGBOOK": {"nazwa_pl": "logbook nurkowy", "nazwa_en": "dive logbook", "grupa": "M"},
    "MORSOWANIE": {"nazwa_pl": "sprzęt do morsowania", "nazwa_en": "ice swimming gear", "grupa": "M"},
    "ZESTAW_DO_NURKOWANIA": {"nazwa_pl": "zestaw do nurkowania (bundle)", "nazwa_en": "dive set / bundle", "grupa": "M"},
}

# --- Mapowanie kategorii marek → concept keys ---
# Klucze = nagłówki z 11_mapa_marek-reviewed.md sekcji "Rekomendowane marki wg kategorii"
BRAND_CATEGORY_TO_CONCEPTS: dict[str, list[str]] = {
    "Automaty oddechowe": [
        "AUTOMAT_ODDECHOWY", "PIERWSZY_STOPIEN", "DRUGI_STOPIEN", "OCTOPUS",
        "ZESTAW_AUTOMATU_REKR", "ZESTAW_AUTOMATU_TWIN", "ZESTAW_AUTOMATU_STAGE",
        "ZESTAW_AUTOMATU_SM", "ZESTAW_SERWISOWY",
        "WAZ_LP", "WAZ_HP",  # węże do automatów/manometrów — te same marki
        "REBREATHER",         # marki pokrywają się z automatami
    ],
    "Fotografia i Video": [
        "OBUDOWA_PODWODNA", "KAMERA_GOPRO", "LAMPA_FOTO_VIDEO",
    ],
    "Instrumenty pomiarowe": [
        "MANOMETR", "KONSOLA", "KOMPAS", "ANALIZATOR_TLENU",
    ],
    "Komputery nurkowe": [
        "KOMPUTER_NURKOWY", "TRANSMITER", "ZEGAREK_NURKOWY",
    ],
    "Latarki nurkowe": [
        "LATARKA_NURKOWA",
    ],
    "Maski i Fajki": [
        "MASKA_JEDNOSZYBOWA", "MASKA_DWUSZYBOWA", "MASKA_PELNOTWARZOWA",
        "MASKA_KOREKCYJNA", "MASKA_PANORAMICZNA", "MASKA_DLA_DZIECI",
        "ZESTAW_MASKA_FAJKA", "FAJKA",
    ],
    "Noże": [
        "NOZ_NURKOWY", "LINE_CUTTER",
    ],
    "Odzież nurkowa": [
        "ODZIEZ_NURKOWA", "RASHGUARD",
    ],
    "Odzież Termoaktywna": [
        "ODZIEZ_TERMOAKTYWNA",
    ],
    "Płetwy": [
        "PLETWY_PASKOWE", "PLETWY_KALOSZOWE", "PLETWY_JET", "SPREZYNY_DO_PLETW",
    ],
    "Skafandry mokre": [
        "PIANKA_MOKRA", "PIANKA_ZIMNE_WODY", "PIANKA_CIEPLE_WODY", "PIANKA_SHORTY",
        "KOMPLET_PIANEK", "PIANKA_POLSUCHA", "DOCIEPLACZ", "KAPTUR",
        "REKAWICE", "BUTY_NEOPRENOWE",
    ],
    "Skafandry suche": [
        "SUCHY_SKAFANDER", "ZAWOR_SUCHEGO_SKAFANDRA", "DUMP_VALVE_DRYSUIT",
        "WAZ_SUCHY_SKAFANDER", "MANSZETY_SUCHEGO", "SYSTEM_SUCHYCH_REKAWIC",
        "BUTY_DO_SUCHEGO", "OGRZEWANIE_NURKOWE", "OCIEPLACZ",
    ],
    "Skrzydła i jackety": [
        "JACKET", "SKRZYDLO", "BACKPLATE", "UPRZAZ",
        "ZESTAW_SKRZYDLO_SINGLE", "ZESTAW_SKRZYDLO_TWIN",
        "INFLATOR", "WAZ_INFLATORA", "WAZ_KARBOWANY", "DUMP_VALVE_BCD",
        "SIDEMOUNT", "BALAST", "PAS_BALASTOWY", "KIESZENIE_ZINTEGROWANE",
        "TRYMOWKA", "SZELKI_STAGE",
        "BOJA_NURKOWA", "SZPULKA", "KOLOWROTEK", "WOREK_PODNOSZACY",
        "KARABINEK", "RETRACTOR",  # akcesoria tech — marki BP/W
        "BUTLA_NURKOWA", "BUTLA_STAGE", "BUTLA_ARGONU",
        "TWINSET", "MANIFOLD", "ZAWOR_BUTLOWY",
        "ZLACZE_DIN", "ZLACZE_INT", "ADAPTER_DIN_INT",  # hardware nurkowy
    ],
    "Torby i Skrzynie": [
        "TORBA_NURKOWA", "SKRZYNIA_NURKOWA",
    ],
    "Dla dzieci i juniorów": [
        "MASKA_DLA_DZIECI",
    ],
}

# Odwrotna mapa: concept_key → lista kategorii brand
CONCEPT_TO_BRAND_CATEGORIES: dict[str, list[str]] = {}
for cat, concepts in BRAND_CATEGORY_TO_CONCEPTS.items():
    for concept_key in concepts:
        CONCEPT_TO_BRAND_CATEGORIES.setdefault(concept_key, []).append(cat)
