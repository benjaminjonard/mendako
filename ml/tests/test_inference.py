from pathlib import Path

import numpy as np
import pytest

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
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.1, 0.9, 0.8, 0.2]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"]: t for t in result["tags"]}
    assert "1girl" in names and names["1girl"]["category"] == "general"
    assert "smile" not in names
    assert "obscure_oc" in names and names["obscure_oc"]["category"] == "character"
    assert "general" not in names and "explicit" not in names
    assert result["rating"]["label"] == "general" and result["rating"]["score"] > 0.7
    assert result["tags"] == sorted(result["tags"], key=lambda t: t["score"], reverse=True)

def test_character_tag_below_high_threshold_is_dropped(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    inference._load_tags.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.0, 0.5, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"] for t in result["tags"]}
    assert "obscure_oc" not in names
    assert "1girl" in names

def _write_thresholds(model_dir: Path, values: list[str]) -> None:
    rows = "\n".join(f"{name},{level}" for name, level in
                     zip(("1girl", "smile", "obscure_oc", "general", "explicit"), values))
    (model_dir / "thresholds.csv").write_text(f"name,threshold\n{rows}\n")

def test_per_tag_thresholds_override_the_category_defaults(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    _write_thresholds(model_dir, ["0.50", "0.10", "0.95", "0.50", "0.50"])
    inference._load_tags.cache_clear()
    inference._load_thresholds.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.2, 0.9, 0.8, 0.2]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"] for t in result["tags"]}
    assert "smile" in names
    assert "obscure_oc" not in names
    assert "1girl" in names
    assert result["rating"]["label"] == "general"

def test_a_model_without_thresholds_keeps_the_category_defaults(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    inference._load_tags.cache_clear()
    inference._load_thresholds.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.2, 0.9, 0.8, 0.2]))

    result = inference.analyze(model_dir, _png(tmp_path))

    names = {t["name"] for t in result["tags"]}
    assert "smile" not in names
    assert "obscure_oc" in names

def test_thresholds_of_a_different_length_are_refused(monkeypatch, tmp_path):
    """A shift of one row would apply every tag's threshold to its neighbour, silently."""
    model_dir = _write_tags(tmp_path)
    (model_dir / "thresholds.csv").write_text("name,threshold\n1girl,0.5\nsmile,0.5\n")
    inference._load_tags.cache_clear()
    inference._load_thresholds.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.2, 0.9, 0.8, 0.2]))

    with pytest.raises(ValueError, match="not from the same model"):
        inference.analyze(model_dir, _png(tmp_path))

def _write_tables(model_dir: Path) -> None:
    (model_dir / "implications.csv").write_text(
        "tag,ancestors\n"
        "1girl,solo\n"
    )
    (model_dir / "character_copyright.csv").write_text(
        "character,copyrights,rule\n"
        "obscure_oc,some_series,strict\n"
    )

def test_a_character_brings_its_copyright(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    _write_tables(model_dir)
    for cache in (inference._load_tags, inference._load_thresholds,
                  inference._load_implications, inference._load_copyrights):
        cache.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.4, 0.0, 0.9, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    by_name = {t["name"]: t for t in result["tags"]}
    assert by_name["some_series"]["category"] == "copyright"
    assert by_name["some_series"]["score"] == by_name["obscure_oc"]["score"]
    assert by_name["some_series"]["derived"] is True

def test_a_fallback_rule_lowers_the_derived_score(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    _write_tables(model_dir)
    (model_dir / "character_copyright.csv").write_text(
        "character,copyrights,rule\nobscure_oc,some_series,fallback:0.500\n")
    for cache in (inference._load_tags, inference._load_thresholds,
                  inference._load_implications, inference._load_copyrights):
        cache.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.4, 0.0, 0.9, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    by_name = {t["name"]: t for t in result["tags"]}
    assert by_name["some_series"]["score"] == pytest.approx(by_name["obscure_oc"]["score"] * 0.5)

def test_an_implied_tag_is_added_and_a_predicted_one_is_left_alone(monkeypatch, tmp_path):
    model_dir = _write_tags(tmp_path)
    _write_tables(model_dir)
    for cache in (inference._load_tags, inference._load_thresholds,
                  inference._load_implications, inference._load_copyrights):
        cache.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.0, 0.0, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    by_name = {t["name"]: t for t in result["tags"]}
    assert by_name["solo"]["score"] == by_name["1girl"]["score"]
    assert "derived" not in by_name["1girl"]

def test_tables_are_optional(monkeypatch, tmp_path):
    """A model shipping neither table behaves exactly as before."""
    model_dir = _write_tags(tmp_path)
    for cache in (inference._load_tags, inference._load_thresholds,
                  inference._load_implications, inference._load_copyrights):
        cache.cache_clear()
    monkeypatch.setattr(inference, "_session", lambda _p: _FakeSession([0.9, 0.0, 0.9, 0.9, 0.0]))

    result = inference.analyze(model_dir, _png(tmp_path))

    assert all("derived" not in t for t in result["tags"])
