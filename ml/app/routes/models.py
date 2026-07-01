"""Model catalog + inference endpoints.

`GET /models` : the allowlisted catalog with a per-entry status. Models are baked
                into the image (no runtime download), so status is ``ready`` when
                every declared file is present and ``absent`` only if the image is
                somehow incomplete.
`POST /analyze`: run base-model inference (tags + optional CLIP embedding/zero-shot).
"""

import json
import logging
import os
import shutil
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

from fastapi import APIRouter, BackgroundTasks, File, Form, HTTPException, UploadFile
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


class VocabularyRequest(BaseModel):
    model: str
    tags: list[str]
    reset: bool = False


@router.post("/vocabulary")
def warm_vocabulary(request: VocabularyRequest, background_tasks: BackgroundTasks) -> dict:
    """Kick off the background encoding of the tag vocabulary into the persistent text cache.
    reset=False encodes only the missing tags (incremental); reset=True wipes the cache and
    re-encodes everything. Returns immediately; idempotent (a call while one runs is a no-op)."""
    models_dir = _models_dir()
    entry = find_entry(request.model)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown model")
    if _status(entry, models_dir) != "ready":
        raise HTTPException(status_code=409, detail="model not ready")

    background_tasks.add_task(inference.warm_vocabulary, models_dir / entry["id"], request.tags, request.reset)
    return inference.warm_status()


@router.get("/vocabulary")
def vocabulary_status() -> dict:
    """Progress of the vocabulary warm-up: {running, done, total}."""
    return inference.warm_status()


@router.post("/vocabulary/missing")
def vocabulary_missing(request: VocabularyRequest) -> dict:
    """How many of the given tags are already cached vs still to encode (cheap, no model load)."""
    models_dir = _models_dir()
    entry = find_entry(request.model)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown model")

    cached, missing = inference.vocabulary_coverage(models_dir / entry["id"], request.tags)
    return {"cached": cached, "missing": missing, "total": cached + missing}


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


# Conservative defaults so zero-shot surfaces only the most-similar tag names rather than
# flooding ~top_n weak matches. Uncalibrated cosine (see deferred SigLIP calibration);
# env-tunable so an operator can adjust without a rebuild.
ZEROSHOT_TOP_N = int(os.environ.get("MENDAKO_ZEROSHOT_TOP_N", "8"))
ZEROSHOT_MIN_SCORE = float(os.environ.get("MENDAKO_ZEROSHOT_MIN_SCORE", "0.1"))


def _parse_tag_names(raw: str | None) -> list[str]:
    """Parse the optional tag_names JSON array; tolerate junk by returning []."""
    if not raw:
        return []
    try:
        names = json.loads(raw)
    except (ValueError, TypeError):
        return []
    if not isinstance(names, list):
        return []
    return [name for name in names if isinstance(name, str) and name]


@router.post("/analyze")
def analyze(
    model: str = Form(...),
    image: UploadFile = File(...),
    clip_model: str | None = Form(None),
    tag_names: str | None = Form(None),
) -> dict:
    models_dir = _models_dir()

    entry = find_entry(model)
    if entry is None:
        raise HTTPException(status_code=404, detail="unknown model")
    if _status(entry, models_dir) != "ready":
        raise HTTPException(status_code=409, detail="model not ready")

    # Optional CLIP embedding folded into the same call (one upload → tags + embedding).
    clip_entry = None
    if clip_model is not None:
        clip_entry = find_entry(clip_model)
        if clip_entry is None:
            raise HTTPException(status_code=404, detail="unknown clip model")
        if _status(clip_entry, models_dir) != "ready":
            raise HTTPException(status_code=409, detail="clip model not ready")

    suffix = Path(image.filename or "image").suffix or ".png"
    with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
        shutil.copyfileobj(image.file, tmp)
        tmp_path = tmp.name

    try:
        # WD (tags) and the CLIP visual encoder are independent ONNX sessions over the same
        # image, so run them concurrently. onnxruntime releases the GIL during inference, so
        # the two model runs overlap on CPU cores instead of running back-to-back.
        with ThreadPoolExecutor(max_workers=2) as pool:
            tags_future = pool.submit(inference.analyze, models_dir / entry["id"], tmp_path)
            clip_dir = models_dir / clip_entry["id"] if clip_entry is not None else None
            embed_future = pool.submit(inference.embed, clip_dir, tmp_path) if clip_dir is not None else None

            # WD is the primary result; its ValueError/OSError surfaces as a 422 below.
            result = tags_future.result()

            if embed_future is not None:
                # Embedding is best-effort: a CLIP failure must not lose the WD tags.
                try:
                    embedding = embed_future.result()
                    result["embedding"] = embedding["embedding"]
                    result["embedding_dim"] = embedding["dim"]
                    result["clip_model_id"] = clip_entry["id"]

                    # Zero-shot: score the image against the user's own tag names (text-encoded).
                    names = _parse_tag_names(tag_names)
                    if names:
                        try:
                            result["zeroshot"] = inference.zeroshot(
                                clip_dir, embedding["embedding"], names, ZEROSHOT_TOP_N, ZEROSHOT_MIN_SCORE
                            )
                        except Exception:  # noqa: BLE001 — zero-shot is best-effort, keep tags + embedding
                            logger.warning("Zero-shot scoring failed for model %s", clip_entry["id"])
                except Exception:  # noqa: BLE001 — degrade gracefully, keep the tags
                    logger.warning("CLIP embedding failed for model %s; returning tags only", clip_entry["id"])
        return result
    except (ValueError, OSError) as exc:
        # Corrupt/non-image/oversized upload — clean 422 rather than a 500.
        raise HTTPException(status_code=422, detail="could not process image") from exc
    finally:
        Path(tmp_path).unlink(missing_ok=True)
