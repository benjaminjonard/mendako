from pathlib import Path

import numpy as np

from app import inference


class _FakeInput:
    name = "input"
    shape = ["batch", 448, 448, 3]  # NHWC (WD)


class _FakeWdSession:
    """Mirrors the patched WD model: two outputs [tag logits, embedding]."""

    def __init__(self, embedding, second_output=True):
        self._embedding = embedding
        self._second = second_output

    def get_inputs(self):
        return [_FakeInput()]

    def run(self, _outputs, _feed):
        logits = np.zeros((1, 10), dtype=np.float32)
        if not self._second:
            return [logits]
        return [logits, np.array([self._embedding], dtype=np.float32)]


def _wd_dir(tmp_path: Path) -> Path:
    model_dir = tmp_path / "wd-eva02-large-tagger-v3"
    model_dir.mkdir()
    (model_dir / "model.onnx").write_bytes(b"fake")
    return model_dir


def _png(tmp_path: Path) -> str:
    from PIL import Image

    path = tmp_path / "img.png"
    Image.new("RGB", (10, 20), (120, 30, 200)).save(path)
    return str(path)


def test_embed_returns_unit_normalized_vector(monkeypatch, tmp_path):
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeWdSession([3.0, 4.0]))  # norm 5

    result = inference.embed(_wd_dir(tmp_path), _png(tmp_path))

    assert result["dim"] == 2
    norm = float(np.linalg.norm(np.array(result["embedding"], dtype=np.float32)))
    assert abs(norm - 1.0) < 1e-5
    # direction preserved (3,4) -> (0.6, 0.8)
    assert abs(result["embedding"][0] - 0.6) < 1e-5
    assert abs(result["embedding"][1] - 0.8) < 1e-5


def test_embed_rejects_zero_vector(monkeypatch, tmp_path):
    import pytest

    monkeypatch.setattr(inference, "_session", lambda _p: _FakeWdSession([0.0, 0.0]))

    with pytest.raises(ValueError):
        inference.embed(_wd_dir(tmp_path), _png(tmp_path))


def test_embed_rejects_model_without_embedding_output(monkeypatch, tmp_path):
    import pytest

    monkeypatch.setattr(inference, "_session", lambda _p: _FakeWdSession([1.0], second_output=False))

    with pytest.raises(ValueError):
        inference.embed(_wd_dir(tmp_path), _png(tmp_path))


def test_embed_rejects_non_finite_output(monkeypatch, tmp_path):
    import pytest

    monkeypatch.setattr(inference, "_session", lambda _p: _FakeWdSession([float("nan"), 0.0]))

    with pytest.raises(ValueError):
        inference.embed(_wd_dir(tmp_path), _png(tmp_path))
