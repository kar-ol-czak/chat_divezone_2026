# T-032 — BACKEND: Kregoslup panelu admin PrestaShop faza 1 (echo end-to-end)

**Data:** 2026-06-01
**Instancja:** backend
**Wejscie:** ADR-068 (decyzje 174a/175a/176a), ADR-067 (kregoslup), ADR-054 (AdminEditorialPicksController = wzorzec)
**Cel kromki:** zwalidowac CALY lancuch auth/role/kanal/render END-TO-END na trywialnym echo endpoincie. ZERO logiki rekomendacji — to drugi task.

---

## CO WALIDUJEMY (i nic poza tym)
Lancuch z ADR-068: modul PS (zalogowany pracownik) podpisuje request sekretem serwerowym z employee_id -> backend weryfikuje -> lookup roli w divechat_admin_roles -> zwraca {status, employee_id, role} -> natywny AdminController renderuje wynik w panelu PS.
Jesli to dziala, kregoslup jest potwierdzony i kolejny task dokłada rekomendacje na gotowym fundamencie.

## OGRANICZENIA SRODOWISKA
- Cel: PrestaShop **1.7.6**, PHP 7.2 (modul). Backend = PHP 8.4 (standalone).
- Modul MUSI unikac konstrukcji USUNIETYCH w PrestaShop 9 (upgrade na horyzoncie — nie chcemy kodu do przepisania). Jesli CC nie jest pewne czy dana konstrukcja zostala w PS 9 — wybrac wariant obecny w obu liniach lub zaznaczyc w raporcie.
- Sekret serwerowy poza repo (.env, wzorzec jak istniejace sekrety). NIE commitowac sekretu.
- AdminAuthMiddleware (Basic Auth) ZOSTAJE nietkniety dla istniejacego /admin. Kanal serwerowy to NOWY tryb obok, nie zamiennik.

---

## KROK 0 — ROZPOZNANIE + BRAMKA (STOP po tym kroku)
1. Przeczytaj: ADR-068 i ADR-067 w _docs/10_decyzje_projektowe.md; standalone/src/Auth/HmacVerifier.php; standalone/src/Http/AdminAuthMiddleware.php; standalone/src/Controller/AdminEditorialPicksController.php; standalone/config/routes.php; sql/011_editorial_picks.sql (wzorzec migracji).
2. MySQL prod: zweryfikuj ze podane employee_id ISTNIEJA i sa aktywne w pr_employee. Pokaz: id | firstname/lastname | email | id_profile | active dla:
   - admin: 2, 5, 46
   - operator: 14, 54, 61
3. Zaproponuj (do akceptacji Karola, NIE wykonuj jeszcze):
   - schemat tabeli divechat_admin_roles (employee_id PK, role, created_at; CHECK role IN ('admin','operator'));
   - dokladny kontrakt podpisu serwerowego (co wchodzi do HMAC: employee_id + timestamp; nazwa naglowkow; nazwa zmiennej .env sekretu);
   - sciezka echo endpointu (proponowane: GET /api/admin/ping).
STOP. Czekaj na akceptacje Karola przed KROK 1.

## KROK 1 — MIGRACJA + SEED ROL (po akceptacji)
- sql/018_admin_roles.sql + rollback (wzorzec jak 011: header ADR/TASK, IF NOT EXISTS, CHECK, COMMENT).
- Seed: admin = 2,5,46; operator = 14,54,61 (idempotentnie, ON CONFLICT).
- Zastosuj na Railway PG. Pokaz SELECT po seedzie.

## KROK 2 — BACKEND: weryfikacja kanalu serwerowego + autoryzacja rol
- Nowa klasa DiveChat\Auth\ServerHmacVerifier (OSOBNA od HmacVerifier): hash_hmac sha256, ladunek employee_id.':'.timestamp, sekret env DIVECHAT_SERVER_SECRET (rozny od klienckiego), anti-replay +-300s.
- Lookup roli po employee_id w divechat_admin_roles.
- Echo controller GET /api/admin/whoami -> weryfikacja podpisu (headery X-DiveChat-Server-Token / -Employee / -Time) -> lookup roli -> 200 {status:"ok", employee_id, role}. Brak/zly/przeterminowany podpis -> 401 {error:"Unauthorized"}. Podpis OK ale brak roli -> 403 {error:"Forbidden", reason:"no_role"}.
- Wpiac route w config/routes.php wzorcem istniejacych /api/admin/*.
- DIVECHAT_SERVER_SECRET: realny do .env (NIE commitowac), placeholder do .env.example.
- NIE ruszaj AdminAuthMiddleware ani istniejacych endpointow.

## KROK 3 — MODUL PS: szkielet + tab + natywny controller + wywolanie kanalu
- Glowny plik modulu divezone_chat (install: rejestracja taba, deinstall: usuniecie).
- Natywny AdminController (np. AdminDivezoneChatController) renderujacy prosta strone "Divezone Chat".
- employee_id = $this->context->employee->id (decyzja 20a).
- Strona woła backend GET /api/admin/whoami kanalem serwerowym (headery X-DiveChat-Server-Token / -Employee / -Time) i wyswietla zwrocone {status, employee_id, role}.
- SEKRET w module (decyzja 25a): przechowywany w pr_configuration przez Configuration::get/set, klucz DIVEZONE_CHAT_SERVER_SECRET. NIE hardcode, NIE plik. Wartosc IDENTYCZNA z backendowym DIVECHAT_SERVER_SECRET (.env) — generowana raz, wprowadzana w obu miejscach. W raporcie opisz jak Karol wprowadza sekret (np. recznym Configuration::updateValue lub przez UI konfiguracji modulu).
- PHP 7.2 kompatybilnosc; unikac konstrukcji wywalonych w PS 9.

## KROK 4 — SMOKE END-TO-END
- Pracownik admin (np. 2): panel pokazuje status ok + role=admin.
- Pracownik operator (np. 14): status ok + role=operator.
- Employee spoza tabeli: 403 (do potwierdzenia — moze byc trudne do odtworzenia bez logowania jako on; wystarczy test backendu curl-em z employee_id spoza tabeli).
- Zly/brak podpis: 401 (curl bez naglowka).

## GIT
- KROK 0: brak commitu (rozpoznanie + bramka).
- KROK 1-4 (po akceptacji): git add konkretne sciezki (sql/018_*, standalone/src/..., standalone/config/routes.php, modules/divezone_chat/...). Commit message wg konwencji repo (sprawdz git log: kod = "T-032: ..."). git push origin main. Sekret .env POMINIETY (gitignore).
- Po deploy: osobny `docs:` commit ze statusem (_docs/21_STATUS_PROJEKTU.md). Handoff _instances/backend/handoff/ jest LOKALNY (.gitignore) — NIE commituj, NIE uzywaj -f.

## RAPORT KONCOWY
KROK 0: lista pracownikow + propozycje (schemat/kontrakt/sciezka) -> STOP.
Po wdrozeniu: wynik smoke (admin/operator/403/401), potwierdzenie ze AdminAuthMiddleware i istniejace endpointy nietkniete.
