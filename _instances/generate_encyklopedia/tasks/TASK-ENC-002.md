# TASK-ENC-002: Dashboard HTML do przegladania wynikow pipeline
# Instancja: generate_encyklopedia (Python)
# Priorytet: SREDNI (po TASK-ENC-001)
# Status: DO ZROBIENIA
# Blokowane przez: TASK-ENC-001 (struktura output/ musi istniec)
# Data: 2026-02-27

## Cel

Prosty plik HTML (single file, zero dependencies) ktory renderuje wyniki
pipeline generacji encyklopedii. Otwierany lokalnie w przegladarce.
Czyta pliki z generate_encyclopedia/output/ i wyswietla w zakladkach.

## Wymagania

### Zakladki glowne
1. **Grupy** - lista grup A-M, klikniecie otwiera podstrone grupy
2. **Logi** - tabela z logami: grupa, data, model, tokeny, koszt, status
3. **Koszty** - podsumowanie kosztow: per grupa, per model, laczne

### Podstrona grupy (po kliknieciu w zakladce Grupy)
Zakladki wewnetrzne:
- **Definicje** - renderuje JSON z grupa_X_final.json (lub original jesli brak final) jako czytelne karty
- **Walidacja** - renderuje grupa_X_validated.md jako markdown
- **Prompt gen.** - renderuje prompt_generation.md
- **Odpowiedz gen.** - renderuje response_generation.md
- **Prompt wal.** - renderuje prompt_validation.md
- **Odpowiedz wal.** - renderuje response_validation.md
- **Log** - renderuje JSON loga tej grupy

### Karta definicji (w zakladce Definicje)
Dla kazdego concept key pokaz:
- Naglowek: id + nazwa_pl + nazwa_en
- Definicja (pelny tekst)
- Synonimy PL (exact, near, potoczne, bledne_ale_popularne) z kolorowym oznaczeniem typu
- Nie mylic z (lista z dlaczego)
- Marki w sklepie
- FAQ (pytanie/odpowiedz)
- Uwagi dla AI (wyroznienie kolorem)
- Werdykt walidacji: PASS (zielony) / PASS z uwagami (zolty) / FAIL (czerwony)

### Tabela logow
Kolumny: Grupa | Data | Model gen. | Tokeny gen. (in/out/reasoning) | Koszt gen. |
Model wal. | Tokeny wal. (in/out/thinking) | Koszt wal. | Koszt laczny | Werdykt
Sortowanie po dacie (najnowsze gora).

### Podsumowanie kosztow
- Tabela: grupa | koszt generacji | koszt walidacji | laczny
- Wiersz TOTAL na dole
- Laczna liczba tokenow per model

## Technologia

- Single HTML file, ZERO external dependencies (no CDN)
- CSS inline w <style>
- JS inline w <script>
- Dane ladowane przez fetch() z lokalnych plikow (file:// protocol)
- UWAGA: fetch z file:// nie dziala w Chrome bez flagi.
  Rozwiazanie: maly skrypt Python ktory sluzy pliki:
  `python -m http.server 8080 --directory generate_encyclopedia`
  Dodac instrukcje w README.

## Ladowanie danych

Dashboard skanuje output/ szukajac folderow grupa_*/:
```javascript
// config - lista grup do zaladowania
const GROUPS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M'];

// dla kazdej grupy probuj zaladowac:
// output/grupa_{X}/grupa_{X}_final.json (lub _original.json)
// output/grupa_{X}/grupa_{X}_validated.md
// output/grupa_{X}/prompt_generation.md
// output/grupa_{X}/prompt_validation.md
// output/grupa_{X}/response_generation.md
// output/grupa_{X}/response_validation.md
// logs/grupa_{X}_*.json (najnowszy)
```

Grupy A i B: pliki sa w data/encyclopedia/ (nie w output/).
Dashboard musi tez umiec je wczytac:
- data/encyclopedia/grupa_A_oddychanie.json
- data/encyclopedia/grupa_B_butle_zawory.json

## Styl

- Ciemny motyw (dark mode) - praca wieczorami
- Monospace dla JSON i promptow
- Kolorowe werdykty: PASS=#22c55e, PASS z uwagami=#eab308, FAIL=#ef4444
- Minimalistyczny, bez ozdobnikow
- Responsywny (laptop + tablet)

## Plik docelowy

generate_encyclopedia/dashboard.html

## Definicja done

- [ ] dashboard.html renderuje grupy A i B z istniejacych danych
- [ ] Zakladki: Grupy, Logi, Koszty dzialaja
- [ ] Karta definicji pokazuje wszystkie pola JSON
- [ ] Werdykty walidacji z kolorami
- [ ] python -m http.server + otworz w przegladarce = dziala
- [ ] README zawiera instrukcje uruchomienia dashboardu
