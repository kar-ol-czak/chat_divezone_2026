# TASK-ENC-007: Cleanup i scalenie transkrypcji kwestionariusza eksperta
# Data: 2026-03-04
# Status: DO ZROBIENIA
# Instancja: embeddings
# Czas: ~15-20 min
# Blokuje: start generacji encyklopedii w Gemini

---

## 1. CEL

Scalić 15 plików transkrypcji z TurboScribe w jeden czysty markdown.
Usunąć duplikaty, zapętlenia, nagłówki TurboScribe. Dodać strukturę grup.
Output: jeden plik gotowy do wrzucenia jako kontekst do Gemini 2.5 Pro.

## 2. PLIKI WEJŚCIOWE

Katalog: `_docs/wiedza_nurkowa/Kwestionariusz_Eksperta/`

### Pliki z podziałem na grupy (UNIKALNA TREŚĆ):
| Plik | Grupa | Uwagi |
|------|-------|-------|
| Part_1.txt | 1: Automaty, 2: BCD, 5: Komputery, 3: Węże, 4: Butle | Główne źródło grup 1-5 |
| Part_2.txt | 8: Suche skafandry (cd. z Part 1) | Kontynuacja od zamków, + ocieplacze, rękawice, ogrzewanie, błędy |
| Part_4.txt | 8: Suche (duplikat Part 2) + 15: Konserwacja/akcesoria + 19: Edukacja + 20: Lifestyle | Linie ~63+ to NOWY materiał |
| balast.txt | 6: Balast | Cały plik = unikalna treść |
| zawory i manifoldy.txt | 7: Zawory i złącza | Cały plik = unikalna treść |
| Maski.txt | 9: Maski i fajki | Zapętlenie na końcu (maski dziecięce 4x) |
| pianki1.txt | 10a: Pianki mokre | Cały plik = unikalna treść |
| pianki2.txt | 10b: Pianki (cd.) | Cały plik = unikalna treść |
| rekawice buty kaptury.txt | 11: Rękawice, buty, kaptury | Poprawiony, bez zapętlenia |
| płetwy.txt | 12: Płetwy | Cały plik = unikalna treść |
| bezpieczenstow boje szpulki noze.txt | 13: Bezpieczeństwo | Brak podziału na linie, jeden blok |
| latarki.txt | 14: Latarki | Cały plik = unikalna treść |
| fotografia podwodna.txt | 16: Fotografia podwodna | Cały plik = unikalna treść |
| torby.txt | 17: Torby i transport | Cały plik = unikalna treść |

### Pliki DO POMINIĘCIA (DUPLIKATY):
| Plik | Powód |
|------|-------|
| Part_3.txt | 100% duplikat Part_1.txt (TurboScribe transkrybował od początku) |
| Part_4.txt linie 1-62 | Duplikat Part_2.txt (suche skafandry + butle powtórzone) |

## 3. ALGORYTM SCALENIA

```
1. Part_1.txt → wyodrębnij sekcje:
   - "Jak klienci szukają automatów?" → ## Grupa 1: Automaty oddechowe
   - "BCD, czakety, skrzydła" → ## Grupa 2: BCD, jackety, skrzydła
   - "Komputery norkowe i instrumenty" → ## Grupa 5: Komputery nurkowe i instrumenty
   - "Bęże" / "Co różnicuje węże" → ## Grupa 3: Węże
   - "Jak klienci szukają butli?" → ## Grupa 4: Butle

2. Part_2.txt → cały plik jako:
   - ## Grupa 8: Suche skafandry i akcesoria
   (kontynuacja: zamki, ocieplacze, suche rękawice, ogrzewanie, typowe błędy)

3. Part_4.txt → TYLKO linie od "Konserwacja i akcesoria drobne" do końca:
   - ## Grupa 15: Konserwacja i akcesoria drobne
   - ## Grupa 19: Edukacja i książki
   - ## Grupa 20: Lifestyle, morsowanie, odzież

4. Osobne pliki → bezpośrednio jako sekcje:
   - balast.txt → ## Grupa 6: Balast
   - zawory i manifoldy.txt → ## Grupa 7: Zawory i złącza
   - Maski.txt → ## Grupa 9: Maski i fajki (UCIĄĆ zapętlenie na końcu)
   - pianki1.txt + pianki2.txt → ## Grupa 10: Pianki mokre i półsuche
   - rekawice buty kaptury.txt → ## Grupa 11: Rękawice, buty, kaptury
   - płetwy.txt → ## Grupa 12: Płetwy
   - bezpieczenstow...txt → ## Grupa 13: Bezpieczeństwo i sygnalizacja
   - latarki.txt → ## Grupa 14: Latarki
   - fotografia podwodna.txt → ## Grupa 16: Fotografia podwodna
   - torby.txt → ## Grupa 17: Torby i transport
```

## 4. CLEANUP RULES

### Usunąć:
- Nagłówki TurboScribe: "(Transcribed by TurboScribe.ai. Go Unlimited to remove this message.)"
- Zapętlenia (ten sam tekst powtórzony 2+ razy z rzędu) — dotyczy: Maski.txt końcówka
- Duplikat treści Part_4.txt linie 1-62 (suche skafandry + butle = już w Part_1/2)
- Cały Part_3.txt (= Part_1.txt)

### Poprawić formatowanie:
- bezpieczenstow...txt: dodać podziały na akapity (jest jednym ciągłym blokiem)
- Dodać nagłówki ### dla podtematów wewnątrz grup (np. "### Oktopus", "### Zestaw czy osobno?")

### NIE modyfikować:
- Treść merytoryczną (nawet jeśli zawiera błędy transkrypcji jak "czaket" zamiast "jacket")
- Nazwy marek (nawet zniekształcone: "Apex" = Apeks, "sumto" = Suunto, "barew" = Bare)
- Potoczny język eksperta (to celowe, oddaje styl rozmowy z klientem)

## 5. FORMAT WYJŚCIOWY

Plik: `_docs/wiedza_nurkowa/transkrypt_kwestionariusza_eksperta.md`

```markdown
# Kwestionariusz Eksperta Divezone.pl
# Źródło: nagranie audio, transkrypcja TurboScribe, cleanup Claude Code
# Data nagrania: marzec 2026
# Ekspert: współwłaściciel divezone.pl, 15+ lat doświadczenia w sprzedaży sprzętu nurkowego
# Grup: 19 (brak osobnego nagrania sidemount, wzmianki w grupach 2, 4, 7)

---

## Grupa 1: Automaty oddechowe

### Jak klienci szukają automatów?
[treść z Part_1.txt]

### Co jest standardem i nie różnicuje?
[treść]

### Co realnie różnicuje?
[treść]

### Zestaw czy osobno?
[treść]

### Oktopus
[treść]

### Typowy błąd klienta
[treść]

### Czego LLM nie wie
[treść]

---

## Grupa 2: BCD, jackety, skrzydła
[...]
```

## 6. WALIDACJA

Po scaleniu sprawdź:
- [ ] Plik parsuje się jako poprawny markdown
- [ ] 19 sekcji ## Grupa N (numery 1-7, 8, 9-17, 19-20; brak 18)
- [ ] Zero duplikatów (Part 3 i Part 4 linie 1-62 nie występują)
- [ ] Zero nagłówków TurboScribe
- [ ] Zero zapętleń (ten sam akapit powtórzony 2+ razy)
- [ ] bezpieczenstow...txt ma podziały na akapity
- [ ] Encoding UTF-8, polskie znaki poprawne

## 7. UWAGI

- To NIE jest edycja merytoryczna. Nie poprawiaj błędów transkrypcji, nie dodawaj treści.
- Jedyny cel: czysty, czytelny markdown z jednym wystąpieniem każdej treści.
- Output idzie bezpośrednio do Gemini 2.5 Pro jako kontekst wiedzy eksperckiej.
