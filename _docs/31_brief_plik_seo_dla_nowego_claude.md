# Informacja o pliku `30_pliki_do_audytu_seo.md` z projektu czatu AI divezone.pl

## Czym jest ten plik

Plik `30_pliki_do_audytu_seo.md` to katalog zasobów danych zebranych podczas budowy czatu AI dla sklepu nurkowego divezone.pl. Projekt czatu AI wymagał zgromadzenia ogromnej ilości danych o sprzęcie nurkowym, frazach wyszukiwania klientów, wiedzy eksperckiej i danych sprzedażowych. Te same dane są bezpośrednio przydatne do pisania nowych opisów produktów pod SEO.

## Skąd pochodzi

Projekt czatu AI (chat.divezone.pl) trwał od lutego 2026. W ramach prac:
- Wygenerowano encyklopedię 105 rodzajów sprzętu nurkowego (pipeline: Evidence Registry → Gemini 3.1 Pro JSON Schema → Validator → Renderer)
- Zebrano 1404 fraz z Google Search Console z wolumenami wyszukiwań
- Zebrano 1060 pytań People Also Ask + autocomplete z Google (DataForSEO)
- Przeprowadzono wywiad z ekspertem divezone.pl (21 grup tematycznych sprzętu)
- Wyciągnięto dane sprzedażowe cross-sell z 8680 zamówień (12 miesięcy)
- Wygenerowano frazy wyszukiwania per produkt dla ~2500 SKU
- Zbudowano mapę 79 marek z rekomendacjami per kategoria

## Co zawiera katalog (7 kategorii)

1. **Encyklopedia sprzętu** — 105 haseł z definicjami, podtypami, synonimami (oficjalne/slang/anglicyzmy/błędne zapytania), frazami long-tail z tagami źródłowymi [GSC, vol], [PAA], [AC], parametrami zakupowymi, cross-sell z %, FAQ klientów, uwagami sprzedawcy. Dostępna jako markdown i jako strukturyzowane JSON-y per hasło.

2. **Frazy z wyszukiwarek** — CSV z frazami które klienci wpisują w Google + pytania PAA (People Also Ask) i autocomplete. Z wolumenami wyszukiwań.

3. **Wiedza ekspercka** — transkrypt wywiadu z ekspertem: co polecać, czego unikać, trendy rynkowe, typowe błędy klientów. Unikalna treść której konkurencja nie ma.

4. **Dane sprzedażowe** — co klienci kupują razem (cross-sell z %), bestsellery per kategoria.

5. **Mapa marek + reguły domenowe** — które marki dominują w jakiej kategorii, co jest zakazane (Cressi), reguły (DIN jedyny standard, INT martwy, pianka półsucha martwy produkt).

6. **Synonimy** — zweryfikowane pary synonimów nurkowych + frazy wyszukiwania per produkt (~2500 SKU × 5-7 fraz).

7. **Evidence registry** — precyzyjne mapowanie fraza → hasło encyklopedii z dokładnym źródłem i numerem linii w pliku.

## Jak użyć do opisów produktów

Plik zawiera rekomendowany workflow: wczytaj encyklopedię JSON dla kategorii → evidence registry (frazy SEO) → fragment eksperta → dane sprzedażowe → search phrases per SKU → waliduj przez reguły domenowe. Na końcu jest propozycja struktury opisu produktu (H1, definicja, podtyp, parametry, kompletny zestaw, FAQ schema.org) i wskazówki pod Google AI Overview.

## Ścieżka

```
/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/_docs/30_pliki_do_audytu_seo.md
```

Wszystkie pliki danych referencyjne są w tym samym repozytorium projektu czatu AI.
