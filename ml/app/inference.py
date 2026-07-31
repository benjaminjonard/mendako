"""Tagger inference (ONNX).

Two taggers, one result shape. ``analyze()`` runs the WD illustration tagger (Danbooru
tags + a content rating); ``analyze_ram()`` runs RAM++ over photographic content. Both
return ``{tags, rating}`` so the caller never branches on which model produced it —
``analyze_for()`` picks the one belonging to a catalog category.

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

# RAM++ preprocessing: the reference implementation squashes to a square (no aspect-ratio
# padding, unlike WD) and normalizes with the ImageNet statistics.
RAM_IMAGE_SIZE = 384
RAM_MEAN = (0.485, 0.456, 0.406)
RAM_STD = (0.229, 0.224, 0.225)
# Beyond this magnitude the sigmoid is saturated to within 1e-13; clamping keeps a
# degenerate logit from overflowing np.exp.
_SIGMOID_CLAMP = 30.0

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


@functools.lru_cache(maxsize=4)
def _load_lines(path: str) -> tuple[str, ...]:
    with open(path, encoding="utf-8") as handle:
        return tuple(line.strip() for line in handle if line.strip())


@functools.lru_cache(maxsize=4)
def _load_thresholds(path: str) -> tuple[float, ...]:
    return tuple(float(value) for value in _load_lines(path))


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


def _preprocess_ram(image_path: str, size: int) -> np.ndarray:
    from PIL import Image

    image = _open_image(image_path)
    # Squash to a square: RAM++ is trained without aspect-ratio preservation, so padding
    # (what the WD path does) would put it off-distribution. Bilinear to match the reference
    # transform (torchvision Resize defaults to bilinear) — see ml/tools/export_ram_plus.py.
    canvas = image.resize((size, size), Image.BILINEAR)

    array = np.asarray(canvas, dtype=np.float32) / 255.0
    array = (array - np.asarray(RAM_MEAN, dtype=np.float32)) / np.asarray(RAM_STD, dtype=np.float32)

    # HWC -> NCHW; ascontiguousarray because onnxruntime rejects the transposed view's strides.
    return np.ascontiguousarray(array.transpose(2, 0, 1)[np.newaxis, ...])


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


def analyze_ram(model_dir: Path, image_path: str) -> dict:
    """RAM++ inference: per-tag sigmoid probabilities filtered by the model's own thresholds.

    Returns the same ``{tags, rating}`` shape as ``analyze()``. RAM++ has no rating head, so
    the rating is always empty — the caller treats every model identically.
    """
    tags = _load_lines(str(model_dir / "tags.txt"))
    thresholds = _load_thresholds(str(model_dir / "thresholds.txt"))
    if len(tags) != len(thresholds):
        raise ValueError(f"tag count ({len(tags)}) != threshold count ({len(thresholds)})")

    logits = _predict(model_dir, image_path, _preprocess_ram, RAM_IMAGE_SIZE, len(tags))

    # float64: in float32 a saturated logit rounds to exactly 1.0, which would let the
    # tags RAM++ disables with a 1.0 threshold fire anyway.
    clamped = np.clip(logits.astype(np.float64), -_SIGMOID_CLAMP, _SIGMOID_CLAMP)
    scores = 1.0 / (1.0 + np.exp(-clamped))

    result_tags: list[dict] = []
    for name, threshold, raw_score in zip(tags, thresholds, scores):
        score = float(raw_score)
        if not np.isfinite(score):
            continue  # guard against inf/NaN logits → invalid JSON the PHP client would reject
        # Strict >, per the reference implementation: it also means the handful of tags
        # shipped with a 1.0 threshold are disabled outright, since sigmoid never reaches 1.
        if score > threshold:
            result_tags.append({"name": name, "category": "general", "score": score})

    result_tags.sort(key=lambda tag: tag["score"], reverse=True)
    return {"tags": result_tags, "rating": {"label": None, "score": 0.0}}


def analyze_for(category: str, model_dir: Path, image_path: str) -> dict:
    """Run the tagger belonging to a catalog category; every one returns ``{tags, rating}``.

    Adding a tagger means adding an analyze function and one branch here — nothing outside this
    module has to learn which model is which. Dispatch is by call, not by a table of captured
    references, so the individual functions stay substitutable.
    """
    if category == "ram":
        return analyze_ram(model_dir, image_path)

    return analyze(model_dir, image_path)
