"""
Ekstrakcja produktów z PrestaShop MySQL przez SSH tunnel.
Buduje document_text do embeddingów i zwraca listę produktów.
"""

import os
import re
import json
import subprocess
import signal
import time
import logging
from pathlib import Path
from html.parser import HTMLParser

import pymysql
from dotenv import load_dotenv
from bs4 import BeautifulSoup

load_dotenv(Path(__file__).resolve().parent.parent / ".env")

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
logger = logging.getLogger(__name__)

LOCAL_MYSQL_PORT = 33060
MAX_DESCRIPTION_LENGTH = 500

# Zapytanie główne: aktywne produkty z lang_id=1 (polski)
PRODUCTS_SQL = """
SELECT
    p.id_product,
    pl.name AS product_name,
    pl.description,
    pl.description_short,
    pl.link_rewrite,
    cl.name AS category_name,
    m.name AS brand_name,
    ROUND(ps.price * (1 + COALESCE(t.rate, 23) / 100), 2) AS price_brutto,
    ps.active,
    COALESCE(sa.quantity, 0) AS quantity
FROM pr_product p
JOIN pr_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
LEFT JOIN pr_category_lang cl ON p.id_category_default = cl.id_category AND cl.id_lang = 1
LEFT JOIN pr_manufacturer m ON p.id_manufacturer = m.id_manufacturer
LEFT JOIN pr_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
LEFT JOIN pr_tax_rule tr ON p.id_tax_rules_group = tr.id_tax_rules_group AND tr.id_country = 14
LEFT JOIN pr_tax t ON tr.id_tax = t.id_tax
WHERE ps.active = 1
ORDER BY p.id_product
"""

# Cechy produktów
FEATURES_SQL = """
SELECT fp.id_product, fl.name AS feature_name, fvl.value AS feature_value
FROM pr_feature_product fp
JOIN pr_feature_lang fl ON fp.id_feature = fl.id_feature AND fl.id_lang = 1
JOIN pr_feature_value_lang fvl ON fp.id_feature_value = fvl.id_feature_value AND fvl.id_lang = 1
"""

# Cover image
IMAGES_SQL = """
SELECT id_product, id_image FROM pr_image WHERE cover = 1
"""


def open_ssh_tunnel():
    """Otwiera SSH tunnel do MySQL na VPS divezone.pl."""
    # Zamknij ewentualny stary tunel
    subprocess.run(["pkill", "-f", "ssh.*33060.*divezonededyk"], capture_output=True)
    time.sleep(0.5)

    ssh_cmd = [
        "ssh",
        "-i", os.getenv("SSH_KEY_PATH", "/Users/karol/.ssh/id_ed25519"),
        "-p", os.getenv("SSH_PORT", "5739"),
        "-L", f"{LOCAL_MYSQL_PORT}:127.0.0.1:3306",
        "-f", "-N",
        "-o", "StrictHostKeyChecking=no",
        "-o", "ConnectTimeout=10",
        f"{os.getenv('SSH_USER', 'divezone')}@{os.getenv('SSH_HOST', 'divezonededyk.smarthost.pl')}",
    ]
    result = subprocess.run(ssh_cmd, capture_output=True, text=True)
    if result.returncode != 0:
        raise ConnectionError(f"SSH tunnel failed: {result.stderr}")
    logger.info("SSH tunnel otwarty na localhost:%d", LOCAL_MYSQL_PORT)
    time.sleep(1)


def close_ssh_tunnel():
    """Zamyka SSH tunnel."""
    subprocess.run(["pkill", "-f", "ssh.*33060.*divezonededyk"], capture_output=True)
    logger.info("SSH tunnel zamknięty")


def get_mysql_connection():
    """Zwraca połączenie z MySQL PrestaShop przez tunel."""
    return pymysql.connect(
        host="127.0.0.1",
        port=LOCAL_MYSQL_PORT,
        user=os.getenv("DB_USER"),
        password=os.getenv("DB_PASSWORD"),
        database=os.getenv("DB_NAME_PROD", "divezone_2025"),
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
    )


def strip_html(text: str) -> str:
    """Usuwa HTML z tekstu. Zwraca czysty tekst."""
    if not text:
        return ""
    soup = BeautifulSoup(text, "html.parser")
    clean = soup.get_text(separator=" ", strip=True)
    # Usuń wielokrotne spacje
    clean = re.sub(r"\s+", " ", clean).strip()
    return clean


def build_document_text(product: dict) -> str:
    """Buduje tekst dokumentowy do embeddingu."""
    parts = [f"Produkt: {product['product_name']}"]

    if product.get("brand_name"):
        parts.append(f"Marka: {product['brand_name']}")

    if product.get("category_name"):
        parts.append(f"Kategoria: {product['category_name']}")

    if product.get("price_brutto"):
        parts.append(f"Cena: {product['price_brutto']:.2f} PLN")

    # Opis: najpierw short, potem long jako fallback
    desc = strip_html(product.get("description_short") or "")
    if len(desc) < 20:
        desc = strip_html(product.get("description") or "")
    if desc:
        desc = desc[:MAX_DESCRIPTION_LENGTH]
        parts.append(f"Opis: {desc}")

    # Cechy
    if product.get("features"):
        features_str = ", ".join(f"{k}: {v}" for k, v in product["features"].items())
        parts.append(f"Cechy: {features_str}")

    return "\n".join(parts)


def extract_products(limit: int = None) -> list[dict]:
    """
    Wyciąga produkty z MySQL PrestaShop.

    Args:
        limit: opcjonalny limit produktów (do testów)

    Returns:
        lista słowników z danymi produktów i document_text
    """
    conn = get_mysql_connection()
    cur = conn.cursor()

    # Pobierz produkty
    sql = PRODUCTS_SQL
    if limit:
        sql += f" LIMIT {int(limit)}"
    cur.execute(sql)
    products_raw = cur.fetchall()
    logger.info("Pobrano %d produktów z MySQL", len(products_raw))

    # Pobierz features
    cur.execute(FEATURES_SQL)
    features_raw = cur.fetchall()
    features_map = {}
    for f in features_raw:
        pid = f["id_product"]
        if pid not in features_map:
            features_map[pid] = {}
        features_map[pid][f["feature_name"]] = f["feature_value"]

    # Pobierz cover images
    cur.execute(IMAGES_SQL)
    images_map = {row["id_product"]: row["id_image"] for row in cur.fetchall()}

    conn.close()

    # Złóż produkty
    products = []
    for p in products_raw:
        pid = p["id_product"]
        link_rewrite = p.get("link_rewrite", "")

        product = {
            "ps_product_id": pid,
            "product_name": p["product_name"],
            "product_description": strip_html(p.get("description") or ""),
            "category_name": p.get("category_name"),
            "brand_name": p.get("brand_name"),
            "features": features_map.get(pid, {}),
            "price": float(p["price_brutto"]) if p["price_brutto"] else None,
            "is_active": True,
            "in_stock": (p.get("quantity") or 0) > 0,
            "product_url": f"https://divezone.pl/{link_rewrite}.html" if link_rewrite else None,
            "image_url": None,
        }

        # URL obrazka
        image_id = images_map.get(pid)
        if image_id and link_rewrite:
            product["image_url"] = f"https://divezone.pl/{image_id}-large_default/{link_rewrite}.jpg"

        # Document text do embeddingu
        product["document_text"] = build_document_text({**product, **p})

        products.append(product)

    logger.info("Przygotowano %d produktów z document_text", len(products))
    return products


if __name__ == "__main__":
    open_ssh_tunnel()
    try:
        products = extract_products(limit=5)
        for p in products:
            print(f"\n{'─'*60}")
            print(f"ID: {p['ps_product_id']} | {p['product_name']}")
            print(f"Cena: {p['price']} PLN | Kategoria: {p['category_name']} | Marka: {p['brand_name']}")
            print(f"W magazynie: {p['in_stock']} | Features: {json.dumps(p['features'], ensure_ascii=False)}")
            print(f"URL: {p['product_url']}")
            print(f"IMG: {p['image_url']}")
            print(f"\nDOCUMENT_TEXT:\n{p['document_text']}")
    finally:
        close_ssh_tunnel()
