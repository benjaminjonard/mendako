from pathlib import Path

import numpy as np

from app import inference


class _FakeInput:
    name = "input"
    shape = [1, 448, 448, 3]


class _FakeSession:
    def __init__(self, scores):
        self._scores = scores

    def get_inputs(self):
        return [_FakeInput()]

    def run(self, _outputs, _feed):
        return [np.array([self._scores], dtype=np.float32)]


def _write_tags(tmp_path: Path) -> Path:
    model_dir = tmp_path / "wd"
    model_dir.mkdir()
    (model_dir / "model.onnx").write_bytes(b"fake")
    (model_dir / "selected_tags.csv").write_text(
        "tag_id,name,category\n"
        "1,1girl,0\n"
        "2,smile,0\n"
        "3,obscure_oc,4\n"
        "4,general,9\n"
        "5,explicit,9\n"
    )
    return model_dir


def _png(tmp_path: Path) -> str:
    from PIL import Image

    path = tmp_path / "img.png"
    Image.new("RGB", (10, 20), (120, 30, 200)).save(path)
    return str(path)


def test_analyze_returns_thresholded_tags_and_rating(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    inference._load_tags.cache_clear()
    # 1girl=0.9 (keep), smile=0.1 (drop), obscure_oc=0.9 (char, below 0.85? no, keep), rating general=0.8, explicit=0.2
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.1, 0.9, 0.8, 0.2]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"]: t for t in result["tags"]}
    assert "1girl" in names and names["1girl"]["category"] == "general"
    assert "smile" not in names  # below general threshold
    assert "obscure_oc" in names and names["obscure_oc"]["category"] == "character"
    assert "general" not in names and "explicit" not in names  # ratings excluded from tags
    assert result["rating"]["label"] == "general" and result["rating"]["score"] > 0.7
    # sorted by score desc
    assert result["tags"] == sorted(result["tags"], key=lambda t: t["score"], reverse=True)


def test_character_tag_below_high_threshold_is_dropped(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    inference._load_tags.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.0, 0.5, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"] for t in result["tags"]}
    assert "obscure_oc" not in names  # character score 0.5 < 0.85
    assert "1girl" in names
