# CHAT-T-112 — FEATURE: lead-capture przy zamknięciu czatu (oddzwońcie / wyślij transkrypt na mail)

**Instancje:** frontend (widget — trigger + modal) + backend (endpoint + zapis + e-mail).
**Powiązane:** CHAT-T-091 (serwis e-mail backend), CHAT-T-111 (transkrypt z `divechat_messages` jako źródło prawdy), incydent 2026-06-29 (nieobsłużeni klienci znikają bez śladu kontaktu — np. EN XDEEP ZEOS pytał 3× i nie zostawił danych).
**Status:** DRAFT — wymaga decyzji produktowych Karola (sekcja „Do decyzji").

## CEL
Gdy klient pisał z botem i zamyka czat, pokaż krótkie okienko: „Chcesz, żebyśmy się odezwali, albo dostać tę rozmowę na maila? Zostaw adres." → odzyskujemy leada (kontakt), którego dziś tracimy. Rozwiązuje realny problem: nieobsłużone/urwane rozmowy nie zostawiają żadnej drogi do klienta.

## KONTEKST (dlaczego teraz)
Z analizy bazy (2026-06-29): część klientów zadaje konkretne, wartościowe pytania (dobór drogiego sprzętu), bot nie zdąża/nie umie odpowiedzieć, klient wychodzi — i nie mamy jak go odzyskać, bo brak kontaktu. Lead-capture przy wyjściu zamienia „utracony" w „do oddzwonienia".

## ZAKRES — FRONTEND (widget)
1. **Trigger:** klient zamyka/minimalizuje widget (klik X) PO tym, jak wysłał ≥1 wiadomość w tej sesji. NIE pokazuj, gdy:
   - rozmowa pusta (zero wiadomości użytkownika),
   - klient już zostawił kontakt w tej sesji,
   - (opcja) klient jest zalogowany i mamy `ps_customer_id` → wtedy nie pytamy o mail, ewentualnie tylko „oddzwońcie".
2. **Modal (krótki, jeden ekran):** nagłówek + 1 pole e-mail (walidacja) + 2 opcje (checkboxy lub dwa przyciski):
   - ☑ „Oddzwońcie / odezwijcie się do mnie",
   - ☑ „Wyślijcie mi tę rozmowę na maila".
   - Opcjonalnie pole telefon (jeśli „oddzwońcie"). Zgoda RODO (krótka klauzula + link do polityki).
   - CTA „Wyślij" + „Nie, dzięki" (zamyka). Nie blokuj zamknięcia (UX: łatwo pominąć).
3. **Wysyłka:** `POST /api/chat/contact` (HMAC jak reszta), body `{session_id, email, phone?, want_callback:bool, want_transcript:bool, consent:true}`. Po sukcesie krótkie „Dzięki, odezwiemy się / mail w drodze".
4. Ton zgodny z botem (ciepły, zwięzły, PL). i18n: PL + EN (klient EN realnie wystąpił).

## ZAKRES — BACKEND (standalone)
1. **Endpoint** `POST /api/chat/contact` (publiczny, HMAC): walidacja e-mail/telefon, rate-limit (anty-spam, jak inne publiczne), wymaga `consent=true`.
2. **Zapis kontaktu:** nowa tabela `divechat_leads` (id, conversation_id/session_id, email, phone, want_callback, want_transcript, consent_at, created_at) LUB kolumny na rozmowie — REKOMENDACJA: osobna tabela (lead = osobny byt, może być >1 na rozmowę, czysty RODO-scope/retencja).
3. **E-mail transkryptu** (gdy `want_transcript`): zbuduj treść z `divechat_messages` (źródło prawdy po CHAT-T-111), wyślij przez serwis e-mail (CHAT-T-091). Szablon: pytania + odpowiedzi, stopka kontaktowa DiveZone.
4. **Powiadomienie zespołu** (gdy `want_callback`): mail/alert do obsługi (`dive@divezone.pl`) z linkiem do rozmowy w panelu recenzji + danymi kontaktowymi → „oddzwoń". Spina się z panelem (status `do_weryfikacji`).
5. RODO: minimalizacja danych, zgoda zapisana z timestampem, retencja do ustalenia, prawo do usunięcia.

## DO DECYZJI (Karol — produktowe)
- Moment triggera: tylko przy kliku X, czy też przy bezczynności / próbie wyjścia ze strony (`beforeunload`)? (rekomendacja: klik X — najmniej nachalne).
- Pola: sam e-mail, czy e-mail+telefon? Telefon tylko przy „oddzwońcie"?
- Czy auto-wysyłać transkrypt od razu, czy dopiero po potwierdzeniu w mailu (double opt-in)?
- Treść klauzuli zgody RODO + retencja leadów.
- Czy pokazywać też zalogowanym (mamy ich e-mail z PS) — czy tylko gościom.

## KRYTERIA AKCEPTACJI (po decyzjach)
- [ ] Modal pojawia się tylko po realnej rozmowie, raz na sesję, łatwy do pominięcia.
- [ ] `POST /api/chat/contact` waliduje, wymaga zgody, rate-limit, zapisuje lead.
- [ ] „Wyślij transkrypt" → mail z treścią z `divechat_messages`.
- [ ] „Oddzwońcie" → powiadomienie obsługi + lead widoczny przy rozmowie w panelu.
- [ ] PL+EN. RODO: zgoda z timestampem. Zapisy fail-open nie psują UX zamknięcia.

## POZA ZAKRESEM
CRM/integracje zewnętrzne; automatyczne follow-up sekwencje; scoring leadów.
