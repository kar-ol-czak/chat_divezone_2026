# CHAT-T-053 — FRONTEND/PS: fix widgetu (mobile autofocus + link polityki)

**Instancja:** frontend (widget, moduł PrestaShop)
**Plik:** modules/divezone_chat/views/js/widget-bundle.js (jedyny edytowany).
**Powiązane:** CHAT-T-037 (widget), ADR-063 (a11y). Karol wgrywa moduł ręcznie (116b).

## Cel
Dwa drobne, niezależne fixy widgetu czatu na froncie sklepu.

## Fix 1 — mobile: brak autofocus przy otwarciu (decyzja 124a)
PROBLEM: na telefonie po otwarciu panelu klawiatura ekranowa otwiera się od razu (bo input dostaje focus) i zasłania chipy powitalne.
PRZYCZYNA: w openWindow (ok. linia 777) jest `setTimeout(function(){ if(state.inputEl) state.inputEl.focus(); }, 180);`. `isMobile` jest JUŻ wykrywane kilka linii wyżej w tej samej funkcji (`window.matchMedia('(max-width: 599.98px)').matches`).
POPRAWKA: NIE ustawiać autofocus na mobile. Na desktopie focus zostaje bez zmian.
- Najprościej: opakować ten setTimeout/focus warunkiem `if (!isMobile) { ... }` (użyć istniejącej zmiennej isMobile z tej funkcji; jeśli jest w węższym scope — wyliczyć ją raz na początku openWindow i użyć w obu miejscach: blokada scrolla + focus).
- Efekt: mobile po otwarciu pokazuje chipy, klawiatura otwiera się dopiero gdy user sam tapnie pole tekstowe (albo gdy chipy znikną po wyborze).
- NIE zmieniać linii ~407 (`state.inputEl.focus()` po odpowiedzi bota) — to osobny przypadek, rozmowa już trwa, klawiatura oczekiwana. Poza zakresem.

## Fix 2 — poprawny link polityki prywatności
PROBLEM: link "Polityka prywatności" prowadzi donikąd.
PRZYCZYNA: linia ~41 — href = `https://divezone.pl/content/3-polityka-prywatnosci` (stary URL po ID PrestaShop).
POPRAWKA: zmienić href na `https://divezone.pl/polityka-prywatnosci`. Zostawić target=_blank rel="noopener noreferrer".

## Granice
- Tylko widget-bundle.js. Bez backendu, bez innych plików, bez zmian w panelu admina.
- Nie ruszać logiki chipów, streamingu, a11y poza opisanymi dwoma punktami.

## Kryteria akceptacji
1. Mobile (≤599.98px): po otwarciu panelu klawiatura NIE otwiera się automatycznie; chipy widoczne. Po tapnięciu pola — klawiatura działa normalnie.
2. Desktop: zachowanie bez zmian (input dostaje focus po otwarciu).
3. Link "Polityka prywatności" prowadzi do https://divezone.pl/polityka-prywatnosci (otwiera się, nie 404/donikąd).
4. Brak regresji: wysyłanie wiadomości, chipy, modal zamówienia działają jak wcześniej.
