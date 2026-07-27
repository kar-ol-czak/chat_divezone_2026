# TASK-012b: Multi-Vector Retrieval
# Instancja: embeddings (Python) + integration (SQL)
# Zależności: TASK-011b (search_phrases muszą istnieć)
# Priorytet: ŚREDNI (po TASK-012)

## CEL
3 osobne kolumny wektorowe zamiast jednej. Izolacja sygnałów.

## NOWE KOLUMNY

```sql
ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS embedding_name vector(1536),
    ADD COLUMN IF NOT EXISTS embedding_desc vector(1536),
    ADD COLUMN IF NOT EXISTS embedding_jargon vector(1536);
```

## CO EMBEDDUJEMY

| Kolumna | Tekst źródłowy | Przykład |
|---|---|---|
| embedding_name | product_name + brand_name | "BARE Velocity Semi-Dry 7mm Lady BARE" |
| embedding_desc | category_name + opis + cechy | "Skafandry Na ZIMNE wody. Opis: ..." |
| embedding_jargon | search_phrases (z TASK-011b) | "pianka damska na zimno, wetsuit 7mm zimowy" |

## SKRYPT

embeddings/batch_embed_multivector.py:
1. Pobierz produkty z divechat_product_embeddings
2. Dla każdego: zbuduj 3 teksty (name, desc, jargon)
3. Batch embed przez OpenAI (text-embedding-3-large, dim=1536)
4. UPDATE 3 kolumn

Batch po 100 produktów, checkpoint co 500.

## INDEKSY HNSW

```sql
CREATE INDEX idx_emb_name_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_name vector_cosine_ops);
CREATE INDEX idx_emb_desc_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_desc vector_cosine_ops);
CREATE INDEX idx_emb_jargon_hnsw ON divechat_product_embeddings
    USING hnsw (embedding_jargon vector_cosine_ops);
```

## AKTUALIZACJA RRF (TASK-012)

Po dodaniu multi-vector, RRF rozrasta się z 3 do 5 torów:
- embedding_name <=> query (nawigacyjne)
- embedding_desc <=> query (eksploracyjne)
- embedding_jargon <=> query (vocabulary mismatch)
- FTS (leksykalne)
- Trigram (fuzzy)

Stara kolumna `embedding` zostaje jako fallback do czasu weryfikacji.

## KOSZT
- 3 * 2556 embeddingów = 7668 calls
- text-embedding-3-large: ~$0.77
- Czas: ~5 minut

## KRYTERIA AKCEPTACJI
- [ ] 3 nowe kolumny wypełnione (zero NULL-i)
- [ ] Indeksy HNSW utworzone
- [ ] RRF zaktualizowany o 5 torów
- [ ] Test: "Shearwater Teric" → embedding_name dominuje
- [ ] Test: "komputer na trimix" → embedding_desc dominuje
- [ ] Test: "pianka na zimno" → embedding_jargon dominuje
