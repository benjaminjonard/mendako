"""Mendako ML inference service — application entrypoint.

Minimal FastAPI app. Wires the health endpoint and the read-only model catalog;
model download and inference endpoints arrive in later stories.
"""

from fastapi import FastAPI

from app.routes import health, models

app = FastAPI(title="Mendako ML", version="0.1.0")
app.include_router(health.router)
app.include_router(models.router)
