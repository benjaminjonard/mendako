"""Build-time model fetch (download_models.py).

The single-file copy is trivial; the embedding-output patch (exposing WD's penultimate
tensor as a second ONNX graph output) carries the logic worth pinning down.
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


def test_expose_embedding_output_adds_a_second_graph_output(tmp_path):
    import onnx

    # Minimal graph: input -> Identity -> output, plus an intermediate we want to expose.
    node = onnx.helper.make_node("Identity", ["input"], ["hidden"], name="id")
    out = onnx.helper.make_node("Identity", ["hidden"], ["output"], name="head")
    graph = onnx.helper.make_graph(
        [node, out],
        "g",
        [onnx.helper.make_tensor_value_info("input", onnx.TensorProto.FLOAT, [1, 4])],
        [onnx.helper.make_tensor_value_info("output", onnx.TensorProto.FLOAT, [1, 4])],
    )
    model_path = tmp_path / "model.onnx"
    onnx.save(onnx.helper.make_model(graph), str(model_path))

    download_models._expose_embedding_output(model_path, "hidden")

    reloaded = onnx.load(str(model_path))
    output_names = {o.name for o in reloaded.graph.output}
    assert output_names == {"output", "hidden"}

    # Idempotent: a second call does not duplicate the output.
    download_models._expose_embedding_output(model_path, "hidden")
    reloaded = onnx.load(str(model_path))
    assert [o.name for o in reloaded.graph.output].count("hidden") == 1
