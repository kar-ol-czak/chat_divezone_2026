# TASK-004b: Batch embeddingi produktów + test 50
# Data: 2026-02-20
# Instancja: embeddings (ŚWIEŻA, poprzednia wyczerpała kontekst)
# Priorytet: WYSOKI
# Zależności: extract_products.py GOTOWY

## Kontekst
TASK-004 ukończył krok 1 (extract_products.py). Reszta do zrobienia.
Przeczytaj:
- ../../_docs/02_schemat_bazy.md (tabela divechat_product_embeddings)
- ../../embeddings/extract_products.py (gotowy, nie modyfikuj)
- ../../.env (OPENAI_API_KEY, DATABASE_URL, SSH/MySQL credentials)

## Krok 1: Stwórz batch_embed_products.py
Plik: ../../embeddings/batch_embed_products.py

Funkcjonalność:
1. Uruchom extract_products.py i pobierz produkty (import lub subprocess)
2. Dla każdego produktu zbuduj document_text:
```
Produkt: {product_name}
Marka: {brand_name}
Kategoria: {category_name}
Cena: {price} PLN
Opis: {description_short} (bez HTML, strip_tags via BeautifulSoup)
Cechy: {feature1}: {value1}, {feature2}: {value2}, ...
```
3. Wygeneruj embeddingi: OpenAI text-embedding-3-large, dimensions=1536
4. Wgraj do PostgreSQL: divechat_product_embeddings

Dwa tryby:
- --test N: przetwarza N pierwszych produktów, embeddingi przez zwykłe API (nie batch)
- --full: wszystkie produkty, OpenAI Batch API (.jsonl upload, poll, download)

URL produktu: https://divezone.pl/{link_rewrite}.html (BEZ id_product!)
URL obrazka: https://divezone.pl/{id_image}-large_default/{link_rewrite}.jpg

## Krok 2: Test na 50 produktach
Uruchom: python3 batch_embed_products.py --test 50
Sprawdź że dane weszły do PostgreSQL (SELECT count, sample rows).

## Krok 3: Test wyszukiwania
Użyj ../../embeddings/test_search.py lub napisz quick test.
5 zapytań testowych (sekcja A z ../../_docs/08_testy_i_ewaluacja.md):
- "maska do nurkowania z korekcją"
- "komputer nurkowy z podłączeniem powietrza"
- "pianka mokra 7mm do zimnych wód"
- "latarka nurkowa do jaskiń"
- "automat oddechowy do wody zimnej"
Wyświetl: pytanie -> top 3 produkty z similarity score.

## SSH Tunnel
Biblioteka sshtunnel NIE DZIAŁA (konflikt paramiko 4.x).
Użyj tunelu bash PRZED uruchomieniem skryptu:
```bash
ssh -i /Users/karol/.ssh/id_ed25519 -p 5739 -L 33060:127.0.0.1:3306 -f -N divezone@divezonededyk.smarthost.pl
```
Skrypt łączy się z MySQL na localhost:33060.
Po zakończeniu: pkill -f "ssh.*33060.*divezonededyk"

## Requirements
Dodaj do ../../embeddings/requirements.txt: beautifulsoup4 (jeśli nie ma)
pip3 install: openai pymysql psycopg2-binary python-dotenv beautifulsoup4 numpy

## Definition of Done
- [ ] batch_embed_products.py działa w trybie --test
- [ ] 50 produktów w divechat_product_embeddings
- [ ] 5 zapytań testowych zwraca trafne produkty z similarity >0.5
