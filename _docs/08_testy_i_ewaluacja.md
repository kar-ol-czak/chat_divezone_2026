# Pytania testowe do kalibracji embeddingów
# Wersja: 1.0 | Data: 2026-02-19

## Cel
25-30 pytań symulujących realne zapytania klientów sklepu nurkowego.
Każde pytanie ma przypisane: oczekiwane narzędzie (tool), typ pytania, uwagi do oceny.

## Legenda narzędzi
- SP = search_products (wyszukiwanie semantyczne)
- GPD = get_product_details
- GEK = get_expert_knowledge (baza wiedzy)
- CP = compare_products
- COS = check_order_status
- GSI = get_shipping_info
- GPA = get_product_availability

---

## A. Pytania produktowe (wyszukiwanie semantyczne, SP)

Cel: testują jakość embeddingów i wyszukiwania hybrydowego.

### A1. Pytania ogólne / początkujący nurek

1. "Cześć, kończę kurs OWD i chcę kupić swój pierwszy sprzęt. Od czego zacząć?"
   - Tool: GEK + SP
   - Oczekiwanie: porada o zestawie ABC + propozycje produktów
   
2. "Szukam maski do nurkowania dla osoby z wąską twarzą, budżet do 300 zł"
   - Tool: SP (filtr: kategoria maski, cena <=300)
   - Oczekiwanie: maski małe/kobiece z ceną

3. "Jaki komputer nurkowy dla początkującego do 2000 zł?"
   - Tool: SP (filtr: kategoria komputery, cena <=2000)
   - Oczekiwanie: komputery entry-level

4. "Potrzebuję piankę na Morze Czerwone, nurkuję w grudniu"
   - Tool: GEK + SP
   - Oczekiwanie: porada 3-5mm + propozycje pianek

5. "Mam 190 cm wzrostu i 100 kg, jaką piankę mi polecicie?"
   - Tool: SP (rozmiary XL/XXL)
   - Oczekiwanie: pianki dostępne w dużych rozmiarach

6. "Szukam zestawu ABC dla dziecka 10 lat"
   - Tool: SP (filtr: kategoria junior/dzieci)
   - Oczekiwanie: maski, płetwy, fajki junior

### A2. Pytania zaawansowane / techniczne

7. "Potrzebuję automat na zimne wody, nurkuję w Bałtyku i jeziorach do -2 stopni"
   - Tool: GEK + SP
   - Oczekiwanie: automaty environmentally sealed/zimne wody + porada

8. "Szukam skrzydła backplate do nurkowania technicznego, twinset 2x12L"
   - Tool: SP (filtr: kategoria BCD/skrzydła tech)
   - Oczekiwanie: skrzydła o dużej wyporności (>20kg lift)

9. "Jaki jest najlżejszy komputer z algorytmem Bühlmanna i integracja powietrza?"
   - Tool: SP + CP
   - Oczekiwanie: komputery z nadajnikiem, porównanie wag

10. "Macie latarki główne do nurkowania jaskiniowego powyżej 4000 lumenów?"
    - Tool: SP (filtr: kategoria latarki, parametry)
    - Oczekiwanie: latarki primary canister/handheld cave

11. "Potrzebuję suchego skafandra, nurkuję w Polsce cały rok, budżet 5000-8000 zł"
    - Tool: SP (filtr: kategoria suche, cena 5000-8000)
    - Oczekiwanie: suche trilaminat/neopren w tym przedziale

### A3. Pytania z naturalnym językiem (trudniejsze dla embeddingów)

12. "Coś na zimno pod piankę, żeby nie marznąć na nurkowaniach w październiku"
    - Tool: SP
    - Oczekiwanie: ocieplacz/rashguard/podpianka termiczna

13. "Moja żona ma brodę z maski, ciągle jej zalewa, co poradzicie?"
    - Tool: GEK + SP
    - Oczekiwanie: porada o dopasowaniu masek + maski do wąskich/trudnych twarzy

14. "Szukam czegoś na prezent dla nurka do 500 zł"
    - Tool: SP
    - Oczekiwanie: akcesoria, gadżety, latarki backup, zegarki

15. "Potrzebuję sprzęt do freedivingu, długie płetwy i maska niskoprofilowa"
    - Tool: SP (filtr: kategoria freediving)
    - Oczekiwanie: płetwy freediving + maski low volume

---

## B. Pytania eksperckie / baza wiedzy (GEK)

Cel: testują trafność wyszukiwania w tabeli divechat_knowledge.

16. "Czym się różni jacket od skrzydła?"
    - Tool: GEK
    - Oczekiwanie: wyjaśnienie różnic BCD jacket vs wing

17. "Kiedy powinienem oddać automat do serwisu?"
    - Tool: GEK
    - Oczekiwanie: informacja o serwisie co 12 miesięcy/100-200 nurkowań

18. "Czy mogę nurkować z astmą?"
    - Tool: GEK
    - Oczekiwanie: ogólna info + zalecenie konsultacji z lekarzem medycyny nurkowej

19. "Jaka jest różnica między nitroksem a zwykłym powietrzem?"
    - Tool: GEK
    - Oczekiwanie: wyjaśnienie EANx, korzyści, wymagany kurs

20. "Jak dobrać rozmiar płetw? Mam buty neoprenowe 5mm"
    - Tool: GEK
    - Oczekiwanie: porada o doborze rozmiaru z uwzględnieniem butów

21. "Jak przechowywać piankę neoprenową żeby dłużej wytrzymała?"
    - Tool: GEK
    - Oczekiwanie: porady dot. pielęgnacji neoprenu

---

## C. Pytania o zamówienia i logistykę (COS, GSI, GPA)

Cel: testują routing do narzędzi nie-wektorowych.

22. "Jaki jest status mojego zamówienia numer 54321?"
    - Tool: COS
    - Oczekiwanie: sprawdzenie statusu w PrestaShop

23. "Ile kosztuje wysyłka do Niemiec?"
    - Tool: GSI
    - Oczekiwanie: informacja o kosztach i czasie dostawy

24. "Czy komputer Suunto D5 jest dostępny od ręki?"
    - Tool: GPA (konkretny produkt)
    - Oczekiwanie: stan magazynowy

25. "Kiedy będziecie mieli ponownie w ofercie Apeks XTX50?"
    - Tool: GPA
    - Oczekiwanie: informacja o dostępności / przewidywanej dostawie

---

## D. Pytania mieszane (wymagają wielu narzędzi)

26. "Porównajcie mi Suunto D5 i Garmin Descent MK2S, który lepszy dla rekreacji?"
    - Tools: SP + GPD + CP + GEK
    - Oczekiwanie: porównanie parametrów, cen, opinii eksperckich

27. "Zamówiłem piankę Mares Flexa ale chyba za mała, jak wygląda procedura zwrotu?"
    - Tools: COS + GEK
    - Oczekiwanie: info o zwrotach + status zamówienia

28. "Lecę do Egiptu za 2 tygodnie, potrzebowałbym komputer nurkowy, macie coś w magazynie do 3000 zł?"
    - Tools: SP + GPA
    - Oczekiwanie: komputery w budżecie dostępne od ręki + info o szybkiej wysyłce

---

## E. Do uzupełnienia przez zespół divezone.pl (10-15 pytań)

Tu wpisz realne pytania z maili i rozmów telefonicznych:

29. [PUSTE - do uzupełnienia]
30. [PUSTE - do uzupełnienia]
31. [PUSTE - do uzupełnienia]
32. [PUSTE - do uzupełnienia]
33. [PUSTE - do uzupełnienia]
34. [PUSTE - do uzupełnienia]
35. [PUSTE - do uzupełnienia]
36. [PUSTE - do uzupełnienia]
37. [PUSTE - do uzupełnienia]
38. [PUSTE - do uzupełnienia]
39. [PUSTE - do uzupełnienia]
40. [PUSTE - do uzupełnienia]

---

## Metryki oceny

Dla każdego pytania oceniamy (skala 1-5):
1. **Trafność narzędzia** - czy AI wybrało właściwe narzędzie (tool)?
2. **Trafność wyników** - czy znalezione produkty/informacje pasują do zapytania?
3. **Jakość odpowiedzi** - czy odpowiedź jest pomocna i kompletna?
4. **Ranking produktów** - czy najlepiej pasujące produkty są na górze listy? (tylko SP)

Cel: średnia >= 4.0 przed launch.
