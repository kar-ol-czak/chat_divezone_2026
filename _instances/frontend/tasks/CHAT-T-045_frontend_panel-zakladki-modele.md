# CHAT-T-045 — FRONTEND/PS: Panel roboczy w zakladki + UI konfiguracji modeli

**Data:** 2026-06-02
**Instancja:** frontend (modul PS, PHP 7.2)
**Wejscie:** CHAT-T-044 (/api/settings zabezpieczone kanalem serwerowym admin-only, kontrakt nizej), decyzje 90a (UI modeli), 92a/98a (zakladki w panelu roboczym), 93c (primary+escalation+reasoning+max_tokens, bez dublowania kosztow), ADR-070 (panel PS jako jedyny front, struktura zakladek), pamiec: docelowo 3 poziomy modeli (basic/primary/escalation) — UI ma przyjac 3. bez przerobki, ale BUDUJEMY 2.

---

## CEL
Panel roboczy "DiveZone Chat" (AdminDivezoneChatController) dostaje ZAKLADKI. Dzis renderuje whoami+rekomendacje jako jedna tresc. Po tasku: zakladka "Rekomendacje" (obecne) + zakladka "Modele" (nowy UI konfiguracji AI). Ekran "Konfiguruj" (getContent w divezone_chat.php — sekrety/IP) ZOSTAJE OSOBNO, bez zmian (to lokalny zapis PS, inny mechanizm).

## PODZIAL (decyzja 98a, ADR-070)
- "Konfiguruj" (getContent) = sekrety/IP/URL, lokalny Configuration::updateValue. NIE RUSZAC w tym tasku.
- "DiveZone Chat" (panel roboczy, ten controller) = rzeczy zywe z backendu przez kanal serwerowy. Tu dodajemy zakladki.

## KONTRAKT /api/settings (CHAT-T-044)
GET /api/settings — naglowki kanalu serwerowego (X-DiveChat-Server-Token/-Employee/-Time, payload employee_id:timestamp, sekret DIVECHAT_SERVER_SECRET). Modul JUZ to generuje (callBackend). Zwraca:
  {settings:{ai_provider, model_primary, model_escalation, temperature, reasoning_effort, max_tokens, ...},
   available_models:{claude:{primary:[...],escalation:[...]}, openai:{...}},  // kazdy model: value,label,input_price,output_price,supports_temperature,supports_reasoning_effort,effort_param
   exchange_rate_usd_pln: float}
POST /api/settings — body {settings:{...}} (bulk) lub {key,value} (single). 200 sukces / 401 / 403 admin_only / 403 no_role.
available_models sa POGRUPOWANE provider->tier i posortowane po cenie. supports_* mowi ktore pola pokazac dla danego modelu.

## ZAKRES — ZAKLADKI
- Mechanizm zakladek w renderContent panelu: "Rekomendacje" (istniejaca renderRecommendationsSection) + "Modele" (nowa). Whoami zostaje (diagnostyka kanalu) — moze byc nad zakladkami lub w osobnej malej zakladce "Diagnostyka"; zdecyduj prosto (np. whoami maly pasek u gory, zakladki ponizej). Przelaczanie zakladek: czysty HTML/CSS/JS w panelu PS (bez frameworka), albo natywne taby PS jesli prostsze. Stan zakladki nie musi przetrwac reloadu (prosto).

## ZAKRES — ZAKLADKA "MODELE" (93c)
- callBackend('/api/settings', employeeId) — pob. settings + available_models + kurs. Wzorzec 1:1 z callBackend dla rekomendacji.
- Render formularza konfiguracji (PHP generuje HTML, jak renderRecommendationsSection):
  * model_primary — <select> z available_models (pogrupowane optgroup po providerze; label + cena obok). Zaznaczony obecny.
  * model_escalation — <select> j.w.
  * reasoning_effort — <select> (minimal/low/medium/high — wartosci jakie backend wspiera; sprawdz w settings/AIModel). Pokazac TYLKO gdy wybrany model ma supports_reasoning_effort=true (JS: zmiana modelu primary przelacza widocznosc; jak sie nie da prosto bez frameworka — pokazac zawsze z notka "dziala tylko dla modeli reasoning"). Zdecyduj prosto.
  * max_tokens — <input type=number> (np. 256-4096, obecna wartosc).
  * temperature — pokazac TYLKO gdy supports_temperature (analogicznie); jak komplikuje — odloz (93c nie wymaga temperature krytycznie, ale backend ma pole; jak proste, dodaj).
- STRUKTURA POD 3 POZIOMY (pamiec #10): uklad ma byc taki, by dolozyc trzeci poziom (basic) bez przebudowy — np. renderowanie poziomow z tablicy [['primary',...],['escalation',...]] zamiast hardkodu dwoch blokow. Komentarz w kodzie: "// TODO 3. poziom: basic — wymaga routingu w ChatService (osobny task)". NIE budowac basic teraz.
- Zapis: przycisk "Zapisz modele" -> POST /api/settings z body {settings:{model_primary, model_escalation, reasoning_effort, max_tokens, (temperature)}}. Przez callBackend (POST wariant — sprawdz czy callBackend wspiera POST; jak nie, dodaj). Po zapisie: komunikat sukcesu + przeladowanie sekcji (pokaz nowe wartosci).
- Obsluga bledow: 403 admin_only -> "Tylko administrator moze zmieniac modele" (operator widzi sekcje read-only LUB komunikat — zdecyduj: najprosciej pokazac formularz, a przy 403 z backendu wyswietlic komunikat; backend i tak wymusza). 401 -> "Sesja/sekret — sprawdz konfiguracje kanalu serwerowego".

## POZA ZAKRESEM
- Trzeci poziom (basic) + routing w ChatService. Temperature jesli komplikuje. Podglad kosztu/analityka (osobna zakladka pozniej, ADR-070). Migracja rozmow/analityki (przyszle zakladki). Zmiana getContent (sekrety/IP). Dodawanie modeli do enuma (Gemini — osobny temat).

## KROKI
KROK 0 — git pull. Przeczytaj ten task, raport CHAT-T-044 (kontrakt /api/settings), ADR-070, AdminDivezoneChatController.php (callBackend, renderRecommendationsSection — wzorzec render+wolanie; jak obsluguje POST czy tylko GET). Potwierdz czy callBackend umie POST; jak nie — zaplanuj dodanie. STOP jesli cos niejasne.
KROK 1 — Mechanizm zakladek w panelu (Rekomendacje | Modele, whoami jako pasek/diagnostyka).
KROK 2 — Zakladka Modele: callBackend GET /api/settings, renderModelsSection (selecty z available_models pogrupowane, reasoning, max_tokens; struktura pod 3 poziomy). 
KROK 3 — Zapis: POST /api/settings przez callBackend, komunikat sukcesu/bledu, obsluga 403/401.
KROK 4 — Test: otworz panel jako admin (employee 2/5) -> zakladka Modele pokazuje obecne (gpt-5-mini primary, gpt-4.1 escalation, minimal, 1500). Zmien primary na claude-haiku-4-5, zapisz -> 200, weryfikuj w divechat_settings. Jako operator (employee 14) -> zmiana odrzucona (403 admin_only). Whoami nadal dziala.
KROK 5 — GIT: git add controllers/admin/AdminDivezoneChatController.php (+ ew. divezone_chat.php jesli zakladki wymagaja, + views jesli osobny tpl/css/js). commit "CHAT-T-045: panel roboczy w zakladki + UI konfiguracji modeli (2 poziomy, struktura pod 3)". push. docs: commit ze statusem. Handoff LOKALNY. Pliki PHP modulu wymagaja wgrania na PROD (rsync jak wczesniej) — opisz w instrukcji dla Karola (to controllers/, nie views/ — czy lapane bez bumpa wersji? PHP modulu PS jest ladowane przy zadaniu, wiec rsync wystarczy, bez "Aktualizuj"; POTWIERDZ).

## RAPORT
KROK 0: czy callBackend wspiera POST (jak nie, co dodano). Po wdrozeniu: co zbudowane (zakladki + ekran modeli), potwierdzenie struktury pod 3 poziomy (gdzie TODO), wynik testu (admin zmienia model -> zapis; operator -> 403), zgodnosc wygladu z reszta panelu, instrukcja wgrania na PROD dla Karola (czy bump wersji potrzebny).
