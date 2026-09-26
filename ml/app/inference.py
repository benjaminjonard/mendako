"""WD tagger inference (ONNX).

``analyze()`` produces scored Danbooru tags and a content rating.

ONNX sessions are created lazily and cached (onnxruntime is imported lazily so this
module is importable without it).
"""

import csv
import functools
from pathlib import Path

import numpy as np

_CATEGORY = {0: "general", 1: "artist", 3: "copyright", 4: "character", 5: "meta", 9: "rating"}

DEFAULT_GENERAL_THRESHOLD = 0.35
DEFAULT_CHARACTER_THRESHOLD = 0.85
WD_IMAGE_SIZE = 448

MAX_PIXELS = 50_000_000

@functools.lru_cache(maxsize=8)
def _session(model_path: str):
    import onnxruntime as ort

    return ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])

@functools.lru_cache(maxsize=4)
def _load_implications(csv_path: str) -> dict[str, tuple[str, ...]]:
    """Tag to every tag Danbooru says it implies, transitively.

    Written out as the full ancestor set rather than as edges, so this is a lookup and not a walk.
    A model should not have to learn that a `seifuku` is `school_uniform`: the relation is a fact
    of the vocabulary, stated once by Danbooru, and predicting it separately can only add noise.
    """
    if not Path(csv_path).is_file():
        return {}

    with open(csv_path, newline="") as handle:
        return {row["tag"]: tuple(row["ancestors"].split()) for row in csv.DictReader(handle)}

@functools.lru_cache(maxsize=4)
def _load_copyrights(csv_path: str) -> dict[str, tuple[tuple[str, ...], float]]:
    """Character to its copyrights, and how often they actually ride along.

    `strict` means every post carrying the character carried the copyright too, measured over the
    corpus. A `fallback:0.84` means 84 % of them did, and that share multiplies the character's
    score: a copyright is never more certain than the character it was derived from.
    """
    if not Path(csv_path).is_file():
        return {}

    table: dict[str, tuple[tuple[str, ...], float]] = {}
    with open(csv_path, newline="") as handle:
        for row in csv.DictReader(handle):
            rule = row["rule"]
            share = 1.0 if rule == "strict" else float(rule.partition(":")[2] or 0.0)
            table[row["character"]] = (tuple(row["copyrights"].split()), share)

    return table

@functools.lru_cache(maxsize=4)
def _load_thresholds(csv_path: str) -> tuple[float, ...] | None:
    """One operating point per tag, in output order, or None when the model ships none.

    A single threshold per category is a compromise a model has to make when nothing measured its
    tags one by one. When `thresholds.csv` is there, each was measured on held-out posts, and a
    common value can only be worse than the value it replaces.

    Returned in the file's own order, which must be the vocabulary's: the check below is what
    stops a mismatched pair being served, since a shift of one row would apply every tag's
    threshold to its neighbour without any error at all.
    """
    if not Path(csv_path).is_file():
        return None

    with open(csv_path, newline="") as handle:
        return tuple(float(row["threshold"]) for row in csv.DictReader(handle))

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

    array = np.asarray(canvas, dtype=np.float32)[:, :, ::-1]
    return np.ascontiguousarray(array[np.newaxis, ...])

def _predict(model_dir: Path, image_path: str, preprocess, fallback_size: int, tag_count: int) -> np.ndarray:
    """One image through one model, as a flat array with one entry per tag.

    ``preprocess`` is the tagger's own image→tensor step; it is handed the model's declared
    input size so a re-exported model at a different resolution needs no code change.
    """
    session = _session(str(model_dir / "model.onnx"))

    model_input = session.get_inputs()[0]
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
    """WD inference: scored Danbooru tags plus the winning content rating.

    The two thresholds are the fallback, used by a model that ships no `thresholds.csv`.
    """
    tags = _load_tags(str(model_dir / "selected_tags.csv"))
    measured = _load_thresholds(str(model_dir / "thresholds.csv"))
    if measured is not None and len(measured) != len(tags):
        raise ValueError(f"thresholds.csv has {len(measured)} rows against {len(tags)} tags "
                         f"in selected_tags.csv — the two files are not from the same model")
    predictions = _predict(model_dir, image_path, _preprocess, WD_IMAGE_SIZE, len(tags))

    result_tags: list[dict] = []
    rating = {"label": None, "score": 0.0}
    for position, ((name, category), raw_score) in enumerate(zip(tags, predictions)):
        score = float(raw_score)
        if not np.isfinite(score):
            continue
        if category == "rating":
            if score > rating["score"]:
                rating = {"label": name, "score": score}
            continue
        if measured is not None:
            threshold = measured[position]
        else:
            threshold = character_threshold if category == "character" else general_threshold
        if score >= threshold:
            result_tags.append({"name": name, "category": category, "score": score})

    result_tags += _derive(result_tags, model_dir, dict(tags))
    result_tags.sort(key=lambda tag: tag["score"], reverse=True)
    return {"tags": result_tags, "rating": rating}

def _derive(predicted: list[dict], model_dir: Path, category_of: dict[str, str]) -> list[dict]:
    """The tags that follow from the predicted ones rather than being predicted themselves.

    Two relations, both taken from Danbooru rather than learned: a tag implies its ancestors (C1),
    and a character carries its copyright (C4). Neither needs an output neuron, and giving them one
    would spend capacity on facts a table states exactly.

    A derived tag is never more confident than what it was derived from, and one already predicted
    is left alone — the model's own score for it is a measurement, this is an inference.
    """
    implications = _load_implications(str(model_dir / "implications.csv"))
    copyrights = _load_copyrights(str(model_dir / "character_copyright.csv"))
    if not implications and not copyrights:
        return []

    seen = {tag["name"] for tag in predicted}
    best: dict[str, dict] = {}

    def offer(name: str, category: str, score: float) -> None:
        if name in seen:
            return
        if name not in best or score > best[name]["score"]:
            best[name] = {"name": name, "category": category, "score": score, "derived": True}

    for tag in predicted:
        for ancestor in implications.get(tag["name"], ()):
            offer(ancestor, category_of.get(ancestor, "general"), tag["score"])
        if tag["category"] == "character":
            owners, share = copyrights.get(tag["name"], ((), 0.0))
            for owner in owners:
                offer(owner, "copyright", tag["score"] * share)

    return list(best.values())
