import hmac
import os
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from dotenv import load_dotenv
from pydantic import ValidationError

# Keep the HTTP service and CLI fallback on the same configuration path.
# Existing process environment values win over local development defaults.
load_dotenv(Path(__file__).resolve().parents[1] / ".env", override=False)

from app.graph.pipeline import ResearchPipeline
from app.schemas.research import OrganizationEnrichmentEnvelope, OrganizationEnrichmentRequest, ResearchRequest, ResearchResponse

app = FastAPI(title="Funder Scout Strands Service", version="0.1.0")
pipeline = ResearchPipeline()


def authenticate(x_agent_secret: str = Header(default="")) -> None:
    expected = os.getenv("AGENT_SERVICE_SECRET", "local-demo-secret")
    if not hmac.compare_digest(x_agent_secret, expected):
        raise HTTPException(status_code=401, detail="Invalid internal service secret")


@app.get("/health")
def health() -> dict[str, str]:
    return {"status": "ok", "orchestrator": "strands", "workflow_version": "strands-v3", "mode": "demo" if os.getenv("DEMO_MODE", "false").lower() == "true" else "live"}


@app.get("/ready")
def ready() -> dict[str, object]:
    demo = os.getenv("DEMO_MODE", "false").lower() == "true"
    aws_configured = bool(os.getenv("AWS_PROFILE") or (os.getenv("AWS_ACCESS_KEY_ID") and os.getenv("AWS_SECRET_ACCESS_KEY")))
    checks = {"demo_mode": demo, "aws_credentials_configured": aws_configured, "agent_secret_configured": bool(os.getenv("AGENT_SERVICE_SECRET"))}
    if not demo and not aws_configured:
        raise HTTPException(status_code=503, detail={"status": "not_ready", "checks": checks, "missing": ["AWS credentials"]})
    return {"status": "ready", "checks": checks}


@app.post("/research", response_model=ResearchResponse, dependencies=[Depends(authenticate)])
async def research(request: ResearchRequest) -> ResearchResponse:
    try:
        return await pipeline.run(request)
    except RuntimeError as exc:
        raise HTTPException(status_code=503, detail=str(exc)) from exc


@app.post("/enrich-organization", response_model=OrganizationEnrichmentEnvelope, dependencies=[Depends(authenticate)])
async def enrich_organization(request: OrganizationEnrichmentRequest) -> OrganizationEnrichmentEnvelope:
    try:
        return await pipeline.enrich_organization(request)
    except (RuntimeError, TimeoutError, ValidationError) as exc:
        raise HTTPException(status_code=503, detail="Organization enrichment failed closed") from exc
