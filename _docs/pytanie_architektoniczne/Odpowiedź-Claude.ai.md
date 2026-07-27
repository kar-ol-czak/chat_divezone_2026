Zanim odpowiem, przeczytam pliki których jeszcze nie widzę w kontekście.Przeczytałem wszystkie pliki. Mam pełny obraz. Oto moja diagnoza i rekomendacja.

---

## 1. DIAGNOZA

Twoja diagnoza o „głuchym telefonie" jest trafna, ale niedopowiedziana w jednym kluczowym punkcie.

**Co się naprawdę stało:** Problem nie polega na tym, że użyłeś LLM. Problem polega na tym, że warstwa 3 (GPT-5.2) **zignorowała wyjście warstwy 1** i generowała od zera. Porównałem pliki v1 (01-04b) z v2 (05) i widzę dokładnie to, co opisujesz:

| Element | v1 (warstwa 1) | v2 (warstwa 3) |
|---|---|---|
| Synonim „płyta" dla BACKPLATE | ✅ jest jako exact_synonym | ❌ zniknął |
| Synonim „opona" dla SKRZYDŁO | ✅ jest jako colloquial | ❌ zniknął |
| Synonim „blacha" | ✅ jest jako colloquial | ⚠️ tylko „blacha do skrzydła" |
| Evidence/cytaty ze źródeł | ✅ 4-6 cytatów per pojęcie | ❌ zero cytatów |
| Kodowanie polskich znaków | ✅ spójne Unicode | ❌ wpisy 1-8 ASCII, 9-16 Unicode |
| DUMP_VALVE nie_mylic_z | ✅ poprawne relacje | ❌ samoodwołanie |

**Warstwy v1 są zaskakująco dobre.** To kluczowe odkrycie. Pliki 01-04b mają poprawne definicje, kompletne synonimy, cytat-evidence i sensowne relacje. Status „needs_review" jest uzasadniony, ale bazowy materiał ma wysoką jakość.

**Główny błąd architektoniczny:** nie w użyciu LLM, ale w tym, że warstwa 3 nie dostała v1 jako inputu wiążącego. Zamiast transformować v1→v2, GPT-5.2 generował v2 od zera, co nieuchronnie prowadzi do utraty danych.

**Twój wniosek z pliku 11 jest prawidłowy:** ~70% pól v2 da się wypełnić deterministyczną transformacją z v1, a LLM potrzebny jest tylko do ~30%.

---

## 2. REKOMENDOWANA ARCHITEKTURA

Jeden przepływ, trzy narzędzia, zero pośrednich warstw LLM:

```
v1 JSON (46 plików)
    ↓
[KROK 1] Python: deterministyczna transformacja v1→v2 schema    (~70% pól)
    ↓
[KROK 2] Python: lookup z danych zewnętrznych (mapa marek, DataForSEO)  (~15% pól)
    ↓
v2 szkielet (85% pól wypełnionych, 15% pustych)
    ↓
[KROK 3] LLM: jedno wywołanie per pojęcie, z v2_szkieletem + surowe źródła jako kontekstem
         → wypełnia: podtypy, parametry_zakupowe, faq, uwagi_dla_ai, bledne_ale_popularne, archaiczne
    ↓
v2 kompletny (draft)
    ↓
[KROK 4] Python: walidacja automatyczna (schema, dwustronność relacji, kolizje synonimów, encoding)
    ↓
[KROK 5] Człowiek: review FAILów i uwag z walidacji
    ↓
v2 final
```

---

## 3. PODZIAŁ ODPOWIEDZIALNOŚCI

### KROK 1: Python (deterministyczny)

Transformacja pól v1→v2 wg mapowania z pliku 11:

| Pole v2 | Skąd | Jak |
|---|---|---|
| `id` | `concept_key` | `.upper()` |
| `nazwa_pl` | `canonical_term_pl` | rename |
| `nazwa_en` | `canonical_term_en` | rename |
| `definicja` | `definition_operational_pl` | rename, jeśli < 50 znaków → `definition_pl` |
| `synonimy_pl.exact` | `synonyms[type=exact_synonym, lang=pl]` | filtr + map |
| `synonimy_pl.near` | `synonyms[type=near_synonym, lang=pl]` | filtr + map |
| `synonimy_pl.potoczne` | `synonyms[type=colloquial, lang=pl]` | filtr + map |
| `synonimy_en.exact` | `synonyms[lang=en, type∈{exact,anglicyzm}]` | filtr + klasyfikacja |
| `nie_mylic_z` | `relations[type=nie_mylic_z]` | restrukturyzacja |
| `powiazane_produkty` | `relations[type=czesc_zestawu]` | filtr + wyciąg target_concept_key |

**Anglicyzmy** to jedyne pole wymagające decyzji. Proponuję prostą regułę: jeśli anglicyzm jest powszechnie używany w polskim kontekście (jacket, wing, backplate, inflator, sidemount), trafia do `synonimy_pl.potoczne`. Jeśli to termin czysto angielski (buoyancy compensator), trafia do `synonimy_en.near`.

### KROK 2: Python (lookup)

| Pole v2 | Źródło | Jak |
|---|---|---|
| `marki_w_sklepie` | plik 08_mapa_marek.md | lookup: kategoria → lista marek dozwolonych |
| synonimy z DataForSEO | plik fraz (1404 frazy) | match frazy → concept_key, dodaj do odpowiedniej kategorii synonimów |

### KROK 3: LLM (jedyne użycie)

Pola wymagające inteligencji językowej:

| Pole | Dlaczego LLM | Dlaczego nie regex |
|---|---|---|
| `podtypy` | wymaga zrozumienia taksonomii produktowej | źródła opisują podtypy w prozie, nie jako listę |
| `parametry_zakupowe` | wymaga ekstrakcji cech z opisów i wiedzy e-commerce | parametry rozrzucone po wielu akapitach |
| `faq` | wymaga perspektywy klienta + poprawność merytoryczna | generacja pytań + odpowiedzi wymaga rozumienia intencji |
| `uwagi_dla_ai` | wymaga syntezy reguł domenowych + typowych błędów | kontekstowe instrukcje wymagają rozumowania |
| `synonimy_pl.archaiczne` | wymaga klasyfikacji: czy termin jest przestarzały | decyzja semantyczna, nie pattern matching |
| `synonimy_pl.bledne_ale_popularne` | wymaga wiedzy: co klienci piszą błędnie | krzyżowanie DataForSEO z wiedzą merytoryczną |

**Dlaczego deterministyczne podejście tu nie wystarczy:** te pola wymagają albo (a) ekstrakcji z prozy (podtypy, parametry), albo (b) generacji wymagającej rozumienia domeny (FAQ, uwagi), albo (c) klasyfikacji semantycznej (archaiczne vs potoczne). Regex nie rozpozna, że „chomąto" jest archaizmem, a „blacha" jest aktualna.

**Struktura promptu do LLM (per pojęcie):**

```
SYSTEM: Jesteś ekspertem nurkowym. Uzupełniasz JSON encyklopedii.
REGUŁY: [wklej 09_reguly_domenowe.md]
MAPA MAREK: [wklej sekcję kategorii z 08_mapa_marek.md]

USER:
Oto pojęcie do uzupełnienia:
[wklej v2_szkielet z KROKÓW 1-2, z pustymi polami oznaczonymi null]

Oto źródła ludzkie dotyczące tego pojęcia:
[wklej relevantne fragmenty z PADI, IANTD, nurkomania — per pojęcie, nie całość]

Oto evidence z v1:
[wklej evidence[] z pliku v1]

Uzupełnij TYLKO pola oznaczone null. Nie modyfikuj istniejących pól.
Odpowiedz WYŁĄCZNIE JSON-em, bez komentarzy.
```

Kluczowe: LLM dostaje v2_szkielet z 85% pól już wypełnionych i ma uzupełnić tylko 15%. Nie generuje od zera. Nie ma możliwości „zgubienia" synonimów, bo synonimy już są w JSON-ie.

### KROK 4: Python (walidacja automatyczna)

Testy, które powinny się wykonywać automatycznie:

1. Schema validation (wszystkie wymagane pola, typy, min 50 znaków definicji)
2. Dwustronność `nie_mylic_z`: jeśli A→B, to B→A
3. Kolizje synonimów: żaden synonim nie powinien być exact w dwóch pojęciach (poza jawnymi aliasami)
4. Brak samoodwołań w `nie_mylic_z`
5. Encoding: wszystkie pola PL w Unicode (ą, ę, ó...)
6. Język: pola `synonimy_pl` nie zawierają czystego angielskiego (poza oznaczonymi anglicyzmami)
7. `marki_w_sklepie` ⊂ whitelist z mapy marek
8. `powiazane_produkty` referencje → istniejące concept_key

### KROK 5: Człowiek

Review skupiony na:
- FAILach z walidacji automatycznej
- Podtypach (czy taksonomia ma sens dla sklepu)
- FAQ (czy odpowiadają realnym pytaniom klientów)
- `bledne_ale_popularne` (czy AI poprawnie rozpoznał błędy)

---

## 4. MINIMALNY MODEL

Do KROKU 3 wystarczy **Claude Sonnet 4.5** ($3/M input, $15/M output).

Uzasadnienie: zadanie to uzupełnienie 5-6 pól per pojęcie, z bogatym kontekstem źródłowym i jasnym schematem. To nie wymaga zaawansowanego rozumowania, tylko solidnej ekstrakcji i generacji na podstawie dostarczonych materiałów. Sonnet 4.5 radzi sobie z tym bez problemu.

Nie potrzebujesz Opus ($15/$75) ani GPT-5.2 do tego zadania. Opus warto użyć jedynie w roli walidatora końcowego, jeśli chcesz dodatkowe bezpieczeństwo, ale to opcja, nie wymóg.

---

## 5. ESTYMACJA

| Metryka | Wartość |
|---|---|
| Pojęcia | 105 |
| Pola deterministyczne (kroki 1-2) | ~85% per pojęcie |
| Wywołania LLM | 105 (jedno per pojęcie) |
| Kontekst per wywołanie | ~3-5K tokenów input (szkielet + źródła + reguły) |
| Łącznie input | ~400-500K tokenów |
| Łącznie output | ~150-200K tokenów |
| Koszt LLM (Sonnet 4.5) | ~$4-5 za całość |
| Czas dev (Python transformacja + walidacja) | 1-2 dni |
| Czas LLM (105 wywołań) | ~30-60 min (sekwencyjnie), <5 min (równolegle) |
| Czas human review | 2-4 dni (105 pojęć × 5-10 min) |
| Ryzyko błędów | niskie: LLM nie może usunąć istniejących danych, bo dostaje gotowy JSON |

Porównanie z obecnym podejściem: koszt LLM spada z milionów tokenów w najdroższych modelach do ~650K tokenów w modelu za $3/$15. Ryzyko utraty danych spada radykalnie, bo LLM nie generuje od zera.

---

## 6. CZEGO NIE WIDZISZ (blind spoty)

**6a. V1 nie pokrywa wszystkich 105 pojęć.** Masz 46 plików v1. Masz 105 concept keys. Dla ~59 pojęć nie ma v1. Dla tych pojęć krok 3 (LLM) musi pracować ciężej, bo nie ma szkieletu z v1. Rozwiązanie: dla pojęć bez v1, LLM generuje od zera, ale z twardym kontekstem (fragmenty źródeł + reguły + schema). To jest jedyny moment, gdzie LLM pracuje „od zera", i trzeba go walidować bardziej agresywnie.

**6b. Masz 46 plików v1, ale przesłałeś mi tylko 5.** Nie wiem jaka jest jakość pozostałych 41. Jeśli podobna do tych 5 (wysoka), architektura działa. Jeśli część ma poważne braki, krok 3 musi to nadrobić.

**6c. DataForSEO i Luigi's Box nie są w załącznikach.** Krok 2 (lookup fraz klientów) wymaga tych danych. Bez nich pole `bledne_ale_popularne` będzie oparte wyłącznie na wiedzy LLM, co jest ryzykowne.

**6d. Hierarchia źródeł przy konflikcie** jest dobrze zdefiniowana w dokumencie, ale nie jest zaimplementowana w żadnym kroku. Proponuję wbudować ją w prompt LLM jako jawną instrukcję priorytetów, a nie zakładać że LLM „sam to rozwiąże".

**6e. Pojęcia „zestawowe" (ZESTAW_SKRZYDLO_SINGLE, ZESTAW_AUTOMATU_REKR itp.) nie mają v1.** To pojęcia kompozytowe, które trudniej wygenerować deterministycznie. Dla nich krok 3 będzie krytyczny i warto je walidować ręcznie w 100%.

**6f. Problem DUMP_VALVE jest architektoniczny, nie generacyjny.** Walidacja go poprawnie zidentyfikowała. Decyzja o rozbiciu na DUMP_VALVE_BCD i DUMP_VALVE_DRYSUIT musi zapaść przed uruchomieniem pipeline'u. Rekomendacja walidatora (dwa osobne koncepty) jest poprawna.

---

## PODSUMOWANIE w jednym zdaniu

Zamiast 4 warstw LLM generujących od zera, zrób deterministyczną transformację v1→v2 w Pythonie, dorzu lookup marek i fraz, i użyj jednego wywołania LLM (Sonnet) per pojęcie wyłącznie do uzupełnienia brakujących pól, z kontekstem źródłowym i zakazem modyfikacji istniejących danych.

---

Chcesz, żebym zaczął od napisania skryptu Python do kroku 1 (transformacja v1→v2) na tych 5 przykładowych pojęciach?