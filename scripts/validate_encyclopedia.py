#!/usr/bin/env python3
"""
TASK-ENC-010: Walidacja encyklopedii v3 — kompletnosc, struktura, linki.
"""

import re
import statistics
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent.parent
ENCYCLOPEDIA = PROJECT_ROOT / "data" / "encyclopedia" / "v3" / "encyclopedia_v3_all.md"
CONCEPT_KEYS_FILE = PROJECT_ROOT / "_docs" / "FAZA1_concept_keys_v2.md"
OUTPUT = PROJECT_ROOT / "data" / "encyclopedia" / "v3" / "validation_report.md"

REQUIRED_SECTIONS = [
    "### Definicja i zasada działania",
    "### Podtypy i konstrukcje",
    "### Synonimy i słowa kluczowe",
    "### Nie mylić z",
    "### Parametry zakupowe",
    "### Powiązane produkty (Cross-selling)",
    "### FAQ klienta",
    "### Uwagi dla sprzedawcy",
]


def parse_entries(text: str) -> list[dict]:
    """Parsuje encyklopedie na liste hasel."""
    entries = []
    # Splituj na hasla po ## N. NAZWA
    parts = re.split(r'^(## \d+\.\s)', text, flags=re.MULTILINE)
    # parts: ['prefix', '## 1. ', 'content', '## 2. ', 'content', ...]
    for i in range(1, len(parts) - 1, 2):
        header_prefix = parts[i].strip()  # "## 1."
        body = parts[i + 1]
        first_line = body.split("\n")[0].strip()
        num = int(re.search(r'\d+', header_prefix).group())
        full_header = f"{header_prefix} {first_line}"
        # Wyciagnij nazwe PL i EN
        name_match = re.match(r'(.+?)\s*/\s*(.+)', first_line)
        name_pl = name_match.group(1).strip() if name_match else first_line
        name_en = name_match.group(2).strip() if name_match else ""
        entries.append({
            "number": num,
            "name_pl": name_pl,
            "name_en": name_en,
            "header": full_header,
            "body": body,
            "chars": len(body),
        })
    return entries


def parse_concept_keys(text: str) -> list[dict]:
    """Wyciaga concept keys z FAZA1_concept_keys_v2.md."""
    keys = []
    for line in text.split("\n"):
        m = re.match(r'\|\s*(\d+b?)\s*\|\s*(\w+)\s*\|\s*(.+?)\s*\|', line)
        if m:
            keys.append({
                "num": m.group(1),
                "key": m.group(2),
                "name": m.group(3).strip(),
            })
    return keys


def check_missing_sections(entries: list[dict]) -> list[str]:
    issues = []
    for e in entries:
        missing = [s for s in REQUIRED_SECTIONS if s not in e["body"]]
        if missing:
            issues.append(f"  #{e['number']} {e['name_pl']}: brak {missing}")
    return issues


def check_cross_links(entries: list[dict]) -> tuple[list[str], dict]:
    """Sprawdza linki (→ KEY) i zwraca broken + stats."""
    all_names_upper = {e["name_pl"].upper() for e in entries}
    # Dodaj warianty bez nawiasow
    all_names_clean = set()
    for n in all_names_upper:
        all_names_clean.add(n)
        # Usun tekst w nawiasach
        clean = re.sub(r'\s*\(.*?\)', '', n).strip()
        all_names_clean.add(clean)

    broken = []
    total_links = 0
    for e in entries:
        links = re.findall(r'\(→\s*([^)]+)\)', e["body"])
        total_links += len(links)
        for link in links:
            link_upper = link.strip().upper()
            if link_upper not in all_names_clean:
                # Sprawdz czy pasuje czesciowo
                found = any(link_upper in n or n in link_upper for n in all_names_clean)
                if not found:
                    broken.append(f"  #{e['number']} {e['name_pl']}: → {link}")
    return broken, {"total": total_links, "broken": len(broken)}


def check_din_int(text: str) -> list[str]:
    """Flaguje podejrzane uzycia INT/yoke jako rownorzednej opcji."""
    issues = []
    for i, line in enumerate(text.split("\n"), 1):
        line_lower = line.lower()
        if ("int" in line_lower or "yoke" in line_lower) and \
           ("lub int" in line_lower or "din/int" in line_lower or "din lub yoke" in line_lower):
            # Pomijaj hasla o adapterach i zlaczach
            if "adapter" not in line_lower and "złącze int" not in line_lower and "archaiczn" not in line_lower:
                issues.append(f"  L{i}: {line.strip()[:120]}")
    return issues


def main():
    enc_text = ENCYCLOPEDIA.read_text(encoding="utf-8")
    ck_text = CONCEPT_KEYS_FILE.read_text(encoding="utf-8")

    entries = parse_entries(enc_text)
    concept_keys = parse_concept_keys(ck_text)

    report = []
    report.append("# Raport walidacji encyklopedii v3")
    report.append(f"# Plik: {ENCYCLOPEDIA.name}")
    report.append(f"# Rozmiar: {len(enc_text):,} chars ({len(enc_text) // 1024} KB)")
    report.append("")

    # 1. Liczba hasel
    report.append(f"## 1. Liczba hasel: {len(entries)}")
    report.append(f"Oczekiwane: 105 (106 concept keys - duplikat ZESTAW_SERWISOWY)")
    status = "OK" if len(entries) == 105 else "PROBLEM"
    report.append(f"Status: **{status}**")
    report.append("")

    # 2. Brakujace concept keys
    report.append("## 2. Porownanie z concept keys")
    enc_names = {e["name_pl"].upper() for e in entries}
    ck_names = {ck["name"].split("/")[0].strip().upper() for ck in concept_keys}
    # Normalizacja - usun tekst w nawiasach
    enc_names_clean = set()
    for n in enc_names:
        enc_names_clean.add(n)
        enc_names_clean.add(re.sub(r'\s*\(.*?\)', '', n).strip())

    ck_names_clean = set()
    for n in ck_names:
        ck_names_clean.add(n)
        ck_names_clean.add(re.sub(r'\s*\(.*?\)', '', n).strip())

    missing_in_enc = []
    for ck in concept_keys:
        ck_name = ck["name"].split("/")[0].strip().upper()
        ck_name_clean = re.sub(r'\s*\(.*?\)', '', ck_name).strip()
        found = ck_name in enc_names_clean or ck_name_clean in enc_names_clean
        if not found:
            # Fuzzy: sprawdz czy jakis entry zawiera kluczowe slowa
            keywords = ck_name_clean.split()
            found = any(all(kw in en for kw in keywords) for en in enc_names_clean)
        if not found:
            missing_in_enc.append(f"  {ck['num']}. {ck['key']} ({ck['name']})")

    if missing_in_enc:
        report.append(f"Brakujace w encyklopedii ({len(missing_in_enc)}):")
        report.extend(missing_in_enc)
    else:
        report.append("Wszystkie concept keys pokryte.")
    report.append("")

    # 3. Struktura sekcji
    report.append("## 3. Struktura sekcji")
    section_issues = check_missing_sections(entries)
    if section_issues:
        report.append(f"Hasla z brakujacymi sekcjami ({len(section_issues)}):")
        report.extend(section_issues)
    else:
        report.append("Wszystkie hasla maja komplet 8 wymaganych sekcji.")
    report.append("")

    # 4. Linki (→ KEY)
    report.append("## 4. Linki wewnetrzne (→ KEY)")
    broken_links, link_stats = check_cross_links(entries)
    report.append(f"Laczna liczba linkow: {link_stats['total']}")
    report.append(f"Broken links: {link_stats['broken']}")
    if broken_links:
        report.extend(broken_links[:30])
        if len(broken_links) > 30:
            report.append(f"  ... i {len(broken_links) - 30} wiecej")
    report.append("")

    # 5. DIN/INT
    report.append("## 5. Sprawdzenie DIN/INT")
    din_issues = check_din_int(enc_text)
    if din_issues:
        report.append(f"Podejrzane uzycia INT/yoke ({len(din_issues)}):")
        report.extend(din_issues[:20])
    else:
        report.append("Brak podejrzanych uzyc INT/yoke.")
    report.append("")

    # 6. Statystyki
    report.append("## 6. Statystyki")
    char_counts = [e["chars"] for e in entries]
    report.append(f"Laczna objetosc: {sum(char_counts):,} chars ({sum(char_counts) // 1024} KB)")
    report.append(f"Min: {min(char_counts):,} chars (#{min(entries, key=lambda x: x['chars'])['number']} {min(entries, key=lambda x: x['chars'])['name_pl']})")
    report.append(f"Max: {max(char_counts):,} chars (#{max(entries, key=lambda x: x['chars'])['number']} {max(entries, key=lambda x: x['chars'])['name_pl']})")
    report.append(f"Avg: {int(statistics.mean(char_counts)):,} chars")
    report.append(f"Median: {int(statistics.median(char_counts)):,} chars")
    report.append("")

    # Spis tresci
    report.append("## 7. Spis tresci")
    for e in entries:
        report.append(f"  {e['number']}. {e['name_pl']} / {e['name_en']} ({e['chars']:,} chars)")

    output = "\n".join(report)
    OUTPUT.write_text(output, encoding="utf-8")
    print(output)
    print(f"\nZapisano: {OUTPUT}")


if __name__ == "__main__":
    main()
