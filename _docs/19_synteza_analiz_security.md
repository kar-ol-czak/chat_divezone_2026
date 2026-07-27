# Synteza zewnętrznych analiz security (OpenAI Deep Research + Gemini)

Data analizy: 2026-02-23  
Dokumenty źródłowe: deep-research-report.md, Analiza_Bezpieczen_stwa_Czatbota_E-commerce.md  
Odniesienie do: TASK_007_security_guards.md

---

## Zgodność obu analiz - co jest pewne

Oba modele zgodnie wskazały te same krytyczne braki:

### KRYTYCZNE (muszą wejść do TASK_007)

**1. Brak sanityzacji OUTPUT (XSS)**  
OWASP LLM05:2025. Jeśli UI renderuje odpowiedź modelu jako HTML lub Markdown bez kodowania, atakujący może przez indirect injection wstrzyknąć JS. Skutek: kradzież sesji, przejęcie konta admina.  
Akcja: dodać do TASK_007 jako osobna sekcja. PHP: htmlspecialchars() lub HTMLPurifier. JS: DOMPurify po stronie frontendu.

**2. Rate limiter w MySQL - antywzorzec**  
MySQL pod atakiem DDoS wypełni pulę połączeń i ubije cały PrestaShop, nie tylko czat.  
Akcja: zostajemy przy MySQL na MVP (brak Redisa w infrastrukturze), ALE dodajemy:
- atomową inkrementację przez INSERT ... ON DUPLICATE KEY UPDATE (bez SELECT + UPDATE)
- blokadę SELECT FOR UPDATE gdzie niezbędne
- świadoma decyzja architektoniczna: akceptujemy ograniczenia, dokumentujemy w ADR

**3. Indirect Prompt Injection przez RAG**  
Złośliwy tekst w opisie produktu / recenzji trafia do kontekstu modelu i wykonuje polecenia.  
To jest specyficzne dla naszej architektury pgvector.  
Akcja: dodać do TASK_007 ochronę RAG - oznaczanie contentu jako "DANE, NIE INSTRUKCJE" przez wrapping w system prompcie.

**4. Brak walidacji function calling**  
Model może być nakłoniony do wywołania narzędzia z nieprawidłowymi parametrami.  
Akcja: dodać sekcję w TASK_007 - każde wywołanie tool weryfikowane po stronie PHP względem schematu JSON i uprawnień sesji.

**5. HMAC bez nonce/timestamp - replay attacks**  
Przechwycone żądanie można odtworzyć.  
Sprawdzić istniejący HmacVerifier.php - czy ma timestamp + nonce?  
Akcja: dodać do TASK_007 jako check do weryfikacji.

### WYSOKIE (wejść do TASK_007 lub osobnego tasku)

**6. IP hash ze stałą solą nie jest anonimizacją wg RODO**  
Przestrzeń IPv4 = 4.3 mld adresów. Rainbow table z wyciekiem soli łamie to w sekundy.  
Akcja: dokumentujemy jako świadomą decyzję (sól rotacyjna to extra złożoność). Alternatywa: codzienny reset soli w CRON (niszczy historyczne korelacje).

**7. Race conditions w rate limitingu**  
Równoległe requesty z jednego IP zaniżają licznik.  
Akcja: atomowa operacja MySQL zamiast SELECT + UPDATE.

**8. Scope Guard - łatwo obejść**  
"Napisz złośliwy kod + automat oddechowy" przejdzie przez filtr.  
Decyzja: akceptujemy ten kompromis na MVP. System prompt AI robi resztę roboty. Dokumentujemy.

**9. Profanity filter - homografy Unicode**  
Cyrylickie "а" wygląda jak łacińskie "a", parser PHP nie wykryje.  
Akcja: normalizacja Unicode przed filtrowaniem (mb_convert_encoding, Normalizer::normalize).

**10. Injection Guard regex - trivially bypassed**  
FlipAttack, Base64, odwracanie liter - model rozumie, regex nie.  
Decyzja: regex zostaje jako pierwsza szybka linia obrony, świadomie akceptujemy ograniczenia.

### SPECYFICZNE DLA BRANŻY NURKOWEJ

**11. Disclaimer medyczny / bezpieczeństwo**  
Błędna porada o mieszankach gazowych / dekompresji może zabić.  
Akcja: dodać do system prompta bezwzględny zakaz porad o parametrach mieszanek i procedurach ratunkowych + stały disclaimer w odpowiedziach dotyczących bezpieczeństwa.

---

## Pytania weryfikacyjne które zadały oba modele

Oba modele pytają o te same rzeczy - musimy mieć jasne odpowiedzi zanim Claude Code zaimplementuje:

**P1. Jak UI renderuje odpowiedź?**  
Czysty tekst / Markdown / HTML?  
> Do sprawdzenia w ChatController i widgetcie JS.

**P2. Czy HMAC ma nonce i timestamp?**  
> Sprawdzić HmacVerifier.php

**P3. Skąd bierzemy IP - czy stoimy za proxy/CDN?**  
> Sprawdzić konfigurację serwera. Jeśli tak - skąd ufamy X-Forwarded-For?

**P4. Czy opisy produktów mogą być edytowane przez zewnętrzne importy / integracje?**  
> Tak (importy CSV, integracje z dostawcami). To oznacza że indirect injection przez RAG jest realnym ryzykiem.

---

## Decyzje architektoniczne do zapisania w ADR

**ADR-01-Security:** Rate limiter w MySQL (akceptowalne dla MVP, nie dla skali).  
**ADR-02-Security:** Stała sól SHA-256 (znane ryzyko RODO, akceptowalne przy braku Redisa).  
**ADR-03-Security:** Regex injection guard jako pierwsza linia (nie wystarczy sam, świadome ograniczenia).  
**ADR-04-Security:** Scope Guard z kompromisem słów nurkowych (akceptujemy false negatives).

---

## Co zmienia się w TASK_007

Nowe sekcje do dodania:
- Sekcja 9: Output Sanitization (KRYTYCZNA)
- Sekcja 10: Function Call Validation (WYSOKA)
- Sekcja 11: RAG Indirect Injection Protection (WYSOKA)
- Sekcja 12: Medical Disclaimer w system prompt (WYSOKA dla branży)
- Sekcja 13: HMAC replay attack check (KRYTYCZNA - weryfikacja istniejącego kodu)

Modyfikacje istniejących sekcji:
- Sekcja 2 (Rate Limiter): atomowa inkrementacja MySQL
- Sekcja 3 (Profanity): normalizacja Unicode przed porównaniem
- Sekcja 5 (Injection): dodać adnotację o ograniczeniach regex

---

## Co NIE wchodzimy do TASK_007 (świadome decyzje)

- Redis jako rate limiter (osobny task jeśli wdrożenie będzie się skalować)
- LLM-as-a-judge classifier (koszt, złożoność - przyszłość)
- Rotacyjna sól CRON (przyszłość, dokumentujemy ryzyko)
- Red teaming / testy penetracyjne (osobny proces po wdrożeniu)
- Aktualizacja PrestaShop 1.7.6 (osobny, duży temat)
