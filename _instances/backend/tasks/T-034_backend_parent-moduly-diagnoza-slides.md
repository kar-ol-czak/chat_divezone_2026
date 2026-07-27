# T-034 — BACKEND: Parent taba modulu = Moduly + diagnoza AdminConfigureSlides

**Data:** 2026-06-01
**Instancja:** backend
**Wejscie:** T-033 (fix instalacji, parent ustawiony na AdminAdvancedParameters), zlecenie Karola: (1) wepnij tab pod menu "Moduly" zamiast "Zaawansowane"; (2) zajmij sie nieaktywnym tabem AdminConfigureSlides.
**PROD:** PrestaShop docroot /home/divezone/public_html/newtmp2/ (zmienna PROD_PATH w .env). To PRODUKCJA.

---

## CZESC 1 — parent taba divezone_chat: Zaawansowane -> Moduly

### KROK 0
- git pull origin main. Przeczytaj modules/divezone_chat/divezone_chat.php (stale TAB_PARENT_PRIMARY/FALLBACK z T-033).
- Potwierdz realna klase kontenera "Moduly" w pr_tab (tak jak T-033 potwierdzil AdminAdvancedParameters=id 103):
  SELECT id_tab, class_name, id_parent, active FROM pr_tab WHERE class_name IN ('AdminParentModulesSb','AdminModulesSf','AdminParentModules','AdminModules','AdminModulesManage') ORDER BY id_parent;
  (w PS 1.7.6 kontener menu "Moduly" to zwykle AdminParentModulesSb; potwierdz empirycznie, NIE zakladaj).

### KROK 1
- Zmien TAB_PARENT_PRIMARY na potwierdzona klase kontenera "Moduly". Zachowaj wzorzec z T-033: fallback chain + jawny error_log gdy getIdFromClassName<=0 (NIE cichy id_parent=0). FALLBACK moze zostac AdminAdvancedParameters (sensowny zapas gdyby "Moduly" nie bylo dostepne).
- Idempotencja installTab() bez zmian (juz jest z T-033).
- Kompatybilnosc PS 1.7.6 / PHP 7.2; unikac konstrukcji wywalonych w PS 9.

## CZESC 2 — diagnoza AdminConfigureSlides (BRAMKA, NIE usuwaj jeszcze)

Karol zglasza ze tab AdminConfigureSlides nie dziala i chce go usunac. ZANIM cokolwiek skasujemy na produkcji — ustal stan i pokaz Karolowi. ZERO DELETE w tym kroku.

### KROK 2 — rozpoznanie (STOP po tym)
- pr_tab: SELECT id_tab, class_name, module, id_parent, active FROM pr_tab WHERE class_name='AdminConfigureSlides';
- Z jakiego modulu pochodzi (kolumna module). Sprawdz czy ten modul jest zainstalowany i aktywny: SELECT id_module, name, active FROM pr_module WHERE name = '<module z taba>';
- Czy plik kontrolera istnieje na dysku: controllers/admin/AdminConfigureSlidesController.php w katalogu tego modulu (PROD_PATH/modules/<module>/).
- pr_tab_lang dla tego id_tab (czy ma nazwy jezykowe).
- Ustal kategorie: (A) tab OSIEROCONY (modul odinstalowany/usuniety, zostal sam wpis) -> bezpieczny DELETE; (B) tab NALEZY do zainstalowanego modulu (slider dziala czesciowo) -> czysta droga to deinstalacja modulu z panelu, NIE reczny DELETE.
- STOP. Zaraportuj kategorie (A/B) + dane. Czekaj na decyzje Karola co do usuniecia.

### KROK 3 — usuniecie (TYLKO po akceptacji Karola, zaleznie od kategorii)
- Jesli (A) osierocony: backup wiersza (zapisz SELECT do pliku/raportu), nastepnie DELETE z pr_tab + pr_tab_lang dla tego id_tab. Pokaz dokladne DELETE przed wykonaniem.
- Jesli (B) modul zainstalowany: NIE rob recznego DELETE. Zarekomenduj Karolowi deinstalacje modulu slidera z panelu PS (usunie swoje taby sam). Opisz ktory to modul.

## GIT
- CZESC 1 (kod modulu): git add modules/divezone_chat/divezone_chat.php -> commit "T-034: parent taba modulu = Moduly (AdminParentModulesSb) + zachowany fallback" -> push.
- CZESC 2 KROK 2: brak commitu (diagnoza). KROK 3 jesli DELETE: ewentualny skrypt/backup commitowany osobno wg decyzji.
- Po deploy: osobny `docs:` commit ze statusem. Handoff _instances/backend/handoff/ LOKALNY — NIE commituj.

## RAPORT
CZESC 1: potwierdzona klasa parenta "Moduly" + instrukcja dla Karola do (re)instalacji modulu (zip/scp jak T-033).
CZESC 2: kategoria A/B taba AdminConfigureSlides + dane -> STOP na decyzji Karola.
