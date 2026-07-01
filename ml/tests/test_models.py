from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_models_returns_catalog(monkeypatch, tmp_path):
    # Point MODELS_DIR at an empty dir → everything is 'absent'
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))

    resp = client.get("/models")

    assert resp.status_code == 200
    body = resp.json()
    categories = {e["category"] for e in body}
    assert categories == {"wd"}
    for entry in body:
        assert set(entry.keys()) == {"category", "id", "repo_id", "revision", "files", "dim", "status"}
        assert entry["status"] == "absent"


def test_models_marks_ready_when_files_present(monkeypatch, tmp_path):
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    # Materialize the WD model's files
    wd_dir = tmp_path / "wd-eva02-large-tagger-v3"
    wd_dir.mkdir()
    (wd_dir / "model.onnx").write_text("x")
    (wd_dir / "selected_tags.csv").write_text("x")

    body = client.get("/models").json()
    by_cat = {e["category"]: e for e in body}

    assert by_cat["wd"]["status"] == "ready"
