from pathlib import Path

import numpy as np

from app import inference


class _FakeInput:
    name = "pixel_values"
    shape = [1, 3, 384, 384]  # NCHW


class _FakeVisualSession:
    def __init__(self, vector):
        self._vector = vector

    def get_inputs(self):
        return [_FakeInput()]

    def run(self, _outputs, _feed):
        return [np.array([self._vector], dtype=np.float32)]


def _clip_dir(tmp_path: Path) -> Path:
    model_dir = tmp_path / "siglip2-so400m"
    model_dir.mkdir()
    (model_dir / "visual.onnx").write_bytes(b"fake")
    return model_dir


def _png(tmp_path: Path) -> str:
    from PIL import Image

    path = tmp_path / "img.png"
    Image.new("RGB", (10, 20), (120, 30, 200)).save(path)
    return str(path)


def test_embed_returns_unit_normalized_vector(monkeypatch, tmp_path):
    model_dir = _clip_dir(tmp_path)
    raw = [3.0, 4.0] + [0.0] * 1150  # 1152-dim, norm 5 before normalization
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeVisualSession(raw))

    result = inference.embed(model_dir, _png(tmp_path))

    assert result["dim"] == 1152
    assert len(result["embedding"]) == 1152
    norm = float(np.linalg.norm(np.array(result["embedding"], dtype=np.float32)))
    assert abs(norm - 1.0) < 1e-5
    # direction preserved (3,4) -> (0.6, 0.8)
    assert abs(result["embedding"][0] - 0.6) < 1e-5
    assert abs(result["embedding"][1] - 0.8) < 1e-5


def test_embed_handles_zero_vector(monkeypatch, tmp_path):
    model_dir = _clip_dir(tmp_path)
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeVisualSession([0.0] * 1152))

    result = inference.embed(model_dir, _png(tmp_path))

    assert result["dim"] == 1152
    assert all(v == 0.0 for v in result["embedding"])  # no division by zero


def test_embed_rejects_non_finite_output(monkeypatch, tmp_path):
    import pytest

    model_dir = _clip_dir(tmp_path)
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeVisualSession([float("nan")] + [0.0] * 1151))

    with pytest.raises(ValueError):
        inference.embed(model_dir, _png(tmp_path))
