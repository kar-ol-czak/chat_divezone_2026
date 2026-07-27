"""Pipeline generacji i walidacji encyklopedii sprzetu nurkowego."""

import json
import logging
import re
import shutil
from dataclasses import dataclass, field, asdict
from datetime import datetime, timezone
from pathlib import Path

import math

from config import OUTPUT_DIR, LOGS_DIR, MAX_CONCEPTS_PER_BATCH
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

        # 2b. --gen-only: sprawdz kompletnosc JSON
        if gen_only and sanitize_result and sanitize_result.json_valid:
            self._check_json_completeness(metadata, group_dir)

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
        """Krok generacji: prompt -> GPT-5.2 -> sanityzacja.

        Automatycznie dzieli grupy >MAX_CONCEPTS_PER_BATCH na sub-batche.
        """
        needs_split = metadata.concept_count > MAX_CONCEPTS_PER_BATCH

        if needs_split:
            return self._run_generation_sub_batches(metadata, group_dir, dry_run)
        else:
            return self._run_generation_single(metadata, group_dir, dry_run)

    def _run_generation_single(
        self,
        metadata: GroupMetadata,
        group_dir: Path,
        dry_run: bool,
        *,
        sub_batch_concepts: list | None = None,
        batch_number: int = 0,
        total_batches: int = 0,
    ) -> tuple[GenerationResult | None, PipelineStep, SanitizeResult | None, PipelineStep | None]:
        """Generacja pojedynczego batcha (lub calej grupy jesli <=8 pojec)."""
        suffix = f"_{batch_number}" if batch_number > 0 else ""
        step_name = f"generation_batch_{batch_number}" if batch_number > 0 else "generation"

        # Zbuduj prompt
        prompt = build_generation_prompt(
            metadata,
            sub_batch_concepts=sub_batch_concepts,
            batch_number=batch_number,
            total_batches=total_batches,
        )

        # Zapisz prompt
        prompt_path = group_dir / f"prompt_generation{suffix}.md"
        prompt_path.write_text(prompt, encoding="utf-8")
        logger.info(f"Prompt zapisany: {prompt_path} ({len(prompt)} znakow)")

        if dry_run:
            step = PipelineStep(step=step_name, status="dry_run")
            return None, step, None, None

        # Wyslij do GPT-5.2
        logger.info(f"Wysylanie do GPT-5.2 thinking{f' (batch {batch_number}/{total_batches})' if batch_number else ''}...")
        gen_result = generate(prompt)
        logger.info(f"Odpowiedz: {len(gen_result.response_text)} znakow, "
                     f"{gen_result.tokens_input} in / {gen_result.tokens_output} out / "
                     f"{gen_result.tokens_reasoning} reasoning, "
                     f"${gen_result.cost_usd:.4f}, {gen_result.duration_seconds}s")

        # Zapisz surowa odpowiedz
        response_path = group_dir / f"response_generation{suffix}.md"
        response_path.write_text(gen_result.response_text, encoding="utf-8")

        gen_step = PipelineStep(
            step=step_name,
            model=gen_result.model,
            tokens_input=gen_result.tokens_input,
            tokens_output=gen_result.tokens_output,
            tokens_reasoning=gen_result.tokens_reasoning,
            cost_usd=gen_result.cost_usd,
            duration_seconds=gen_result.duration_seconds,
        )

        return gen_result, gen_step, None, None

    def _run_generation_sub_batches(
        self,
        metadata: GroupMetadata,
        group_dir: Path,
        dry_run: bool,
    ) -> tuple[GenerationResult | None, PipelineStep, SanitizeResult | None, PipelineStep | None]:
        """Generacja z podzialom na sub-batche dla grup >8 pojec."""
        all_concepts = metadata.concepts
        total_batches = math.ceil(len(all_concepts) / MAX_CONCEPTS_PER_BATCH)

        logger.info(f"Grupa {metadata.group_id}: {metadata.concept_count} pojec -> "
                     f"{total_batches} sub-batchy po max {MAX_CONCEPTS_PER_BATCH}")

        all_steps: list[PipelineStep] = []
        all_entries: list[dict] = []
        total_cost = 0.0
        total_duration = 0.0
        total_input = 0
        total_output = 0
        total_reasoning = 0
        last_model = ""

        for batch_idx in range(total_batches):
            start = batch_idx * MAX_CONCEPTS_PER_BATCH
            end = start + MAX_CONCEPTS_PER_BATCH
            sub_concepts = all_concepts[start:end]
            batch_num = batch_idx + 1

            logger.info(f"--- Sub-batch {batch_num}/{total_batches}: "
                         f"{len(sub_concepts)} pojec ({', '.join(c.key for c in sub_concepts)}) ---")

            gen_result, gen_step, _, _ = self._run_generation_single(
                metadata, group_dir, dry_run,
                sub_batch_concepts=sub_concepts,
                batch_number=batch_num,
                total_batches=total_batches,
            )
            all_steps.append(gen_step)

            if dry_run:
                continue

            # Sanityzuj JSON z tego batcha (tylko parsowanie, walidacja na koncu)
            batch_sanitize = sanitize_and_parse(
                gen_result.response_text,
                allowed_brands=metadata.all_allowed_brands,
            )

            if not batch_sanitize.json_valid:
                error_path = group_dir / f"grupa_{metadata.group_id}_batch_{batch_num}_invalid.json"
                error_path.write_text(batch_sanitize.raw_json, encoding="utf-8")
                logger.error(f"JSON invalid w batch {batch_num}. Zapisano do {error_path}")
                # Zwroc blad z sumarycznym stepem
                summary_step = PipelineStep(
                    step="generation",
                    model=gen_result.model,
                    tokens_input=total_input + gen_result.tokens_input,
                    tokens_output=total_output + gen_result.tokens_output,
                    tokens_reasoning=total_reasoning + gen_result.tokens_reasoning,
                    cost_usd=round(total_cost + gen_result.cost_usd, 4),
                    duration_seconds=round(total_duration + gen_result.duration_seconds, 1),
                    extras={"sub_batches": total_batches, "failed_batch": batch_num},
                )
                sanitize_step = PipelineStep(
                    step="json_sanitize", status="error",
                    json_valid=False, entries_count=len(all_entries),
                )
                return gen_result, summary_step, batch_sanitize, sanitize_step

            all_entries.extend(batch_sanitize.entries)
            total_cost += gen_result.cost_usd
            total_duration += gen_result.duration_seconds
            total_input += gen_result.tokens_input
            total_output += gen_result.tokens_output
            total_reasoning += gen_result.tokens_reasoning
            last_model = gen_result.model

            logger.info(f"Batch {batch_num}: {batch_sanitize.entries_count} entries OK")

        if dry_run:
            # Zwroc sumaryczny step dry-run
            summary_step = PipelineStep(
                step="generation", status="dry_run",
                extras={"sub_batches": total_batches},
            )
            return None, summary_step, None, None

        # Polaczony JSON — schema + brand validation
        logger.info(f"Polaczono {len(all_entries)} entries z {total_batches} batchy. "
                     "Walidacja schema + marki na polaczonym JSON...")
        merged_sanitize = sanitize_and_parse(
            json.dumps(all_entries, ensure_ascii=False),
            allowed_brands=metadata.all_allowed_brands,
        )

        # Zapisz polaczony original JSON
        original_path = group_dir / f"grupa_{metadata.group_id}_original.json"
        original_path.write_text(
            json.dumps(all_entries, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        logger.info(f"JSON polaczony zapisany: {original_path} ({len(all_entries)} entries)")

        # Sumaryczny step generacji
        summary_step = PipelineStep(
            step="generation",
            model=last_model,
            tokens_input=total_input,
            tokens_output=total_output,
            tokens_reasoning=total_reasoning,
            cost_usd=round(total_cost, 4),
            duration_seconds=round(total_duration, 1),
            extras={"sub_batches": total_batches},
        )

        # Sanitize step na merged
        sanitize_step = PipelineStep(
            step="json_sanitize",
            replacements=merged_sanitize.unicode_replacements,
            json_valid=merged_sanitize.json_valid,
            schema_valid=len(merged_sanitize.schema_errors) == 0,
            schema_errors=merged_sanitize.schema_errors,
            entries_count=len(all_entries),
        )

        if merged_sanitize.schema_errors:
            sanitize_step.status = "warnings"
            for err in merged_sanitize.schema_errors:
                logger.warning(f"Schema: {err}")

        if merged_sanitize.brand_warnings:
            for warn in merged_sanitize.brand_warnings:
                logger.warning(f"Marka: {warn}")

        return None, summary_step, merged_sanitize, sanitize_step

    def _check_json_completeness(self, metadata: GroupMetadata, group_dir: Path) -> None:
        """Sprawdza kompletnosc JSON: czy wszystkie pojecia sa obecne."""
        original_path = group_dir / f"grupa_{metadata.group_id}_original.json"
        if not original_path.exists():
            return

        entries = json.loads(original_path.read_text(encoding="utf-8"))
        generated_keys = {e.get("id", "") for e in entries}
        expected_keys = {c.key for c in metadata.concepts}

        missing = expected_keys - generated_keys
        extra = generated_keys - expected_keys

        print(f"\n--- Kompletnosc JSON (--gen-only) ---")
        print(f"Oczekiwano: {len(expected_keys)} pojec")
        print(f"Wygenerowano: {len(generated_keys)} pojec")
        if missing:
            print(f"BRAKUJACE: {', '.join(sorted(missing))}")
        if extra:
            print(f"NADMIAROWE: {', '.join(sorted(extra))}")
        if not missing and not extra:
            print(f"Status: KOMPLETNY ({len(generated_keys)}/{len(expected_keys)})")
        else:
            print(f"Status: NIEKOMPLETNY ({len(generated_keys)}/{len(expected_keys)})")
        print()

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

        # Aktualizuj index logow dla dashboardu
        self._update_log_index()

    def _update_log_index(self) -> None:
        """Aktualizuje output/logs/index.json dla dashboardu."""
        log_files = sorted(
            [f.name for f in LOGS_DIR.glob("grupa_*.json") if f.name != "index.json"],
            reverse=True,
        )
        index_path = LOGS_DIR / "index.json"
        index_path.write_text(json.dumps(log_files, ensure_ascii=False), encoding="utf-8")

    def _print_report(self, group_id: str, steps: list[PipelineStep]) -> None:
        """Wyswietla raport na stdout."""
        from group_metadata import GROUP_NAMES
        group_name = GROUP_NAMES.get(group_id, group_id)

        print(f"\n{'=' * 60}")
        print(f"=== GRUPA {group_id}: {group_name} ===")
        print(f"{'=' * 60}")

        for s in steps:
            if s.step == "generation":
                sub_info = f" ({s.extras['sub_batches']} batchy)" if s.extras.get("sub_batches") else ""
                print(f"Generacja{sub_info}: {s.model} | "
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

        total_batches = math.ceil(metadata.concept_count / MAX_CONCEPTS_PER_BATCH)
        needs_split = metadata.concept_count > MAX_CONCEPTS_PER_BATCH

        print(f"\n{'=' * 60}")
        print(f"=== DRY RUN: GRUPA {group_id}: {group_name} ===")
        print(f"{'=' * 60}")
        print(f"Pojecia: {metadata.concept_count}")
        if needs_split:
            print(f"Sub-batche: {total_batches} (po max {MAX_CONCEPTS_PER_BATCH} pojec)")
            for batch_idx in range(total_batches):
                start = batch_idx * MAX_CONCEPTS_PER_BATCH
                end = start + MAX_CONCEPTS_PER_BATCH
                sub = metadata.concepts[start:end]
                prompt_path = group_dir / f"prompt_generation_{batch_idx + 1}.md"
                prompt_size = prompt_path.stat().st_size if prompt_path.exists() else 0
                print(f"  Batch {batch_idx + 1}: {len(sub)} pojec "
                      f"({', '.join(c.key for c in sub)}) — {prompt_size} bytes")
        else:
            prompt_path = group_dir / "prompt_generation.md"
            prompt_size = prompt_path.stat().st_size if prompt_path.exists() else 0
            print(f"Prompt zapisany: {prompt_path} ({prompt_size} bytes)")
        print(f"Frazy DataForSEO: {len(metadata.dataforseo_phrases)}")
        print(f"Reguly domenowe: {len(metadata.domain_rules)}")
        print(f"Znane bledy v1: {len(metadata.v1_known_errors)}")
        print(f"Marki dozwolone: {len(metadata.all_allowed_brands)}")
        print(f"{'=' * 60}\n")
