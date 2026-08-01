from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)


def test_health_returns_ok_and_empty_models():
    resp = client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body == {"status": "ok", "models": []}


def test_health_models_is_a_list():
    body = client.get("/health").json()
    assert isinstance(body["models"], list)
