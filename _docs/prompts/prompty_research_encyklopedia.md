# Prompty do konsultacji: architektura encyklopedii nurkowej
# Wersja 2.0 — po review

## Kontekst wspólny (wklej na początku obu promptów)

Jestem właścicielem największego polskiego sklepu internetowego ze sprzętem nurkowym (divezone.pl, ~2600 produktów). Buduję system AI chat dla klientów z wyszukiwaniem semantycznym (pgvector, embeddingi). 

### Problem
Mam bazę wiedzy z wielu źródeł. Chcę z niej zbudować:
1. **TERAZ (MVP):** ustrukturyzowaną encyklopedię sprzętową (~40 kategorii) jako referencję dla AI generującego synonimy produktowe. Bez niej AI halucynuje (myli kołowrotek ze szpulką, wymyśla nieistniejące terminy jak "oddechówka"). MVP nie wymaga pełnych artykułów, ale wymaga **wysokiej precyzji terminologicznej**.
2. **DOCELOWO:** własną encyklopedię nurkowania po polsku, kompleksową, opartą na tych źródłach + mojej wiedzy instruktorskiej.

**Output będzie używany przez AI jako słownik referencyjny do mapowania zapytań klientów na kategorie/cechy produktów, więc priorytetem jest precyzja terminologii ponad stylistykę.**

### Przykłady realnych błędów AI (bez encyklopedii)
- "szpulka" wrzucona jako synonim kołowrotka (to dwa różne produkty)
- "oddechówka" wymyślona jako synonim automatu (termin nie istnieje)
- "kiełbasa" w polskich synonimach boi (to angielski slang "sausage")
- "sausage" w polskich synonimach (język angielski w polu PL)
- brak "aparat oddechowy" przy automatach (oficjalny termin pominięty)

### Hierarchia źródeł przy konflikcie terminologicznym
Jeśli źródła są sprzeczne, obowiązuje:
a) **Definicje techniczne i bezpieczeństwo:** PADI Encyclopedia > IANTD OWD > CMAS albikrosno
b) **Terminy potoczne i aliasy klientów:** nurkomania.pl + dane ze sklepu divezone.pl
c) **Nazewnictwo finalne PL:** dostosowane do polskiego rynku e-commerce (to co klient wpisuje w wyszukiwarkę)
d) **Nazewnictwo EN:** PADI Encyclopedia jako primary source

### Źródła danych — struktura i charakterystyka

#### 1. nurkomania.pl — sprzęt (JSON, PL)
- 210 wpisów (~100 unikalnych, reszta duplikaty)
- Struktura: `{url, tytul, kategoria, tresc, obrazki, linki_wewnetrzne}`
- Styl: blogowy, potoczny, rady zakupowe
- Język: polski
- Rozmiar: ~728 KB (~180k tokenów)
- Przykład (Automat oddechowy):
```
"Automat oddechowy to jeden z najważniejszych elementów naszego sprzętu
nurkowego. Właściwy dobór automatu, prawidłowy serwis, oraz prawidłowe
obchodzenie się z automatem zwiększa nasze bezpieczeństwo pod wodą."
```
- Przykład (Kołowrotek):
```
"Jaki kołowrotek jest najlepszy? To pytanie zadaje sobie każdy nurek 
przed zakupem. I tu zła wiadomość, nie ma jednoznacznej odpowiedzi."
```
- Przykład (Szpulka — osobny artykuł, kluczowe rozróżnienie):
```
"Szpulka nurkowa z powodzeniem zastępuje w wielu przypadkach kołowrotek, 
jest mniejsza i praktyczniejsza."
```

#### 2. nurkomania.pl — teoria (JSON, PL)
- 1292 stron w jednym JSON
- Struktura: `{sekcja, liczba_stron, dane: [{url, tytul, kategoria, tresc, tabele, obrazki}]}`
- Styl: akademicki, podręcznikowy
- Język: polski
- Rozmiar: ~200k+ tokenów
- Zawiera: fizyka nurkowania, medycyna, dekompresja, mieszanki oddechowe
- Pokrywa się ze sprzętem w miejscach jak "budowa automatu oddechowego"

#### 3. Książka OWD IANTD (Markdown skonwertowany z PDF, PL)
- 6 plików MD, łącznie ~420 KB (~105k tokenów), 137 stron
- Styl: podręcznik kursowy, techniczny ale przystępny
- Język: polski (skonwertowane z PDF przez Marker z OCR, diakrytyki poprawne)
- Przykład (Automaty oddechowe):
```
"Automat nurkowy jest to mechanizm, który redukuje ciśnienie powietrza 
panujące w butli do ciśnienia otoczenia, czyli do takiego poziomu, 
którym możemy oddychać na danej głębokości."
```
- Zawiera szczegóły techniczne: tłokowe vs membranowe, DIN vs INT, serwis

#### 4. PADI Encyclopedia of Recreational Diving Ch3 — Equipment (Markdown z PDF, EN)
- 1 plik MD, ~330 KB (~82k tokenów), 142 strony
- Styl: encyklopedyczny, nowoczesny, bogaty w kontekst użytkowania
- Język: angielski
- Przykład (Regulator):
```
"Your regulator delivers air from your cylinder on demand when you inhale. 
It does this by reducing the compressed air pressure to match the 
surrounding water pressure in two steps or stages."
```
- Pokrywa: maski, płetwy, fajki, pianki, BCD, automaty, komputery, butle, latarki, noże, boje, kompasy, suche skafandry + tec diving

#### 5. PADI Encyclopedia Ch5 — The Diver Within (Markdown z PDF, EN)
- 1 plik MD, ~330 KB (~82k tokenów), 123 strony
- Styl: jak Ch3
- Język: angielski
- Zawiera: fizjologia, bezpieczeństwo, medycyna nurkowania

#### 6. Skrypt albikrosno.pl P1 CMAS (PDF/MD, PL)
- Skrypt szkoleniowy P1 CMAS
- Styl: podręcznik kursowy
- Język: polski

### Podsumowanie różnic między źródłami

| Cecha | nurkomania sprzęt | nurkomania teoria | OWD IANTD | PADI Encyclopedia |
|---|---|---|---|---|
| Język | PL | PL | PL | EN |
| Format | JSON | JSON | Markdown | Markdown |
| Styl | blogowy, potoczny | akademicki | podręcznikowy | encyklopedyczny |
| Głębokość | przegląd, rady | teoria, fizyka | kurs, technika | kompletna encyklopedia |
| Duplikaty | ~50% | mało | brak | brak |
| Pokrycie sprzętu | dobre, ~60 artykułów | częściowe | dobre | najlepsze |
| Pokrycie teorii | brak | bardzo dobre | dobre | dobre |
| Terminologia | polska potoczna | polska formalna | polska kursowa | angielska formalna |
| Tokeny (~) | ~180k | ~200k+ | ~105k | ~165k |

**Łącznie: ~650k+ tokenów. Żaden model poza Gemini nie pomieści w jednym kontekście.**

### Format docelowy encyklopedii sprzętowej (cel 1)

```json
{
  "automat_oddechowy": {
    "canonical_term_pl": "Automat oddechowy",
    "canonical_term_en": "Regulator",
    "definicja_pl": "Urządzenie redukujące ciśnienie z butli do ciśnienia otoczenia w dwóch stopniach...",
    "definicja_en": "A device that reduces tank pressure to ambient pressure in two stages...",
    "synonimy_pl": [
      {"termin": "aparat oddechowy", "typ": "techniczny"},
      {"termin": "regulator", "typ": "anglicyzm"},
      {"termin": "lung", "typ": "anglicyzm"}
    ],
    "synonimy_en": [
      {"termin": "regulator", "typ": "techniczny"},
      {"termin": "demand valve", "typ": "techniczny"},
      {"termin": "breathing apparatus", "typ": "formalny"}
    ],
    "relacje": [
      {"termin": "oktopus", "typ": "nie_mylic_z", "dlaczego": "Oktopus to zapasowy 2. stopień, nie pełny automat"},
      {"termin": "inflator", "typ": "nie_mylic_z", "dlaczego": "Inflator to mechanizm nadmuchiwania BCD"},
      {"termin": "1. stopień", "typ": "podrzedny", "opis": "Reduktor ciśnienia z butli do średniego"},
      {"termin": "2. stopień", "typ": "podrzedny", "opis": "Podaje powietrze na żądanie nurka"},
      {"termin": "zestaw automatów", "typ": "czesc_zestawu", "opis": "1. stopień + 2. stopień + oktopus + węże"}
    ],
    "confidence": "high",
    "status": "validated",
    "evidence": ["nurkomania.pl/automat", "OWD str. 31-35", "PADI Ch3 linie 55-70"],
    "źródła_count": 3
  },
  "kolowrotek": {
    "canonical_term_pl": "Kołowrotek",
    "canonical_term_en": "Reel",
    "relacje": [
      {"termin": "szpulka", "typ": "nie_mylic_z", "dlaczego": "Szpulka to prosty walec bez mechanizmu zwijania, linka nawijana ręcznie. Kołowrotek ma korbkę i obudowę."},
      {"termin": "finger spool", "typ": "wariant", "opis": "Mała szpulka, nie kołowrotek"}
    ],
    "confidence": "high",
    "status": "validated"
  }
}
```

### Typy relacji w encyklopedii
- `nie_mylic_z` — produkty często mylone (musi być dwustronna!)
- `nadrzedny` / `podrzedny` — hierarchia (automat > 1. stopień)
- `czesc_zestawu` — element zestawu produktowego
- `wariant` — odmiana tego samego typu
- `alias` — inna nazwa tego samego produktu
- `bledne_uzycie` — termin błędny ale spotykany (np. "oddechówka")

### Typy synonimów
- `techniczny` — oficjalna terminologia (podręczniki, certyfikaty)
- `potoczny` — używany przez nurków w Polsce
- `anglicyzm` — angielski termin używany w polskim żargonie
- `formalny` — termin z przepisów/norm
- `niezalecany` — termin spotykany ale nieprawidłowy

### Kryteria akceptacji jakości (Acceptance Criteria)
1. Brak duplikatów terminów między kategoriami (poza jawnie wskazanymi aliasami)
2. Każda relacja "nie_mylic_z" musi być dwustronna (A wspomina B i B wspomina A)
3. Każda definicja ma minimum 2 źródła, jeśli dostępne
4. Każda kategoria ma wszystkie pola obowiązkowe i pole confidence
5. Synonimy mają etykiety typu (techniczny/potoczny/anglicyzm/bledne)
6. Zero mieszania języków: pola PL zawierają tylko polski, EN tylko angielski
7. Anglicyzmy w PL (lung, jacket) są dopuszczalne ale oznaczone jako typ "anglicyzm"

---

## PROMPT 1: Dla OpenAI Deep Research

[Wklej kontekst wspólny powyżej, a potem:]

### Pytania

1. **Architektura pipeline:** Mam ~650k tokenów wiedzy w 4 różnych formatach, stylach i językach (PL/EN). Jak zaprojektować pipeline przetwarzania, który:
   - Najpierw chunkuje i klasyfikuje treści per temat/kategoria sprzętowa
   - Potem syntetyzuje wiedzę z wielu źródeł w jedną spójną definicję
   - Rozwiązuje konflikty terminologiczne wg podanej hierarchii źródeł
   - Zachowuje dwujęzyczność (PL + EN) bez mieszania

2. **Chunking heterogenicznych źródeł:** Każde źródło ma inną strukturę (JSON z polami vs Markdown z nagłówkami). Jak zunifikować chunking żeby potem można było łączyć chunki z różnych źródeł o tym samym temacie?

3. **Synteza z wielu źródeł:** Jak połączyć fragment blogowy z nurkomania ("Jaki kołowrotek jest najlepszy?"), techniczny z OWD ("mechanizm z korbką i systemem blokady"), i encyklopedyczny z PADI ("A reel is a mechanical device...") w jedną spójną definicję? Jakie LLM-owe podejście minimalizuje halucynacje przy syntezie?

4. **Dwujęzyczność:** Terminologia nurkowa jest specyficzna. Polscy nurkowie mieszają PL i EN ("lung", "jacket", "spool"). Jak zaprojektować system synonimów który uchwyci ten bilingwizm bez mieszania języków w outputcie?

5. **Jakość i spójność batchowa:** Przy batchowym przetwarzaniu ~40 kategorii sprzętowych, jak zapewnić że:
   - Definicje nie są sprzeczne między kategoriami
   - Relacje "nie_mylic_z" są dwustronne
   - Synonimy nie wyciekają między kategoriami

6. **Dwa cele, jedno źródło:** Jak zaprojektować bazę chunków żeby służyła zarówno do encyklopedii sprzętowej (JSON, teraz) jak i do pisania artykułów encyklopedycznych (tekst, później)?

7. **Narzędzia i koszt:** Jakie konkretne narzędzia (LangChain, LlamaIndex, custom Python, inne) i modele (Claude Sonnet/Opus, GPT-4o, Gemini 3.1 Pro) rekomendujecie? Szacunkowy koszt API dla przetworzenia ~650k tokenów? **Użyj wbudowanej wyszukiwarki internetowej (web search), aby sprawdzić oficjalne i aktualne cenniki API** dla proponowanych modeli (zaznacz datę pobrania cennika).

8. **Walidacja i testy regresji:** Jakie testy automatyczne i półautomatyczne wdrożyć, aby wykrywać:
   - Wyciek synonimów między kategoriami
   - Brak relacji dwustronnych
   - Sprzeczne definicje między kategoriami
   - Mieszanie języków w polach PL/EN
   - Regresję po ponownym przeliczeniu batcha

### Wymagany format odpowiedzi
Odpowiedz w formacie:
1. **Rekomendowana architektura** (jedna główna + 1 alternatywa, z uzasadnieniem dlaczego odrzucasz pozostałe na etapie MVP)
2. **Diagram etapów pipeline** (tekstowo, krok po kroku)
3. **Schemat danych chunków i indeksu** (struktura JSON/DB)
4. **Procedura syntezy per kategoria sprzętowa** (dokładnie co wchodzi do LLM, jaki prompt, jaki output)
5. **Walidacja jakości i testy regresji** (automatyczne + human-in-the-loop)
6. **Stack narzędzi i modeli** (konkretne nazwy, wersje)
7. **Szacunkowy koszt i czas** (rozdzielone: etap 1 MVP vs etap 2 pełna encyklopedia)
8. **Lista ryzyk + mitigacje**
9. **Plan wdrożenia na 2 tygodnie** (etap 1 MVP)

**Przedstaw jedną rekomendowaną architekturę jako decyzję główną. Nie dawaj listy opcji bez wyboru.**

---

## PROMPT 2: Dla Gemini 3.1 Pro (1M+ kontekst)

[Wklej kontekst wspólny powyżej, a potem:]

### Pytania specyficzne dla Gemini

1. **Cały korpus w kontekście:** Mam ~650k tokenów wiedzy. Gemini 3.1 Pro ma 1M+ tokenów kontekstu. Czy realistyczne jest wrzucenie CAŁEGO korpusu + instrukcji (~100k tokenów) i poproszenie o wygenerowanie encyklopedii sprzętowej w jednym przebiegu? Oceń nie tylko czy się zmieści, ale **czy to jest rozsądne jakościowo i produkcyjnie dla terminologii specjalistycznej**. Ryzyka:
   - Gubienie detali w połowie kontekstu (lost in the middle)
   - Halucynacje przy syntezie wielu źródeł
   - Spójność terminologiczna na przestrzeni ~40 kategorii
   - Jakość przy tak dużym input
   
   **Jeśli rekomendujesz wczytanie całego korpusu w jednym zapytaniu (podejście "Full Context"), podaj dokładną strukturę promptu: kolejność bloków (instrukcje systemowe, hierarchia źródeł, definicja formatu JSON, surowe dane), która zminimalizuje ryzyko "lost in the middle".**

2. **Gemini jako indeksator:** Alternatywa: Gemini czyta cały korpus i produkuje ustrukturyzowany indeks tematyczny (per kategoria sprzętowa: które fragmenty z których źródeł są relevantne). A potem Claude przetwarza per temat z tylko relevantnymi chunkami. Czy to ma sens?

3. **Porównanie podejść:** Co da lepszą jakość encyklopedii sprzętowej? Porównaj w tabeli z kryteriami: jakość terminologii, spójność, kontrolowalność, koszt, czas, łatwość debugowania, łatwość iteracji.
   a) Gemini: cały korpus → jedna sesja → cała encyklopedia
   b) Claude: per kategoria, ~5-10k tokenów per call, ~40 calls
   c) Hybryda: Gemini indeksuje, Claude syntetyzuje

4. **Dwujęzyczność w długim kontekście:** Przy 650k tokenów w dwóch językach, czy Gemini poprawnie rozróżni terminy PL vs EN i nie zacznie mieszać?

5. **Koszt:** Ile kosztuje przetworzenie ~650k tokenów input + ~50k output na Gemini 3.1 Pro? Podaj ceny wg stanu na dzień odpowiedzi.

### Wymagany format odpowiedzi
1. **Rekomendacja: jedna architektura** jako decyzja główna z uzasadnieniem
2. **Tabela porównawcza** podejść a/b/c z pytania 3
3. **Ocena ryzyk** z konkretnymi mitigacjami
4. **Szacunkowy koszt i czas**

**Przedstaw jedną rekomendowaną architekturę. Nie dawaj listy opcji bez wyboru.**

---

## Załączniki do obu promptów

Dołącz poniższe pliki. Dają modelowi realne próbki danych zamiast opisu.

### Lista plików do przygotowania:

| Nr | Plik | Zawartość | Jak przygotować |
|---|---|---|---|
| 1 | `sample_nurkomania_sprzet.json` | 3 artykuły: automat oddechowy, kołowrotek, szpulka | Patrz skrypt poniżej |
| 2 | `sample_owd_automaty.md` | Fragment OWD o automatach oddechowych | Patrz skrypt poniżej |
| 3 | `sample_padi_regulators.md` | Fragment PADI Encyclopedia o regulatorach | Patrz skrypt poniżej |
| 4 | `synonyms_review_v3.csv` | Pełny CSV z 50 produktów (pokazuje realne błędy) | Gotowy plik |
| 5 | `sample_kategorie_sklepu.txt` | Lista kategorii divezone.pl | Patrz skrypt poniżej |

### Skrypt do przygotowania próbek:

Uruchom w terminalu:

```bash
cd /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026
mkdir -p _docs/research_attachments

# 1. Nurkomania: 3 artykuły sprzętowe (automat, kołowrotek, szpulka)
python3 -c "
import json
with open('_docs/wiedza_nurkowa/sprzet_do_nurkowania.json','r') as f:
    data = json.load(f)
selected = []
targets = ['Automat oddechowy', 'Kołowrotek', 'Szpulka nurkowa']
seen = set()
for item in data:
    t = item.get('tytul','')
    if t in targets and t not in seen:
        selected.append(item)
        seen.add(t)
with open('_docs/research_attachments/sample_nurkomania_sprzet.json','w') as f:
    json.dump(selected, f, ensure_ascii=False, indent=2)
print(f'Zapisano {len(selected)} artykułów')
"

# 2. OWD: fragment o automatach (linie 227-300 z pliku 31-60)
sed -n '227,300p' '_docs/wiedza_nurkowa/Książka OWD/0 - Książka OWD cała bez okładki-31-60.md' > '_docs/research_attachments/sample_owd_automaty.md'

# 3. PADI: fragment o regulatorach (linie 45-110)
sed -n '45,110p' '_docs/wiedza_nurkowa/Encyclopedia of Recreational Diving/Encyclopedia of Recreational Diving Ch3-Dive-equipment.md' > '_docs/research_attachments/sample_padi_regulators.md'

# 4. Kategorie sklepu
cat > '_docs/research_attachments/sample_kategorie_sklepu.txt' << 'EOF'
KATEGORIE PRODUKTÓW DIVEZONE.PL (PrestaShop):

Pianki/skafandry: Skafandry Na ZIMNE wody, Skafandry Na CIEPŁE wody, Komplety Pianek, Skafandry suche (Trylaminat Cordura, Neoprenowe), Ocieplacze do Suchych, Kaptury, Rękawice, Buty, Buty do suchego, Zawory do suchego skafandra, Manszety
Automaty: 1 stopnie, 2 stopnie, Automaty Oddechowe, Automaty stage, Węże do Automatów, Akcesoria do automatów
Wypornościowe: Skrzydła, Skrzydła z uprzężą do Poj. Butli, Skrzydła z uprzężą do Twina, Jackety (BCD), Side Mount, Płyty i uprzęże, Systemy Balastowe, Balast
Maski i fajki: Maski jednoszybowe, Maski dwuszybowe, Maski panoramiczne, Maski korekcyjne, Fajki, Zestawy Maska+Fajka
Płetwy: Płetwy Paskowe na Buta, Płetwy Gumowe JET, Płetwy Kaloszowe na Stopę
Komputery: Komputery Nurkowe, Komputery SHEARWATER/SUUNTO/SCUBAPRO/MARES/Garmin/RATIO/AQUALUNG/Halcyon/TUSA, Konsole, Manometry, Kompasy, Interfejsy
Oświetlenie: Małe i do Ręki, Duże z Głowicą, Oświetlenia Video, Baterie i akcesoria, Latarki nurkowe
Butle: Butle Stalowe, Butle Aluminiowe, Butle do Argonu, Twinsety, Manifoldy i Obejmy, Zawory do butli, Akcesoria do butli
Bezpieczeństwo: Bojki dekompresyjne, Noże, Szpulki, Kołowrotki, Karabinki nurkowe, Sygnalizatory, Retraktory
Inne: Książki nurkowe, Odzież nurkowa, Odzież Termoaktywna, Ogrzewanie nurkowe, Torby na Sprzęt, Skrzynie transportowe
EOF

# 5. Synonimy v3 - skopiuj istniejący
cp '_docs/synonyms/synonyms_review_v3.csv' '_docs/research_attachments/' 2>/dev/null || echo "Skopiuj ręcznie synonyms_review_v3.csv do _docs/research_attachments/"

echo "Gotowe. Pliki w _docs/research_attachments/"
ls -la _docs/research_attachments/
```

### Po uruchomieniu skryptu:
Sprawdź pliki w `_docs/research_attachments/`. Do promptu dołączasz wszystkie 5 plików jako załączniki.
