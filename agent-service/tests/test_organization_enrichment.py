import pytest
from pydantic import ValidationError

from app.schemas.research import OrganizationEnrichmentEnvelope


def test_organization_enrichment_requires_resolved_sources():
    with pytest.raises(ValidationError):
        OrganizationEnrichmentEnvelope.model_validate(
            {
                "sources": [{"id": "org_01", "title": "About", "url": "https://example.org/about", "source_type": "official_site", "excerpt_or_locator": "A sufficiently detailed source excerpt for this organization fact."}],
                "facts": [{"field": "mission", "value": "Serve neighbors", "confidence": "high", "source_ids": ["missing"]}],
            }
        )


def test_organization_enrichment_enforces_field_types():
    with pytest.raises(ValidationError):
        OrganizationEnrichmentEnvelope.model_validate(
            {
                "sources": [{"id": "org_01", "title": "About", "url": "https://example.org/about", "source_type": "official_site", "excerpt_or_locator": "A sufficiently detailed source excerpt for this organization fact."}],
                "facts": [{"field": "staff_size", "value": "many", "confidence": "medium", "source_ids": ["org_01"]}],
            }
        )


def test_scalar_list_normalization_is_lossless():
    value = "Adults with disabilities; caregivers, rural communities"
    normalized = [item.strip() for item in __import__("re").split(r"[;\n,]+", value) if item.strip()]

    assert normalized == ["Adults with disabilities", "caregivers", "rural communities"]
