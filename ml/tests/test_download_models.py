"""Build-time model fetch (download_models.py).

The single-file copy op renames a repo file into the model dir; pin that behaviour down.
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


def test_fetch_single_file_renames(monkeypatch, tmp_path):
    repo_files = {"model.onnx": "weights"}
    monkeypatch.setattr(download_models, "hf_hub_download", _fake_hf(repo_files))

    dest = tmp_path / "wd" / "model.onnx"
    download_models._fetch("repo", "rev", "model.onnx", dest)

    assert dest.read_text() == "weights"
