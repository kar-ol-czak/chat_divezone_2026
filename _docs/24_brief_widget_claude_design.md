

## Brand i ton

- Źródło prawdy: web capture `https://divezone.pl` (kolory, typografia, logo SVG).
- Kolor chrome marki: ciemny grafit `#292b2d` (z meta theme-color sklepu).
- Logo: `https://divezone.pl/img/shoplogo.svg` (desktop), `shoplogo-m.svg` (mobile).
- Język: polski podstawowy, angielski drugi (sklep ma PL/EN).
- Ton: doświadczony instruktor nurkowania, ciepły, rzeczowy, konkretny. Po polsku.

## Ekrany i stany (kolejność draftów)

1. **Desktop, panel powitalny:** pływające okno ~384×680 px w prawym dolnym rogu + dymek-launcher obok.
2. **Mobile, fullscreen powitalny:** overlay na całą wysokość ekranu, padding safe-area.
3. **Karty produktów:** 2 do 3 kompaktowe karty w bąblu bota.
4. **Streaming:** wskaźnik "Asystent pisze..." + odpowiedź pojawiająca się stopniowo.
5. **Formularz statusu zamówienia:** dwa pola + przycisk, krótka nota o celu danych.
6. **Błąd/fallback:** kontakt (telefon, e-mail, przycisk "Napisz do nas").
7. **Stan zamknięty:** sam dymek-launcher na tle sklepu (web capture jako tło).


## Komponenty

- **Nagłówek:** logo/nazwa + etykieta "Asystent AI" + przycisk zamknięcia (X).
- **Lista wiadomości:** bąbel użytkownika i bąbel bota; bot wspiera markdown (pogrubienia, linki, listy).
- **Chipy szybkich odpowiedzi:** w stanie powitalnym, mobile max 4, desktop max 6.
- **Pasek wpisu:** pole tekstowe + przycisk wyślij.
- **Pasywna nota RODO:** jedna linijka nad polem wpisu, mała, szara.
- **Karta produktu:** miniatura ~64 do 80 px po lewej, nazwa do 2 linii, cena pogrubiona, jeden przycisk "Zobacz produkt". Max 3 na odpowiedź, na mobile jedna na wiersz lub karuzela pozioma.
- **Formularz statusu zamówienia:** pole "Numer/referencja zamówienia" + pole "Adres e-mail" + przycisk "Sprawdź status".

## Ograniczenia wizualne i wymiary

- Desktop okno ~384×680 px, prawy dolny róg, zaokrąglenie 16 px, krótka animacja otwarcia.
- Dymek-launcher 56 do 60 px, prawy dolny róg.
- Mobile: fullscreen overlay, padding safe-area (notch góra, home indicator dół).
- Cele dotykowe min 48 px, pole wpisu font 16 px (blokada auto-zoom iOS).
- Wysoki kontrast, widoczny focus ring (wymóg dostępności EAA/WCAG 2.1 AA).
- Priorytet: czytelność i zaufanie ponad dekoracją.


## Copy (PL, do mockupów)

- **Wiadomość powitalna:** "Cześć! Jestem asystentem Divezone. Pomogę dobrać sprzęt, sprawdzić dostępność albo odpowiedzieć na pytania o zamówienie. Od czego zaczynamy?"
- **Chipy szybkich odpowiedzi:** "Pomóż dobrać sprzęt", "Dobierz rozmiar (pianka/skafander)", "Sprawdź kompatybilność (komputer/transmiter)", "Dostępność i wysyłka", "Status zamówienia", "Serwis sprzętu".
- **Nota RODO nad polem wpisu:** "Rozmawiasz z asystentem AI, nie podawaj danych wrażliwych. Polityka prywatności." (ostatnie słowa jako link)
- **Nota przy formularzu statusu:** "Dane posłużą wyłącznie do sprawdzenia statusu Twojego zamówienia."
- **Błąd/fallback:** "Nie potrafię tu pomóc. Skontaktuj się z nami: 56 307 03 03 lub e-mail sklepu." + przycisk "Napisz do nas".

Dane kontaktowe sklepu (do stanu fallback): telefon 56 307 03 03, siedziba Toruń, godziny Pn-Pt 9-17.

## Poza zakresem mockupu

- Add-to-cart z czatu (faza 2), upload plików, live handoff do człowieka (przyszłość: WhatsApp/Messenger).
- Hybryda bottom sheet na mobile (MVP = fullscreen).
- Warstwa techniczna: Shadow DOM, transport fetch+SSE, JWT, CSP, rate limiting. To nie dotyczy mockupu, wchodzi dopiero do tasków Claude Code.

## Powiązania

ADR-060 (osadzenie), ADR-061 (frontend: fullscreen mobile, safe-area, 48 px, kontrast, focus), ADR-063 (RODO 3-warstwowy, status zamówienia, dostępność), ADR-064 (karty produktów link-only). Handoff 23.
