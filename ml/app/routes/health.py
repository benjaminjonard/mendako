"""Health/readiness endpoint for the Mendako ML service.

At this stage the service loads no models; ``models`` is always an empty list.
Later stories (1.3/1.4) populate it from the downloaded-model catalog.
"""

from fastapi import APIRouter
from pydantic import BaseModel

router = APIRouter()


class HealthResponse(BaseModel):
    status: str
    models: list[str]


@router.get("/health", response_model=HealthResponse)
def health() -> HealthResponse:
    return HealthResponse(status="ok", models=[])
