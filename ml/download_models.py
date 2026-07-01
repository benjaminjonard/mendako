"""Build-time model fetch.

Materialises every catalog model into MODELS_DIR/<id>/ from the original upstream
repos so they are baked into the image — there is no runtime download. Run from the
image build (see Dockerfile); fails the build if any declared file is missing or
empty so a broken image is never published.

Two op kinds (see catalog ``download``):
  - {"src", "dst"}            : copy a single repo file, renaming it to ``dst``.
  - {"folder", "rename"}      : copy every file under ``folder/`` into the model dir,
                                flattened to its basename (skipping the ``rknpu`` NPU
                                variants), applying the optional ``rename`` map. Used
                                for the SigLIP text encoder's ONNX external-data files.
"""

import os
import shutil
import sys
from pathlib import Path

from huggingface_hub import hf_hub_download, list_repo_files

from app.catalog import CATALOG

MODELS_DIR = Path(os.environ.get("MENDAKO_MODELS_DIR", "/models"))


def _fetch(repo_id: str, revision: str, repo_path: str, dest: Path) -> None:
    cached = hf_hub_download(repo_id=repo_id, filename=repo_path, revision=revision)
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(cached, dest)


def _fetch_folder(repo_id: str, revision: str, folder: str, rename: dict, model_dir: Path) -> None:
    prefix = folder.rstrip("/") + "/"
    for repo_path in list_repo_files(repo_id=repo_id, revision=revision):
        if not repo_path.startswith(prefix):
            continue
        relative = repo_path[len(prefix):]
        if not relative or "/" in relative:
            continue  # skip nested dirs (e.g. rknpu/ NPU variants) — flat encoder files only
        name = rename.get(relative, relative)
        print(f"Fetching {repo_id} :: {repo_path} -> {name}", flush=True)
        _fetch(repo_id, revision, repo_path, model_dir / name)


def main() -> int:
    for entry in CATALOG:
        model_dir = MODELS_DIR / entry["id"]
        model_dir.mkdir(parents=True, exist_ok=True)
        for op in entry["download"]:
            if "folder" in op:
                _fetch_folder(entry["repo_id"], entry["revision"], op["folder"], op.get("rename", {}), model_dir)
            else:
                print(f"Fetching {entry['repo_id']} :: {op['src']} -> {op['dst']}", flush=True)
                _fetch(entry["repo_id"], entry["revision"], op["src"], model_dir / op["dst"])

        for filename in entry["files"]:
            path = model_dir / filename
            if not path.is_file() or path.stat().st_size == 0:
                print(f"ERROR: missing or empty file after download: {path}", file=sys.stderr)
                return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
