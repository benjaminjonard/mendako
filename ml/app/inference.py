"""WD tagger inference (ONNX).

Loads the WD tagger ONNX model + its ``selected_tags.csv`` and produces scored Danbooru
tags, a content rating, and an image embedding (WD's penultimate ``fc_norm`` feature, exposed
as a second graph output at build time). One forward pass yields both the tags and the
embedding — there is no separate encoder. The ONNX session is created lazily and cached
(onnxruntime is imported lazily so this module is importable without it).
"""

import csv
import functools
from pathlib import Path

import numpy as np

# selected_tags.csv category codes (SmilingWolf WD taggers) → our category names.
_CATEGORY = {0: "general", 1: "artist", 3: "copyright", 4: "character", 5: "meta", 9: "rating"}

DEFAULT_GENERAL_THRESHOLD = 0.35
DEFAULT_CHARACTER_THRESHOLD = 0.85
MAX_PIXELS = 50_000_000  # decompression-bomb guard (~50 MP)


@functools.lru_cache(maxsize=8)
def _session(model_path: str):
    # Resident, used on every image. Default intra-op threads (each forward is matmul-heavy
    # and benefits from all cores).
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


def _preprocess(image_path: str, size: int) -> np.ndarray:
    from PIL import Image

    image = Image.open(image_path)
    if image.width * image.height > MAX_PIXELS:
        raise ValueError("image exceeds the maximum allowed size")
    image = image.convert("RGB")
    width, height = image.size
    side = max(width, height)
    canvas = Image.new("RGB", (side, side), (255, 255, 255))
    canvas.paste(image, ((side - width) // 2, (side - height) // 2))
    canvas = canvas.resize((size, size), Image.BICUBIC)

    array = np.asarray(canvas, dtype=np.float32)[:, :, ::-1]  # RGB -> BGR
    # onnxruntime requires C-contiguous input (the BGR slice has negative strides).
    return np.ascontiguousarray(array[np.newaxis, ...])  # NHWC batch of 1


def _embedding_from_outputs(outputs: list) -> np.ndarray | None:
    """Unit-normalized embedding from the WD model's second output (the exposed fc_norm feature),
    or None if the model wasn't patched to expose it (pre-rebuild image → degrade gracefully)."""
    if len(outputs) < 2:
        return None
    vec = np.asarray(outputs[1]).reshape(-1).astype(np.float32)
    if not np.all(np.isfinite(vec)):
        return None
    norm = float(np.linalg.norm(vec))
    return vec / norm if norm > 0.0 else vec


def analyze(
    model_dir: Path,
    image_path: str,
    general_threshold: float = DEFAULT_GENERAL_THRESHOLD,
    character_threshold: float = DEFAULT_CHARACTER_THRESHOLD,
) -> dict:
    session = _session(str(model_dir / "model.onnx"))
    tags = _load_tags(str(model_dir / "selected_tags.csv"))

    model_input = session.get_inputs()[0]
    # Pick the spatial dim (a concrete int > 3) so NHWC and NCHW both resolve; fallback 448.
    size = next((d for d in model_input.shape if isinstance(d, int) and d > 3), 448)
    x = _preprocess(image_path, size)

    # The WD model exposes two outputs (see catalog embed_output): [0] tag logits, [1] embedding.
    outputs = session.run(None, {model_input.name: x})
    predictions = np.asarray(outputs[0]).reshape(-1)
    if len(predictions) != len(tags):
        raise ValueError(f"model output size ({len(predictions)}) != tag count ({len(tags)})")

    result_tags: list[dict] = []
    rating = {"label": None, "score": 0.0}
    for (name, category), raw_score in zip(tags, predictions):
        score = float(raw_score)
        if category == "rating":
            if score > rating["score"]:
                rating = {"label": name, "score": score}
            continue
        threshold = character_threshold if category == "character" else general_threshold
        if score >= threshold:
            result_tags.append({"name": name, "category": category, "score": score})

    result_tags.sort(key=lambda tag: tag["score"], reverse=True)
    result = {"tags": result_tags, "rating": rating}

    embedding = _embedding_from_outputs(outputs)
    if embedding is not None:
        result["embedding"] = [float(v) for v in embedding]
        result["embedding_dim"] = int(embedding.shape[0])
    return result


def embed(model_dir: Path, image_path: str) -> dict:
    """Unit-normalized image embedding = the WD tagger's penultimate feature (fc_norm), a
    Danbooru-native embedding produced as a byproduct of the same forward pass as the tags."""
    session = _session(str(model_dir / "model.onnx"))

    model_input = session.get_inputs()[0]
    size = next((d for d in model_input.shape if isinstance(d, int) and d > 3), 448)
    x = _preprocess(image_path, size)

    embedding = _embedding_from_outputs(session.run(None, {model_input.name: x}))
    if embedding is None:
        raise ValueError("model does not expose an embedding output (rebuild the ML image)")
    return {"embedding": [float(v) for v in embedding], "dim": int(embedding.shape[0])}
