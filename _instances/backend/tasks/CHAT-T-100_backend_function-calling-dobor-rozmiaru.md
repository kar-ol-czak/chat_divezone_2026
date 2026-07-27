# CHAT-T-100 — INSTANCJA: backend — Function calling: dobór rozmiaru skafandra (recommend_wetsuit_size)

> **Powiązane:** handoff `_instances/embeddings/handoff/HANDOFF_CHAT-T-099_function-calling-rozmiar.md` (PEŁNY kontrakt — przeczytaj w całości, łącznie z ADDENDUM 099b). ADR-099 (algorytm, deterministyka), ADR-098 (uniwersalny przez regulację). Warstwa danych GOTOWA na Railway (migracje 035+036, 67 mapowań, chart dziecięcy, aliasy).
> **Charakter:** implementacja w standalone PHP. Port gotowej logiki Python → PHP. Bez nowych decyzji architektonicznych — wszystko rozstrzygnięte w handoffie.

## ⚠️ DWIE RZECZY NA START
1. **Ścieżka projektu**: dysk bywa montowany jako `/Users/karol/...` LUB `/Volumes/karol/...` (ten sam `.git`). Jeśli jedna ścieżka daje ENOENT — użyj drugiej.
2. **Handoff jest gitignored** — NIE dostaniesz go przez `git pull`. Odczytaj lokalnie z dysku: `_instances/embeddings/handoff/HANDOFF_CHAT-T-099_function-calling-rozmiar.md`. To źródło prawdy dla tego taska.

## CEL
Dodać do standalone backendu narzędzie function calling `recommend_wetsuit_size` (deterministyczny dobór rozmiaru skafandra mokrego) + reguły SystemPrompt. Logika MA być parytetem referencyjnego `embeddings/size_matcher.py` (przedziałowy dla dorosłych + punktowy dla dzieci).

## ⚠️ NIE embeddingi
Lookup relacyjny SQL (`BETWEEN` dla dorosłych, dokładne/najbliższe dla punktowych dzieci). Zero wektoryzacji (ADR-099 pkt 1).

## KROKI

**KROK 0 — pull/read.**
- `git pull origin main`
- Przeczytaj w CAŁOŚCI: handoff CHAT-T-099 (+ADDENDUM 099b) — ścieżka wyżej.
- Przeczytaj: ADR-099 i ADR-098 w `_docs/10_decyzje_projektowe.md`.
- Obejrzyj referencję: `embeddings/size_matcher.py` (`match_size()` + `match_pointwise()`).
- Sprawdź jak zarejestrowane są istniejące toole: `standalone/config/tools.php` + interfejs `ToolInterface` (wzoruj się na istniejącym toolu, np. wyszukiwarka produktów).
- Zweryfikuj realną nazwę klas/namespace w standalone (NIE zakładaj `DiveChat\Tools\` — sprawdź faktyczny namespace istniejących tooli).

**KROK 1 — tool SizeRecommender.**
- Utwórz klasę narzędzia wg kontraktu z handoffu sekcja 1 (getName=`recommend_wetsuit_size`, getDescription, getParametersSchema = JSON Schema z handoffu).
- Wstrzyknij połączenie PG (jak inne toole korzystające z Railway).

**KROK 2 — wybór charta.**
- Z `product_id` → JOIN `divechat_product_size_chart` → `divechat_size_charts`.
- Gdy brak product_id → po (`brand`, `gender`).
- `gender` ZAWSZE z parametru (od klienta), nadpisuje płeć produktu przy bi-gender/unisex.
- Produkt bi-gender (dwa mapowania M+K): `gender` klienta wybiera właściwy chart.

**KROK 3 — algorytm (port 1:1 z size_matcher.py).**
- **Chart przedziałowy** (dorośli, min≠max): algorytm z handoffu sekcja 2 / ADR-099 pkt 4. Klatka wiodąca → weryfikacja → match / ambiguous / out_of_scale.
- **Chart punktowy** (dzieci, min==max, wymiar `height`): `match_pointwise` z ADDENDUM. Wiodący `height` NIE `chest`. Trafienie dokładne→match; między dwoma→boundary (dwa najbliższe + `graniczny:true`); poza→out_of_scale.
- Detekcja typu charta: jeśli wiersze mają min==max (lub gender='DZIECI') → punktowy. Zaproponuj czysty sposób rozróżnienia spójny z danymi.
- Output JSON wg handoffu (decision/sizes/size_full/consult/reason/brand/gender). Dla dzieci dołóż `graniczny`.

**KROK 4 — normalizacja etykiet.**
- Regex `^(\d+)\s*Plus$` → `\1+` (Bare plus).
- Warstwa aliasów: czytaj `divechat_size_label_alias` (resolve_label/load_aliases), wzoruj na `map_size_products.py`. To dla matchu rozmiaru do oferty/prezentacji.

**KROK 5 — reguły SystemPrompt (komponent metodologiczny).**
Dodaj do SystemPrompt (znajdź gdzie składany jest prompt systemowy w standalone):
- Płeć: ZAWSZE pytaj „dla kobiety czy mężczyzny?" przed wywołaniem narzędzia. Nie zgaduj.
- Klatka wiodąca: brak obwodu klatki (dorośli) → poproś.
- Suche skafandry: NIE używaj narzędzia, kieruj do konsultacji (kat. suchych — zweryfikuj realne id kategorii suchych, handoff wspomina 205/477).
- out_of_scale / ambiguous: dwa najbliższe + konsultacja, zero zgadywania.
- F.1 dobór dziecięcy (przyleganie, „na wyrost" tylko jako informacja), F.2 kaptur Rebel (wymień+konsultacja), F.3 jacket Rebel (uniwersalny przez regulację pasa). Pełne brzmienie w ADDENDUM.

**KROK 6 — rejestracja.**
- `standalone/config/tools.php`: zarejestruj nowy tool (wzór z istniejących).
- Upewnij się, że jest widoczny w pętli function calling OBU providerów (Anthropic + OpenAI).

**KROK 7 — testy (acceptance z handoffu).**
- Parytet z size_matcher.py na zestawie testowym (ten sam input → ten sam wynik).
- chest 104 / Scubapro M → „L"; chest 200 → out_of_scale (4XL/3XL); brak płci → bot pyta.
- Dziecięce: wzrost 134 → [S,M] graniczny; 140 → M; 170 → out_of_scale.
- Suchy skafander → konsultacja, narzędzie NIE wywołane.
- Testuj REALNĄ ścieżką aplikacji (function calling przez ChatService), nie tylko unit. Zasada projektu: testy przez prawdziwą ścieżkę, nie substytuty.

**KROK 8 — deploy (⚠️ STOP wg ADR-089).**
- To zmiana w standalone → deployment per ADR-089: backup do `_deploy_bak/`, md5 verify, `php -l`, smoke `/api/health`, STOP przed rsync, czekaj na akceptację Karola.
- Pamiętaj: git push ≠ deploy. Push do repo to osobny krok od rsync na serwer.

**KROK 9 — status + raport.**
- `git status` → `git add` per ścieżka (tool, tools.php, zmiany SystemPrompt, testy; BEZ handoff/gitignored) → commit wg konwencji (sprawdź `git log`) → `git push origin main`.
- Po deploy (po akceptacji): osobny commit `docs:` ze statusem `_docs/21_STATUS_PROJEKTU.md`.
- Raport: nazwy utworzonych klas/plików, wynik parytetu z size_matcher.py, wyniki testów acceptance, potwierdzenie rejestracji w obu providerach, punkt STOP deploy.

## HARD STOP
- Deploy na produkcję `chat.divezone.pl` (KROK 8) — STOP przed rsync, akceptacja Karola.

## POZA ZAKRESEM
- Warstwa 2 (kalkulator na stronie czytający te same tabele) — osobny projekt dev (Janek).
- Dzieci Bare (Guppy/Tadpole), produkty z listy Janka — czekają na dane/poprawki źródłowe.
