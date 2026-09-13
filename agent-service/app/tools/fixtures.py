from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from urllib.parse import urlparse

from app.schemas.research import (
    Claim, Confidence, GrantRecord, PersonRecord, ProspectResult,
    RelationshipEdge, ResearchRequest, ScoreSignals, Source,
)


@dataclass(frozen=True)
class CauseProfile:
    key: str
    label: str
    program: str
    funders: tuple[tuple[str, str], ...]


CAUSES = (
    CauseProfile("stem", "STEM education", "hands-on technology learning", (("Miller Family Foundation", "Family foundation"), ("Keystone Community Trust", "Private foundation"), ("Northstar Corporate Giving", "Corporate philanthropy program"))),
    CauseProfile("food", "food security", "community food access", (("Harvest Equity Foundation", "Private foundation"), ("Common Table Trust", "Family foundation"), ("FreshFields Community Fund", "Corporate philanthropy program"))),
    CauseProfile("health", "community health", "accessible health services", (("WellSpring Health Foundation", "Private foundation"), ("Beacon Wellness Trust", "Family foundation"), ("CivicCare Community Fund", "Corporate philanthropy program"))),
    CauseProfile("environment", "environmental resilience", "local conservation and climate resilience", (("Green Horizon Foundation", "Private foundation"), ("Watershed Legacy Trust", "Family foundation"), ("Evergreen Community Impact", "Corporate philanthropy program"))),
    CauseProfile("arts", "arts and culture", "community arts access", (("Mosaic Arts Foundation", "Private foundation"), ("Crescendo Family Trust", "Family foundation"), ("Canvas Community Fund", "Corporate philanthropy program"))),
    CauseProfile("housing", "housing stability", "stable and affordable housing", (("Open Door Foundation", "Private foundation"), ("HomeGround Trust", "Family foundation"), ("Neighborhood Builders Fund", "Corporate philanthropy program"))),
)

KEYWORDS = {
    "stem": ("stem", "robot", "coding", "science", "technology", "engineering", "school", "education", "student", "youth"),
    "food": ("food", "hunger", "meal", "nutrition", "pantry", "farm", "grocer"),
    "health": ("health", "clinic", "medical", "mental health", "wellness", "patient"),
    "environment": ("climate", "environment", "conservation", "watershed", "green", "sustainability", "park"),
    "arts": ("arts", "artist", "music", "theater", "museum", "dance", "culture"),
    "housing": ("housing", "homeless", "shelter", "tenant", "affordable home"),
}


def analyze_request(request: ResearchRequest) -> tuple[CauseProfile, str, str]:
    text = f"{request.nonprofit.name or ''} {request.campaign.title} {request.campaign.description}".lower()
    scores = {key: sum(1 for word in words if word in text) for key, words in KEYWORDS.items()}
    best_key = max(scores, key=scores.get)
    if scores[best_key] == 0:
        best_key = "stem"
    profile = next(item for item in CAUSES if item.key == best_key)
    geography = request.campaign.geography or "the nonprofit's service region"
    host = urlparse(str(request.nonprofit.website)).hostname or "nonprofit"
    identity = re.sub(r"[^a-z0-9]+", "-", host.removeprefix("www.")).strip("-")
    return profile, geography, identity


def source(identity: str, title: str, path: str, locator: str, kind: str = "demo_fixture") -> Source:
    return Source(title=f"{title} — fictional scenario record", url=f"https://demo.example.org/{identity}/{path}", source_type=kind, excerpt_or_locator=locator)


def ask_range(goal: int, typical_grant: int) -> tuple[int, int]:
    upper = min(round(goal * .34 / 1000) * 1000, round(typical_grant * 1.35 / 1000) * 1000)
    upper = max(5000, upper)
    lower = max(2500, round(upper * .60 / 2500) * 2500)
    return lower, upper


def prospects(request: ResearchRequest) -> list[ProspectResult]:
    profile, geography, identity = analyze_request(request)
    digest = hashlib.sha256(f"{identity}|{request.campaign.title}|{request.campaign.goal_amount}".encode()).digest()
    nonprofit_name = request.nonprofit.name or urlparse(str(request.nonprofit.website)).hostname or "the nonprofit"
    board_member = request.campaign.board_members[0] if request.campaign.board_members else None
    results: list[ProspectResult] = []
    for index, (funder_name, funder_type) in enumerate(profile.funders):
        comparable_count = 6 - index * 2 + digest[index] % 2
        relevant_count = max(1, comparable_count - 2)
        typical_grant = (18000, 13000, 10000)[index] + (digest[index + 3] % 4) * 1000
        year = 2025 - digest[index + 6] % 2
        grant_source = source(identity, f"{funder_name} grant history", f"{profile.key}/funder-{index + 1}/grants", f"Scenario ledger rows 1–{comparable_count}", "fixture_form_990")
        priority_source = source(identity, f"{funder_name} priorities", f"{profile.key}/funder-{index + 1}/priorities", f"{profile.label}; {geography}")
        people_source = source(identity, f"{funder_name} leadership", f"{profile.key}/funder-{index + 1}/leadership", "Public leadership roster")
        ask_min, ask_max = ask_range(request.campaign.goal_amount, typical_grant)
        connection_strength = .8 if index == 0 and board_member else 0
        relationships = [] if not connection_strength else [
            RelationshipEdge(from_name=board_member, relationship="serves with", to_name="Community Partnership Council", sources=[people_source]),
            RelationshipEdge(from_name="Community Partnership Council", relationship="also includes", to_name="Jordan Lee", sources=[people_source]),
        ]
        results.append(ProspectResult(
            name=funder_name, funder_type=funder_type,
            ein=f"00-{digest[index]:07d}" if funder_type != "Corporate philanthropy program" else None,
            summary=f"Fictional scenario evidence indicates {profile.label} alignment and giving relevant to {geography}.",
            confidence=Confidence.high if index < 2 else Confidence.medium,
            claims=[
                Claim(key="comparable_grants", claim=f"{comparable_count} fictional grants supported organizations comparable to {nonprofit_name}.", value=comparable_count, confidence=Confidence.high, sources=[grant_source]),
                Claim(key="cause_grants", claim=f"{relevant_count} fictional grants specifically supported {profile.label}.", value=relevant_count, confidence=Confidence.high, sources=[grant_source]),
                Claim(key="geography", claim=f"The fictional giving profile includes {geography}.", value=geography, confidence=Confidence.high if index < 2 else Confidence.medium, sources=[priority_source]),
            ],
            grants=[
                GrantRecord(recipient=f"Comparable {profile.label.title()} Organization {chr(65 + index)}", purpose=profile.program.title(), amount=typical_grant, year=year, source=grant_source),
                GrantRecord(recipient=f"Regional {profile.label.title()} Network", purpose=f"Expanded {profile.program}", amount=max(5000, typical_grant - 3000), year=year - 1, source=grant_source),
            ],
            people=[PersonRecord(name=("Jordan Lee", "Elena Brooks", "Morgan Chen")[index], role=("President", "Program Director", "Community Impact Lead")[index], confidence=Confidence.high if index < 2 else Confidence.medium, sources=[people_source])],
            relationships=relationships,
            score_signals=ScoreSignals(cause_alignment=(.93, .84, .72)[index], historical_giving=(.90, .72, .54)[index], geographic_fit=(1, .9, .55)[index], grant_size_fit=min(1, typical_grant / max(typical_grant, request.campaign.goal_amount * .25)), recency=(.9, .8, .65)[index], relationship_strength=connection_strength),
            recommended_ask_min=ask_min, recommended_ask_max=ask_max,
            ask_rationale=f"This scenario range uses a ${typical_grant:,} comparable fictional grant and caps the ask relative to the ${request.campaign.goal_amount:,} campaign goal.",
        ))
    return results
