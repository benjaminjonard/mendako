from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)

def test_models_returns_catalog(monkeypatch, tmp_path):
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
    """Indexed by id, not by category: a category holds several models."""
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    by_id = {e["id"]: e for e in client.get("/models").json()}
    materialised = next(iter(by_id))

    model_dir = tmp_path / materialised
    model_dir.mkdir()
    for filename in by_id[materialised]["files"]:
        (model_dir / filename).write_text("x")

    after = {e["id"]: e for e in client.get("/models").json()}

    assert after[materialised]["status"] == "ready"
    for model_id, entry in after.items():
        if model_id != materialised:
            assert entry["status"] == "absent"

def test_a_model_is_absent_until_every_declared_file_is_there(monkeypatch, tmp_path):
    """One missing file is one unusable model — a head without its tag list says nothing."""
    monkeypatch.setenv("MENDAKO_MODELS_DIR", str(tmp_path))
    by_id = {e["id"]: e for e in client.get("/models").json()}
    partial = max(by_id, key=lambda i: len(by_id[i]["files"]))

    model_dir = tmp_path / partial
    model_dir.mkdir()
    for filename in by_id[partial]["files"][:-1]:
        (model_dir / filename).write_text("x")

    assert {e["id"]: e for e in client.get("/models").json()}[partial]["status"] == "absent"

