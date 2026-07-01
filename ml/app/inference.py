"""WD tagger inference (ONNX).

Loads a WD tagger ONNX model + its ``selected_tags.csv`` and produces scored
Danbooru tags + a content rating. The ONNX session is created lazily and cached
(onnxruntime is imported lazily so this module is importable without it).
"""

import csv
import functools
import gc
import os
import threading
from pathlib import Path

import numpy as np

# selected_tags.csv category codes (SmilingWolf WD taggers) → our category names.
_CATEGORY = {0: "general", 1: "artist", 3: "copyright", 4: "character", 5: "meta", 9: "rating"}

DEFAULT_GENERAL_THRESHOLD = 0.35
DEFAULT_CHARACTER_THRESHOLD = 0.85
MAX_PIXELS = 50_000_000  # decompression-bomb guard (~50 MP)


@functools.lru_cache(maxsize=8)
def _session(model_path: str):
    # WD model.onnx + CLIP visual.onnx — resident, used on every image. Default intra-op
    # threads (each forward is matmul-heavy and benefits from all cores).
    import onnxruntime as ort  # lazy: keeps the module importable without onnxruntime

    return ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])


@functools.lru_cache(maxsize=1)
def _text_session(model_path: str):
    # textual.onnx (~3 GB) — kept in its OWN cache so the vocabulary warm-up can release it
    # (release_text_session) afterwards without evicting the resident WD/visual sessions.
    import onnxruntime as ort

    return ort.InferenceSession(model_path, providers=["CPUExecutionProvider"])


def release_text_session() -> None:
    """Drop the textual.onnx session and return its memory — the per-image path never needs it
    (zero-shot is cached-only), so it only has to be resident while encoding new tags."""
    cache_clear = getattr(_text_session, "cache_clear", None)
    if cache_clear is not None:  # plain function in tests has no lru_cache
        cache_clear()
    gc.collect()


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

    predictions = np.asarray(session.run(None, {model_input.name: x})[0]).reshape(-1)
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
    return {"tags": result_tags, "rating": rating}


def _preprocess_clip(image_path: str, size: int, channels_first: bool) -> np.ndarray:
    from PIL import Image

    image = Image.open(image_path)
    if image.width * image.height > MAX_PIXELS:
        raise ValueError("image exceeds the maximum allowed size")
    image = image.convert("RGB").resize((size, size), Image.BICUBIC)

    # SigLIP preprocessing: scale pixels to [-1, 1] (mean 0.5 / std 0.5).
    array = np.asarray(image, dtype=np.float32) / 127.5 - 1.0  # HWC
    if channels_first:
        array = array.transpose(2, 0, 1)  # HWC -> CHW
    return np.ascontiguousarray(array[np.newaxis, ...])


def embed(model_dir: Path, image_path: str) -> dict:
    """Produce a unit-normalized CLIP image embedding via the model's visual.onnx."""
    session = _session(str(model_dir / "visual.onnx"))

    model_input = session.get_inputs()[0]
    shape = model_input.shape
    # NCHW when the channel dim (==3) sits at index 1; otherwise treat as NHWC.
    channels_first = len(shape) == 4 and shape[1] == 3
    size = next((d for d in shape if isinstance(d, int) and d > 3), 384)
    x = _preprocess_clip(image_path, size, channels_first)

    output = np.asarray(session.run(None, {model_input.name: x})[0]).reshape(-1).astype(np.float32)
    if not np.all(np.isfinite(output)):
        raise ValueError("embedding contains non-finite values")
    norm = float(np.linalg.norm(output))
    if norm > 0.0:
        output = output / norm
    return {"embedding": [float(v) for v in output], "dim": int(output.shape[0])}


@functools.lru_cache(maxsize=8)
def _tokenizer(tokenizer_path: str):
    from tokenizers import Tokenizer

    return Tokenizer.from_file(tokenizer_path)


# Persistent text-embedding store: the user's tag vocabulary is encoded once and reused
# across images, runs and container restarts (the store lives on a Docker volume). Encoding
# the whole vocabulary is a slow one-off (the SigLIP text encoder is fixed at batch 1), so
# it runs as a background warm-up with progress; per-image scoring only uses what's cached.
_text_store_lock = threading.Lock()
_text_store: dict[str, dict[str, np.ndarray]] = {}  # model_dir -> {tag name: unit vector}

# Progress of the background vocabulary warm-up, polled by the UI.
_warm_lock = threading.Lock()
_warm_state: dict = {"running": False, "done": 0, "total": 0}


def warm_status() -> dict:
    """Snapshot of the vocabulary warm-up progress: {running, done, total}."""
    with _warm_lock:
        return dict(_warm_state)


def _set_warm(**fields) -> None:
    with _warm_lock:
        _warm_state.update(fields)


def _text_cache_path(model_dir_str: str) -> Path:
    base = Path(os.environ.get("MENDAKO_TEXT_CACHE_DIR", "/text-cache"))
    return base / f"{Path(model_dir_str).name}.npz"


def _load_store(model_dir_str: str) -> dict[str, np.ndarray]:
    cached = _text_store.get(model_dir_str)
    if cached is not None:
        return cached
    store: dict[str, np.ndarray] = {}
    path = _text_cache_path(model_dir_str)
    if path.is_file():
        try:
            data = np.load(path, allow_pickle=True)
            store = {str(name): vec for name, vec in zip(data["names"], data["vectors"])}
        except Exception:  # noqa: BLE001 — a corrupt cache must never break inference; rebuild it
            store = {}
    _text_store[model_dir_str] = store
    return store


def _save_store(model_dir_str: str, store: dict[str, np.ndarray]) -> None:
    path = _text_cache_path(model_dir_str)
    path.parent.mkdir(parents=True, exist_ok=True)
    names = np.array(list(store.keys()), dtype=object)
    vectors = np.stack(list(store.values())) if store else np.zeros((0, 0), dtype=np.float32)
    tmp = path.with_name(path.name + ".tmp")
    # Pass a file handle (not a str path) so np.savez doesn't append its own ".npz" suffix.
    with open(tmp, "wb") as handle:
        np.savez(handle, names=names, vectors=vectors)
    tmp.replace(path)  # atomic swap so a crash mid-write can't corrupt the cache


def _encode_one(model_dir_str: str, text: str) -> np.ndarray:
    """Unit-normalized text embedding for one tag. The SigLIP text encoder is fixed at
    [1, seq_len]; the input dtype is read from the model (it wants int32, not the int64 some
    mirrors use) — feeding the wrong dtype makes every call fail."""
    model_dir = Path(model_dir_str)
    session = _text_session(str(model_dir / "textual.onnx"))
    tokenizer = _tokenizer(str(model_dir / "tokenizer.json"))

    model_input = session.get_inputs()[0]
    seq_len = next((d for d in reversed(model_input.shape) if isinstance(d, int) and d > 1), 64)
    dtype = np.int32 if "int32" in (model_input.type or "") else np.int64

    token_ids = tokenizer.encode(text).ids[:seq_len]
    token_ids = token_ids + [0] * (seq_len - len(token_ids))
    x = np.asarray([token_ids], dtype=dtype)  # [1, seq_len]
    out = np.asarray(session.run(None, {model_input.name: x})[0], dtype=np.float32).reshape(-1)
    norm = float(np.linalg.norm(out))
    return out / norm if norm > 0.0 else out


def cached_vectors(model_dir: Path, texts: list[str]) -> dict[str, np.ndarray]:
    """{tag: unit vector} for the requested tags already in the store — no encoding, so the
    per-image path never blocks on the warm-up (it scores against whatever is cached)."""
    store = _load_store(str(model_dir))
    with _text_store_lock:  # snapshot: the background warm-up may be mutating the store
        return {text: store[text] for text in texts if text in store}


def vocabulary_coverage(model_dir: Path, texts: list[str]) -> tuple[int, int]:
    """(cached, missing) counts for the given tags. Read-only — it only loads the on-disk name
    list, never textual.onnx — so the admin can cheaply see how many tags still need encoding."""
    store = _load_store(str(model_dir))
    unique = list(dict.fromkeys(texts))
    with _text_store_lock:
        cached = sum(1 for text in unique if text in store)
    return cached, len(unique) - cached


def warm_vocabulary(model_dir: Path, texts: list[str], reset: bool = False) -> None:
    """Encode the tag names into the persistent store, one at a time, updating progress.
    With reset=False only the not-yet-cached tags are encoded (incremental); with reset=True the
    store is wiped first so the whole vocabulary is re-encoded. Idempotent: a call while a warm-up
    is already running is a no-op. Runs in the background, then textual.onnx is unloaded."""
    model_dir_str = str(model_dir)
    with _warm_lock:
        if _warm_state["running"]:
            return
        _warm_state.update(running=True, done=0, total=0)

    try:
        if reset:
            with _text_store_lock:
                _text_store[model_dir_str] = {}
                _save_store(model_dir_str, {})
        store = _load_store(model_dir_str)
        wanted = list(dict.fromkeys(texts))
        missing = [text for text in wanted if text not in store]
        already = len(wanted) - len(missing)
        _set_warm(total=len(wanted), done=already)

        for index, text in enumerate(missing, start=1):
            vector = _encode_one(model_dir_str, text)
            with _text_store_lock:
                store[text] = vector
            _set_warm(done=already + index)
            if index % 50 == 0:  # periodic checkpoint so progress survives a crash
                with _text_store_lock:
                    _save_store(model_dir_str, store)
        with _text_store_lock:
            _save_store(model_dir_str, store)
    finally:
        _set_warm(running=False)
        release_text_session()  # free textual.onnx (~3 GB); the image path is cached-only


def embed_texts(model_dir: Path, texts: list[str]) -> list[list[float]]:
    """Unit-normalized text embeddings for a list of strings (encoding+persisting any misses)."""
    model_dir_str = str(model_dir)
    store = _load_store(model_dir_str)
    missing = [text for text in dict.fromkeys(texts) if text not in store]
    if missing:
        with _text_store_lock:
            store = _load_store(model_dir_str)
            for text in [m for m in missing if m not in store]:
                store[text] = _encode_one(model_dir_str, text)
            _save_store(model_dir_str, store)
    return [store[text].tolist() for text in texts]


def zeroshot(
    model_dir: Path,
    image_embedding: list[float],
    texts: list[str],
    top_n: int,
    min_score: float,
) -> list[dict]:
    """Score a (unit-normalized) image embedding against the cached text-encoded tag names.

    Uses only vectors already in the store (see warm_vocabulary) — never encodes on the image
    path — so it's just one matrix-vector product. Both sides are unit length, so the dot
    product is cosine similarity. Returns the top matches above the floor, highest first.
    """
    if not texts:
        return []

    store = cached_vectors(model_dir, texts)
    names = [text for text in texts if text in store]
    if not names:
        return []

    matrix = np.stack([store[name] for name in names])  # [N, dim]
    image = np.asarray(image_embedding, dtype=np.float32)
    scores = matrix @ image  # [N] cosine similarities

    results = [
        {"name": name, "score": float(score)}
        for name, score in zip(names, scores)
        if float(score) >= min_score
    ]
    results.sort(key=lambda r: r["score"], reverse=True)
    return results[:top_n]
