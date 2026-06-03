# CHAT-T-047 — FRONTEND/PS: Drabina ekspozycji widgetu + konfiguracja jako zakladka w menu bocznym

**Data:** 2026-06-02
**Instancja:** frontend (modul PS, PHP 7.2 — bez typed props/enum/match)
**Wejscie:** ADR-069 (etap 1 = IP), planowany etap 2 (drabina ekspozycji), ADR-064 (rate-limit/Turnstile — ochrona backendu, JESZCZE NIE wdrozona), ADR-070 (panel PS, zakladki). Decyzje: 106c (poziomy 1-2 od reki; 3-4 PL/wszyscy wymaga ochrony backendu — ostrzezenie), 108a (konfiguracja jako zakladka w panelu roboczym), 109 (checkboxy grup, nie lista), 110a+c (OR + "wszyscy" wyszarza reszte; lista IP zostaje jako rownolegla sciezka "zawsze pokaz"), 111a (filtr botow osobno od grup).

---

## CEL
1. Rozszerzyc gating widgetu z "tylko IP" na DRABINE EKSPOZYCJI: checkboxy grup widocznosci (pracownicy / zalogowani klienci / PL / wszyscy) z logika OR, + lista IP jako rownolegla sciezka "zawsze widza", + osobny filtr "odfiltruj boty".
2. Udostepnic konfiguracje (dzis tylko Moduly->Konfiguruj/getContent) takze jako ZAKLADKE w panelu roboczym (menu boczne "DiveZone Chat" -> zakladka "Konfiguracja"), obok Rekomendacje/Modele.

## CZESC A — DRABINA EKSPOZYCJI (hookDisplayFooter + getContent/zakladka)

### Model gatingu (decyzje 110a+c, 111a)
Widget pokazuje sie, jesli odwiedzajacy nalezy do KTOREJKOLWIEK z zaznaczonych grup (OR), LUB jego IP jest na liscie "zawsze pokaz". Potem (jesli filtr botow wlaczony) odsiewane sa znane boty.
Logika: pokaz = ( IP_na_liscie OR grupa1 OR grupa2 OR grupa3 OR grupa4 ) AND NOT (filtr_botow AND jest_botem)

### Grupy (nowe klucze Configuration, checkboxy)
- KEY_SHOW_EMPLOYEES (pracownicy) — odwiedzajacy ma WAZNA sesje back-office. UWAGA: hookDisplayFooter to hook FRONT — PS na froncie zna klienta, NIE pracownika. Rozpoznanie pracownika wymaga sprawdzenia ciastka/sesji employee (np. Context::getContext()->employee, lub cookie 'PrestaShop-<id>' z id_employee). DO ROZPOZNANIA w KROK 0 — jak wiarygodnie wykryc zalogowanego pracownika na froncie w PS 1.7.6. Jesli niewiarygodne — zaznacz, zaproponuj najlepsze dostepne (np. czy istnieje aktywna sesja admin).
- KEY_SHOW_CUSTOMERS (zalogowani klienci) — $this->context->customer->isLogged() (juz uzywane w hooku).
- KEY_SHOW_POLAND (wszyscy z PL) — geolokalizacja po naglowku CF-IPCountry (Cloudflare). DO ROZPOZNANIA w KROK 0 — czy CF przekazuje HTTP_CF_IPCOUNTRY (zwykle tak, ale bywa wylaczone w ustawieniach CF). Jesli brak — zaznacz, grupa PL nieaktywna do czasu wlaczenia w CF (instrukcja dla Karola).
- KEY_SHOW_ALL (wszyscy) — brak ograniczenia grupowego. Gdy zaznaczone: w UI WYSZARZ pozostale grupy (bo i tak obejmuje wszystkich); logicznie pokaz=true dla kazdego (modulo filtr botow + ochrona backendu, patrz nizej).

### Lista IP — ZOSTAJE (110c)
KEY_ALLOWED_IPS dziala dalej jako rownolegla sciezka: IP na liscie -> zawsze widzi, NIEZALEZNIE od grup. Cel: Karol zawsze ma dostep do testow, nawet gdy grupy ustawione wasko. NIE usuwac obecnej logiki IP — wlaczyc ja do OR.

### Filtr botow (111a — OSOBNO od grup)
- KEY_FILTER_BOTS (checkbox, osobna sekcja "Filtry", nie w rzedzie grup). Gdy wlaczony: odsiewa znane boty. Wykrywanie: User-Agent (znane stringi: Googlebot, bingbot, facebookexternalhit, Meta, AhrefsBot, SemrushBot itp.) — proste, ale UA latwo podrobic. Cloudflare ma wlasna detekcje; jesli dostepny naglowek (np. cf bot management) — zaznacz w KROK 0, ale NIE zakladaj ze jest (to platny feature CF). Na MVP: lista UA known-bots. To filtr UX (zeby boty nie odpalaly widgetu/nie generowaly kosztu), nie zabezpieczenie.

### OSTRZEZENIE przy PL / wszyscy (106c — KRYTYCZNE)
- Gdy zaznaczone KEY_SHOW_POLAND lub KEY_SHOW_ALL: w UI pokaz wyrazne ostrzezenie: "Otwarcie czatu dla szerokiej publicznosci (PL/wszyscy) wymaga ochrony backendu (rate-limit + Turnstile, ADR-064), ktora NIE jest jeszcze wdrozona. Bez niej ryzyko naduzyc i kosztow LLM. Wlaczaj swiadomie."
- Decyzja 106c: NIE blokujemy zapisu twardo (Karol decyduje swiadomie), ale ostrzezenie MUSI byc widoczne przy zaznaczeniu. Rozwaz wymaganie potwierdzenia (checkbox "rozumiem ryzyko") przy PL/wszyscy — zaproponuj, ale nie komplikuj nadmiernie.

### hookDisplayFooter — zmiana logiki
Zamiast obecnego "IP musi byc na liscie" -> nowa funkcja shouldShowWidget() implementujaca OR grup + lista IP + filtr botow. customerId/HMAC bez zmian. Jesli pokaz=false -> return ''. Zachowac bezpieczny default: jesli ZADNA grupa nie zaznaczona i lista IP pusta -> niewidoczny (jak dzis).

## CZESC B — KONFIGURACJA JAKO ZAKLADKA (108a)
- AdminDivezoneChatController: dodaj TAB_CONFIG = 'config' (wzorzec jak TAB_MODELS: stala + warunek w initContent + walidacja listy zakladek + pasek zakladek linie ~137-141 + renderConfigSection).
- renderConfigSection: ten SAM formularz co getContent (BACKEND_URL, sekret serwerowy, sekret kliencki, drabina ekspozycji: checkboxy grup + lista IP + filtr botow). Zapis LOKALNY (Configuration::updateValue), NIE przez backend/callBackend — to inny mechanizm niz Modele/Rekomendacje.
- ANTY-DUPLIKACJA: wydziel logike formularza+zapisu konfiguracji do wspolnej prywatnej metody uzywanej przez OBA miejsca (getContent w divezone_chat.php ORAZ renderConfigSection w kontrolerze). NIE kopiuj formularza dwa razy. Jesli wspoldzielenie miedzy modul a kontroler trudne (rozne klasy) — zaproponuj najczystsze (np. statyczna metoda w module, wolana z kontrolera). Opisz wybor.
- Stare wejscie Moduly->Konfiguruj (getContent) ZOSTAJE dziala dalej (89a — dwa wejscia OK). Zakladka to dodatkowe wejscie z menu bocznego.

## POZA ZAKRESEM
- Ochrona backendu (rate-limit/Turnstile, ADR-064) — osobny task, warunek realnego otwarcia na PL/wszyscy. Tu tylko OSTRZEZENIE w UI.
- Migracja pozostalych zakladek (Rozmowy/Analityka/Editorial) — osobny program (handoff 25, nowy czat).
- Zmiany w backendzie standalone — gating jest CALKOWICIE po stronie modulu PS.

## KROKI
KROK 0 — git pull. Przeczytaj task, ADR-069/064/070, divezone_chat.php (getContent ~173-255, hookDisplayFooter ~269-335, resolveVisitorIp ~352-376), AdminDivezoneChatController.php (wzorzec zakladek: TAB_, initContent ~54-82, pasek ~137-141, renderModelsSection jako wzor). ROZPOZNAJ: (1) jak wiarygodnie wykryc zalogowanego PRACOWNIKA na froncie PS 1.7.6; (2) czy CF przekazuje HTTP_CF_IPCOUNTRY; (3) czy wspoldzielenie formularza konfiguracji miedzy modul a kontroler jest czyste. STOP — zaraportuj 3 ustalenia + plan, czekaj na akceptacje Karola PRZED kodowaniem.
KROK 1 — (po akceptacji) Nowe klucze Configuration (SHOW_EMPLOYEES/CUSTOMERS/POLAND/ALL, FILTER_BOTS) + init w install/upgrade (upgrade-1.0.2.php? lub przy istniejacym — sprawdz wersjonowanie; bump 1.0.1->1.0.2 jesli trzeba nowych kluczy przez upgrade). Default: wszystkie OFF (bezpieczny default — z lista IP nadal dziala dotychczasowy gating Karola).
KROK 2 — shouldShowWidget() w module: OR grup + lista IP + filtr botow + wykrywanie pracownika/kraju/bota. hookDisplayFooter uzywa jej.
KROK 3 — Formularz konfiguracji: checkboxy grup (z wyszarzeniem przy "wszyscy"), lista IP (zostaje), filtr botow (osobna sekcja), OSTRZEZENIE przy PL/wszyscy. Wspolna metoda dla getContent + zakladki.
KROK 4 — Zakladka "Konfiguracja" w AdminDivezoneChatController (TAB_CONFIG, wzorzec jak Modele).
KROK 5 — Test: (a) tylko lista IP (jak dzis) -> Karol widzi; (b) zazn. "zalogowani klienci" -> zalogowany klient widzi, gosc nie; (c) "wszyscy" -> wyszarza reszte, kazdy widzi + ostrzezenie w UI; (d) filtr botow -> User-Agent Googlebot nie dostaje widgetu; (e) zakladka Konfiguracja w menu bocznym dziala, zapisuje, getContent nadal dziala. Pracownik/PL wg ustalen KROK 0.
KROK 6 — GIT: git add modules/divezone_chat/divezone_chat.php controllers/admin/AdminDivezoneChatController.php (+ upgrade/upgrade-1.0.2.php jesli bump). commit "CHAT-T-047: drabina ekspozycji widgetu (grupy OR + IP + filtr botow) + konfiguracja jako zakladka menu". push. docs: commit ze statusem. Handoff LOKALNY. Instrukcja wgrania na PROD (rsync + ew. "Aktualizuj" jesli bump wersji dla nowych kluczy — POTWIERDZ czy upgrade potrzebny czy klucze tworzone lazy).

## RAPORT
KROK 0: 3 ustalenia (wykrycie pracownika, CF-IPCountry, wspoldzielenie formularza) + plan -> STOP.
Po wdrozeniu: co zbudowane, wynik testow (a-e), potwierdzenie ostrzezenia przy PL/wszyscy, bezpieczny default (OFF), czy bump wersji, instrukcja wgrania dla Karola.

---

## STATUS: ZAIMPLEMENTOWANO (2026-06-03, commit 69e468f)

### Decyzje przyjete (po raporcie KROK 0)
- **112a**: pominieto grupe "Pracownicy" w MVP (lista IP juz pozwala pokazac widget zespolowi; cookie-bridge NIE budowany).
- **113**: Karol wlacza IP Geolocation w CF (Network -> IP Geolocation -> On). CC dodal graceful degradation: jesli HTTP_CF_IPCOUNTRY puste a grupa PL zaznaczona -> grupa PL nieaktywna + zolte ostrzezenie w UI.
- **114a**: wspoldzielenie formularza OPCJA B — publiczne `renderConfigForm()` + `handleConfigSubmit()` na `Divezone_Chat`; `getContent()` = thin wrapper; kontroler woła `Module::getInstanceByName('divezone_chat')`. Zero duplikacji.
- **115b**: LAZY INIT kluczy (Configuration::get z domyslnym '0' jesli brak), BEZ bumpa wersji. Wgranie na PROD = sam rsync, BEZ "Aktualizuj".

### Zaimplementowane
**KROK 1 — Klucze + refactor:** 5 nowych stalych w `Divezone_Chat`: `KEY_SHOW_CUSTOMERS`, `KEY_SHOW_POLAND`, `KEY_SHOW_ALL`, `KEY_FILTER_BOTS`, `KEY_ACK_PUBLIC_RISK`. BEZ `KEY_SHOW_EMPLOYEES` (112a). Lazy init — brak `install()`/upgrade dla nowych kluczy, `Configuration::get` zwraca `''` = OFF (bezpieczny default). `getContent()` zredukowany do thin wrapper (delegacja do publicznych metod).

**KROK 2 — `shouldShowWidget()`:** prywatna metoda w `Divezone_Chat`. Logika: `(ip_on_list OR g_customers OR g_poland OR g_all) AND NOT (filter_bots AND is_bot)`. Bezpieczny default (wszystko OFF + lista pusta) -> false. Helpery prywatne: `isLoggedCustomer`, `isFromPoland` (HTTP_CF_IPCOUNTRY === 'PL', graceful gdy brak), `isOnAllowedIpList`, `getUserAgent`, `isBot` (12 sygnatur UA), `cfIpCountryAvailable`. `hookDisplayFooter` upraszczony — sprawdza tylko sekrety + woła `shouldShowWidget()`; HMAC/customerId/bootJSON bez zmian.

**KROK 3 — Formularz (`renderConfigForm`):** sekcje (1) Backend URL, (2) Dwa sekrety [bez zmian], (3) Drabina ekspozycji — lista IP "zawsze pokaz" + 3 checkboxy grup (klienci/PL/Wszyscy) + dwa banery ostrzegawcze (publiczne ryzyko ADR-064 + CF geo unavailable) + ack risk checkbox, (4) Filtry — filtr botow. Inline JS: "Wszyscy" wyszarza klienci/PL (opacity 0.5), toggle banera ryzyka, toggle banera CF (gdy PL bez CF). Walidacja w `handleConfigSubmit`: PL/Wszyscy bez ack -> nie zapisujemy tych pol, `validation_failed=true` powoduje render formularza z POSTowanymi wartosciami (zachowanie UX po bledzie).

**KROK 4 — Zakladka "Konfiguracja" w `AdminDivezoneChatController`:** `TAB_CONFIG = 'config'` (3. stala). Walidacja listy zakladek (linia 62 initContent) zaktualizowana. Pozycja w pasku `renderTabsNav` ("Konfiguracja"). `renderConfigSection()` — woła `Module::getInstanceByName('divezone_chat')`->`handleConfigSubmit()` (jesli POST) + `renderConfigForm()`. `getContent()` (Moduly -> Konfiguruj) zostaje bez zmian funkcjonalnych — dwa wejscia, jedno zrodlo.

### KROK 5 — Test
Testy logiczne (static review obu plikow, `php -l` clean dla obu):
- (a) **Bezpieczny default (jak dzis):** wszystko OFF + IP Karola na liscie -> `shouldShowWidget()` widzi: customers/poland/all OFF, ipList non-empty, idzie do OR, `isOnAllowedIpList(karol_ip, list)` = true -> widget widoczny dla Karola, niewidoczny dla gosci ✓
- (b) **Tylko klienci:** `show_customers=1`, IP pusta -> widget widoczny dla zalogowanych, niewidoczny dla gosci ✓
- (c) **Wszyscy:** `show_all=1` + `ack=1` -> wszyscy widza; UI dim na pozostalych grupach, baner czerwony ostrzezenia widoczny ✓
- (d) **Filtr botow:** UA `Googlebot/2.1` + filter_bots=1 + jakakolwiek grupa -> `isBot('Googlebot/2.1')` = true -> false ✓
- (e) **Zakladka Konfiguracja:** menu boczne Ulepsz -> DiveZone Chat -> Konfiguracja; submit z tej zakladki preserveuje tab (line 2b initContent) i zapisuje przez te sama metode co Moduly -> Konfiguruj ✓
- (PL) jesli `HTTP_CF_IPCOUNTRY=='PL'` -> grupa aktywna; jesli puste -> `isFromPoland()` zwraca false (graceful), w UI baner zolty ✓

**Testy live (do wykonania przez Karola po wgraniu)** — patrz instrukcja ponizej.

### Bezpieczny default OFF
- Brak `KEY_SHOW_*` w bazie -> `Configuration::get` zwraca `''` -> porownanie z `'1'` = false -> grupa OFF.
- `KEY_ALLOWED_IPS` istnieje od pierwszej instalacji (`uninstall()` deleteByName, ale fresh install nie wymusza creation z install()) — gdy puste, grupa "lista IP" nieaktywna.
- `shouldShowWidget()` zwraca false gdy `!showCustomers && !showPoland && !showAll && ipListEmpty`. ZAINSTALOWANIE nowej wersji modulu BEZ zmiany konfiguracji = NIC SIE NIE ZMIENIA (Karol nadal widzi po IP, wszyscy inni jak wczoraj).

### Bez bumpa wersji (115b)
- `version = '1.0.1'` BEZ zmian. Nowy upgrade NIE jest tworzony.
- Wgranie na PROD = sam rsync `modules/divezone_chat/` -> docelowo na VPS. PS NIE pokaze przycisku "Aktualizuj" (numer wersji niezmieniony).
- Klucze tworzone lazy przy pierwszym submit formularza (`Configuration::updateValue` w `handleConfigSubmit`).

### Instrukcja wgrania na PROD (Karol)

```bash
# 1. Z lokalnej kopii (Mac) wgraj zmienione pliki na VPS (sklep PrestaShop):
rsync -avz --progress \
  /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/modules/divezone_chat/divezone_chat.php \
  /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php \
  divezonededyk.smarthost.pl:public_html/<sciezka_do_PS>/modules/divezone_chat/
```
(uzyj wlasciwej sciezki docelowej do katalogu PrestaShop na VPS — modul divezone_chat siedzi w `<docroot_PS>/modules/divezone_chat/`).

```bash
# 2. (Opcjonalnie) wyczysc cache PS na VPS — przy zmianach w kontrolerze AdminController czasem PS trzyma stara mape klas:
# Z poziomu PS admin: Zaawansowane -> Wydajnosc -> "Wyczysc cache" / "Smarty cache".
# Albo SSH: rm -rf <docroot_PS>/var/cache/prod/* (PS 1.7+).
```

**NIE klikaj "Aktualizuj" w Module Managerze** — wersja nie zmieniona, `install()` nie ma nowych pol do utworzenia.

### Czego JESZCZE TRZEBA dokonczyc (poza tym taskiem)
1. **Karol: wlacz CF IP Geolocation** — Cloudflare Dashboard -> divezone.pl -> Network -> IP Geolocation -> ON. Bez tego grupa "PL" pozostanie zawsze nieaktywna (graceful degradation). Free plan to ma. Sprawdz panel modulu — jesli baner zolty znika po wlaczeniu, znaczy ze CF zaczal wysylac HTTP_CF_IPCOUNTRY.
2. **ADR-064 ochrona backendu (rate-limit + Turnstile)** — warunek otwarcia na PL/wszyscy do publiki. Tu tylko OSTRZEZENIE w UI; realne otwarcie wymaga osobnego taska.

### Out of scope (potwierdzone)
- Ochrona backendu (ADR-064) — osobny task.
- Migracja pozostalych zakladek (Rozmowy/Analityka/Editorial) — handoff 25.
- Zmiany w backendzie standalone — gating jest CALKOWICIE po stronie modulu PS.
- Bump wersji 1.0.1 -> 1.0.2 — odrzucony (decyzja 115b, lazy init).
- KEY_SHOW_EMPLOYEES + cookie-bridge — pominiete (decyzja 112a).
