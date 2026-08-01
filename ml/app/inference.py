"""WD tagger inference (ONNX).

``analyze()`` produces scored Danbooru tags and a content rating.

ONNX sessions are created lazily and cached (onnxruntime is imported lazily so this
module is importable without it).
"""

import csv
import functools
from pathlib import Path

import numpy as np

# selected_tags.csv category codes (SmilingWolf WD taggers) → our category names.
_CATEGORY = {0: "general", 1: "artist", 3: "copyright", 4: "character", 5: "meta", 9: "rating"}

DEFAULT_GENERAL_THRESHOLD = 0.35
DEFAULT_CHARACTER_THRESHOLD = 0.85
WD_IMAGE_SIZE = 448


MAX_PIXELS = 50_000_000  # decompression-bomb guard (~50 MP)


@functools.lru_cache(maxsize=8)
def _session(model_path: str):
    import onnxruntime as ort  # lazy: keeps the module importable without onnxruntime

    return ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])


@functools.lru_cache(maxsize=4)
def _load_tags(csv_path: str) -> tuple[tuple[str, str], ...]:
    rows: list[tuple[str, str]] = []
    with open(csv_path, newline="") as handle:
        for row in csv.DictReader(handle):
            category = _CATEGORY.get(int(row["category"]), "general")
            rows.append((row["name"], category))
    return tuple(rows)


def _open_image(image_path: str):
    """Decode to RGB, guarding against decompression bombs and honouring EXIF orientation."""
    from PIL import Image, ImageOps

    image = Image.open(image_path)
    if image.width * image.height > MAX_PIXELS:
        raise ValueError("image exceeds the maximum allowed size")
    # Honour EXIF orientation (phone photos) before inference, else tags are
    # computed on a rotated/mirrored image.
    image = ImageOps.exif_transpose(image)

    return image.convert("RGB")


def _preprocess(image_path: str, size: int) -> np.ndarray:
    from PIL import Image

    image = _open_image(image_path)
    width, height = image.size
    side = max(width, height)
    canvas = Image.new("RGB", (side, side), (255, 255, 255))
    canvas.paste(image, ((side - width) // 2, (side - height) // 2))
    canvas = canvas.resize((size, size), Image.BICUBIC)

    array = np.asarray(canvas, dtype=np.float32)[:, :, ::-1]  # RGB -> BGR
    # onnxruntime requires C-contiguous input (the BGR slice has negative strides).
    return np.ascontiguousarray(array[np.newaxis, ...])  # NHWC batch of 1


def _predict(model_dir: Path, image_path: str, preprocess, fallback_size: int, tag_count: int) -> np.ndarray:
    """One image through one model, as a flat array with one entry per tag.

    ``preprocess`` is the tagger's own image→tensor step; it is handed the model's declared
    input size so a re-exported model at a different resolution needs no code change.
    """
    session = _session(str(model_dir / "model.onnx"))

    model_input = session.get_inputs()[0]
    # Pick the spatial dim (a concrete int > 3) so NHWC and NCHW both resolve.
    size = next((d for d in model_input.shape if isinstance(d, int) and d > 3), fallback_size)

    outputs = session.run(None, {model_input.name: preprocess(image_path, size)})
    predictions = np.asarray(outputs[0]).reshape(-1)
    if len(predictions) != tag_count:
        raise ValueError(f"model output size ({len(predictions)}) != tag count ({tag_count})")

    return predictions


def analyze(
    model_dir: Path,
    image_path: str,
    general_threshold: float = DEFAULT_GENERAL_THRESHOLD,
    character_threshold: float = DEFAULT_CHARACTER_THRESHOLD,
) -> dict:
    """WD inference: scored Danbooru tags plus the winning content rating."""
    tags = _load_tags(str(model_dir / "selected_tags.csv"))
    predictions = _predict(model_dir, image_path, _preprocess, WD_IMAGE_SIZE, len(tags))

    result_tags: list[dict] = []
    rating = {"label": None, "score": 0.0}
    for (name, category), raw_score in zip(tags, predictions):
        score = float(raw_score)
        if not np.isfinite(score):
            continue  # guard against inf/NaN logits → invalid JSON the PHP client would reject
        if category == "rating":
            if score > rating["score"]:
                rating = {"label": name, "score": score}
            continue
        threshold = character_threshold if category == "character" else general_threshold
        if score >= threshold:
            result_tags.append({"name": name, "category": category, "score": score})

    result_tags.sort(key=lambda tag: tag["score"], reverse=True)
    return {"tags": result_tags, "rating": rating}
