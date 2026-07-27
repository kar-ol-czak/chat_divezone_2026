# CHAT-T-052 — FRONTEND/PS: polish layoutu zakładki „Rozmowy" (CSS, 6 poprawek)

**Instancja:** frontend (moduł PrestaShop)
**Powiązane:** CHAT-T-051 (redesign master-detail — WYKONANY, commity 80884c2/a1b8cd6), ADR-073.
**Plik:** `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php` (sekcja Rozmów).
**Backend:** bez zmian. To wyłącznie layout/CSS w module PS. Karol wgrywa ręcznie (116b).

## Cel
Dopracowanie układu zakładki Rozmowy po redesignie — 6 konkretnych poprawek z obserwacji na żywym panelu. Zwięzłość pionowa (mało miejsca w pionie, więcej w poziomie → łączenie w wiersze/kolumny).

## Poprawki (6) — każda odnosi się do istniejącej metody

### 1. Lista: data + „gość | status" w JEDNEJ linii
`renderConvListItem()` renderuje dziś DWA osobne `<div class="dz-conv-item-meta">` (data w pierwszym, „klient | status + ⚠" w drugim) → łamią się na 2 linie.
- Scalić w JEDEN `<div class="dz-conv-item-meta">` z datą po lewej i „#klient/gość | status(badge) ⚠" po prawej.
- Klasa `.dz-conv-item-meta` ma już `display:flex;justify-content:space-between` — wykorzystać: lewy `<span>` = data, prawy `<span>` = klient|status|⚠. Zmieści się w jednej linii (potwierdzone na zrzucie).

### 2. Lista: padding kontenera = 0
W `renderConversationsList()` panel listy ma `<div style="padding:18px;">` zaraz po `panel-heading`. Zmienić padding tego diva na `0` (lista ma stykać się z krawędziami panelu — pozycje `.dz-conv-item` mają własny padding). Dotyczy TYLKO panelu listy (lewa kolumna), NIE panelu szczegółów ani innych zakładek.

### 3. Filtry: jedna linia + zmiana tekstów (`renderConvFilters()`)
Obecnie kolumna (w wąskiej liście CSS wymusza `flex-direction:column`). Docelowo wszystkie kontrolki w JEDNYM wierszu:
- USUNĄĆ labele „Szukaj" i „Wyświetlany"/„Status" (oba `<label>` znad inputów).
- Input search: placeholder na `Szukaj konwersacji` (zamiast „tresc wiadomosci").
- Select status: pierwsza opcja `— Wyświetlane wszystkie —` (zamiast „— wszystkie —").
- Układ w jednej linii: [input search] [select status] [checkbox „Tylko luki wiedzy"] [przycisk Filtruj] — wszystko w jednym rzędzie, wyrównane.
- UWAGA na CSS `.dz-conv-list-col .dz-conv-filters{flex-direction:column}` (linie ~176-178) — to wymusza pion w wąskiej kolumnie. Dla jednej linii albo nadpisać tę regułę dla filtrów Rozmów, albo dostosować tak, by w wąskiej kolumnie (340px) zmieściły się w rzędzie (może wymagać zwężenia inputów / wrap). Cel: jedna linia gdy szerokość pozwala; w bardzo wąskiej kolumnie dopuszczalny `flex-wrap`. Zachować hidden inputy controller/token/tab (routing PS — NIE usuwać).

### 4. Szczegóły: „info o rozmowie" + „koszty" w jednym wierszu, 2 kolumny
Dziś `dz-conv-meta` (meta) i `dz-conv-cost` (koszty) to dwa osobne bloki jeden pod drugim. Umieścić je obok siebie w jednym wierszu, 2 kolumny (np. wrapper `display:flex;gap:14px` lub grid 1fr/1fr; każdy blok we własnej kolumnie). Oszczędność pionu. Na wąskim ekranie dopuszczalny wrap.

### 5. Formularz statusu: 2 kolumny (Status | Notatki), niższy (`renderConvStatusForm()`)
Dziś grid `160px 1fr` układa Status i Notatki JEDEN POD DRUGIM (label-pole, label-pole) → wysokie.
- Przebudować na 2 KOLUMNY obok siebie: LEWA = Status (select) + przycisk „Zapisz status" pod selectem; PRAWA = Notatki (textarea).
- Dzięki przyciskowi w kolumnie Status, textarea może być NIŻSZA (np. rows=2-3 wystarczy, pole nie musi ciągnąć wysokości).
- Etykiety: „Status" i „Notatki" (NIE „Wyświetlany" — to było źródłem nieporozumienia; w kodzie jest już `$this->l('Status')`, potwierdzić; faktyczne „Wyświetlany" pochodzi z tłumaczenia PS — patrz nota niżej).

### 6. Koszty: usunąć nadmiarowy „estimated_cost" (`renderConvCosts()`)
ZWERYFIKOWANE w źródle: `estimated_cost` (kolumna rozmowy) i `conversation_cost.total_usd` (`getConversationCost`) to TA SAMA wartość — `getConversationCost` czyta dokładnie kolumnę `estimated_cost`, dokładając tylko PLN. Linia „estimated_cost: $X USD" jest w 100% nadmiarowa.
- USUNĄĆ fragment ` · estimated_cost: $X USD` (linia z `$estCost`).
- ZOSTAWIĆ: „Koszty i tokeny: input … output … [cache …]" ORAZ „Sumaryczny koszt rozmowy: $X USD / Y zł".
- `$estCost` przestaje być potrzebny do wyświetlenia — można usunąć zmienną (zostawić tylko jeśli używana gdzie indziej; nie jest).

---

## Ostrzeżenia PS na górze panelu (decyzja 116a — NIE tłumić w kodzie)
Zielony pasek z komunikatami/ostrzeżeniami nad panelem to GLOBALNE komunikaty PrestaShop/PHP (deprecation/notice), NIE pochodzą z kodu zakładki Rozmowy (w module nie ma nic, co je generuje — `$http_response_header` jest obwarowany `isset`, jedyne `@` to bezpieczny `@strtotime`). NIE tłumić ich CSS-em/JS w module (maskowałoby też realne ostrzeżenia). Właściwe miejsce: ustawienie PS na PROD — Zaawansowane → Wydajność → Tryb debugowania = NIE, oraz `display_errors=Off`. To poza zakresem tego taska — informacja dla Karola, nie zmiana w kodzie.

## Etykieta „Wyświetlany" → „Status" (powtórka z CHAT-T-051, jeśli wciąż widoczna)
Kod używa `$this->l('Status')`. Jeśli na żywym panelu wciąż widać „Wyświetlany", to tłumaczenie modułu na serwerze. Poprawka RĘCZNA przez Karola: Międzynarodowy → Tłumaczenia → Tłumaczenia modułów → divezone_chat → polski → string „Status". CC NIE tworzy `translations/pl.php` w repo. (W tym tasku tylko potwierdzić, że kod nie ma literału „Wyświetlany".)

## Granice
- WYŁĄCZNIE layout/CSS/teksty w sekcji Rozmów. Bez zmian logiki, bez backendu, bez innych zakładek.
- Zachować bezpieczeństwo z CHAT-T-048/051: hidden controller/token/tab w filtrach, escaping, formatowanie bąbli (bold+linki) bez zmian.
- Bez JS/bibliotek. Bez wdrażania modułu (Karol — 116b).

## Kryteria akceptacji
1. Pozycja listy: data i „gość | status" w JEDNEJ linii (data lewo, klient|status|⚠ prawo).
2. Panel listy: brak wewnętrznego paddingu wokół listy (lista styka się z krawędzią panelu).
3. Filtry w jednej linii (search + status + checkbox + Filtruj); brak labeli „Szukaj"/„Status"; placeholder „Szukaj konwersacji"; opcja „— Wyświetlane wszystkie —". Hidden controller/token/tab nadal obecne.
4. W szczegółach: meta i koszty obok siebie (2 kolumny, jeden wiersz).
5. Formularz statusu: 2 kolumny (Status+przycisk | Notatki), pole niższe; etykiety „Status"/„Notatki".
6. Koszty: tylko jedna linia kosztu sumarycznego ($ USD / zł); brak „estimated_cost: …".
7. php -l clean; PHP 7.2/PS 1.7.6 (bez typed props, bez match).
