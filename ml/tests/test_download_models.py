"""Build-time model fetch (download_models.py).

The single-file ops are trivial; the folder op (flatten + rename for the SigLIP
text encoder's ONNX external-data files) carries the logic worth pinning down.
"""

from pathlib import Path

import download_models


def _fake_hf(files: dict):
    """Return an hf_hub_download stub backed by an in-memory {repo_path: content} map."""

    def fake(repo_id, filename, revision):
        blob = Path("/tmp") / "fake-hf" / repo_id / filename
        blob.parent.mkdir(parents=True, exist_ok=True)
        blob.write_text(files[filename])
        return str(blob)

    return fake


def test_fetch_folder_flattens_and_renames(monkeypatch, tmp_path):
    repo_files = {
        "textual/model.onnx": "graph",
        "textual/onnx__MatMul_1": "weights",
        "textual/tokenizer.json": "tok",
        "textual/rknpu/model.rknn": "npu",  # nested → skipped
        "visual/model.onnx": "vis",          # other folder → ignored
    }
    monkeypatch.setattr(download_models, "list_repo_files", lambda repo_id, revision: list(repo_files))
    monkeypatch.setattr(download_models, "hf_hub_download", _fake_hf(repo_files))

    model_dir = tmp_path / "clip"
    download_models._fetch_folder("repo", "rev", "textual", {"model.onnx": "textual.onnx"}, model_dir)

    assert (model_dir / "textual.onnx").read_text() == "graph"   # renamed
    assert (model_dir / "onnx__MatMul_1").read_text() == "weights"  # external data, flattened
    assert (model_dir / "tokenizer.json").read_text() == "tok"
    assert not (model_dir / "model.rknn").exists()  # nested rknpu/ skipped
    assert not (model_dir / "visual.onnx").exists()  # other folder untouched


def test_fetch_single_file_renames(monkeypatch, tmp_path):
    repo_files = {"visual/model.onnx": "vis"}
    monkeypatch.setattr(download_models, "hf_hub_download", _fake_hf(repo_files))

    dest = tmp_path / "clip" / "visual.onnx"
    download_models._fetch("repo", "rev", "visual/model.onnx", dest)

    assert dest.read_text() == "vis"
