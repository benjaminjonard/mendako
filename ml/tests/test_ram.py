from pathlib import Path

import numpy as np
import pytest

from app import inference


class _FakeInput:
    name = "input"
    shape = [1, 3, 384, 384]


class _FakeSession:
    """Records the tensor it was fed so preprocessing can be asserted on."""

    def __init__(self, logits):
        self._logits = logits
        self.seen = None

    def get_inputs(self):
        return [_FakeInput()]

    def run(self, _outputs, feed):
        self.seen = next(iter(feed.values()))
        return [np.array([self._logits], dtype=np.float32)]


def _model_dir(tmp_path: Path, thresholds="0.650000\n0.650000\n1.000000\n") -> Path:
    model_dir = tmp_path / "ram-plus"
    model_dir.mkdir()
    (model_dir / "model.onnx").write_bytes(b"fake")
    (model_dir / "tags.txt").write_text("beach\nsunset\nbody\n")
    (model_dir / "thresholds.txt").write_text(thresholds)
    return model_dir


def _png(tmp_path: Path, size=(10, 20)) -> str:
    from PIL import Image

    path = tmp_path / "img.png"
    Image.new("RGB", size, (120, 30, 200)).save(path)
    return str(path)


def _clear_caches():
    inference._load_lines.cache_clear()
    inference._load_thresholds.cache_clear()


def test_tags_above_their_own_threshold_are_kept(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path)
    _clear_caches()
    # logits → sigmoid: 2.0 → 0.88 (over 0.65, keep), -2.0 → 0.12 (drop), 50.0 → ~1.0
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([2.0, -2.0, 50.0]))

    result = inference.analyze_ram(model_dir, _png(tmp_path))

    names = {t["name"]: t for t in result["tags"]}
    assert "beach" in names and names["beach"]["category"] == "general"
    assert "sunset" not in names
    # 'body' ships a 1.0 threshold: RAM++ disables it, and a saturated logit must not revive it
    assert "body" not in names
    assert 0.8 < names["beach"]["score"] < 0.9


def test_rating_is_always_empty(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path)
    _clear_caches()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([5.0, 5.0, 5.0]))

    result = inference.analyze_ram(model_dir, _png(tmp_path))

    # RAM++ has no rating head; the shape still matches analyze() so callers don't branch.
    assert result["rating"] == {"label": None, "score": 0.0}


def test_tags_are_sorted_by_score(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path, thresholds="0.100000\n0.100000\n0.100000\n")
    _clear_caches()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([1.0, 3.0, 2.0]))

    result = inference.analyze_ram(model_dir, _png(tmp_path))

    assert [t["name"] for t in result["tags"]] == ["sunset", "body", "beach"]


def test_preprocessing_squashes_to_square_and_normalizes(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path)
    _clear_caches()
    session = _FakeSession([0.0, 0.0, 0.0])
    monkeypatch.setattr(inference, "_session", lambda _p: session)

    # A non-square source: RAM++ squashes rather than padding, so no letterbox bars appear.
    inference.analyze_ram(model_dir, _png(tmp_path, size=(10, 40)))

    assert session.seen.shape == (1, 3, 384, 384)  # NCHW
    # The source is a single flat colour, so every pixel normalizes to the same per-channel
    # value — a padded canvas would have introduced a second, different value.
    for channel in range(3):
        plane = session.seen[0, channel]
        assert np.allclose(plane, plane.flat[0], atol=1e-4)

    expected_r = (120 / 255.0 - inference.RAM_MEAN[0]) / inference.RAM_STD[0]
    assert session.seen[0, 0].flat[0] == pytest.approx(expected_r, abs=1e-5)


def test_threshold_count_mismatch_is_rejected(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path, thresholds="0.650000\n")
    _clear_caches()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([1.0, 1.0, 1.0]))

    try:
        inference.analyze_ram(model_dir, _png(tmp_path))
    except ValueError as exc:
        assert "threshold count" in str(exc)
    else:
        raise AssertionError("expected a ValueError on a tag/threshold count mismatch")


def test_output_size_mismatch_is_rejected(monkeypatch, tmp_path):
    model_dir = _model_dir(tmp_path)
    _clear_caches()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([1.0, 1.0]))

    try:
        inference.analyze_ram(model_dir, _png(tmp_path))
    except ValueError as exc:
        assert "model output size" in str(exc)
    else:
        raise AssertionError("expected a ValueError on an output/tag count mismatch")
