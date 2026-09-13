from __future__ import annotations

import hashlib
import json
import logging
import re
import time
from typing import Any

from pydantic import ValidationError

from app.schemas.research import CandidateEnvelope


logger = logging.getLogger(__name__)


class StructuredResponseError(RuntimeError):
    def __init__(self, kind: str, message: str, validation_errors: list[dict[str, Any]] | None = None) -> None:
        self.kind = kind
        self.validation_errors = validation_errors or []
        super().__init__(message)


def response_fingerprint(raw: str) -> dict[str, Any]:
    """Return diagnostics that identify a response without logging its content."""
    return {
        "characters": len(raw),
        "sha256": hashlib.sha256(raw.encode("utf-8")).hexdigest()[:16],
        "has_fence": "```" in raw,
    }


def log_stage(stage: str, started_at: float, **metadata: Any) -> None:
    safe = {"stage": stage, "elapsed_ms": round((time.monotonic() - started_at) * 1000), **metadata}
    logger.warning("research_diagnostic=%s", json.dumps(safe, sort_keys=True, default=str))


def extract_json_object(raw: str) -> dict[str, Any]:
    text = raw.strip()
    if not text:
        raise StructuredResponseError("empty", "The model returned an empty response")

    fenced = re.search(r"```(?:json)?\s*(.*?)\s*```", text, flags=re.IGNORECASE | re.DOTALL)
    if fenced:
        text = fenced.group(1).strip()

    start = text.find("{")
    if start < 0:
        raise StructuredResponseError("malformed_json", "Could not parse model JSON: no JSON object found")
    def reject_duplicate_keys(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
        value: dict[str, Any] = {}
        for key, item in pairs:
            if key in value:
                raise ValueError(f"duplicate JSON key: {key}")
            value[key] = item
        return value

    try:
        value, _ = json.JSONDecoder(object_pairs_hook=reject_duplicate_keys).raw_decode(text[start:])
    except (json.JSONDecodeError, ValueError) as exc:
        message = exc.msg if isinstance(exc, json.JSONDecodeError) else str(exc)
        raise StructuredResponseError("malformed_json", f"Could not parse model JSON: {message}") from exc
    if isinstance(value, dict):
        return value
    message = "top-level JSON value was not an object"
    raise StructuredResponseError("malformed_json", f"Could not parse model JSON: {message}")


def parse_candidate_envelope(raw: str) -> CandidateEnvelope:
    payload = extract_json_object(raw)
    try:
        return CandidateEnvelope.model_validate(payload)
    except ValidationError as exc:
        errors = [
            {"location": ".".join(str(part) for part in error["loc"]), "type": error["type"]}
            for error in exc.errors()
        ]
        raise StructuredResponseError("schema_violation", "Model JSON failed schema validation", errors) from exc


def compact_validation_errors(error: StructuredResponseError) -> str:
    if not error.validation_errors:
        return error.kind
    return "; ".join(
        f"{item['location']}:{item['type']}" for item in error.validation_errors[:12]
    )
