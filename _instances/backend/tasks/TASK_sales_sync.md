# TASK: Cykliczna synchronizacja danych sprzedażowych do bazy czatu

## Cel
AI chat powinien mieć dostęp do aktualnych danych sprzedażowych bez odpytywania MySQL PrestaShop w czasie rzeczywistym.

## Zakres danych (zapisywanych w PostgreSQL czatu)

### Tabela: sales_bestsellers
- category_id, category_name
- product_id, product_name
- orders_count, quantity_sold
- period (last_30d / last_90d / last_365d)
- updated_at

### Tabela: sales_cross_sell
- category_a_id, category_a_name
- category_b_id, category_b_name
- co_purchase_count
- co_purchase_percent (kupił A → kupił też B)
- period
- updated_at

## Częstotliwość
- Raz dziennie (CRON, np. 03:00)
- Okno: ostatnie 90 dni (rolling window)
- Opcjonalnie: raz w tygodniu pełny 12-miesięczny snapshot

## Zastosowanie w chacie
1. Cross-selling: AI po rekomendacji automatu podpowiada "klienci kupujący automaty często dokupują też octopus i manometr"
2. Bestsellery: "Najpopularniejszy komputer w naszym sklepie to Shearwater Peregrine"
3. Social proof: "W ostatnim miesiącu 48 nurków wybrało bojkę XDEEP zamkniętą 140cm"

## Implementacja
- Skrypt PHP (CRON job) odpytujący MySQL PrestaShop
- Zapis do PostgreSQL (tabele powyżej)
- AI dostaje te dane przez function calling: get_bestsellers(category), get_cross_sell(category)

## Priorytet
Po zakończeniu encyklopedii. Estymacja: 4-6h pracy Claude Code (backend).

## Powiązane
- _docs/dane_sprzedazowe_crosssell_12m.md (statyczny snapshot, marzec 2026)
- _docs/dane_sprzedazowe_bestsellery_12m.md (statyczny snapshot, marzec 2026)
