"""Konfiguracja pipeline generacji encyklopedii."""

import os
from pathlib import Path

from dotenv import load_dotenv

# --- Sciezki ---
PROJECT_ROOT = Path(__file__).resolve().parent.parent
PIPELINE_DIR = Path(__file__).resolve().parent

# Ladowanie .env z project root
load_dotenv(PROJECT_ROOT / ".env")

# Pliki wejsciowe (wzgledne do PROJECT_ROOT)
FAZA1_PATH = PROJECT_ROOT / "_docs" / "FAZA1_concept_keys_v2.md"
BRANDS_PATH = PROJECT_ROOT / "_docs" / "11_mapa_marek-reviewed.md"
DOMAIN_RULES_PATH = PROJECT_ROOT / "_docs" / "17_reguly_domenowe_grupy_C-M.md"
DATAFORSEO_CSV_PATH = PROJECT_ROOT / "data" / "dataforseo" / "processed" / "all_keywords.csv"
RAW_DIR = PROJECT_ROOT / "data" / "encyclopedia" / "raw"
GRUPA_A_PATH = PROJECT_ROOT / "data" / "encyclopedia" / "grupa_A_oddychanie.json"
GRUPA_B_PATH = PROJECT_ROOT / "data" / "encyclopedia" / "grupa_B_butle_zawory.json"
PROMPT_GEN_A_PATH = PROJECT_ROOT / "_docs" / "prompts" / "PROMPT_encyklopedia_grupa_A.md"
PROMPT_VAL_A_PATH = PROJECT_ROOT / "_docs" / "prompts" / "PROMPT_walidacja_grupa_A.md"
PROMPT_GEN_B_PATH = PROJECT_ROOT / "_docs" / "prompts" / "PROMPT_encyklopedia_grupa_B.md"
PROMPT_VAL_B_PATH = PROJECT_ROOT / "_docs" / "prompts" / "PROMPT_walidacja_grupa_B.md"

# Wyjscie
OUTPUT_DIR = PIPELINE_DIR / "output"
LOGS_DIR = OUTPUT_DIR / "logs"
TEMPLATES_DIR = PIPELINE_DIR / "templates"

# --- Klucze API ---
OPENAI_API_KEY = os.getenv("OPENAI_API_KEY", "")
ANTHROPIC_API_KEY = os.getenv("ANTHROPIC_API_KEY", "")

# --- Modele AI ---

# Generacja: GPT-5.2 thinking
GENERATION_MODEL = "gpt-5.2"
# UWAGA: GPT-5.2 default = none. Jawne ustawienie jest KRYTYCZNE.
# Opcje: none, low, medium, high, xhigh
GENERATION_REASONING_EFFORT = "high"
GENERATION_TEMPERATURE = 0.4
GENERATION_MAX_OUTPUT_TOKENS = 16_000

# Sub-batche: grupy >8 pojec dzielone na czesci (limit 16k output tokens)
MAX_CONCEPTS_PER_BATCH = 8

# Walidacja: Claude Opus 4.6 adaptive thinking
VALIDATION_MODEL = "claude-opus-4-6"
VALIDATION_EXTENDED_THINKING = True
VALIDATION_BUDGET_TOKENS = 16_000
VALIDATION_TEMPERATURE = 1.0  # wymagane przez API przy extended thinking
VALIDATION_MAX_TOKENS = 32_000  # musi byc > budget_tokens

# --- Retry ---
MAX_RETRIES = 3
RETRY_BASE_DELAY = 2.0  # sekundy, exponential backoff

# --- Cennik per milion tokenow (USD) ---
# GPT-5.2: https://pricepertoken.com/pricing-page/model/openai-gpt-5.2
# Reasoning tokens rozliczane jako output tokens
PRICING = {
    "gpt-5.2": {
        "input": 1.75,       # $/M tokenow
        "output": 14.00,     # $/M tokenow (w tym reasoning)
        "cached_input": 0.175,
    },
    # Claude Opus 4.6: https://pricepertoken.com/pricing-page/model/anthropic-claude-opus-4.6
    # Thinking tokens rozliczane jako output tokens
    "claude-opus-4-6": {
        "input": 5.00,       # $/M tokenow
        "output": 25.00,     # $/M tokenow (w tym thinking)
        "cached_input": 0.50,
    },
}


def calculate_cost(model: str, tokens_input: int, tokens_output: int) -> float:
    """Oblicza koszt w USD na podstawie cennika."""
    prices = PRICING.get(model, {})
    if not prices:
        return 0.0
    cost_in = (tokens_input / 1_000_000) * prices["input"]
    cost_out = (tokens_output / 1_000_000) * prices["output"]
    return round(cost_in + cost_out, 4)
