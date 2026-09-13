"""Replaceable, source-preserving research tool boundary.

Fixture mode intentionally returns fictional records under example.org. Live
adapters belong behind this protocol and must never synthesize missing facts.
"""
from typing import Protocol

from app.schemas.research import ProspectResult, ResearchRequest


class ResearchProvider(Protocol):
    def find_comparables(self, request: ResearchRequest) -> list[dict]: ...
    def find_common_funders(self, comparables: list[dict]) -> list[ProspectResult]: ...

