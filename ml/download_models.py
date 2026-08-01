"""Build-time model fetch.

Materialises every catalog model into MODELS_DIR/<id>/ from the original upstream
repos so they are baked into the image — there is no runtime download. Run from the
image build (see Dockerfile); fails the build if any declared file is missing or
empty so a broken image is never published.

Each ``download`` op is a {"src", "dst"} pair: copy a single repo file, renaming it to
``dst``.
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
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
