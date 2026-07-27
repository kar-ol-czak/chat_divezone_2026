-- =============================================================================
-- CHAT-T-101 — Diagnoza READ-ONLY schematu atrybutow rozmiarow w PrestaShop
-- Baza: divezone_2025 (MariaDB 10.11.18), prefix pr_, id_lang=1 (Polski)
-- Konto: divezone_chat_reader (GRANT SELECT, SHOW VIEW — zero write)
-- Te same zapytania uruchamia embeddings/diagnose_size_attributes.py.
-- Uruchom recznie przez tunel SSH (localhost:33060) tylko do odtworzenia wynikow.
-- ZERO write. Diagnoza z danych pod projekt mini-modulu rozmiarow (ADR-100).
-- =============================================================================

-- --- KONTEKST: dowod read-only -----------------------------------------------
SELECT CURRENT_USER(), VERSION();
SHOW GRANTS;

-- =============================== Q1 ==========================================
-- Q1.a — wszystkie grupy atrybutow (PL) z liczba wartosci i uzyciem w kombinacjach
SELECT ag.id_attribute_group gid, agl.name,
       (SELECT COUNT(*) FROM pr_attribute a WHERE a.id_attribute_group=ag.id_attribute_group) n_vals,
       (SELECT COUNT(DISTINCT pac.id_product_attribute)
          FROM pr_attribute a2
          JOIN pr_product_attribute_combination pac ON pac.id_attribute=a2.id_attribute
         WHERE a2.id_attribute_group=ag.id_attribute_group) n_combos
  FROM pr_attribute_group ag
  JOIN pr_attribute_group_lang agl
    ON agl.id_attribute_group=ag.id_attribute_group AND agl.id_lang=1
 ORDER BY ag.id_attribute_group;

-- Q1.b — wartosci atrybutow w grupie rozmiarowej (przyklad: ROZMIAR=27; analogicznie 29,30,42,46,69)
SELECT al.name
  FROM pr_attribute a
  JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
 WHERE a.id_attribute_group=27
 ORDER BY a.position;

-- Q1.c — jak rozmiar konkretnego produktu jest reprezentowany (kombinacje wariantow)
-- Kazdy rozmiar = osobny wiersz pr_product_attribute (z wlasnym SKU/reference) +
-- powiazanie z etykieta atrybutu przez pr_product_attribute_combination.
SELECT p.id_product, pl.name, m.name brand,
       pa.id_product_attribute pa_id, pa.reference, pa.price AS price_impact,
       agl.name grp, al.name val
  FROM pr_product p
  JOIN pr_product_lang pl ON pl.id_product=p.id_product AND pl.id_lang=1
  LEFT JOIN pr_manufacturer m ON m.id_manufacturer=p.id_manufacturer
  JOIN pr_product_attribute pa ON pa.id_product=p.id_product
  JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
  JOIN pr_attribute a ON a.id_attribute=pac.id_attribute
  JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
  JOIN pr_attribute_group_lang agl ON agl.id_attribute_group=a.id_attribute_group AND agl.id_lang=1
 WHERE a.id_attribute_group IN (27,29,30)
   AND p.id_product = 4054   -- przyklad: BARE CD4 Pro Dry
 ORDER BY pa.id_product_attribute;

-- =============================== Q2 ==========================================
-- Q2.a — pr_feature / pr_feature_value: czy ktos trzyma tam wymiary
SELECT f.id_feature fid, fl.name,
       (SELECT COUNT(*) FROM pr_feature_value fv WHERE fv.id_feature=f.id_feature) n_vals,
       (SELECT COUNT(*) FROM pr_feature_product fp WHERE fp.id_feature=f.id_feature) n_used
  FROM pr_feature f
  JOIN pr_feature_lang fl ON fl.id_feature=f.id_feature AND fl.id_lang=1
 ORDER BY n_used DESC;

-- Q2.b — wartosci cech wygladajace na wymiary (cm/zakresy) — to wymiary AKCESORIOW,
-- nie progi rozmiar->wymiar ciala (brak per-rozmiar zakresow klatki/talii/bioder).
SELECT fl.name feature, fvl.value
  FROM pr_feature_value fv
  JOIN pr_feature_value_lang fvl ON fvl.id_feature_value=fv.id_feature_value AND fvl.id_lang=1
  JOIN pr_feature_lang fl ON fl.id_feature=fv.id_feature AND fl.id_lang=1
 WHERE fvl.value REGEXP 'klatk|obw[oó]d|pas|biodr|wzrost|[0-9]{2,3} ?- ?[0-9]{2,3}|[0-9]{2,3} ?cm';

-- Q2.c — czy w opisach istnieje interaktywny kalkulator / strukturalna tabela progow
-- (markery formularza i JS = 0 w aktywnych produktach; sa tylko statyczne <table>/<img>)
SELECT
  SUM(d LIKE '%<input%')         input_form,
  SUM(d LIKE '%<select%')        select_form,
  SUM(d LIKE '%<form%')          form_tag,
  SUM(d LIKE '%getElement%')     js_dom,
  SUM(d LIKE '%kalkulat%')       slowo_kalkulator,
  SUM(d LIKE '%<table%')         static_table,
  SUM(d LIKE '%tabela rozmiar%') tekst_tabela_rozmiar,
  SUM(d LIKE '%<img%')           img_grafika,
  SUM(d LIKE '%klatk%')          slowo_klatka
FROM (
  SELECT pl.description d
    FROM pr_product_lang pl
    JOIN pr_product p ON p.id_product=pl.id_product AND p.active=1
   WHERE pl.id_lang=1
) t;

-- =============================== Q3 ==========================================
-- Q3.a — marki w kategoriach skafandrow mokrych (337 CIEPLE, 367 ZIMNE)
SELECT m.id_manufacturer mid, m.name brand, COUNT(DISTINCT p.id_product) n_prod
  FROM pr_product p
  JOIN pr_category_product cp ON cp.id_product=p.id_product AND cp.id_category IN (337,367)
  LEFT JOIN pr_manufacturer m ON m.id_manufacturer=p.id_manufacturer
 WHERE p.active=1
 GROUP BY m.id_manufacturer, m.name
 ORDER BY n_prod DESC;

-- Q3.b — czy produkty tej samej marki wspoldziela te same wartosci atrybutu rozmiaru
-- (potwierdza: etykiety atrybutow sa GLOBALNE/wspoldzielone, jeden id_attribute 'L'
--  uzywany przez dziesiatki produktow; przyklad marka Scubapro=18)
SELECT al.name val, COUNT(DISTINCT pa.id_product) n_prod
  FROM pr_product_attribute pa
  JOIN pr_product p ON p.id_product=pa.id_product AND p.id_manufacturer=18 AND p.active=1
  JOIN pr_product_attribute_combination pac ON pac.id_product_attribute=pa.id_product_attribute
  JOIN pr_attribute a ON a.id_attribute=pac.id_attribute AND a.id_attribute_group IN (27,29,30)
  JOIN pr_attribute_lang al ON al.id_attribute=a.id_attribute AND al.id_lang=1
 GROUP BY a.id_attribute, al.name
 HAVING n_prod > 1
 ORDER BY n_prod DESC;

-- =============================== Q4 ==========================================
-- Q4 — identyfikacja kategorii rozmiarowych (skafandry suche/mokre, buty, rekawice, kaptury)
SELECT c.id_category cid, cl.name, c.id_parent,
       (SELECT COUNT(*) FROM pr_category_product cp
          JOIN pr_product p ON p.id_product=cp.id_product AND p.active=1
         WHERE cp.id_category=c.id_category) n_prod
  FROM pr_category c
  JOIN pr_category_lang cl ON cl.id_category=c.id_category AND cl.id_lang=1
 WHERE cl.name REGEXP 'skafand|pianka|suche|mokre|but|rekawic|r[eę]kawic|kapt'
 ORDER BY n_prod DESC;
