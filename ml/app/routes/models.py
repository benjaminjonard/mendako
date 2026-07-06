"""Model catalog + inference endpoints.

`GET /models` : the allowlisted catalog with a per-entry status (``ready`` when every
                declared file is present; models are baked in, no runtime download).
`POST /analyze`: WD inference — scored tags + a content rating.
"""

import logging
import os
import shutil
import tempfile
from pathlib import Path

from fastapi import APIRouter, File, Form, HTTPException, UploadFile
from pydantic import BaseModel

from app import inference
from app.catalog import CATALOG, find_entry

router = APIRouter()
logger = logging.getLogger(__name__)

DEFAULT_MODELS_DIR = "/models"


def _models_dir() -> Path:
    return Path(os.environ.get("MENDAKO_MODELS_DIR", DEFAULT_MODELS_DIR))


def _status(entry: dict, models_dir: Path) -> str:
    try:
        model_dir = models_dir / entry["id"]
        files = entry["files"]
        if files and model_dir.is_dir() and all((model_dir / f).is_file() for f in files):
            return "ready"
    except OSError:
        return "absent"
    return "absent"


class CatalogEntry(BaseModel):
    category: str
    id: str
    repo_id: str
    revision: str
    files: list[str]
    dim: int | None
    status: str


@router.get("/models", response_model=list[CatalogEntry])
def list_models() -> list[CatalogEntry]:
    models_dir = _models_dir()
    return [
        CatalogEntry(
            category=entry["category"],
            id=entry["id"],
            repo_id=entry["repo_id"],
            revision=entry["revision"],
            files=entry["files"],
            dim=entry.get("dim"),
            status=_status(entry, models_dir),
        )
        for entry in CATALOG
    ]


@router.post("/analyze")
def analyze(
    model: str = Form(...),
    image: UploadFile = File(...),
) -> dict:
    models_dir = _models_dir()

    entry = find_entry(model)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown model")
    if _status(entry, models_dir) != "ready":
        raise HTTPException(status_code=409, detail="model not ready")

    suffix = Path(image.filename or "image").suffix or ".png"
    tmp_path = None
    try:
        # Set tmp_path before the copy so a mid-copy failure (disk full, client disconnect)
        # is still caught here and the temp file is still cleaned up in `finally`.
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp_path = tmp.name
            shutil.copyfileobj(image.file, tmp)

        return inference.analyze(models_dir / entry["id"], tmp_path)
    except (ValueError, OSError) as exc:
        # Corrupt/non-image/oversized upload — clean 422 rather than a 500.
        raise HTTPException(status_code=422, detail="could not process image") from exc
    except Exception as exc:
        # onnxruntime / unexpected inference failure — clean 500, no stack-trace leak.
        logger.exception("analyze failed")
        raise HTTPException(status_code=500, detail="inference failed") from exc
    finally:
        if tmp_path is not None:
            Path(tmp_path).unlink(missing_ok=True)
