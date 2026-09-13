from __future__ import annotations

from datetime import datetime, timezone
from enum import Enum
from typing import Any
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, HttpUrl, model_validator


class Confidence(str, Enum):
    high = "high"
    medium = "medium"
    low = "low"
    unverified = "unverified"
    conflicting = "conflicting"


class ClaimStatus(str, Enum):
    supported = "supported"
    contradicted = "contradicted"
    unknown = "unknown"
    disqualifying = "disqualifying"


class Source(BaseModel):
    title: str
    url: HttpUrl
    source_type: str
    retrieved_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    excerpt_or_locator: str


class Claim(BaseModel):
    key: str
    claim: str
    value: Any | None = None
    confidence: Confidence
    status: ClaimStatus = ClaimStatus.supported
    sources: list[Source] = Field(min_length=1)


class GrantRecord(BaseModel):
    recipient: str
    purpose: str
    amount: int = Field(ge=0)
    year: int = Field(ge=2000, le=2100)
    source: Source


class PersonRecord(BaseModel):
    name: str
    role: str
    confidence: Confidence
    sources: list[Source] = Field(min_length=1)


class RelationshipEdge(BaseModel):
    from_name: str
    relationship: str
    to_name: str
    sources: list[Source] = Field(min_length=1)


class ScoreSignals(BaseModel):
    """Evidence-derived ratios only. Laravel owns weights and final score."""

    cause_alignment: float = Field(ge=0, le=1)
    historical_giving: float = Field(ge=0, le=1)
    geographic_fit: float = Field(ge=0, le=1)
    grant_size_fit: float = Field(ge=0, le=1)
    recency: float = Field(ge=0, le=1)
    relationship_strength: float = Field(ge=0, le=1)


class ProspectResult(BaseModel):
    name: str
    funder_type: str
    ein: str | None = None
    summary: str
    confidence: Confidence
    claims: list[Claim] = Field(min_length=1)
    grants: list[GrantRecord] = []
    people: list[PersonRecord] = []
    relationships: list[RelationshipEdge] = []
    score_signals: ScoreSignals
    recommended_ask_min: int | None = Field(default=None, ge=0)
    recommended_ask_max: int | None = Field(default=None, ge=0)
    ask_rationale: str
    opportunity_status: str = "worth_investigating"
    opportunity_type: str = "mission_match"
    eligibility_status: str = "not_verified"
    evidence_gaps: list[str] = []
    next_actions: list[str] = []

    @model_validator(mode="after")
    def valid_ask_range(self) -> "ProspectResult":
        if self.recommended_ask_min and self.recommended_ask_max:
            if self.recommended_ask_min > self.recommended_ask_max:
                raise ValueError("recommended ask minimum exceeds maximum")
        return self


class ProspectEnvelope(BaseModel):
    prospects: list[ProspectResult] = Field(min_length=1, max_length=4)


class CandidateSource(BaseModel):
    model_config = ConfigDict(extra="forbid")

    id: str = Field(min_length=1, max_length=40)
    title: str = Field(min_length=3, max_length=300)
    url: HttpUrl
    source_type: str = Field(min_length=3, max_length=80)
    # Structural parsing should not discard the entire response for one weak
    # locator. The provenance gate independently removes weak/unmatched sources
    # and every claim that depends on them before persistence.
    excerpt_or_locator: str = Field(min_length=3, max_length=1200)


class CandidateClaim(BaseModel):
    model_config = ConfigDict(extra="forbid")

    key: str = Field(min_length=2, max_length=80)
    claim: str = Field(min_length=10, max_length=800)
    confidence: Confidence
    status: ClaimStatus = ClaimStatus.supported
    source_ids: list[str] = Field(min_length=1)


class CandidateGrant(BaseModel):
    model_config = ConfigDict(extra="forbid")

    recipient: str
    purpose: str
    amount: int = Field(ge=0)
    # Models often use 0 for an unknown optional year. Candidate parsing accepts
    # that sentinel; the provenance gate removes it before GrantRecord promotion.
    year: int = Field(ge=0, le=2100)
    source_id: str


class CandidatePerson(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    role: str
    confidence: Confidence
    source_ids: list[str] = Field(min_length=1)


class CandidateProspect(BaseModel):
    model_config = ConfigDict(extra="forbid")

    name: str
    funder_type: str
    ein: str | None = None
    summary: str = Field(min_length=10, max_length=600)
    confidence: Confidence
    sources: list[CandidateSource] = Field(min_length=1, max_length=6)
    claims: list[CandidateClaim] = Field(min_length=1, max_length=6)
    grants: list[CandidateGrant] = Field(default_factory=list, max_length=4)
    people: list[CandidatePerson] = Field(default_factory=list, max_length=4)

    @model_validator(mode="after")
    def valid_source_references(self) -> "CandidateProspect":
        source_ids = {source.id for source in self.sources}
        if len(source_ids) != len(self.sources):
            raise ValueError("source ids must be unique within a prospect")
        referenced = {
            source_id
            for claim in self.claims
            for source_id in claim.source_ids
        }
        referenced.update(grant.source_id for grant in self.grants)
        referenced.update(
            source_id
            for person in self.people
            for source_id in person.source_ids
        )
        missing = referenced - source_ids
        if missing:
            raise ValueError("all source references must resolve")
        return self


class CandidateEnvelope(BaseModel):
    prospects: list[CandidateProspect] = Field(min_length=1, max_length=4)


class ProgressEvent(BaseModel):
    node: str
    status: str
    message: str
    metadata: dict[str, Any] = {}


class NonprofitInput(BaseModel):
    name: str | None = None
    website: HttpUrl
    ein: str | None = None
    nonprofit_status: str = "unknown"
    fiscal_sponsorship_status: str = "unknown"
    mission: str | None = None
    program_areas: list[str] = []
    populations_served: list[str] = []
    organization_age: int | None = None
    annual_budget: int | None = None
    staff_size: int | None = None
    desired_funding_categories: list[str] = []
    desired_grant_min: int | None = None
    desired_grant_max: int | None = None
    needs: list[str] = []
    prefers_unrestricted: bool = False
    keywords: list[str] = []
    exclusions: list[str] = []

    @model_validator(mode="before")
    @classmethod
    def normalize_nullable_lists(cls, value: Any) -> Any:
        if isinstance(value, dict):
            value = value.copy()
            for field in ("program_areas", "populations_served", "desired_funding_categories", "needs", "keywords", "exclusions"):
                if value.get(field) is None:
                    value[field] = []
        return value


class CampaignInput(BaseModel):
    title: str
    description: str
    goal_amount: int = Field(gt=0)
    geography: str | None = None
    board_members: list[str] = []


class ResearchRequest(BaseModel):
    research_run_id: UUID
    nonprofit: NonprofitInput
    campaign: CampaignInput


class ResearchResponse(BaseModel):
    workflow_version: str = "strands-v3"
    research_run_id: UUID
    mode: str
    graph: list[str]
    events: list[ProgressEvent]
    prospects: list[ProspectResult]
    campaign_brief: dict[str, Any] = {}
    excluded_candidates: list[dict[str, Any]] = []
    research_notes: list[str] = []


class OrganizationEnrichmentRequest(BaseModel):
    website: HttpUrl


class OrganizationFact(BaseModel):
    model_config = ConfigDict(extra="forbid")

    field: str = Field(pattern="^(name|mission|geography|ein|nonprofit_status|program_areas|populations_served|organization_age|annual_budget|staff_size|needs|keywords)$")
    value: str | int | list[str]
    confidence: Confidence
    source_ids: list[str] = Field(min_length=1, max_length=4)


class OrganizationEnrichmentEnvelope(BaseModel):
    model_config = ConfigDict(extra="forbid")

    sources: list[CandidateSource] = Field(min_length=1, max_length=10)
    facts: list[OrganizationFact] = Field(max_length=20)

    @model_validator(mode="after")
    def valid_references_and_types(self) -> "OrganizationEnrichmentEnvelope":
        source_ids = {source.id for source in self.sources}
        if any(source_id not in source_ids for fact in self.facts for source_id in fact.source_ids):
            raise ValueError("all organization fact source references must resolve")
        list_fields = {"program_areas", "populations_served", "needs", "keywords"}
        integer_fields = {"organization_age", "annual_budget", "staff_size"}
        for fact in self.facts:
            if fact.field in list_fields and not isinstance(fact.value, list):
                raise ValueError(f"{fact.field} must be a list")
            if fact.field in integer_fields and (not isinstance(fact.value, int) or isinstance(fact.value, bool)):
                raise ValueError(f"{fact.field} must be an integer")
        return self
