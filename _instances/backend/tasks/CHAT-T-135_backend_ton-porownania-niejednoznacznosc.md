# CHAT-T-135 BACKEND — ton wobec sfrustrowanego + porownania + niezakladanie przy niejednoznacznosci

**Instancja:** backend
**Swiat:** BACKEND standalone (chat.divezone.pl). Wylacznie SystemPrompt.php. ZERO zmian w PS.
**ADR:** ADR-118 (ostatni w pliku ADR-111; 112-116 zajete, 117 zarezerwowany dla CHAT-T-134 —
bierz 118; potwierdz w pliku przed zapisem).
**Karty Trello:** "Chat - Ton wobec sfrustrowanego/wrogiego klienta" (6) + "Chat - Porownania
produktow" (5). Obie → "W trakcie" na start; oba problemy w jednym tasku, jeden deploy.
**Decyzje Karola:** ton (kierunek z notatki conv 634), 33b (niejednoznacznosc: zaproponuj
najprawdopodobniejsza interpretacje + jawnie wskaz alternatywe, nie czyste pytanie, nie slepe zalozenie).

## Trzy reguly do dodania (styl jak reszta SystemPrompt: regula + "Bug do unikniecia (conv N)")

### 1. TON WOBEC SFRUSTROWANEGO / WROGIEGO KLIENTA (conv 634)
Objaw: bot otwiera "Rozumiem frustracje" (deklaracja rozumienia cudzych uczuc — zle) i dokłada
"napisz na maila / zadzwon" klientowi, ktory jest zirytowany SAMYM czatem.
Regula:
- NIE deklaruj rozumienia cudzych uczuc ("rozumiem frustracje", "wiem jak sie czujesz").
- Gdy klient jest zirytowany samym czatem/kontaktem: krotko przepros, wskaz ze wystarczy
  zamknac okno (X w rogu) — NIE wciskaj maila/telefonu jako "rozwiazania".
- Zwiezle, bez psychologizowania. Bug do uniknięcia (conv 634): "nie prościej byłoby nie
  molestować ludzi" → bot: "Rozumiem frustrację" + kontakt mailowy. Zle na dwoch poziomach.

### 2. POROWNANIA — WLASCIWA PARA + zasada domenowa pianek (conv 608)
Objaw: klientka pyta "ktora cieplejsza: Scubapro 7mm czy Bare 7mm" (dwie POJEDYNCZE pianki
7mm). Bot do porownania wzial komplet 7+6mm (13mm) zamiast pojedynczej 7mm → "Scubapro
cieplejszy". Nieuczciwe (inna klasa produktu).
Regula:
- Porownuj produkty tej samej KLASY/konfiguracji. Nie zestawiaj pojedynczej pianki z kompletem,
  automatu z zestawem itd. Jesli search zwraca rozne konfiguracje — wybierz odpowiednik tego,
  o co klient pyta, albo dopytaj (patrz regula 3).
- Zasada domenowa: przy piankach o TEJ SAMEJ grubosci o cieple decyduje DOPASOWANIE i
  szczelnosc, nie marka. Nie orzekaj "X cieplejszy" na podstawie samej marki przy rownej grubosci.
- Bug do uniknięcia (conv 608): porownanie 7mm vs komplet 7+6mm + werdykt po marce.

### 3. NIEJEDNOZNACZNOSC INTENCJI — nie zakladaj, zaproponuj + wskaz alternatywe (conv 584 + 608)
Objaw: klient pyta "co jeszcze jest niezbedne?" (po pytaniu o zestaw Peregrine). Bot ZALOZYL
najszersza interpretacje ("caly sprzet nurkowy") i rozpisal wszystko, zamiast dopytac/zaznaczyc.
Regula (33b):
- Gdy pytanie ma wyraznie ROZNE interpretacje prowadzace do roznych odpowiedzi: zaproponuj
  najprawdopodobniejsza interpretacje ORAZ krotko wskaz alternatywe, tak by klient mogl łatwo
  skorygowac. NIE zakładaj po cichu najszerszej wersji. NIE zamieniaj tez kazdego pytania w
  czyste "co masz na mysli" — to tylko przy realnej wieloznacznosci.
- Wzor (conv 584): "Do samego Peregrine nie potrzebujesz nic wiecej — dziala od razu. Jesli
  natomiast kompletujesz caly sprzet od zera, daj znac — rozpisze co jeszcze bedzie potrzebne."
- Bug do uniknięcia (conv 584): "co jeszcze niezbedne?" → bot wysypal caly sprzet nurkowy.

## WAZNE — brak kolizji z istniejacymi regulami
- Regula 3 dotyczy niejednoznacznosci INTENCJI. NIE osłabia istniejacej "nie dopytuj o
  parametry pod uzywany sprzet" (linie ~328-330) ani "MAJAC BUDZET nie pytaj ponownie" (~384).
  Tam odpowiedz jest jasna — dopytanie byloby bledem. Regula 3 wchodzi tylko gdy interpretacje
  realnie sie rozchodza. Zaznacz to w tekscie reguly, by model nie zaczal nadgorliwie dopytywac.

## Kryteria akceptacji (test PROD przez realny czat)
1. Wrogi/zirytowany komunikat o czacie → bot NIE mowi "rozumiem frustracje", NIE wciska
   maila/telefonu; krotko przeprasza, wskazuje X do zamkniecia.
2. "Ktora cieplejsza, pianka 7mm A czy 7mm B" → bot porownuje pojedyncze 7mm (nie komplet),
   nie orzeka po samej marce, wspomina o dopasowaniu.
3. Pytanie niejednoznaczne typu "co jeszcze niezbedne" po pytaniu o konkretny produkt →
   bot proponuje najwezsza sensowna interpretacje + wskazuje alternatywe, nie wysypuje wszystkiego.
4. Regresja: "mam budzet X" nadal bez ponownego pytania o budzet; "uzywana butla" nadal bez
   dopytywania o parametry (istniejace reguly niezlamane).
5. php -l ea-php84 clean.

## Deploy (ADR-089 — STOP przed rsync, jawne "deployuj")
Backend: rsync SystemPrompt.php → chat.divezone.pl/src/Chat/ + backup + md5 + php -l + smoke
/api/health. NIE deployowac config/tools.php (dryf repo≠prod). Test PROD: 4 scenariusze z kryteriow.

## Git
`git add` per sciezka (SystemPrompt.php + ADR); commit
`CHAT-T-135 backend: ton sfrustrowany + porownania wlasciwej pary + niejednoznacznosc (czat 634/608/584, ADR-118)`;
push. Po deployu osobny docs: commit (status).

## Domkniecie
Po zweryfikowanym deployu: obie karty (5 i 6) → "Zrobione"; rozmowy 634, 608, 584 →
problem_rozwiazany (updated_by=NULL + marker), wg procedury _docs/42.

## Wynik — DONE 2026-07-14

**Zmiany (commit `2590da7`, ADR-118):** trzy sekcje w SystemPrompt.php (+~3,5 tys.
znakow, prompt 96,4 tys.):
1. TON WOBEC SFRUSTROWANEGO / WROGIEGO KLIENTA — po bloku ZASADY.
2. POROWNANIA PRODUKTÓW — WŁAŚCIWA PARA (+ zasada pianek: rowna grubosc →
   dopasowanie/szczelnosc, nie marka) — po FORMAT ODPOWIEDZI PRODUKTOWEJ.
3. NIEJEDNOZNACZNOŚĆ INTENCJI (33b) — bezposrednio po "UŻYJ PODANEGO PARAMETRU",
   z jawna GRANICA (nie oslabia regul o niedopytywaniu: budzet C5/D4 ~384,
   uzywany sprzet case 77 ~328-330). Regula 2 odsyla do reguly 3 przy
   wieloznacznosci pary.

**Deploy:** TYLKO SystemPrompt.php → chat.divezone.pl/src/Chat/ (backup
`_deploy_bak/20260714_T135/`, brak dryfu przed deployem, ea-php84 -l czysty,
md5 zgodne, 3 markery CHAT-T-135, /api/health 200). tools.php NIETKNIETY.

**Test PROD (realny czat /api/chat, 4 scenariusze — wszystkie PASS):**
1. "nie prosciej byloby nie molestowac ludzi tym czatem?" → "Przepraszam za
   niedogodnosc. Okno czatu mozesz w kazdej chwili zamknac krzyzykiem w rogu —
   nie bedzie przeszkadzac." (zero "rozumiem frustracje", zero maila/telefonu).
2. "Ktora cieplejsza: Scubapro 7mm czy Bare 7mm?" → (po dopytaniu o plec — istniejaca
   regula) porownanie DWOCH POJEDYNCZYCH 7mm damskich (Definition vs Nixie Ultra),
   wprost "przy tej samej grubosci zadna marka nie jest z definicji cieplejsza,
   decyduje dopasowanie i szczelnosc" + roznice konstrukcyjne z opisow + CTA przymiarka.
3. Peregrine → "co jeszcze jest niezbedne?" → "Do samego Peregrine'a nie potrzebujesz
   nic — dziala od razu (...) Jesli natomiast kompletujesz caly sprzet od zera, daj
   znac — rozpisze co bedzie potrzebne." (waska interpretacja + alternatywa, wzor 33b).
4. Regresja: "automat, budzet 3500 zl" → bez ponownego pytania o budzet, dobor pod
   gorna granice (XTX50/DST 3133 jako wiodacy, ATX40 jako oszczednosciowa — ADR-114 OK);
   "uzywane butle?" → "wylacznie nowy sprzet", bez dopytywania pod uzywana.

**Domkniecie wykonane:** rozmowy 584, 608, 634 → verdict='problem_rozwiazany',
status='zamkniety', updated_by=NULL, marker w note (guard idempotentny, proc. _docs/42).
Karty Trello 5 i 6 → "Zrobione" (byly juz w "W trakcie" — przesuniete przez Karola).
