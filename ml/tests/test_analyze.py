from fastapi.testclient import TestClient

from app import inference
from app.catalog import find_entry
from app.main import app

client = TestClient(app)

WD = "mendako-tagger"

def _ready(tmp_path):
    """Materialise whatever the catalog declares, rather than a hardcoded pair of files.

    A model that gains a file — `thresholds.csv` did — would otherwise stay `absent` here and the
    failure would point at the route instead of at this helper.
    """
    model_dir = tmp_path / WD
    model_dir.mkdir()
    for filename in find_entry(WD)["files"]:
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
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    resp = client.post("/analyze", data={"model": WD}, files={"image": ("a.png", b"x", "image/png")})
    assert resp.status_code == 409

