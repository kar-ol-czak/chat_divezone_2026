# Prompt do zewnętrznej analizy security (Gemini / OpenAI)

---

## PROMPT (skopiuj i wklej w całości)

---

Jesteś ekspertem ds. bezpieczeństwa aplikacji webowych i systemów AI (LLM security). Przygotowałem specyfikację implementacji zabezpieczeń dla czatu AI osadzonego w sklepie e-commerce z branży nurkowej. Proszę o krytyczną analizę.

**Kontekst systemu:**
- Sklep nurkowy divezone.pl (PrestaShop 1.7.6, PHP)
- Czat AI obsługiwany przez Claude/OpenAI API (function calling, wyszukiwanie semantyczne produktów)
- Backend: standalone PHP 8.4 API na subdomenie chat.divezone.pl
- Klienci: głównie Polacy, część klientów DE
- Baza danych: MySQL (PrestaShop) + PostgreSQL (pgvector, embeddingi)
- Uwierzytelnienie między modułem PS a standalone API: HMAC

**Specyfikacja zabezpieczeń (TASK_007) do analizy:**

Zaplanowane warstwy ochrony w kolejności wywołania:

1. **Input Length Guard** - odrzucenie jeśli puste, < 2 znaki lub > 400 znaków (konfigurowalne). Komunikat dla użytkownika przy przekroczeniu limitu.

2. **Rate Limiter** - sliding window, 30 requestów/h per IP (konfigurowalne), IP hashowane SHA-256 z saltem (RODO). Dane w MySQL.

3. **Profanity Filter** - lista PL wulgaryzmów w pliku PHP, case-insensitive z obsługą polskich znaków.

4. **Scope Guard** - heurystyka off-topic: blokuj jeśli input zawiera trigger off-topic (np. "napisz mi", "przetłumacz") ORAZ nie zawiera żadnego słowa nurkowego z listy 50+ terminów. Celowo nie używamy LLM do tego (koszt).

5. **Injection Guard** - regex patterns w 3 językach (EN, PL, DE) wykrywające typowe prompt injection: "ignore previous instructions", "zignoruj instrukcje", "ignoriere anweisungen", "you are now", "jesteś teraz", "du bist jetzt", "pretend to be", "udawaj że jesteś", itp.

**Dodatkowe zabezpieczenia:**
- max_tokens output: 600 (konfigurowalne, ale z hard-cap w kodzie)
- Logowanie zdarzeń bezpieczeństwa do MySQL (tylko fragmenty inputu, nie pełne wiadomości)
- Wszystkie limity konfigurowalne przez tabelę ustawień (bez deployu)
- System prompt z instrukcją scope: AI odpowiada tylko na pytania o sprzęt nurkowy

**Proszę o:**

1. **Ocenę kompletności** - jakie wektory ataku nie są pokryte przez ten plan? Co pominięto?

2. **Krytykę poszczególnych warstw** - co jest słabe, podatne na obejście lub źle zaprojektowane?

3. **Priorytety uzupełnień** - jeśli czegoś brakuje, oceń czy to must-have czy nice-to-have dla sklepu tej skali

4. **Specyfika LLM security** - czy są techniki ataków na LLM (jailbreak, indirect prompt injection, data exfiltration przez model) które ten plan pomija a powinien uwzględnić?

5. **Ocenę architektury rate limitingu** - czy sliding window per IP w MySQL to wystarczające rozwiązanie? Jakie są pułapki?

6. **Praktyczne rekomendacje** - 3-5 konkretnych rzeczy które dodałbyś lub zmienił, z uzasadnieniem dlaczego

Odpowiedz strukturalnie, odnosząc się do numerów punktów. Bądź krytyczny - zależy mi na wykryciu słabości przed implementacją, nie na pochwałach.
