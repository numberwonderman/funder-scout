import asyncio
import hmac
import json
import os
from pathlib import Path
from typing import AsyncIterator

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import StreamingResponse
from dotenv import load_dotenv
from pydantic import ValidationError

# Keep the HTTP service and CLI fallback on the same configuration path.
# Existing process environment values win over local development defaults.
load_dotenv(Path(__file__).resolve().parents[1] / ".env", override=False)

from app.graph.pipeline import ResearchPipeline
from app.schemas.research import OrganizationEnrichmentEnvelope, OrganizationEnrichmentRequest, ResearchRequest

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


async def _stream_research(request: ResearchRequest) -> AsyncIterator[str]:
    """Yield newline-delimited JSON: per-node progress, then one final result/error line.

    A regular JSON response can't report progress before the whole bounded
    graph finishes, since the HTTP status and body are only decided once.
    Streaming lets the caller persist each node's completion as it actually
    happens instead of learning about all of them at once at the end.
    """
    queue: asyncio.Queue = asyncio.Queue()

    async def on_progress(node: str, status: str, message: str) -> None:
        await queue.put({"type": "progress", "node": node, "status": status, "message": message})

    async def run_pipeline() -> None:
        try:
            response = await pipeline.run(request, on_progress=on_progress)
            await queue.put({"type": "result", "response": response.model_dump(mode="json")})
        except RuntimeError as exc:
            await queue.put({"type": "error", "detail": str(exc)})
        except Exception as exc:  # noqa: BLE001 - guarantee the stream always terminates
            await queue.put({"type": "error", "detail": f"Live Strands research failed closed [{type(exc).__name__.lower()}]"})
        finally:
            await queue.put(None)

    task = asyncio.create_task(run_pipeline())
    try:
        while True:
            item = await queue.get()
            if item is None:
                break
            yield json.dumps(item, default=str) + "\n"
    finally:
        await task


@app.post("/research", dependencies=[Depends(authenticate)])
async def research(request: ResearchRequest):
    if os.getenv("DEMO_MODE", "false").lower() == "true":
        try:
            return await pipeline.run(request)
        except RuntimeError as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc
    return StreamingResponse(_stream_research(request), media_type="application/x-ndjson")


@app.post("/enrich-organization", response_model=OrganizationEnrichmentEnvelope, dependencies=[Depends(authenticate)])
async def enrich_organization(request: OrganizationEnrichmentRequest) -> OrganizationEnrichmentEnvelope:
    try:
        return await pipeline.enrich_organization(request)
    except (RuntimeError, TimeoutError, ValidationError) as exc:
        raise HTTPException(status_code=503, detail="Organization enrichment failed closed") from exc
