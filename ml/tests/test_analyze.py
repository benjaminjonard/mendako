import pytest
from fastapi.testclient import TestClient

from app import inference
from app.main import app

client = TestClient(app)

WD = "wd-eva02-large-tagger-v3"
RAM = "ram-plus"


def _ready(tmp_path):
    model_dir = tmp_path / WD
    model_dir.mkdir()
    (model_dir / "model.onnx").write_text("x")
    (model_dir / "selected_tags.csv").write_text("x")


def _ready_ram(tmp_path):
    model_dir = tmp_path / RAM
    model_dir.mkdir()
    for filename in ("model.onnx", "tags.txt", "thresholds.txt"):
        (model_dir / filename).write_text("x")


def _stub_analyze(monkeypatch, extra=None):
    result = {
        "tags": [{"name": "1girl", "category": "general", "score": 0.9}],
        "rating": {"label": "general", "score": 0.8},
    }
    result.update(extra or {})
    monkeypatch.setattr(inference, "analyze", lambda model_dir, path: dict(result))


def test_analyze_returns_result(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _stub_analyze(monkeypatch)

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


def test_analyze_routes_ram_model_to_the_ram_tagger(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready_ram(tmp_path)
    # Fail loudly if the WD path is taken instead.
    monkeypatch.setattr(inference, "analyze", lambda model_dir, path: pytest.fail("wrong tagger"))
    monkeypatch.setattr(
        inference,
        "analyze_ram",
        lambda model_dir, path: {"tags": [{"name": "beach", "category": "general", "score": 0.9}],
                                 "rating": {"label": None, "score": 0.0}},
    )

    resp = client.post("/analyze", data={"model": RAM}, files={"image": ("a.png", b"PNGDATA", "image/png")})

    assert resp.status_code == 200
    body = resp.json()
    assert body["tags"][0]["name"] == "beach"
    assert body["rating"]["label"] is None


def test_analyze_routes_wd_model_to_the_wd_tagger(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    _ready(tmp_path)
    _stub_analyze(monkeypatch)
    monkeypatch.setattr(inference, "analyze_ram", lambda model_dir, path: pytest.fail("wrong tagger"))

    resp = client.post("/analyze", data={"model": WD}, files={"image": ("a.png", b"PNGDATA", "image/png")})

    assert resp.status_code == 200
    assert resp.json()["tags"][0]["name"] == "1girl"
