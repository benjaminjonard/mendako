"""Mendako ML inference service — application entrypoint.

Minimal FastAPI app wiring the health and model (catalog + inference) routers.
"""

from fastapi import FastAPI

from app.routes import health, models

app = FastAPI(title="Mendako ML", version="0.1.0")
app.include_router(health.router)
app.include_router(models.router)
