"""
divechat_provider.py — custom Promptfoo provider dla DiveChat DEV endpoint.

Per test Promptfoo wywołuje `call_api(prompt, options, context)`. Provider:
  1. Czyta scenariusz YAML z _redteam/scenarios/{category}/{id}.yaml
     (id przekazane przez context['vars']['scenario_id']).
  2. Generuje HMAC token (DIVECHAT_SECRET z .env w project root).
  3. Realizuje multi-turn dialog (attack_steps z obsługą if_target_says regex).
  4. Zwraca dict {output, metadata} z pełnym transcriptem.

Promptfoo wczytuje plik jako moduł, więc importy/ścieżki rozwiązujemy
po PROJECT_ROOT (parent dir of _redteam/).
"""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import re
import time
import uuid
from pathlib import Path
from urllib import error, request

import yaml

PROJECT_ROOT = Path(__file__).resolve().parents[2]
SCENARIOS_DIR = PROJECT_ROOT / "_redteam" / "scenarios"
ENV_FILE = PROJECT_ROOT / ".env"
DEFAULT_API_BASE = "https://chat.divezone.pl"
HTTP_TIMEOUT_SEC = 60


def _load_env() -> dict[str, str]:
    if not ENV_FILE.exists():
        return {}
    env: dict[str, str] = {}
    for line in ENV_FILE.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        env[k.strip()] = v.strip().strip('"').strip("'")
    return env


def _generate_hmac(secret: str, customer_id: int, timestamp: int) -> str:
    msg = f"{customer_id}:{timestamp}".encode("utf-8")
    return hmac.new(secret.encode("utf-8"), msg, hashlib.sha256).hexdigest()


def _find_scenario(scenario_id: str) -> dict:
    """Locate scenario YAML by id (search across all class subdirs)."""
    for yaml_file in SCENARIOS_DIR.rglob("*.yaml"):
        try:
            with yaml_file.open("r", encoding="utf-8") as f:
                doc = yaml.safe_load(f)
        except yaml.YAMLError:
            continue
        if isinstance(doc, dict) and doc.get("id") == scenario_id:
            return doc
    raise FileNotFoundError(f"scenario_id={scenario_id!r} not found in {SCENARIOS_DIR}")


def _post_chat(api_base: str, headers: dict[str, str], session_id: str,
               message: str) -> dict:
    body = json.dumps({"message": message, "session_id": session_id}).encode("utf-8")
    req = request.Request(
        url=f"{api_base}/api/chat",
        data=body,
        headers={**headers, "Content-Type": "application/json"},
        method="POST",
    )
    try:
        with request.urlopen(req, timeout=HTTP_TIMEOUT_SEC) as resp:
            raw = resp.read().decode("utf-8")
            return json.loads(raw)
    except error.HTTPError as e:
        raw = e.read().decode("utf-8", errors="replace")
        return {"success": False, "http_status": e.code, "error_body": raw}
    except (error.URLError, TimeoutError) as e:
        return {"success": False, "transport_error": str(e)}


def _matches_if_target(condition: str | None, last_assistant: str) -> bool:
    """if_target_says regex — case-insensitive match on the last assistant turn."""
    if condition is None:
        return True
    try:
        return re.search(condition, last_assistant, re.IGNORECASE) is not None
    except re.error:
        return False


def _run_scenario(scn: dict, api_base: str, secret: str) -> dict:
    customer_id = 0
    transcript: list[dict] = []
    session_id = f"redteam-{scn['id']}-{uuid.uuid4().hex[:8]}"
    last_assistant = ""
    tools_used_all: list[str] = []
    products_all: list[dict] = []
    usage_total: dict[str, int] = {}

    for idx, step in enumerate(scn.get("attack_steps", []) or []):
        if step.get("role") != "user":
            continue
        if not _matches_if_target(step.get("if_target_says"), last_assistant):
            transcript.append({"turn": idx, "skipped": True,
                               "phase": step.get("phase"),
                               "reason": "if_target_says no match"})
            continue

        # fresh HMAC per turn — token TTL 300s, multi-turn moze przekroczyc
        timestamp = int(time.time())
        token = _generate_hmac(secret, customer_id, timestamp)
        headers = {
            "X-DiveChat-Token": token,
            "X-DiveChat-Customer": str(customer_id),
            "X-DiveChat-Time": str(timestamp),
        }
        user_msg = step.get("content", "")
        resp = _post_chat(api_base, headers, session_id, user_msg)

        if not resp.get("success"):
            transcript.append({"turn": idx, "phase": step.get("phase"),
                               "user": user_msg, "error": resp})
            return {
                "output": "",
                "transcript": transcript,
                "session_id": session_id,
                "error": f"API call failed at turn {idx}: {resp}",
            }

        assistant = resp.get("response", "")
        last_assistant = assistant
        tools = resp.get("tools_used", []) or []
        tools_used_all.extend(tools if isinstance(tools, list) else [])
        prods = resp.get("products", []) or []
        if isinstance(prods, list):
            products_all.extend(prods)
        usage = resp.get("usage", {}) or {}
        for k, v in usage.items():
            if isinstance(v, (int, float)):
                usage_total[k] = usage_total.get(k, 0) + v

        transcript.append({
            "turn": idx,
            "phase": step.get("phase"),
            "user": user_msg,
            "assistant": assistant,
            "tools_used": tools,
            "products_count": len(prods) if isinstance(prods, list) else 0,
        })

    return {
        "output": last_assistant,
        "transcript": transcript,
        "session_id": session_id,
        "tools_used_all": tools_used_all,
        "products_count_all": len(products_all),
        "usage_total": usage_total,
    }


def call_api(prompt, options=None, context=None):
    """Promptfoo Python provider entry point.

    Promptfoo passes:
      - prompt: rendered prompt string (we ignore — scenario_id drives behavior)
      - options: provider config from promptfoo.yaml
      - context: {'vars': {...}} — we read scenario_id from vars

    Returns dict consumable by Promptfoo:
      {'output': str, 'metadata': {...}}
    """
    options = options or {}
    context = context or {}
    vars_ = context.get("vars", {}) or {}
    scenario_id = vars_.get("scenario_id")
    if not scenario_id:
        return {"output": "", "error": "missing context.vars.scenario_id"}

    env = _load_env()
    secret = env.get("DIVECHAT_SECRET")
    if not secret:
        return {"output": "", "error": f"DIVECHAT_SECRET not in {ENV_FILE}"}
    api_base = options.get("config", {}).get("api_base", DEFAULT_API_BASE)

    try:
        scn = _find_scenario(scenario_id)
    except FileNotFoundError as e:
        return {"output": "", "error": str(e)}

    result = _run_scenario(scn, api_base, secret)
    return {
        "output": result.pop("output"),
        "metadata": {
            "scenario_id": scenario_id,
            "category": scn.get("category"),
            "severity": scn.get("severity"),
            **result,
        },
    }


if __name__ == "__main__":
    # quick sanity: python divechat_provider.py JAIL-001
    import sys

    sid = sys.argv[1] if len(sys.argv) > 1 else "JAIL-001"
    out = call_api("", options={}, context={"vars": {"scenario_id": sid}})
    print(json.dumps(out, ensure_ascii=False, indent=2)[:3000])
