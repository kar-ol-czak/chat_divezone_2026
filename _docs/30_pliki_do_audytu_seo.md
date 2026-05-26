# Pliki z projektu czatu AI przydatne do audytu opisów SEO
# Data: 2026-03-09

---

## 1. ENCYKLOPEDIA SPRZĘTU NURKOWEGO (najcenniejsze źródło)

### `data/encyclopedia/v3/gen_v2/encyclopedia_v3_all.md`
**7043 linii, 105 haseł sprzętu nurkowego**
Zawiera per hasło: definicję, podtypy klienckie i techniczne, synonimy (oficjalne,
potoczne, slang, anglicyzmy, błędne zapytania), frazy long-tail z wyszukiwarek,
parametry zakupowe, cross-sell z danymi sprzedażowymi, FAQ klientów, uwagi sprzedawcy.

**Jak użyć do opisów:**
- **Synonimy** → SEO: wstaw w opisy alternatywne nazwy (klient szuka "pianka nurkowa"
  a produkt nazywa się "skafander mokry")
- **Frazy long-tail** z tagami [GSC], [PAA], [AC] → targetuj w opisach frazy
  które klienci realnie wpisują
- **FAQ klientów** → sekcja FAQ w opisie produktu (Google Featured Snippets)
- **Parametry zakupowe** → struktura opisu: co jest ważne przy wyborze
- **Cross-sell** → sekcja "Powiązane produkty" / "Kompletny zestaw"
- **Podtypy klienckie** → segmentacja opisów (rekreacyjny vs techniczny)
- **"Nie mylić z"** → unikaj terminologicznego zamieszania w opisach

### `data/encyclopedia/v3/gen_v2/raw/*.json` (105 plików)
**Surowe JSON-y per hasło** — strukturyzowane dane, łatwiejsze do parsowania
niż markdown. Mają pola: concept_key, definition, subtypes_client, synonyms,
longtail_phrases, purchase_parameters, cross_sell, faq, seller_notes.

**Jak użyć:** Jako input do skryptu generującego opisy — parser wczytuje JSON,
buduje szablon opisu per kategoria.

---

## 2. DANE Z WYSZUKIWAREK (frazy klientów)

### `data/dataforseo/processed/all_keywords.csv`
**1404 fraz z Google Search Console** z wolumenami wyszukiwań.
Frazy które klienci realnie wpisują w Google żeby trafić na divezone.pl.

**Jak użyć:** Bezpośredni input do optymalizacji opisów — frazy z najwyższymi
wolumenami powinny być w tytule, meta description i pierwszym akapicie opisu.
Frazy z niskim wolumenem ale precyzyjne → long-tail sekcje, FAQ.

### `data/dataforseo/questions/atp_questions_all.csv`
**1060 pytań People Also Ask + autocomplete** z Google.
Format: pytania które Google wyświetla klientom szukającym sprzętu nurkowego.

**Jak użyć:**
- PAA pytania → sekcja FAQ w opisach (Google Featured Snippets / AI Overview)
- Autocomplete → frazy do naturalnego wplecenia w tekst opisu
- Grupowanie pytań per kategoria → struktura contentu

### `data/dataforseo/processed/raport_keywords.md`
**Raport analityczny** — podsumowanie keywords, top frazy, rozkład wolumenów.

---

## 3. WIEDZA EKSPERCKA (unikalna treść)

### `_docs/wiedza_nurkowa/transkrypt_kwestionariusza_eksperta.md`
**Transkrypt 21 grup tematycznych** — wiedza eksperta divezone.pl o sprzęcie.
Zawiera: co polecać, czego unikać, jakie marki dominują, co się sprzedaje,
co jest archaiczne (INT), trendy rynkowe, typowe błędy klientów.

**Jak użyć:** Najcenniejsze źródło UNIKALNEJ treści do opisów.
Ekspert mówi co jest ważne dla klienta — to jest content którego
konkurencja nie ma. Cytaty i insighty → opisy z autorytetem.

### `_docs/wiedza_nurkowa/Encyklopedia_Nurkowania_NotebookLM_v2.md`
**130 haseł z NotebookLM** — drafty encyklopedii, bazowe definicje sprzętu.
Mniej dopracowane niż finalna encyklopedia ale szersze pokrycie tematów.

**Jak użyć:** Dodatkowe definicje i kontekst gdy encyklopedia nie pokrywa
tematu szczegółowo. Dobry jako "second opinion" do treści.

---

## 4. DANE SPRZEDAŻOWE (co się kupuje razem)

### `_docs/dane_sprzedazowe_crosssell_12m.md`
**Cross-sell z 8680 zamówień (12 miesięcy).** Per kategoria: co klienci kupują razem,
% zamówień z daną kombinacją, top powiązane kategorie.

**Jak użyć:** Sekcja "Kompletny zestaw" / "Klienci kupują również" w opisach.
Np. "70.6% kupujących butlę zamawia też zawór" → "Do butli potrzebny jest zawór
(zamów od razu i oszczędź na dostawie)". Dane empiryczne, nie zgadywanie.

### `_docs/dane_sprzedazowe_bestsellery_12m.md`
**Bestsellery per kategoria (12 miesięcy).** Co się realnie sprzedaje.

**Jak użyć:** Priorytetyzacja opisów — zacznij od bestsellerów.
W opisach: "Najpopularniejszy model w kategorii" (social proof).

---

## 5. MAPA MAREK I REGUŁY DOMENOWE

### `_docs/11_mapa_marek-reviewed.md`
**79 marek z rekomendacjami per kategoria.** Która marka jest liderem
w jakiej kategorii, co polecamy, czego nie sprzedajemy (Cressi).

**Jak użyć:** W opisach marek — "APEKS to lider w kategorii automatów
oddechowych" zamiast generycznego "znana marka". Unikaj marek z blacklisty.

### `_docs/17_reguly_domenowe_grupy_C-M.md`
**Reguły domenowe per grupa produktowa.** Np. DIN jedyny standard,
INT archaiczny, pianka półsucha to martwy produkt, płetwy paskowe
wymagają butów. Reguły co WOLNO a czego NIE WOLNO pisać.

**Jak użyć:** Walidacja opisów — żaden opis nie powinien mówić "dostępny
w wersji DIN i INT" bo INT jest martwy. Reguły = quality gate.

---

## 6. SYNONIMY I FRAZY WYSZUKIWANIA

### `data/synonyms/diving_synonyms_curated.json`
**Synonimy nurkowe curated** — zweryfikowane pary synonimów
(np. "pianka" → "skafander mokry", "wing" → "skrzydło BCD").

**Jak użyć:** Wstawianie synonimów w opisy dla SEO — Google rozumie
że "pianka nurkowa" i "skafander mokry" to to samo. Wzbogaca opisy
o frazy które klienci realnie używają.

### `data/enrichment/search_phrases_validated.json`
**172K linii — frazy wyszukiwania per produkt**, wygenerowane przez GPT-5.2,
zwalidowane. Format: product_id → lista 5-7 fraz którymi klient szukałby
tego produktu.

**Jak użyć:** Bezpośredni input do opisów — każdy produkt ma gotowe frazy
do wplecenia. "Torba na automat oddechowy" → "pokrowiec na automat nurkowy",
"etui na automat i octopus". Alternatywne sposoby szukania tego produktu.

### `_docs/synonyms/synonyms_review_v3.csv`
**Review synonimów** — przegląd jakości, korekty.

---

## 7. EVIDENCE REGISTRY (metadane SEO)

### `data/encyclopedia/v3/gen_v2/evidence/*.json` (105 plików)
**Evidence registry per hasło** — zamknięty zbiór fraz z wyszukiwarek
z dokładnym źródłem (GSC + wolumen, PAA, autocomplete), numerem linii
w pliku źródłowym.

**Jak użyć:** Najbardziej precyzyjne mapowanie fraza → hasło encyklopedii.
Np. evidence/AUTOMAT_ODDECHOWY.json zawiera 36 fraz z GSC/PAA/AC
dokładnie przypisanych do tego typu sprzętu. Gotowe do użycia w opisach
produktów z tej kategorii.

### `data/encyclopedia/v3/gen_v2/mappings/*.json` (4 pliki)
**Mapowania hasło → źródła danych** — które frazy z keywords CSV, PAA, eksperta
i crosssell pasują do którego hasła encyklopedii.

---

## STRATEGIA UŻYCIA DLA NOWYCH OPISÓW

### Priorytet danych (od najcenniejszych):

1. **Encyklopedia JSON** (raw/*.json) — strukturyzowana wiedza per kategoria
2. **Transkrypt eksperta** — unikalne insighty, social proof, autorytet
3. **Frazy z wyszukiwarek** (all_keywords.csv + atp_questions_all.csv) — SEO targeting
4. **Dane sprzedażowe** (crosssell + bestsellery) — cross-sell w opisach
5. **Search phrases per produkt** (search_phrases_validated.json) — frazy per SKU
6. **Mapa marek + reguły domenowe** — walidacja i quality gate

### Rekomendowany workflow generacji opisów:

```
1. Wczytaj encyklopedię JSON dla kategorii produktu
   → Masz: definicję, podtypy, parametry, FAQ, cross-sell

2. Wczytaj evidence registry dla tej kategorii
   → Masz: dokładne frazy SEO z wolumenami

3. Wczytaj fragment transkrypcji eksperta
   → Masz: unikalne insighty, co polecać, na co uważać

4. Wczytaj dane sprzedażowe (crosssell)
   → Masz: co klienci kupują razem (empiryczne)

5. Wczytaj search_phrases dla konkretnego produktu
   → Masz: 5-7 fraz wyszukiwania per SKU

6. Waliduj przez reguły domenowe
   → Brak INT, brak Cressi, brak "certyfikacja EN250A" jako USP
```

### Struktura opisu produktu (propozycja):

```
[H1] Nazwa produktu
[Akapit 1] Definicja z encyklopedii + fraza z najwyższym wolumenem GSC
[H2] Dla kogo / Podtyp kliencki
[Akapit] Z encyklopedii: podtypy klienckie dopasowane do produktu
[H2] Kluczowe cechy / Parametry
[Akapit] Z encyklopedii: parametry zakupowe + insight eksperta
[H2] Kompletny zestaw
[Akapit] Z danych sprzedażowych: co kupić razem
[H2] FAQ (schema.org)
[Q&A] Z encyklopedii FAQ + pytania PAA z DataForSEO
[Meta description] Fraza long-tail z najwyższym wolumenem + cena + USP
```

### Google AI Overview / czaty AI:

Opisy powinny odpowiadać na pytania wprost (nie owijać w bawełnę).
Google AI Overview cytuje treści które bezpośrednio odpowiadają na pytanie.
FAQ z encyklopedii + pytania PAA to idealny format.
Strukturyzowane dane (schema.org Product + FAQ) zwiększają szansę na citation.
