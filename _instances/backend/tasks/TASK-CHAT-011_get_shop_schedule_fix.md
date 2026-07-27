# TASK-CHAT-011: Diagnoza i fix bug "get_shop_schedule not called" (P0)

**Instancja:** backend
**Powiązany ADR:** ADR-053 pkt 6
**Priorytet:** P0 KRYTYCZNY (regresja kalendarza po mini-patch v2)
**Powiązane:** TASK-CHAT-007b (deployed), Mini-patch v2 (deployed)

## Cel

Diagnoza dlaczego model produkcyjny NIE wywołuje `get_shop_schedule` mimo deploy 007b i mini-patch v2. Test 2 z 14.05: "Chciałbym do Was wpaść niedługo, prawdopodobnie 6 czerwca, i odebrać moje zamówienie" → bot odpowiedział "6 czerwca sklep divezone.pl będzie otwarty w godzinach 9:00-17:00" mimo że 6 czerwca 2026 to **sobota** (sklep zamknięty).

Bot zhalucynował godziny pracy zamiast wywołać tool. To regresja bezpieczeństwa odpowiedzi.

## Plan diagnozy (3 fazy)

### FAZA 1: Tool registration → JSON schema dla LLM

Sprawdź czy tool jest faktycznie eksportowany do LLM:

1. `cat standalone/config/tools.php` → czy `GetShopSchedule` jest w array
2. Znajdź miejsce gdzie budowany jest `tools` array wysyłany do OpenAI/Anthropic API (najprawdopodobniej `standalone/src/Chat/ChatService.php` lub provider class)
3. Wywołaj endpoint czatu z dummy message, ZALOGUJ pełny payload wysłany do LLM. Sprawdź czy w `tools[]` jest `get_shop_schedule` z poprawną definicją (name, description, parameters).
4. Verify response z LLM — czy zawiera `tool_calls` z `get_shop_schedule` dla daty?

Jeśli tool NIE jest w payload → problem rejestracji, fix natychmiastowy.
Jeśli tool JEST ale model go nie wywołuje → przejście do Fazy 2.

### FAZA 2: Wording w SystemPrompt vs case użycia

Mini-patch v2 instrukcja:
> "Gdy klient pyta o godziny pracy na konkretną datę (np. 'czy będzie otwarte 6 czerwca?') — użyj narzędzia get_shop_schedule."

Test 2 fraza klienta: "Chciałbym wpaść niedługo, prawdopodobnie 6 czerwca, i odebrać moje zamówienie."

**Diagnoza:** Klient NIE pyta wprost o godziny pracy. Pyta o odbiór. Model interpretuje to jako pytanie o procedurę odbioru, nie o godziny. Instrukcja w prompcie nie pokrywa pośrednich form (klient chce wpaść = chce się dowiedzieć czy będzie otwarte).

Sprawdź też reproduktywność:
- Zapytanie "Czy 6 czerwca będzie otwarte?" → czy tool jest wywoływany?
- Zapytanie "Pracujecie 6 czerwca?" → czy tool jest wywoływany?
- Zapytanie "Chcę przyjść 6 czerwca po odbiór" → czy tool jest wywoływany?

Jeśli pierwsza forma OK ale ostatnia nie → wording problem.

### FAZA 3: Cache / stary deployment

- ssh -p 5739 divezone@divezonededyk.smarthost.pl "md5sum /home/divezone/public_html/chat.divezone.pl/src/Chat/SystemPrompt.php"
- Porównaj z lokalnym po mini-patch v2
- Sprawdź czy nie ma starych OpCache cache'owanych klas
- ssh + check /tmp/cache lub odpowiednik

## Propozycje fix-ów (do wyboru po diagnozie)

### Fix A: Wzmocnić instrukcję w SystemPrompt

Zastąp obecną linię:
```
Gdy klient pyta o godziny pracy na konkretną datę (np. "czy będzie otwarte 6 czerwca?") — użyj narzędzia get_shop_schedule.
```

Wersją szerszą:
```
ZAWSZE wywołaj get_shop_schedule gdy klient wspomina KONKRETNĄ DATĘ przyszłą lub bieżący stan otwarcia sklepu, niezależnie od tego czy pyta wprost o godziny. Triggery:
- "Wpadnę 6 czerwca" / "Przyjadę X" / "Mogę przyjść w X" / "Bedę u Was X"
- "Pracujecie X?" / "Czy będzie otwarte X?" / "Macie otwarte w X?"
- "Czy jesteście teraz otwarci?" / "Mogę dziś jeszcze odebrać?"
- "Ile mam czasu na zamówienie żeby wysłali dziś?"

NIGDY nie halucynuj godzin pracy ani dnia tygodnia dla konkretnej daty bez wywołania get_shop_schedule. Standardowe godziny (pon-pt 9-17) z DANE FIRMY podaj tylko gdy klient pyta ogólnie ("jakie macie godziny pracy?") BEZ wskazania konkretnej daty.
```

### Fix B: Few-shot przykład w SystemPrompt

Dodać po sekcji DANE FIRMY:
```
Few-shot get_shop_schedule:

Klient: "Chciałbym wpaść 6 czerwca po odbiór"
→ Bot wywołuje get_shop_schedule(date="2026-06-06")
→ Bot odpowiada: "6 czerwca to sobota, sklep jest zamknięty. Najbliższy dzień roboczy to poniedziałek 8 czerwca, godziny 9:00-17:00. Przy odbiorze osobistym prosimy o wcześniejsze umówienie..."

Klient: "Pracujecie jutro?"
→ Bot wywołuje get_shop_schedule(date="YYYY-MM-DD" gdzie YYYY-MM-DD = jutrzejsza data)
→ Bot odpowiada z wyniku toola
```

### Fix C: Połączenie A + B

Najsilniejsza opcja. Wzmocnienie instrukcji + few-shot przykład.

## STOP point 1 (po Fazie 1)

Raport: `_instances/backend/handoff/TASK-CHAT-011_diagnoza.md`
Zawiera: wyniki Fazy 1, propozycję czy iść w Fazę 2/3.
Status: "DIAGNOZA F1 DONE — decyzja Karol"

## STOP point 2 (po wyborze fixa)

Karol decyduje który fix (A/B/C) wdrażamy. CC implementuje, smoke test, deploy.

## Smoke test po deploy

3 zapytania ręcznie przez chat.divezone.pl:
1. "Chcę przyjść 6 czerwca po odbiór" → wywołanie tool, "sobota, zamknięte"
2. "Czy pracujecie jutro?" → wywołanie tool, odpowiedź z faktycznej daty
3. "Jakie macie godziny pracy?" → bez tool, standardowe pon-pt 9-17 z DANE FIRMY

## Deploy + Git

Standard scp + commit + push, konwencja `TASK-CHAT-011: ...`.

## Out of scope

- Refactor całego tool calling logic
- Zmiana modeli LLM
- Wprowadzanie dat innych niż Europe/Warsaw
