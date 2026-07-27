# CHAT-T-060 — FRONTEND/PS: polish dymka nudge v2 (CSS desktop + mobile)

**Instancja:** frontend (widget). Plik: modules/divezone_chat/views/js/widget-loader.js.
**Powiązane:** CHAT-T-056/058 (nudge). Karol wgrywa ręcznie (116b).
**Cel:** kolejna iteracja wyglądu dymka wg uwag Karola. Tylko CSS + drobny układ tekstu. Bez zmian logiki.

## Desktop (.dz-nudge i dzieci)
1. Tekst w 3 liniach: „Hej!" / „Nie wiesz, jaki sprzęt wybrać?" / „Zapytaj naszych konsultantów." — wymusić łamanie (emoji 🤿 z loadera zostaje jako prefiks z CHAT-T-058; ułożenie: "🤿 Hej!" w 1. linii lub emoji przed "Hej!"). Realizacja: zamiast jednej linii, rozbić tekst na 3 wiersze (np. <br> w renderze LUB struktura z liniami). UWAGA: tekst pochodzi z configu (textContent, anty-XSS) — jeśli łamanie ma być sztywne 3-liniowe niezależnie od configu, najczystsze: w loaderze rozbić znany default na linie, ALBO renderować z white-space tak, by 3 zdania łamały się naturalnie. Skoordynować z tym, że treść w configu to „Hej! Nie wiesz, jaki sprzęt wybrać? Zapytaj naszych konsultantów." — preferowane: CSS/struktura dająca 3 linie, bez twardego <br> zależnego od dokładnej treści. CC wybiera czytelną realizację, odnotuje w raporcie.
2. font-size: +1px względem obecnego (CHAT-T-058 ustawił 16px → teraz 17px).
3. „Hej!" pogrubione (bold) — pierwszy wyraz/linia w <strong> lub span z font-weight:700. (Jeśli tekst z configu: wyróżnić pierwszy segment do pierwszego „!" jako bold — albo prościej, skoro „Hej!" jest stałe, owinąć je w loaderze.)
4. Odstęp przycisku od tekstu: .dz-nudge__text margin: 0 0 20px (było 0 0 10px).
5. Tekst przycisku CTA: font-size 16px (było 15px z CHAT-T-058).

## Mobile (media query ≤599.98px — dodać/rozszerzyć regułę dla .dz-nudge na mobile)
1. width: 340px (desktop ma 320px; na mobile zachować max-width:calc(100vw - 40px) by nie wyjść poza ekran).
2. padding: 25px 55px 25px 25px.
3. font-size: 18px (tekst dymka).
4. CTA font-size: 18px.
(Jeśli nie ma jeszcze media query dla nudge w loaderze — dodać @media (max-width:599.98px){ .dz-nudge{...} .dz-nudge__cta{...} } z powyższymi wartościami.)

## Granice
- Tylko widget-loader.js, tylko CSS dymka + układ tekstu (3 linie, bold „Hej!"). Bez zmian logiki nudge (timer/sessionStorage/klik/X), bez backendu, bez modułu PHP.
- Emoji 🤿 z loadera (CHAT-T-058) zostaje. Plik UTF-8.
- Zachować wartości z CHAT-T-058 których nie zmieniamy (tło #f2feff, CTA tło #f7b427/#0b3b3d, × 36px/#555/hover #1e6363/font-weight 300).

## Kryteria akceptacji
1. Desktop: tekst w 3 liniach (Hej! / Nie wiesz...? / Zapytaj...), „Hej!" bold, font 17px, CTA 16px, odstęp tekst→przycisk 20px.
2. Mobile (≤599.98px): dymek width 340px (mieści się w ekranie), padding 25px 55px 25px 25px, font 18px, CTA 18px.
3. Emoji 🤿 nadal renderowane (nie ????).
4. Logika nudge bez regresji.
5. Plik UTF-8; bundle/transport nietknięte.
