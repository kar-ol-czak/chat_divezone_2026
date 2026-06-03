# CHAT-T-063 — BACKEND/PROMPT: format odpowiedzi + disclaimer ceny + budżet + antifog

**Instancja:** backend (standalone). Plik: standalone/src/Chat/SystemPrompt.php. CC WDRAŻA SAM.
**Powiązane:** ewaluacja 03.06.2026 (sekcja 5 format, E5 cena, C5/D4 budżet, D1 antifog). CHAT-T-062 (dane cen — osobny task, równolegle).
**Decyzje:** 151a (zakaz „cena na pewno aktualna" + disclaimer), 153 doprecyzowane (antifog/płyn do neoprenu jako rekomendacja produktu, NIE poluzowanie zakazu procedur), C5/D4 (użyj podanego budżetu).

## Cel
Poprawki warstwy promptu wynikające z ewaluacji. NIE dotyka danych (to T-062). Cztery zmiany w SystemPrompt.php.

## ZMIANA 1 — disclaimer ceny + zakaz fałszywej pewności (E5, 151a)
PROBLEM: model powiedział „cena jest na pewno aktualna", a w innej turze podał inną. 
- Dodać regułę: NIGDY nie deklaruj że cena jest „na pewno aktualna", „zawsze aktualna", „gwarantowana". Ceny mogą się zmieniać (promocje).
- Przy podawaniu ceny dodawać krótki disclaimer typu: „Aktualną cenę potwierdź na karcie produktu." (nie przy KAŻDEM zdaniu — raz, naturalnie, gdy podajesz cenę/y).
- Gdy klient pyta wprost „czy cena jest aktualna?": odpowiedz uczciwie, że podajesz cenę z aktualnych danych sklepu, ale ostateczną cenę warto potwierdzić na karcie produktu przed zakupem — BEZ deklaracji absolutnej pewności.

## ZMIANA 2 — stały format odpowiedzi produktowej (sekcja 5 ewaluacji)
PROBLEM: niespójny układ odpowiedzi, czasem brak CTA/produktów.
Dodać wytyczną formatu dla odpowiedzi REKOMENDUJĄCYCH PRODUKTY (nie dotyczy czysto edukacyjnych):
1. Krótka odpowiedź — jedno zdanie wprost na pytanie.
2. Rekomendacja — wskazanie najlepszego wyboru dla danego przypadku (początkujący/budżet/zastosowanie).
3. Produkty — MAX 3-5, każdy: nazwa, cena, dostępność, link.
4. Disclaimer ceny (gdy podana cena) — „aktualną cenę potwierdź na karcie produktu".
5. CTA Divezone — naturalnie jedno z: „Sprawdź produkt", „Napisz na dive@divezone.pl", „Dobierzemy rozmiar po wymiarach".
Format ma być WYTYCZNĄ, nie sztywnym szablonem łamiącym naturalność — model dostosowuje do pytania, ale trzyma tę strukturę dla odpowiedzi produktowych. NIE psuć dobrych odpowiedzi edukacyjnych (A1/A2/B-series ocenione 5 — те zostają swobodne).

## ZMIANA 3 — użyj podanego budżetu (C5/D4)
PROBLEM: sekcja PORADY PREZENTOWE (linie ~309-332) każe ZAWSZE najpierw pytać o budżet. W C5 klient podał „do 1000 zł" w pytaniu, a bot i tak zapytał ponownie → strata wartości.
- Zmodyfikować regułę: jeśli klient JUŻ podał budżet/kwotę w pytaniu (np. „prezent do 1000 zł", „mam 500 zł") — NIE pytaj ponownie. Użyj podanego budżetu i od razu pokaż konkretne propozycje (3-5 produktów z tej półki cenowej) + ewentualnie voucher jako dodatek.
- Pytanie o budżet zostaje TYLKO gdy klient NIE podał kwoty.
- Zasada ogólna (nie tylko prezenty): jeśli klient podał parametr (budżet, rozmiar, zastosowanie) — wykorzystaj go, nie pytaj ponownie o to samo.

## ZMIANA 4 — antifog / płyn do neoprenu jako rekomendacja produktu (D1, 153 doprecyzowane)
KONTEKST: guardrail „ZERO PROCEDUR KONSERWACJI" (linia ~260) jest INTENCJONALNY i ZOSTAJE (powstał po incydencie z praniem skafandra, golden DOMAIN-006/SCOPE-002). NIE poluzowujemy go.
DOPRECYZOWANIE (nie wyjątek od zakazu, tylko granica produkt vs procedura):
- Gdy problem eksploatacyjny ma rozwiązanie w postaci PRODUKTU z naszej oferty, możesz zaproponować TEN PRODUKT jako rozwiązanie:
  - „paruje maska" → płyn antifog / przeciw parowaniu
  - „pianka/neopren/buty/rękawice brzydko pachną" → płyn do prania/pielęgnacji neoprenu (redukujący zapach)
- GRANICA (krytyczna): wskazanie produktu = OK. Podanie PROCEDURY (jak myć, jak prać, temperatura, dawkowanie, „krok po kroku", domowe sposoby) = NADAL ZAKAZANE (linia 260 bez zmian). 
- Sformułować jako dopisek przy guardrailu ~260, np.: „WYJĄTEK PRODUKTOWY (nie procedurowy): jeśli problem rozwiązuje produkt z oferty (antifog na parowanie maski, płyn do pielęgnacji neoprenu na zapach) — możesz zaproponować TEN PRODUKT. Nadal NIE podajesz procedur użycia/prania/mycia — od tego instrukcja producenta. Wskazanie produktu = dobór sprzętu (dozwolone); instrukcja jak prać/myć = procedura (zakazane)."
- META-REGUŁA KONSEKWENCJI POD PRESJĄ zostaje: po zaproponowaniu produktu, jeśli klient dopytuje „ok to jak go użyć krok po kroku / jak wyprać" → odsyłasz do instrukcji producenta, NIE podajesz procedury.

## Granice
- Tylko SystemPrompt.php. Bez zmian w narzędziach/danych (ProductSearch/ProductDetails = T-062).
- Guardrail konserwacji (260) ZOSTAJE — dodajemy tylko granicę „produkt vs procedura", nie luzujemy zakazu procedur.
- Nie psuć odpowiedzi edukacyjnych (ocenione 5).
- Zachować istniejące reguły (INT/yoke nieobecne, bezpieczeństwo, brak zachęcania do nurkowania bez kursu).
- PHP 8.4.

## Kryteria akceptacji
1. Model nie deklaruje „cena na pewno/zawsze aktualna"; dodaje disclaimer „potwierdź na karcie produktu" przy cenach.
2. Odpowiedzi produktowe trzymają format: krótka odp → rekomendacja → 3-5 produktów (nazwa/cena/dostępność/link) → disclaimer → CTA. Edukacyjne pozostają swobodne.
3. Gdy budżet podany w pytaniu — model NIE pyta ponownie, pokazuje konkretne propozycje z tej półki.
4. „Paruje maska" → proponuje antifog (produkt). „Pianka śmierdzi" → proponuje płyn do neoprenu (produkt). Oba BEZ procedury użycia/prania.
5. Dopytanie „jak wyprać/jak użyć krok po kroku" → nadal odesłanie do instrukcji producenta (guardrail konserwacji działa).
6. Test regresji: pranie skafandra/rashguarda → nadal odmowa procedury (golden DOMAIN-006/SCOPE-002 niezłamane).
7. php -l clean.
