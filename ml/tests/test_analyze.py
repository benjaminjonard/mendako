import json

from fastapi.testclient import TestClient

from app import inference
from app.main import app

client = TestClient(app)

WD = "wd-eva02-large-tagger-v3"
CLIP = "siglip2-so400m"


def _ready(tmp_path):
    model_dir = tmp_path / WD
    model_dir.mkdir()
    (model_dir / "model.onnx").write_text("x")
    (model_dir / "selected_tags.csv").write_text("x")


def _ready_clip(tmp_path):
    model_dir = tmp_path / CLIP
    model_dir.mkdir()
    (model_dir / "visual.onnx").write_text("x")
    (model_dir / "textual.onnx").write_text("x")
    (model_dir / "tokenizer.json").write_text("x")


def _stub_analyze(monkeypatch):
    monkeypatch.setattr(inference, "analyze", lambda model_dir, path: {
        "tags": [{"name": "1girl", "category": "general", "score": 0.9}],
        "rating": {"label": "general", "score": 0.8},
    })


def test_analyze_returns_result(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    monkeypatch.setattr(inference, "analyze", lambda model_dir, path: {
        "tags": [{"name": "1girl", "category": "general", "score": 0.9}],
        "rating": {"label": "general", "score": 0.8},
    })

    resp = client.post("/analyze", data={"model": WD}, files={"image": ("a.png", b"PNGDATA", "image/png")})

    assert resp.status_code == 200
    body = resp.json()
    assert body["tags"][0]["name"] == "1girl"
    assert body["rating"]["label"] == "general"


def test_analyze_unknown_model_404(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    resp = client.post("/analyze", data={"model": "nope"}, files={"image": ("a.png", b"x", "image/png")})
    assert resp.status_code == 404


def test_analyze_model_not_ready_409(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))  # empty dir → absent
    resp = client.post("/analyze", data={"model": WD}, files={"image": ("a.png", b"x", "image/png")})
    assert resp.status_code == 409


def test_analyze_with_clip_model_returns_embedding(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _ready_clip(tmp_path)
    _stub_analyze(monkeypatch)
    monkeypatch.setattr(inference, "embed", lambda model_dir, path: {"embedding": [0.6, 0.8], "dim": 2})

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP},
        files={"image": ("a.png", b"PNGDATA", "image/png")},
    )

    assert resp.status_code == 200
    body = resp.json()
    assert body["tags"][0]["name"] == "1girl"
    assert body["embedding"] == [0.6, 0.8]
    assert body["embedding_dim"] == 2
    assert body["clip_model_id"] == CLIP


def test_analyze_with_tag_names_returns_zeroshot(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _ready_clip(tmp_path)
    _stub_analyze(monkeypatch)
    monkeypatch.setattr(inference, "embed", lambda model_dir, path: {"embedding": [0.6, 0.8], "dim": 2})
    monkeypatch.setattr(inference, "zeroshot", lambda model_dir, emb, names, top_n, floor: [{"name": "cat", "score": 0.9}])

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP, "tag_names": json.dumps(["cat", "dog"])},
        files={"image": ("a.png", b"PNGDATA", "image/png")},
    )

    assert resp.status_code == 200
    body = resp.json()
    assert body["embedding"] == [0.6, 0.8]
    assert body["zeroshot"] == [{"name": "cat", "score": 0.9}]


def test_analyze_zeroshot_failure_still_returns_embedding(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _ready_clip(tmp_path)
    _stub_analyze(monkeypatch)
    monkeypatch.setattr(inference, "embed", lambda model_dir, path: {"embedding": [0.6, 0.8], "dim": 2})

    def _boom(model_dir, emb, names, top_n, floor):
        raise RuntimeError("text encoder blew up")

    monkeypatch.setattr(inference, "zeroshot", _boom)

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP, "tag_names": json.dumps(["cat"])},
        files={"image": ("a.png", b"PNGDATA", "image/png")},
    )

    assert resp.status_code == 200
    body = resp.json()
    assert body["embedding"] == [0.6, 0.8]   # embedding survives
    assert "zeroshot" not in body            # zero-shot degraded gracefully


def test_analyze_without_clip_model_has_no_embedding(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _stub_analyze(monkeypatch)

    resp = client.post("/analyze", data={"model": WD}, files={"image": ("a.png", b"PNGDATA", "image/png")})

    assert resp.status_code == 200
    assert "embedding" not in resp.json()


def test_analyze_embedding_failure_still_returns_tags(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _ready_clip(tmp_path)
    _stub_analyze(monkeypatch)

    def _boom(model_dir, path):
        raise RuntimeError("onnx blew up")

    monkeypatch.setattr(inference, "embed", _boom)

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP},
        files={"image": ("a.png", b"PNGDATA", "image/png")},
    )

    # Embedding is best-effort: tags survive, no embedding key, no 5xx.
    assert resp.status_code == 200
    body = resp.json()
    assert body["tags"][0]["name"] == "1girl"
    assert "embedding" not in body


def test_analyze_clip_model_missing_tokenizer_is_not_ready(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    # CLIP dir with the two .onnx files but WITHOUT tokenizer.json → not "ready".
    model_dir = tmp_path / CLIP
    model_dir.mkdir()
    (model_dir / "visual.onnx").write_text("x")
    (model_dir / "textual.onnx").write_text("x")
    _stub_analyze(monkeypatch)

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP},
        files={"image": ("a.png", b"x", "image/png")},
    )

    assert resp.status_code == 409


def test_analyze_clip_model_not_ready_409(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)  # wd ready, clip absent
    _stub_analyze(monkeypatch)

    resp = client.post(
        "/analyze",
        data={"model": WD, "clip_model": CLIP},
        files={"image": ("a.png", b"x", "image/png")},
    )

    assert resp.status_code == 409
