# Prompt startowy do nowej konwersacji — kontynuacja hotfix divezone chat

**Data:** 2026-05-14, koniec sesji architekt #4
**Powód przeniesienia:** konwersacja zbyt długa, ryzyko zgubienia detali

---

## Instrukcja dla Karola

Wklej poniższy tekst jako pierwszą wiadomość w nowej konwersacji (claude.ai web, ten sam Project "Chat dla klientów 2026"):

---

```
Kontynuujemy projekt chat divezone.pl. Poprzednia konwersacja była zbyt długa, przenosimy się.

Najpierw przeczytaj 3 pliki dla kontekstu:
1. _docs/21_STATUS_PROJEKTU.md (cały - sekcja "AKTUALNY STAN" na samej górze)
2. _docs/10_decyzje_projektowe.md (sekcje ADR-053, ADR-054, ADR-055 z końca pliku)
3. _instances/backend/tasks/T-003_backend_systemprompt-v3.md (gotowy do puszczenia)

Twoja rola: architekt projektu czatu AI dla divezone.pl. Nie kodujesz - od kodowania jest Claude Code (CC) w osobnych instancjach. Robisz: decyzje architektoniczne, code review, planowanie tasków, specyfikacje, ADRs.

Konwencja (od 14.05):
- Każdy nowy task = nowy numer narastający T-NNN
- Nazwa pliku: T-NNN_INSTANCJA_krotki-opis.md w _instances/{instancja}/tasks/
- Cała treść tasku w pliku, prompt CC w czacie tylko 3 linie typu "wykonaj plik X"
- Każdy prompt CC ma nagłówek z numerem tasku + nazwą instancji (>>> T-NNN — INSTANCJA: backend <<<)
- Git workflow w każdym prompcie CC: git status, add per ścieżka, commit per konwencja (T-NNN: opis), push origin main
- Przy każdym pytaniu zadawaj swoją rekomendację
- Polski, zwięźle, bez ścian tekstu
- Numeracja pytań: kontynuujemy od 58 (ostatnie 57 z poprzedniej konwersacji)

Status w skrócie (szczegóły w 21_STATUS_PROJEKTU.md):
- 3 instancje CC aktywne: frontend (007c follow-up DEPLOYED, weryfikacja przez UI), embeddings (T-001 DONE), backend (T-003 czeka na puszczenie)
- 3 follow-up bugi po smoke teście T-002 (available_to_order, linkowanie, płeć) → rozwiąże T-003
- Wstrzymane: TASK-CHAT-009 Editorial Picks (ADR-054), TASK-CHAT-014 audyt EXCLUDED_CATEGORY_IDS, T-XXX D1 ETL
- Nieodpowiedziane pytania z poprzedniej sesji: 56 (T-004 refresh_stock cron), 57 (T-005 SynonymExpander fix)

Pierwsze co masz zrobić: potwierdź że przeczytałeś 3 pliki, w 2-3 zdaniach podsumuj gdzie jesteśmy, i czekaj na moje instrukcje. NIE rozpoczynaj żadnego nowego tasku samowolnie.
```

---

## Co już mam zapisane w memory (przeniesie się do nowej konwersacji automatycznie)

1. Konsultuj wybór modelu AI przed użyciem
2. Poprawiona mapa marek w `_docs/11_mapa_marek-reviewed.md`
3. INT/yoke to martwy standard, nie traktować jako równorzędnego DIN
4. Standard workflow CC: prompt w czacie z nagłówkiem numer+instancja
5. Git workflow w promptach CC
6. Przy każdym pytaniu dodawać własną rekomendację
7. Konwencja T-NNN: każdy task nowy numer, format pliku, prompt 3 linie

## Pliki kluczowe które nowy Claude powinien znać

- `_docs/21_STATUS_PROJEKTU.md` - aktualny stan, kolejka tasków, ostatnie sesje
- `_docs/10_decyzje_projektowe.md` - wszystkie ADRy (ostatnie: ADR-053, 054, 055)
- `_docs/22_red_team_panel.md` - panel red-team ataków na bot
- `_docs/23_red_team_konsolidacja.md` - konsolidacja 45 ataków z testów
- `_instances/backend/tasks/T-003_backend_systemprompt-v3.md` - następny task do puszczenia
- `_instances/backend/handoff/T-002_done.md` - ostatni zakończony task backend
- `_instances/embeddings/handoff/T-001_done.md` - ostatni zakończony task embeddings (jeśli istnieje)
- `_instances/backend/handoff/TASK-CHAT-011_done.md` - get_shop_schedule fix
- `standalone/src/Chat/SystemPrompt.php` - obecna wersja produkcyjna prompta

## Jak działa projekt — przypomnienie

- **Stack:** PrestaShop 1.7.6 (prefix `pr_`, MySQL), standalone PHP 8.4 chat.divezone.pl, Railway PostgreSQL z pgvector, GPT-4.1 jako default LLM
- **Repo Git:** `git@github.com:kar-ol-czak/chat_divezone_2026.git`, branch main
- **Ścieżka prod:** `/home/divezone/public_html/chat.divezone.pl/`
- **SSH prod:** divezonededyk.smarthost.pl port 5739 user divezone, key `~/.ssh/id_ed25519`
- **Konwencja commitów:** `T-NNN: opis` lub historycznie `TASK-CHAT-NNN: opis` lub `docs: opis`

## Po jakim czasie zacząć nową konwersację

Jeśli kolejna sesja architekta przekroczy podobny rozmiar (kilkadziesiąt długich wiadomości, 5+ instancji CC obsłużonych), powtórzyć ten proces:
1. Architekt aktualizuje `21_STATUS_PROJEKTU.md`
2. Architekt aktualizuje ten plik (`24_prompt_kontynuacja_hotfix.md` → kolejny numer)
3. Karol wkleja prompt startowy w nowej konwersacji
