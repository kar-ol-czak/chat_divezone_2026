# T-033 — BACKEND: Diagnoza + fix instalacji modulu PS divezone_chat

**Data:** 2026-06-01
**Instancja:** backend
**Wejscie:** T-032 (modul zbudowany), zgloszenie Karola: instalacja modulu wisi ("kolko sie kreci, nic sie nie dzieje"), po nieudanej probie modulu BRAK na liscie modulow (install nie zostawil trwalego wpisu).
**Cel:** ustalic FAKTYCZNA przyczyne z logow (NIE zgadywac), naprawic, potwierdzic czysta instalacje.

---

## OBJAW
Instalacja modulu divezone_chat w live PrestaShop 1.7.6 wisi (AJAX instalatora kreci sie bez odpowiedzi). Po przerwaniu: modul NIE figuruje jako zainstalowany (brak na liscie).

## GLOWNY PODEJRZANY (do POTWIERDZENIA w logu, nie zakladać z gory)
divezone_chat.php: `TAB_PARENT = 'AdminTools'`. W PrestaShop 1.7.6 klasa rodzica menu "Zaawansowane" to **AdminAdvancedParameters**, NIE AdminTools. Jesli Tab::getIdFromClassName('AdminTools') zwraca 0 -> tab z id_parent=0 -> czesta przyczyna fatala/zawieszenia instalatora.
INNE mozliwe przyczyny (sprawdzic jesli log nie wskaze taba): fatal w getContent(), problem z $this->l() przy budowie nazw tabow per jezyk, timeout/biale tlo z PHP fatal niezalogowany do PS logu.

## KROK 0 — ROZPOZNANIE (STOP po tym, jesli przyczyna niejednoznaczna)
1. git pull origin main. Przeczytaj modules/divezone_chat/divezone_chat.php + controllers/admin/AdminDivezoneChatController.php.
2. LOG PrestaShop sklepu (VPS divezone.pl): jesli masz dostep SSH do VPS sklepu — sprawdz var/logs/ (PrestaShop), log PHP/Apache (error_log) z momentu proby instalacji. Jesli NIE masz dostepu do VPS sklepu — POPROS Karola o wklejenie ostatnich linii var/logs/*.log oraz error_log PHP z czasu proby.
3. Sprawdz w MySQL prod (pr_): czy install zostawil osierocone wpisy:
   - pr_module (czy jest wiersz name='divezone_chat'),
   - pr_tab (class_name='AdminDivezoneChat'),
   - pr_tab_lang, pr_module_access / pr_authorization_role (PS 1.7 dodaje role dostepu do taba).
   Pokaz co znalazles.
4. POTWIERDZ klase rodzica: SELECT id_tab, class_name FROM pr_tab WHERE class_name IN ('AdminAdvancedParameters','AdminTools','AdminParentTools'); — ktora realnie istnieje w tym sklepie jako kontener "Zaawansowane".

Jesli log jednoznacznie wskazuje przyczyne -> przejdz do KROK 1. Jesli niejednoznaczne -> STOP, zaraportuj findings.

## KROK 1 — FIX (po ustaleniu przyczyny)
- Jesli potwierdzony zly parent: popraw TAB_PARENT na realna klase kontenera "Zaawansowane" w 1.7.6 (najpewniej AdminAdvancedParameters; uzyj tej ktora KROK 0.4 potwierdzil). Rozwaz fallback: jesli getIdFromClassName(parent)<=0 -> nie wieszaj, ustaw sensowny parent lub zwroc czytelny blad zamiast cichego id_parent=0.
- Jesli inna przyczyna: napraw zgodnie z findings (NIE dokladaj fixa taba "na wszelki wypadek" jesli to nie to — minimalna zmiana celujaca w realna przyczyne).
- installTab(): rozwaz zabezpieczenie idempotencji (jesli tab class juz istnieje -> nie duplikuj).
- Zachowaj kompatybilnosc PS 1.7.6 / PHP 7.2; unikaj konstrukcji wywalonych w PS 9.

## KROK 2 — SPRZATANIE OSIEROCONYCH WPISOW (jesli KROK 0.3 cos znalazl)
- Jesli sa osierocone wpisy w pr_module / pr_tab po nieudanej instalacji — usun je czysto (zeby ponowna instalacja nie natknela sie na duplikat). Pokaz DELETE ktore wykonujesz. Jesli baza czysta (zgodne ze zgloszeniem "brak na liscie") — pomin.

## KROK 3 — WALIDACJA
- CC NIE ma dostepu do panelu admin LIVE — pelny smoke instalacji wymaga rak Karola.
- Przygotuj dla Karola krotka instrukcje: (1) podmien modules/divezone_chat/ na poprawiony, (2) zainstaluj z panelu, (3) potwierdz ze instalacja konczy sie sukcesem (bez wiszacego kolka) i modul pojawia sie w menu Zaawansowane.
- Jesli masz mozliwosc — przetestuj install/uninstall przez PS CLI (bin/console / php -r z bootstrapem PS) na VPS sklepu, zeby zlapac fatal bez UI. Tylko jesli dostep pozwala.

## GIT
- KROK 0: brak commitu (diagnoza).
- KROK 1-2: git add modules/divezone_chat/<zmienione pliki> (+ ewentualny skrypt cleanup jesli powstal). Commit "T-033: fix instalacji modulu PS (parent tab + sprzatanie)" wg konwencji repo. git push origin main.
- Po deploy: osobny `docs:` commit ze statusem (_docs/21_STATUS_PROJEKTU.md). Handoff _instances/backend/handoff/ LOKALNY — NIE commituj.

## RAPORT KONCOWY
KROK 0: co pokazal log + stan bazy (osierocone wpisy / czysto) + potwierdzona klasa parenta -> jednoznaczna przyczyna.
Po fixie: co zmienione, instrukcja dla Karola do ponownej instalacji, wynik ewentualnego testu CLI.
