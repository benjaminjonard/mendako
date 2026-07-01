"""Model catalog + inference endpoints.

`GET /models` : the allowlisted catalog with a per-entry status. Models are baked
                into the image (no runtime download), so status is ``ready`` when
                every declared file is present and ``absent`` only if the image is
                somehow incomplete.
`POST /analyze`: WD inference — tags + the image embedding (WD's fc_norm feature).
`POST /embed`  : the image embedding only (embedding-pool prefill), no tagging.
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
            dim=entry["dim"],
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
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        shutil.copyfileobj(image.file, tmp)
        tmp_path = tmp.name

    try:
        # One WD forward pass yields both the tags and the embedding (the exposed fc_norm feature).
        result = inference.analyze(models_dir / entry["id"], tmp_path)
        if "embedding" in result:
            result["embedding_model_id"] = entry["id"]
        return result
    except (ValueError, OSError) as exc:
        # Corrupt/non-image/oversized upload — clean 422 rather than a 500.
        raise HTTPException(status_code=422, detail="could not process image") from exc
    finally:
        Path(tmp_path).unlink(missing_ok=True)


@router.post("/embed")
def embed(
    model: str = Form(...),
    image: UploadFile = File(...),
) -> dict:
    """Visual-encoder-only embedding for the embedding pool (kNN / trained classifier).

    Deliberately does NOT run the WD tagger: this is the bulk pre-embedding path, so we pay
    for the visual encoder alone. Returns a unit-normalized embedding + the producing model id.
    """
    models_dir = _models_dir()

    entry = find_entry(model)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown model")
    if _status(entry, models_dir) != "ready":
        raise HTTPException(status_code=409, detail="model not ready")

    suffix = Path(image.filename or "image").suffix or ".png"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        shutil.copyfileobj(image.file, tmp)
        tmp_path = tmp.name

    try:
        result = inference.embed(models_dir / entry["id"], tmp_path)
        return {"embedding": result["embedding"], "dim": result["dim"], "model_id": entry["id"]}
    except (ValueError, OSError) as exc:
        raise HTTPException(status_code=422, detail="could not process image") from exc
    finally:
        Path(tmp_path).unlink(missing_ok=True)
