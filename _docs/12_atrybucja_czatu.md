# 12. Atrybucja czatu — konwersje i sprzedaz z linkow DiveChat

Dokument specyfikacyjny. Cel: mierzyc, czy klienci klikaja w linki proponowane przez
czat i czy prowadzi to do sprzedazy. Powiazany ADR-119. Decyzje bazowe: 34-40 (sesja
2026-07-01) + 34b/35c (sesja 2026-07-14).

## 1. Problem

Dzis brak mechanizmu pokazujacego w Google Analytics, czy klienci klikaja w linki
podawane przez czat i czy jest z tego sprzedaz. Trzeba dodac atrybucje: powiazac
rozmowe czatu z klinieciem w link produktowy i z finalnym zamowieniem.

## 2. Architektura — dwa strumienie

### Strumien 1 — DETERMINISTYCZNY (zrodlo prawdy)
Odporny na Consent Mode v2 (ktory na sklepie jest aktywny — GA4 systematycznie zaniza,
wiec backend jest KONIECZNOSCIA, nie opcja; decyzja 37a).

- Widget zapisuje `divechat_session_id` do cookie w domenie sklepu (divezone.pl).
  Mozliwe bez cross-origin, bo widget zyje w DOM sklepu (Shadow DOM, ADR-061; nie iframe),
  wiec cookie/localStorage sa w domenie sklepu.
- Hook PrestaShop przy zamowieniu (`actionValidateOrder`) czyta cookie i zapisuje pare
  `id_order` <-> `chat_session_id` do tabeli `pr_divechat_order_attribution`.
- Cookie to funkcjonalne powiazanie zamowienia, nie analityczne — Consent Mode go nie blokuje.
- Laczy sie z Subiektem po `id_order` (realna sprzedaz, marze, zwroty — czego GA4 nie zna).

### Strumien 2 — GA4 (wizualizacja na probie zgod)
- Widget emituje do dataLayer: `chat_engaged` (rozmowa sie odbyla) i `chat_product_click`
  (klikniecie w link produktowy z czatu).
- GTM przekazuje do GA4. Segment/porownanie: konwersje sesji z czatem vs bez.
- GA4 pokazuje trend i proporcje na probie uzytkownikow, ktorzy zgodzili sie na analytics.

## 3. Czas zycia cookie (decyzja 34b)

`divechat_session_id` = **persistent 30 dni**. Obsluguje oba modele atrybucji jednym
mechanizmem: last_touch (rozmowa w tej samej sesji co zamowienie) i assist (rozmowa we
wczesniejszej wizycie, klient wrocil kupic). Rozroznienie po timestampach (patrz 4).
30 dni = standardowe okno atrybucji marketingowej, spojne z GA4.

## 4. Tabela `pr_divechat_order_attribution` (MySQL PrestaShop)

W MySQL PrestaShop (prefix `pr_`), bo hook zamowienia i `id_order` zyja po stronie MySQL.

| kolumna | typ | opis |
|---|---|---|
| id_attribution | INT AUTO_INCREMENT PK | |
| id_order | INT, indeks | zamowienie PrestaShop |
| chat_session_id | VARCHAR(64), indeks | session_id rozmowy (z cookie) |
| attribution_type | ENUM('last_touch','assist') | patrz nizej |
| conversation_last_at | DATETIME NULL | czas ostatniej wiadomosci rozmowy (jesli znany) |
| date_add | DATETIME | czas zapisu (moment zamowienia) |

Rozroznienie `attribution_type`:
- `last_touch` — cookie zapisane w tej samej sesji przegladarki co zamowienie (rozmowa
  i zakup w jednej wizycie).
- `assist` — cookie z wczesniejszej wizyty (rozmowa byla, klient wrocil pozniej kupic).
Sygnal: por. czasu utworzenia cookie / ostatniej rozmowy z czasem zamowienia. Prosty wariant
na start: jesli cookie ma znacznik czasu rozmowy z tej samej sesji → last_touch, inaczej assist.

Uwaga RODO: `chat_session_id` to identyfikator techniczny rozmowy, nie dane osobowe.
Nie zapisujemy tresci rozmowy w tej tabeli — tylko powiazanie id.

## 5. Zdarzenia GA4 (dataLayer)

- `chat_engaged` — emitowane raz, gdy rozmowa realnie sie zaczela (pierwsza wymiana),
  nie na samo otwarcie widgetu.
- `chat_product_click` — emitowane przy klinieciu w link produktowy w tresci czatu;
  parametry: `product_id` (jesli da sie wyciagnac z linku), `session_id`, `url`.
GTM: triggery na te dwa eventy + tag GA4. Konfiguracja GTM po stronie Karola (T-137 daje
instrukcje krok po kroku).

## 6. Podzial na taski (decyzja 35c)

- **CHAT-T-136** (moduł PS): strumien deterministyczny — cookie w widgecie + hook
  `actionValidateOrder` + tabela `pr_divechat_order_attribution`. Zrodlo prawdy, wartosc
  sama w sobie (laczenie zamowien z rozmowami i Subiektem) nawet bez GA4.
- **CHAT-T-137** (widget + GTM): strumien GA4 — emisja `chat_engaged` / `chat_product_click`
  do dataLayer + instrukcja konfiguracji GTM/GA4 dla Karola.

Kolejnosc: T-136 pierwszy (zrodlo prawdy). T-137 po nim (wizualizacja).

## 7. Weryfikacja poprawnosci

- Deterministyczny: po zamowieniu testowym z aktywna rozmowa — rekord w
  `pr_divechat_order_attribution` z poprawnym id_order + chat_session_id + typem.
- Zgodnosc: liczba atrybuowanych zamowien z tabeli >= liczba z GA4 (GA4 zaniza przez
  Consent Mode — jesli GA4 pokazuje wiecej, cos jest zle).
- Krzyzowo z Subiektem po id_order: realna wartosc sprzedazy z czatu.
