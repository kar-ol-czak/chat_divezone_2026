"""Pipeline generacji i walidacji encyklopedii sprzetu nurkowego."""

import json
import logging
import re
import shutil
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from pathlib import Path

from config import OUTPUT_DIR, LOGS_DIR
from group_metadata import GroupMetadata, get_group_metadata
from prompt_builder import build_generation_prompt, build_validation_prompt
from json_sanitizer import sanitize_and_parse, SanitizeResult
from models import generate, validate, GenerationResult, ValidationResult

logger = logging.getLogger(__name__)


@dataclass
class Verdict:
    """Werdykt walidacji per pojecie."""
    concept_key: str
    verdict: str   # "PASS", "PASS z uwagami", "FAIL"
    errors: list[str] = field(default_factory=list)
    notes: list[str] = field(default_factory=list)
    missing_synonyms: list[str] = field(default_factory=list)


@dataclass
class PipelineStep:
    """Log jednego kroku pipeline."""
    step: str
    status: str = "ok"
    model: str = ""
    tokens_input: int = 0
    tokens_output: int = 0
    tokens_reasoning: int = 0
    tokens_thinking: int = 0
    cost_usd: float = 0.0
    duration_seconds: float = 0.0
    # Sanitizer
    replacements: dict = field(default_factory=dict)
    json_valid: bool = True
    schema_valid: bool = True
    schema_errors: list[str] = field(default_factory=list)
    entries_count: int = 0
    # Verdict
    pass_count: int = 0
    pass_with_notes_count: int = 0
    fail_count: int = 0
    fail_concepts: list[str] = field(default_factory=list)
    # Extra
    extras: dict = field(default_factory=dict)


class Pipeline:
    """Glowna klasa pipeline generacji encyklopedii."""

    def run(
        self,
        group_id: str,
        dry_run: bool = False,
        gen_only: bool = False,
        val_only: bool = False,
        retry: bool = False,
    ) -> None:
        """Uruchamia pipeline dla danej grupy."""
        group_id = group_id.upper()
        steps: list[PipelineStep] = []
        start_time = datetime.now(timezone.utc)

        # Katalog wyjsciowy
        group_dir = OUTPUT_DIR / f"grupa_{group_id}"
        group_dir.mkdir(parents=True, exist_ok=True)
        LOGS_DIR.mkdir(parents=True, exist_ok=True)

        # 1. Zaladuj metadane
        logger.info(f"=== Grupa {group_id}: Ladowanie metadanych ===")
        metadata = get_group_metadata(group_id)
        logger.info(f"Pojecia: {metadata.concept_count}, Frazy: {len(metadata.dataforseo_phrases)}, "
                     f"Reguly: {len(metadata.domain_rules)}")

        # 2. Generacja
        if not val_only:
            gen_result, gen_step, sanitize_result, sanitize_step = self._run_generation(
                metadata, group_dir, dry_run
            )
            steps.append(gen_step)
            if sanitize_step:
                steps.append(sanitize_step)

            if dry_run:
                self._print_dry_run_report(group_id, metadata, group_dir)
                return

            if not sanitize_result or not sanitize_result.json_valid:
                logger.error("JSON nie jest valid po sanityzacji. Sprawdz output.")
                self._save_log(group_id, steps, start_time)
                self._print_report(group_id, steps)
                return

        # 3. Walidacja
        if not gen_only:
            # Wczytaj JSON do walidacji
            original_path = group_dir / f"grupa_{group_id}_original.json"
            if not original_path.exists():
                logger.error(f"Brak pliku {original_path} — uruchom generacje najpierw")
                return

            generated_json = original_path.read_text(encoding="utf-8")

            val_result, val_step, verdicts, verdict_step = self._run_validation(
                metadata, generated_json, group_dir
            )
            steps.append(val_step)
            if verdict_step:
                steps.append(verdict_step)

            # Jesli 0 FAIL: skopiuj original jako final
            fail_count = sum(1 for v in verdicts if v.verdict == "FAIL") if verdicts else 0
            if fail_count == 0 and verdicts:
                final_path = group_dir / f"grupa_{group_id}_final.json"
                shutil.copy2(original_path, final_path)
                logger.info(f"0 FAIL — skopiowano original jako final: {final_path}")

        # 4. Log i raport
        self._save_log(group_id, steps, start_time)
        self._print_report(group_id, steps)

    def _run_generation(
        self,
        metadata: GroupMetadata,
        group_dir: Path,
        dry_run: bool,
    ) -> tuple[GenerationResult | None, PipelineStep, SanitizeResult | None, PipelineStep | None]:
        """Krok generacji: prompt -> GPT-5.2 -> sanityzacja."""

        # Zbuduj prompt
        prompt = build_generation_prompt(metadata)

        # Zapisz prompt
        prompt_path = group_dir / "prompt_generation.md"
        prompt_path.write_text(prompt, encoding="utf-8")
        logger.info(f"Prompt zapisany: {prompt_path} ({len(prompt)} znakow)")

        if dry_run:
            step = PipelineStep(step="generation", status="dry_run")
            return None, step, None, None

        # Wyslij do GPT-5.2
        logger.info("Wysylanie do GPT-5.2 thinking...")
        gen_result = generate(prompt)
        logger.info(f"Odpowiedz: {len(gen_result.response_text)} znakow, "
                     f"{gen_result.tokens_input} in / {gen_result.tokens_output} out / "
                     f"{gen_result.tokens_reasoning} reasoning, "
                     f"${gen_result.cost_usd:.4f}, {gen_result.duration_seconds}s")

        # Zapisz surowa odpowiedz
        response_path = group_dir / "response_generation.md"
        response_path.write_text(gen_result.response_text, encoding="utf-8")

        gen_step = PipelineStep(
            step="generation",
            model=gen_result.model,
            tokens_input=gen_result.tokens_input,
            tokens_output=gen_result.tokens_output,
            tokens_reasoning=gen_result.tokens_reasoning,
            cost_usd=gen_result.cost_usd,
            duration_seconds=gen_result.duration_seconds,
        )

        # Sanityzacja
        logger.info("Sanityzacja JSON...")
        sanitize_result = sanitize_and_parse(
            gen_result.response_text,
            allowed_brands=metadata.all_allowed_brands,
        )

        sanitize_step = PipelineStep(
            step="json_sanitize",
            replacements=sanitize_result.unicode_replacements,
            json_valid=sanitize_result.json_valid,
            schema_valid=len(sanitize_result.schema_errors) == 0,
            schema_errors=sanitize_result.schema_errors,
            entries_count=sanitize_result.entries_count,
        )

        if not sanitize_result.json_valid:
            sanitize_step.status = "error"
            # Zapisz surowy JSON do debugowania
            error_path = group_dir / f"grupa_{metadata.group_id}_invalid.json"
            error_path.write_text(sanitize_result.raw_json, encoding="utf-8")
            logger.error(f"JSON invalid. Zapisano do {error_path}")
            return gen_result, gen_step, sanitize_result, sanitize_step

        if sanitize_result.schema_errors:
            sanitize_step.status = "warnings"
            for err in sanitize_result.schema_errors:
                logger.warning(f"Schema: {err}")

        if sanitize_result.brand_warnings:
            for warn in sanitize_result.brand_warnings:
                logger.warning(f"Marka: {warn}")

        # Zapisz original JSON
        original_path = group_dir / f"grupa_{metadata.group_id}_original.json"
        original_path.write_text(
            json.dumps(sanitize_result.entries, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        logger.info(f"JSON zapisany: {original_path} ({sanitize_result.entries_count} entries)")

        replacements_total = sum(sanitize_result.unicode_replacements.values())
        logger.info(f"Sanitizer: {replacements_total} zamian Unicode, "
                     f"JSON {'valid' if sanitize_result.json_valid else 'INVALID'}, "
                     f"schema {'valid' if len(sanitize_result.schema_errors) == 0 else 'ERRORS'}, "
                     f"{sanitize_result.entries_count} entries")

        return gen_result, gen_step, sanitize_result, sanitize_step

    def _run_validation(
        self,
        metadata: GroupMetadata,
        generated_json: str,
        group_dir: Path,
    ) -> tuple[ValidationResult, PipelineStep, list[Verdict], PipelineStep | None]:
        """Krok walidacji: prompt -> Claude Opus 4.6 -> parsowanie werdyktow."""

        # Zbuduj prompt
        prompt = build_validation_prompt(metadata, generated_json)

        # Zapisz prompt
        prompt_path = group_dir / "prompt_validation.md"
        prompt_path.write_text(prompt, encoding="utf-8")
        logger.info(f"Prompt walidacyjny zapisany: {prompt_path} ({len(prompt)} znakow)")

        # Wyslij do Claude Opus 4.6
        logger.info("Wysylanie do Claude Opus 4.6 extended thinking...")
        val_result = validate(prompt)
        logger.info(f"Odpowiedz: {len(val_result.response_text)} znakow, "
                     f"{val_result.tokens_input} in / {val_result.tokens_output} out / "
                     f"{val_result.tokens_thinking} thinking, "
                     f"${val_result.cost_usd:.4f}, {val_result.duration_seconds}s")

        # Zapisz surowa odpowiedz
        response_path = group_dir / "response_validation.md"
        response_path.write_text(val_result.response_text, encoding="utf-8")

        val_step = PipelineStep(
            step="validation",
            model=val_result.model,
            tokens_input=val_result.tokens_input,
            tokens_output=val_result.tokens_output,
            tokens_thinking=val_result.tokens_thinking,
            cost_usd=val_result.cost_usd,
            duration_seconds=val_result.duration_seconds,
        )

        # Parsuj werdykty
        verdicts = self._parse_verdicts(val_result.response_text)

        # Zapisz validated.md
        validated_path = group_dir / f"grupa_{metadata.group_id}_validated.md"
        validated_path.write_text(val_result.response_text, encoding="utf-8")

        verdict_step = None
        if verdicts:
            pass_count = sum(1 for v in verdicts if v.verdict == "PASS")
            pass_notes = sum(1 for v in verdicts if v.verdict == "PASS z uwagami")
            fail_count = sum(1 for v in verdicts if v.verdict == "FAIL")
            fail_concepts = [v.concept_key for v in verdicts if v.verdict == "FAIL"]

            verdict_step = PipelineStep(
                step="verdict_parse",
                pass_count=pass_count,
                pass_with_notes_count=pass_notes,
                fail_count=fail_count,
                fail_concepts=fail_concepts,
                status="needs_review" if fail_count > 0 else "ok",
            )

            logger.info(f"Werdykty: {pass_count} PASS, {pass_notes} PASS z uwagami, {fail_count} FAIL")
            if fail_concepts:
                logger.warning(f"FAIL: {', '.join(fail_concepts)}")

        return val_result, val_step, verdicts, verdict_step

    def _parse_verdicts(self, validation_text: str) -> list[Verdict]:
        """Parsuje werdykty PASS/PASS z uwagami/FAIL z odpowiedzi walidatora."""
        verdicts: list[Verdict] = []

        # Szukaj wzorca: ## [nr] CONCEPT_KEY — WERDYKT: PASS/FAIL/PASS z uwagami
        # Lub: ## CONCEPT_KEY — WERDYKT: ...
        pattern = r"##\s*(?:\[?\d+\]?\s*)?(\w+)\s*[-—]+\s*(?:WERDYKT:\s*)?(PASS z uwagami|PASS|FAIL)"
        matches = re.findall(pattern, validation_text, re.IGNORECASE)

        for concept_key, verdict_str in matches:
            verdict_str = verdict_str.strip()
            # Normalizuj
            if verdict_str.upper() == "PASS Z UWAGAMI":
                verdict_str = "PASS z uwagami"
            elif verdict_str.upper() == "PASS":
                verdict_str = "PASS"
            elif verdict_str.upper() == "FAIL":
                verdict_str = "FAIL"

            verdicts.append(Verdict(
                concept_key=concept_key,
                verdict=verdict_str,
            ))

        if not verdicts:
            # Fallback: szukaj PASS/FAIL w dowolnym formacie
            logger.warning("Nie znaleziono werdyktow w standardowym formacie, proba fallback...")
            for line in validation_text.splitlines():
                if "FAIL" in line.upper() and "WERDYKT" in line.upper():
                    # Sprobuj wyciagnac concept key
                    key_match = re.search(r"([A-Z][A-Z0-9_]+)", line)
                    if key_match:
                        verdicts.append(Verdict(
                            concept_key=key_match.group(1),
                            verdict="FAIL",
                        ))
                elif "PASS" in line.upper() and "WERDYKT" in line.upper():
                    key_match = re.search(r"([A-Z][A-Z0-9_]+)", line)
                    if key_match:
                        v = "PASS z uwagami" if "uwag" in line.lower() else "PASS"
                        verdicts.append(Verdict(
                            concept_key=key_match.group(1),
                            verdict=v,
                        ))

        return verdicts

    def _save_log(self, group_id: str, steps: list[PipelineStep], start_time: datetime) -> None:
        """Zapisuje strukturalny log do JSON."""
        end_time = datetime.now(timezone.utc)
        duration = (end_time - start_time).total_seconds()

        total_cost = sum(s.cost_usd for s in steps)
        total_input = sum(s.tokens_input for s in steps)
        total_output = sum(s.tokens_output for s in steps)
        total_reasoning = sum(s.tokens_reasoning for s in steps)
        total_thinking = sum(s.tokens_thinking for s in steps)

        # Wynik
        result = "ok"
        for s in steps:
            if s.status == "error":
                result = "error"
                break
            if s.status == "needs_review":
                result = "needs_review"

        log_data = {
            "group": group_id,
            "timestamp_start": start_time.isoformat(),
            "timestamp_end": end_time.isoformat(),
            "duration_seconds": round(duration, 1),
            "steps": [
                {k: v for k, v in asdict(s).items() if v or k == "step"}
                for s in steps
            ],
            "total_cost_usd": round(total_cost, 4),
            "total_tokens": {
                "input": total_input,
                "output": total_output,
                "reasoning": total_reasoning,
                "thinking": total_thinking,
            },
            "result": result,
        }

        timestamp_str = start_time.strftime("%Y-%m-%dT%H%M%S")
        log_path = LOGS_DIR / f"grupa_{group_id}_{timestamp_str}.json"
        log_path.write_text(json.dumps(log_data, ensure_ascii=False, indent=2), encoding="utf-8")
        logger.info(f"Log zapisany: {log_path}")

    def _print_report(self, group_id: str, steps: list[PipelineStep]) -> None:
        """Wyswietla raport na stdout."""
        from group_metadata import GROUP_NAMES
        group_name = GROUP_NAMES.get(group_id, group_id)

        print(f"\n{'=' * 60}")
        print(f"=== GRUPA {group_id}: {group_name} ===")
        print(f"{'=' * 60}")

        for s in steps:
            if s.step == "generation":
                print(f"Generacja: {s.model} | "
                      f"{s.tokens_input} in / {s.tokens_output} out / {s.tokens_reasoning} reasoning | "
                      f"${s.cost_usd:.4f} | {s.duration_seconds}s")
            elif s.step == "json_sanitize":
                replacements_total = sum(s.replacements.values()) if s.replacements else 0
                print(f"Sanitizer: {replacements_total} zamian Unicode, "
                      f"JSON {'valid' if s.json_valid else 'INVALID'}, "
                      f"schema {'valid' if s.schema_valid else 'ERRORS'}, "
                      f"{s.entries_count} entries")
                if s.schema_errors:
                    for err in s.schema_errors[:5]:
                        print(f"  ! {err}")
            elif s.step == "validation":
                print(f"Walidacja: {s.model} | "
                      f"{s.tokens_input} in / {s.tokens_output} out / {s.tokens_thinking} thinking | "
                      f"${s.cost_usd:.4f} | {s.duration_seconds}s")
            elif s.step == "verdict_parse":
                print(f"Werdykt: {s.pass_count} PASS, "
                      f"{s.pass_with_notes_count} PASS z uwagami, "
                      f"{s.fail_count} FAIL"
                      + (f" ({', '.join(s.fail_concepts)})" if s.fail_concepts else ""))

        total_cost = sum(s.cost_usd for s in steps)
        total_duration = sum(s.duration_seconds for s in steps)
        minutes = int(total_duration // 60)
        seconds = int(total_duration % 60)
        print(f"Koszt laczny: ${total_cost:.4f} | Czas: {minutes}m {seconds}s")
        print(f"{'=' * 60}\n")

    def _print_dry_run_report(self, group_id: str, metadata: GroupMetadata, group_dir: Path) -> None:
        """Raport dry-run: wyswietla informacje o prompcie."""
        from group_metadata import GROUP_NAMES
        group_name = GROUP_NAMES.get(group_id, group_id)
        prompt_path = group_dir / "prompt_generation.md"
        prompt_size = prompt_path.stat().st_size if prompt_path.exists() else 0

        print(f"\n{'=' * 60}")
        print(f"=== DRY RUN: GRUPA {group_id}: {group_name} ===")
        print(f"{'=' * 60}")
        print(f"Pojecia: {metadata.concept_count}")
        print(f"Frazy DataForSEO: {len(metadata.dataforseo_phrases)}")
        print(f"Reguly domenowe: {len(metadata.domain_rules)}")
        print(f"Znane bledy v1: {len(metadata.v1_known_errors)}")
        print(f"Marki dozwolone: {len(metadata.all_allowed_brands)}")
        print(f"Prompt zapisany: {prompt_path} ({prompt_size} bytes)")
        print(f"{'=' * 60}\n")
