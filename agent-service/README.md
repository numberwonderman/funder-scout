# Strands agent service

FastAPI owns all AI behavior. `app/graph/pipeline.py` defines the deterministic
eight-node Strands graph. Demo mode mirrors that graph with typed, fictional
fixtures and never calls Bedrock or the public web.

Run from this directory:

```bash
source .venv/bin/activate
uvicorn app.main:app --reload --port 8004
pytest
```
