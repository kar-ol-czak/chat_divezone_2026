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
