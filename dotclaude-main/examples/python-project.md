# Example: Python Project CLAUDE.md

This is what the Project Autopilot generates for a typical Python FastAPI project.

---

```markdown
# data-pipeline

ETL pipeline for processing customer analytics data.

## Tech Stack
- Runtime: Python 3.13 (via pyproject.toml)
- Framework: FastAPI 0.115
- Database: PostgreSQL via SQLAlchemy 2.x
- Task queue: Celery + Redis
- Hosting: Railway (Docker)
- Key deps: pydantic, httpx, pandas, alembic

## Commands
- Dev: `uv run fastapi dev`
- Build: `docker build -t data-pipeline .`
- Test: `uv run pytest -v`
- Lint: `uv run ruff check .`
- Format: `uv run ruff format .`
- Type check: `uv run mypy src/`
- DB migrate: `uv run alembic upgrade head`

## Architecture
- `src/api/` — FastAPI route handlers
- `src/services/` — Business logic layer
- `src/models/` — SQLAlchemy models
- `src/schemas/` — Pydantic request/response schemas
- `src/tasks/` — Celery async tasks
- `src/core/` — Config, database, dependencies
- `tests/` — Pytest test suite (mirrors src/ structure)

## Conventions
- Pydantic for all input validation (never trust raw dicts)
- Repository pattern for database access
- Dependency injection via FastAPI's Depends()
- Async by default, sync only when required by library
- Type hints on all function signatures

## Key Files
- Entry: `src/main.py`
- Config: `src/core/config.py`
- Routes: `src/api/`
- Models: `src/models/`
- Environment: `.env.example`
```
