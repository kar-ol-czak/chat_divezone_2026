# Pytanie architektoniczne: encyklopedia nurkowa

## 1. CO MAM (źródła ludzkiej wiedzy, ~7.6MB)

Jestem właścicielem divezone.pl, największego polskiego sklepu internetowego ze sprzętem nurkowym (~2600 produktów, PrestaShop). Buduję system AI czatu z wyszukiwaniem semantycznym (pgvector).

Mam następujące źródła wiedzy napisane przez ekspertów (instruktorów, autorów podręczników):

| Źródło | Rozmiar | Język | Styl | Opis |
|--------|---------|-------|------|------|
| Książka OWD IANTD | 515KB | PL | podręcznikowy | Kurs Open Water Diver, 137 stron |
| PADI Encyclopedia Ch3: Dive Equipment | 325KB | EN | encyklopedyczny | Kompletna encyklopedia sprzętu, 142 strony |
| PADI Encyclopedia Ch5: The Diver Within | 330KB | EN | encyklopedyczny | Fizjologia, bezpieczeństwo, 123 strony |
| Artykuły nurkomania.pl (sprzęt) | 728KB | PL | blogowy/poradnikowy | 210 artykułów o sprzęcie (100 unikalnych) |
| Artykuły nurkomania.pl (teoria) | 5.9MB | PL | akademicki | 1292 stron teorii nurkowania |
| Mapa marek divezone.pl | 4KB | PL | dane | 79 marek, whitelist/blacklist per kategoria |
| Opisy produktów divezone.pl | w bazie MySQL | PL | e-commerce | ~2600 profesjonalnych opisów produktów |

Dodatkowo mam twarde dane liczbowe:
- DataForSEO: 1404 frazy klientów z wolumenami wyszukiwań
- Luigi's Box: dane wyszukiwania wewnętrznego sklepu
- Google Search Console: zapytania zewnętrzne

## 2. CO POTRZEBUJĘ

Ustrukturyzowany JSON (~105 pojęć sprzętowych w 13 grupach) który służy dwóm celom:
1. **Baza wiedzy dla AI czatu:** synonimy, relacje, definicje pozwalają chatbotowi poprawnie mapować zapytania klientów na produkty
2. **Encyklopedia dla ludzi:** docelowo artykuły na stronę sklepu

### Schema docelowy (per pojęcie):
```json
{
  "id": "JACKET",
  "nazwa_pl": "jacket nurkowy (BCD)",
  "nazwa_en": "jacket BCD",
  "definicja": "string, min 50 znaków",
  "podtypy": ["string"],
  "synonimy_pl": {
    "exact": ["kamizelka ratowniczo-wyrównawcza", "KRW"],
    "near": ["kamizelka wypornościowa"],
    "potoczne": ["kamizelka", "bcd"],
    "archaiczne": ["ABLJ"],
    "bledne_ale_popularne": ["kamizelka ratunkowa"]
  },
  "synonimy_en": {
    "exact": ["jacket BCD", "buoyancy control device"],
    "near": ["buoyancy compensator"]
  },
  "nie_mylic_z": [
    {"concept": "SKRZYDLO", "dlaczego": "..."}
  ],
  "parametry_zakupowe": ["siła wyporności", "typ kamizelki"],
  "marki_w_sklepie": ["Aqualung", "Mares", "Scubapro"],
  "powiazane_produkty": ["INFLATOR", "BALAST"],
  "faq": [
    {"pytanie": "...", "odpowiedz": "..."}
  ],
  "uwagi_dla_ai": "string"
}
```

### Dlaczego to jest ważne (przykłady realnych błędów AI bez encyklopedii):
- "szpulka" jako synonim kołowrotka (to dwa różne produkty!)
- "oddechówka" jako synonim automatu (termin nie istnieje)
- "kiełbasa" w polskich synonimach boi (to angielski slang "sausage")
- brak "aparat oddechowy" przy automatach (oficjalny termin pominięty)

## 3. CO ZROBILIŚMY (i dlaczego to nie działa)

Zapytaliśmy dwa modele AI (OpenAI Deep Research, Gemini 3.1 Pro): "jak zaprojektować pipeline który syntetyzuje wiedzę z wielu źródeł w encyklopedię?". Oba odpowiedziały: LLM pipeline z chunkowaniem, syntezą, walidacją. Zbudowaliśmy dokładnie to.

### Efekt: 4 warstwy głuchego telefonu

```
Źródła ludzkie (7.6MB, prawda)
    ↓ LLM warstwa 1: ekstrakcja → 46 plików JSON v1 (237KB)
        ↓ LLM warstwa 2: grupowanie → FAZA1, reguły domenowe
            ↓ LLM warstwa 3: GPT-5.2 generuje v2 od zera (ignorując v1!)
                ↓ LLM warstwa 4: Claude Opus waliduje v2
```

Każda warstwa oddala od prawdy źródłowej. Koszt: miliony tokenów w najdroższych modelach. Wynik: walidacja grupy C (16 pojęć) dała 1 FAIL + 11 PASS z uwagami.

### Konkretne przykłady utraty danych:

| Co się stało | Źródło ludzkie | LLM v1 (warstwa 1) | GPT-5.2 v2 (warstwa 3) |
|---|---|---|---|
| Synonim "płyta" dla BACKPLATE | nurkomania.pl używa terminu | Jest w pliku | ZNIKNĄŁ |
| Synonim "szelki" dla UPRZAZ | OWD, nurkomania.pl | Jest | ZNIKNĄŁ |
| Synonim "opona" dla SKRZYDLO | żargon nurków PL | Jest | ZNIKNĄŁ |
| DUMP_VALVE nie_mylic_z | Źródła rozróżniają BCD vs drysuit | Poprawne relacje | Samoodwołanie (DUMP_VALVE myli z DUMP_VALVE) |
| FAQ dla DUMP_VALVE | - | - | Zawiera FAQ o inflatorze (!?) |
| Kodowanie znaków | Źródła mają polskie znaki | Poprawne | Wpisy 1-8 ASCII, 9-16 Unicode |

### Dlaczego to się stało:

Oryginalny prompt do OpenAI/Gemini ZAKŁADAŁ w pytaniach, że odpowiedź to LLM pipeline:
- "Jak zaprojektować pipeline który **syntetyzuje** wiedzę?"
- "Jak zunifikować **chunking**?"
- "Jakie **LLM-owe** podejście minimalizuje halucynacje?"
- "Jakie **narzędzia** (LangChain, LlamaIndex) i **modele** rekomendujecie?"

Pytania nie pozwalały na odpowiedź: "Nie potrzebujesz LLM do większości tego zadania."

## 4. PYTANIE WŁAŚCIWE

**Mam 7.6MB wiedzy eksperckiej napisanej przez ludzi. Potrzebuję z niej ustrukturyzowany JSON o określonym schemacie (~105 pojęć). Jaka jest minimalna architektura która to zrobi z najwyższą wiernością wobec źródeł?**

Nie zakładam żadnego rozwiązania. Może to jest:
a) Czysty Python z parsowaniem i regexami
b) Python + minimalne użycie LLM do kilku pól
c) Zupełnie inny model niż "pipeline"
d) Coś o czym nie pomyślałem

### Podpytania:

1. **Jaką część schematu v2 da się wypełnić DETERMINISTYCZNIE** (Python, regex, lookup, parsowanie) bezpośrednio ze źródeł ludzkich, bez żadnego LLM?

2. **Jaką część WYMAGA inteligencji językowej** (klasyfikacja synonimów, generacja FAQ, uwagi_dla_ai) i dlaczego deterministyczne podejście tu nie wystarczy?

3. **Jeśli LLM jest potrzebny do części zadania:** jaki minimalny model wystarczy? Czy to musi być model za $14-25/M tokenów, czy wystarczy $1-4/M?

4. **Ile warstw LLM powinno być?** Obecny pipeline ma 4. Jaka jest optymalna liczba i dlaczego?

5. **Jak powinien wyglądać przepływ danych?** Od surowych plików .md/.json do finalnego JSON v2. Krok po kroku, z oznaczeniem: to robi Python, to robi LLM, to weryfikuje człowiek.

6. **Walidacja:** jak sprawdzić jakość wyniku? Testy automatyczne (schema, reguły biznesowe) vs human review? Na jakim etapie?

7. **Hierarchia źródeł przy konflikcie terminologicznym:**
   a) Definicje techniczne: PADI Encyclopedia > IANTD OWD > nurkomania.pl
   b) Terminy potoczne: divezone.pl (dane klientów) > nurkomania.pl
   c) Nazewnictwo PL finalne: dostosowane do polskiego e-commerce
   d) Nazewnictwo EN: PADI Encyclopedia

### Kryteria akceptacji:
1. Brak duplikatów terminów między pojęciami (poza jawnymi aliasami)
2. Każda relacja "nie_mylic_z" jest dwustronna (A wspomina B i B wspomina A)
3. Zero mieszania języków: pola PL zawierają tylko polski, EN tylko angielski
4. Anglicyzmy w PL (lung, jacket) dopuszczalne ale oznaczone jako typ
5. Każda definicja ma evidence ze źródeł ludzkich
6. Synonimy nie wyciekają między pojęciami

## 5. ZAŁĄCZONE PLIKI

Każdy plik oznaczony źródłem pochodzenia:

| Nr | Plik | Pochodzenie | Opis |
|----|------|-------------|------|
| 01-04b | raw v1 (jacket, inflator, backplate, skrzydło, sidemount) | LLM-generated z ludzkich źródeł | Warstwa 1, status "needs_review" |
| 05 | v2 tych samych 5 pojęć | LLM-generated (GPT-5.2) | Warstwa 3, generowane od zera |
| 06 | Walidacja grupy C | LLM-generated (Claude Opus) | Warstwa 4, pokazuje błędy warstwy 3 |
| 07 | FAZA1 lista pojęć | LLM-generated | Warstwa 2 |
| 08 | Mapa marek | LUDZKIE | Whitelist sklepowy |
| 09 | Reguły domenowe | LLM-generated | Warstwa 2 |
| 10 | Schema docelowy | Specyfikacja | Format wyjściowy |
| 11 | Mapowanie v1→v2 | Analiza | Które pola skąd |
| 12 | Artykuły nurkomania.pl | LUDZKIE | 3 fragmenty ze sprzętu |
| 13 | PADI Encyclopedia fragmenty | LUDZKIE | Sekcja BCD/inflator/wing |

Pliki 12-13 to próbki prawdziwych źródeł ludzkich. Porównaj je z plikami 01-05 (LLM-generated) żeby zobaczyć ile informacji ginie w każdej warstwie.

## 6. OCZEKIWANY OUTPUT

1. **Diagnoza:** co dokładnie jest nie tak z obecnym podejściem (potwierdź, obal lub uzupełnij moją diagnozę o "głuchym telefonie")
2. **Rekomendowana architektura:** jeden konkretny przepływ od źródeł do JSON, krok po kroku. Nie lista opcji.
3. **Co robi Python, co robi LLM, co robi człowiek:** jasny podział odpowiedzialności
4. **Minimalny model:** jeśli LLM potrzebny, jaki najtańszy wystarczy
5. **Estymacja:** koszt, czas, ryzyko błędów
6. **Czego nie widzę:** blind spoty w moim rozumowaniu
