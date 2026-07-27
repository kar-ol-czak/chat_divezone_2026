# CHAT-T-137 WIDGET + GTM — atrybucja GA4: dataLayer + konfiguracja

**Instancja:** frontend (widget) + instrukcja GTM dla Karola.
**Swiat:** MODUL PS (newtmp2) — JS widgetu. Konfiguracja GTM/GA4 = po stronie Karola.
**ADR:** powiazany z ADR-119 (atrybucja). Jesli dochodzi decyzja implementacyjna — dopisz nota.
**Spec:** `_docs/12_atrybucja_czatu.md` sekcja 5. Przeczytaj przed praca.
**Zaleznosc:** rob PO CHAT-T-136 (deterministyczny to zrodlo prawdy; GA4 to wizualizacja).
**Karta Trello:** ta sama "Chat - sledzenie konwersji" — czesc GA4. Osobne domkniecie.

## Cel
Strumien GA4 (wizualizacja na probie zgod): widget emituje zdarzenia do dataLayer, GTM
przekazuje do GA4. Pozwala w GA4 porownac konwersje sesji z czatem vs bez.

## Zakres

### A. Emisja do dataLayer (JS widgetu)
W modules/divezone_chat/views/js/ dodaj emisje:
- `chat_engaged` — RAZ, gdy rozmowa realnie sie zaczela (pierwsza wymiana wiadomosci,
  NIE na samo otwarcie widgetu). Parametry: `session_id`.
- `chat_product_click` — przy klinieciu w link produktowy w tresci odpowiedzi czatu.
  Parametry: `session_id`, `url`, `product_id` jesli da sie wyciagnac z linku (slug/id).
Wzor emisji:
```
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({ event: 'chat_engaged', session_id: sid });
```
Uwaga: linki produktowe w czacie sa renderowane w Shadow DOM — upewnij sie, ze listener
klikniec lapie je wewnatrz shadow roota (event delegation na kontenerze widgetu).

### B. Instrukcja GTM/GA4 dla Karola (napisz jako czesc raportu / osobny plik)
Krok po kroku:
1. GTM: dwa triggery Custom Event: `chat_engaged`, `chat_product_click`.
2. GTM: dwa tagi GA4 Event wpiete w te triggery, przekazujace parametry (session_id, url,
   product_id).
3. GA4: rejestracja wymiarow niestandardowych (session_id itd.) jesli maja byc w raportach.
4. GA4: eksploracja/segment — sesje z `chat_engaged` vs bez, konwersje `purchase` w kazdym.
Zaznacz: Consent Mode v2 aktywny → GA4 liczy tylko zgody → liczby BEDA nizsze niz w tabeli
deterministycznej (T-136). To oczekiwane; zrodlo prawdy = tabela.

## Kryteria akceptacji
1. Start rozmowy → `chat_engaged` w dataLayer (widoczne w GTM Preview).
2. Klik linku produktowego w czacie → `chat_product_click` z url (+ product_id jesli mozliwe).
3. Eventy lapane mimo Shadow DOM (delegation dziala).
4. Instrukcja GTM na tyle konkretna, ze Karol skonfiguruje bez zgadywania.

## Deploy (MODUL PS, newtmp2)
Reczny rsync Karola + cache (var/cache/prod + LSCache). Weryfikacja: md5 JS prod==local +
grep markera CHAT-T-137. Konfiguracje GTM robi Karol wg instrukcji (poza deployem kodu).

## Git
`git add` per sciezka (JS widgetu + ewentualny plik instrukcji GTM); commit
`CHAT-T-137 widget: dataLayer chat_engaged/product_click do GA4 (ADR-119)`; push.
Po deployu osobny docs: commit.

## Domkniecie
Po wdrozeniu JS + potwierdzeniu w GTM Preview: karta → "Do weryfikacji"; po konfiguracji
GTM przez Karola i zobaczeniu eventow w GA4 → "Zrobione".
