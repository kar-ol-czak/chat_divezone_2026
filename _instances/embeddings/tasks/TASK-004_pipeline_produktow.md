# TASK-004: Pipeline embeddingów produktów z PrestaShop
# Data: 2026-02-19
# Instancja: embeddings
# Priorytet: WYSOKI
# Zależności: TASK-002 (OpenAI embeddings działają), TASK-003 (opcjonalnie)

## Kontekst
Przeczytaj:
- ../../_docs/02_schemat_bazy.md (tabela divechat_product_embeddings)
- ../../_docs/00_architektura_projektu.md (sekcja o dokumencie produktowym)
- ../../.env (SSH_*, DB_* dla MySQL PrestaShop, DATABASE_URL dla PG)

PrestaShop MySQL jest na VPS dostępnym przez SSH tunnel.
Dane połączenia z .env:
- SSH: divezonededyk.smarthost.pl:5739 user=divezone key=~/.ssh/id_ed25519
- MySQL: localhost:3306 db=divezone_2025 user=divezone_sklep_tmp2 prefix=pr_

## Zadania

### Krok 1: Skrypt ekstrakcji produktów z PrestaShop
Stwórz: ../../embeddings/extract_products.py
- Otwórz tunel SSH (subprocess: ssh -L 33060:127.0.0.1:3306)
- Połącz pymysql na localhost:33060
- Wyciągnij aktywne produkty (lang_id=1 dla polskiego):
```sql
SELECT 
    p.id_product,
    pl.name AS product_name,
    pl.description,
    pl.description_short,
    cl.name AS category_name,
    m.name AS brand_name,
    ps.price,
    ps.active,
    sa.quantity
FROM pr_product p
JOIN pr_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
JOIN pr_product_shop ps ON p.id_product = ps.id_product
LEFT JOIN pr_category_lang cl ON p.id_category_default = cl.id_category AND cl.id_lang = 1
LEFT JOIN pr_manufacturer m ON p.id_manufacturer = m.id_manufacturer
LEFT JOIN pr_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
WHERE ps.active = 1
```
- Wyciągnij też cechy (features):
```sql
SELECT fp.id_product, fl.name AS feature_name, fvl.value AS feature_value
FROM pr_feature_product fp
JOIN pr_feature_lang fl ON fp.id_feature = fl.id_feature AND fl.id_lang = 1
JOIN pr_feature_value_lang fvl ON fp.id_feature_value = fvl.id_feature_value AND fvl.id_lang = 1
```
- Złóż features jako JSONB: {"Kolor": "Czarny", "Rozmiar": "M", "Materiał": "Neopren"}

### Krok 2: Format dokumentu produktowego
Dla każdego produktu stwórz document_text do embeddingu:
```
Produkt: {product_name}
Marka: {brand_name}
Kategoria: {category_name}
Cena: {price} PLN
Opis: {description_short} (bez HTML, strip_tags)
Cechy: {feature1}: {value1}, {feature2}: {value2}, ...
```
Opis ogranicz do 500 znaków (po strip_tags). Usuń HTML, &nbsp;, etc.

### Krok 3: Generowanie embeddingów (OpenAI Batch API)
Stwórz: ../../embeddings/batch_embed_products.py
- Przygotuj plik .jsonl z requestami dla OpenAI Batch API
- Model: text-embedding-3-large, dimensions=1536
- Upload pliku, stwórz batch, poll status
- Po zakończeniu: pobierz wyniki, parsuj embeddingi
Dokumentacja Batch API: https://platform.openai.com/docs/guides/batch

### Krok 4: Wgranie do PostgreSQL
- INSERT do divechat_product_embeddings (wszystkie kolumny z schematu)
- URL produktu: https://divezone.pl/{link_rewrite}.html (BEZ id, link_rewrite z pr_product_lang)
- URL obrazka: https://divezone.pl/{id_image}-large_default/{link_rewrite}.jpg (id_image z pr_image WHERE cover=1)
- Aktywnych produktów w bazie: ~2670
  
### Krok 5: Test na 50 produktach
Zanim wgrasz 3000: uruchom pipeline na pierwszych 50 produktach.
Przetestuj 5 zapytań z ../../_docs/08_testy_i_ewaluacja.md sekcja A.
Wyświetl wyniki: pytanie -> top 5 produktów z similarity.

## Pliki do stworzenia
- ../../embeddings/extract_products.py
- ../../embeddings/batch_embed_products.py
- ../../embeddings/requirements.txt (dodaj: pymysql, beautifulsoup4)

## Definition of Done
- [ ] SSH tunnel do MySQL działa
- [ ] Ekstrakcja produktów zwraca ~2670 aktywnych produktów
- [ ] Test na 50 produktach: pipeline end-to-end działa
- [ ] 5 zapytań testowych zwraca trafne produkty

## Uwagi
- Nie wgrywaj jeszcze 2670 produktów. Najpierw test na 50.
- SSH TUNNEL: biblioteka sshtunnel ma konflikt z paramiko 4.x (brak DSSKey). Użyj tunelu bash:
  ssh -i /Users/karol/.ssh/id_ed25519 -p 5739 -L 33060:127.0.0.1:3306 -f -N divezone@divezonededyk.smarthost.pl
  Potem łącz pymysql na localhost:33060. Na koniec: pkill -f "ssh.*33060.*divezonededyk"
- strip_tags: użyj BeautifulSoup lub html.parser, nie regex
- Hasło MySQL w .env, NIE hardkoduj
- SSH key path: /Users/karol/.ssh/id_ed25519
