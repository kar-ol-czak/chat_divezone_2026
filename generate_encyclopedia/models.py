"""Klienty API: GPT-5.2 (generacja) i Claude Opus 4.6 (walidacja)."""

import time
import logging
from dataclasses import dataclass, field

import openai
import anthropic

from config import (
    OPENAI_API_KEY,
    ANTHROPIC_API_KEY,
    GENERATION_MODEL,
    GENERATION_REASONING_EFFORT,
    GENERATION_TEMPERATURE,
    GENERATION_MAX_OUTPUT_TOKENS,
    VALIDATION_MODEL,
    VALIDATION_EXTENDED_THINKING,
    VALIDATION_BUDGET_TOKENS,
    VALIDATION_TEMPERATURE,
    VALIDATION_MAX_TOKENS,
    MAX_RETRIES,
    RETRY_BASE_DELAY,
    calculate_cost,
)

logger = logging.getLogger(__name__)


@dataclass
class GenerationResult:
    """Wynik generacji z GPT-5.2."""
    response_text: str
    tokens_input: int = 0
    tokens_output: int = 0
    tokens_reasoning: int = 0
    duration_seconds: float = 0.0
    cost_usd: float = 0.0
    model: str = GENERATION_MODEL


@dataclass
class ValidationResult:
    """Wynik walidacji z Claude Opus 4.6."""
    response_text: str
    tokens_input: int = 0
    tokens_output: int = 0
    tokens_thinking: int = 0
    duration_seconds: float = 0.0
    cost_usd: float = 0.0
    model: str = VALIDATION_MODEL


def _retry_with_backoff(func, max_retries: int = MAX_RETRIES, base_delay: float = RETRY_BASE_DELAY):
    """Wrapper retry z exponential backoff."""
    last_error = None
    for attempt in range(max_retries):
        try:
            return func()
        except (openai.RateLimitError, openai.APITimeoutError, openai.APIConnectionError,
                anthropic.RateLimitError, anthropic.APITimeoutError, anthropic.APIConnectionError) as e:
            last_error = e
            delay = base_delay * (2 ** attempt)
            logger.warning(f"Attempt {attempt + 1}/{max_retries} failed: {e}. Retry in {delay}s...")
            time.sleep(delay)
        except (openai.APIError, anthropic.APIError) as e:
            # Bledy 5xx: retry; 4xx: nie retry
            status = getattr(e, "status_code", 500)
            if status >= 500:
                last_error = e
                delay = base_delay * (2 ** attempt)
                logger.warning(f"Attempt {attempt + 1}/{max_retries} server error: {e}. Retry in {delay}s...")
                time.sleep(delay)
            else:
                raise
    raise last_error


def generate(prompt: str) -> GenerationResult:
    """Wysyla prompt do GPT-5.2 thinking i zwraca wynik."""
    client = openai.OpenAI(api_key=OPENAI_API_KEY)
    start = time.time()

    def _call():
        return client.responses.create(
            model=GENERATION_MODEL,
            input=[{"role": "user", "content": prompt}],
            reasoning={"effort": GENERATION_REASONING_EFFORT},
            temperature=GENERATION_TEMPERATURE,
            max_output_tokens=GENERATION_MAX_OUTPUT_TOKENS,
        )

    response = _retry_with_backoff(_call)
    duration = time.time() - start

    # Wyciagniecie tekstu odpowiedzi
    response_text = ""
    for item in response.output:
        if item.type == "message":
            for block in item.content:
                if block.type == "output_text":
                    response_text += block.text

    # Tokeny z usage
    usage = response.usage
    tokens_input = usage.input_tokens if usage else 0
    tokens_output = usage.output_tokens if usage else 0
    tokens_reasoning = getattr(usage, "reasoning_tokens", 0) if usage else 0

    # Koszt: reasoning tokens sa rozliczane jako output
    total_output = tokens_output + tokens_reasoning
    cost = calculate_cost(GENERATION_MODEL, tokens_input, total_output)

    return GenerationResult(
        response_text=response_text,
        tokens_input=tokens_input,
        tokens_output=tokens_output,
        tokens_reasoning=tokens_reasoning,
        duration_seconds=round(duration, 1),
        cost_usd=cost,
    )


def validate(prompt: str) -> ValidationResult:
    """Wysyla prompt do Claude Opus 4.6 extended thinking i zwraca wynik."""
    client = anthropic.Anthropic(api_key=ANTHROPIC_API_KEY)
    start = time.time()

    def _call():
        return client.messages.create(
            model=VALIDATION_MODEL,
            max_tokens=VALIDATION_MAX_TOKENS,
            temperature=VALIDATION_TEMPERATURE,
            thinking={
                "type": "enabled",
                "budget_tokens": VALIDATION_BUDGET_TOKENS,
            },
            messages=[{"role": "user", "content": prompt}],
        )

    response = _retry_with_backoff(_call)
    duration = time.time() - start

    # Wyciagniecie tekstu odpowiedzi (pomijamy bloki thinking)
    response_text = ""
    for block in response.content:
        if block.type == "text":
            response_text += block.text

    # Tokeny
    usage = response.usage
    tokens_input = usage.input_tokens if usage else 0
    tokens_output = usage.output_tokens if usage else 0
    # Thinking tokens sa w cache_read lub osobnym polu
    tokens_thinking = 0
    if usage and hasattr(usage, "cache_creation_input_tokens"):
        tokens_thinking = getattr(usage, "cache_creation_input_tokens", 0)
    # Anthropic API: thinking tokens sa czescia output_tokens

    cost = calculate_cost(VALIDATION_MODEL, tokens_input, tokens_output)

    return ValidationResult(
        response_text=response_text,
        tokens_input=tokens_input,
        tokens_output=tokens_output,
        tokens_thinking=tokens_thinking,
        duration_seconds=round(duration, 1),
        cost_usd=cost,
    )
