"""Build-time model fetch.

Materialises every catalog model into MODELS_DIR/<id>/ from the original upstream
repos so they are baked into the image — there is no runtime download. Run from the
image build (see Dockerfile); fails the build if any declared file is missing or
empty so a broken image is never published.

Each ``download`` op is a {"src", "dst"} pair: copy a single repo file, renaming it to
``dst``. After the files are in place, a model declaring ``embed_output`` gets that internal
tensor exposed as a second ONNX output (so one forward pass yields tags + embedding).
"""

import os
import shutil
import sys
from pathlib import Path

from huggingface_hub import hf_hub_download

from app.catalog import CATALOG

MODELS_DIR = Path(os.environ.get("MENDAKO_MODELS_DIR", "/models"))


def _fetch(repo_id: str, revision: str, repo_path: str, dest: Path) -> None:
    cached = hf_hub_download(repo_id=repo_id, filename=repo_path, revision=revision)
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(cached, dest)


def main() -> int:
    for entry in CATALOG:
        model_dir = MODELS_DIR / entry["id"]
        model_dir.mkdir(parents=True, exist_ok=True)
        for op in entry["download"]:
            print(f"Fetching {entry['repo_id']} :: {op['src']} -> {op['dst']}", flush=True)
            _fetch(entry["repo_id"], entry["revision"], op["src"], model_dir / op["dst"])

        for filename in entry["files"]:
            path = model_dir / filename
            if not path.is_file() or path.stat().st_size == 0:
                print(f"ERROR: missing or empty file after download: {path}", file=sys.stderr)
                return 1

        # Expose the model's penultimate feature as a second ONNX output, so one forward pass
        # yields both the tags and the embedding (see catalog `embed_output`).
        if entry.get("embed_output"):
            _expose_embedding_output(model_dir / "model.onnx", entry["embed_output"])
    return 0


def _expose_embedding_output(model_path: Path, tensor_name: str) -> None:
    """Add `tensor_name` (an internal activation) to the model's graph outputs, in place.

    Idempotent: skips if the output is already present. onnx is a build-time dependency only;
    the runtime uses onnxruntime, which then returns the extra output alongside the logits.
    """
    import onnx

    model = onnx.load(str(model_path))
    if any(out.name == tensor_name for out in model.graph.output):
        return
    model.graph.output.append(onnx.helper.make_tensor_value_info(tensor_name, onnx.TensorProto.FLOAT, None))
    onnx.save(model, str(model_path))
    print(f"Exposed embedding output {tensor_name} on {model_path}", flush=True)


if __name__ == "__main__":
    raise SystemExit(main())
