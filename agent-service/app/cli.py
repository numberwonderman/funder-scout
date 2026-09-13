from __future__ import annotations

import asyncio
import json
import sys
from pathlib import Path

from dotenv import load_dotenv

load_dotenv(Path(__file__).resolve().parents[1] / ".env")

from app.graph.pipeline import ResearchPipeline
from app.schemas.research import ResearchRequest


async def main() -> None:
    request = ResearchRequest.model_validate(json.load(sys.stdin))
    response = await ResearchPipeline().run(request)
    sys.stdout.write(response.model_dump_json())


if __name__ == "__main__":
    asyncio.run(main())
