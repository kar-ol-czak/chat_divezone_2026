# TASK-010: Knowledge pipeline — nurkomania.pl + nowe Q&A
# Data: 2026-02-20
# Instancja: embeddings
# Priorytet: WYSOKI (wpływa na jakość doradztwa)
# Status: DO ZROBIENIA

## Cel
Zbudować bazę wiedzy eksperckiej z nurkomania.pl (encyklopedia nurkowania)
i osadzić ją + nowe wpisy QA-038/039/040 w pgvector.

## Zasoby
- Istniejące scrapery: `/Users/karol/Documents/3_DIVEZONE/Aplikacje/Scraper Nurkomania.pl/`
- Scraper sprzętowy: `/Users/karol/Documents/3_DIVEZONE/Aplikacje/Scraper_nurkomania_sprzet/`
- Gotowe dane: `sprzet.json`, `sprzet_do_nurkowania.json`, `sprzet_do_nurkowania.txt`
- Nowe Q&A do osadzenia: `_docs/04_qa_baza_wiedzy.md` (QA-038, QA-039, QA-040 o płetwach)

## Priorytetowe podstrony nurkomania.pl
1. Jak wybrać maskę: nurkowanie_maska_wybor.htm
2. Jak wybrać płetwy: nurkowanie_pletwy_wybor.htm
3. Jak wybrać automat: wybieramy_automat_oddechowy.htm
4. Jak wybrać skafander suchy: nurkowanie_sprzet_skafander_suchy_jak_wybrac.htm
5. Jak wybrać skafander mokry: sprzet_jak_wybrac_skafander_mokry.htm
6. Komputery nurkowe: nurkowanie_komputer_nurkowy.htm
7. BCD/skrzydła: nurkowanie_system_wypornosciowy.htm

## Pipeline
Scraper → czyszczenie HTML → chunking → review (Karol) → embedding → divechat_knowledge

## Architektura
Patrz ADR-010, ADR-018 w _docs/10_decyzje_projektowe.md
