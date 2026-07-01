"""Allowlisted model catalog — one model per category.

Models are referenced by `repo_id` + a pinned `revision` and are baked into the
image at build time (see ``download_models.py``); there is no runtime download.
We pull the original upstream ONNX exports directly:

  - WD tagger : SmilingWolf/wd-eva02-large-tagger-v3 (flat ``model.onnx`` + ``selected_tags.csv``)
  - SigLIP2   : immich-app/ViT-SO400M-16-SigLIP2-384__webli, whose ``visual``/``textual``
                encoders live in subfolders. ``textual/model.onnx`` is a graph that
                references ONNX external-data tensor files stored as siblings, so the
                whole ``textual`` folder is flattened into the model dir (the external
                refs are by basename and resolve next to ``textual.onnx``).

`files` lists the final, flattened names inside the model dir (used for readiness +
by the inference pipeline). `download` describes how to materialise them from the repo.

Adding a new model = appending an entry here.
"""

# Each entry: category, id, repo_id, revision, files (final names in the model dir),
# download (build-time fetch spec), optional embedding dim (clip), task.
CATALOG: list[dict] = [
    {
        "category": "wd",
        "id": "wd-eva02-large-tagger-v3",
        "repo_id": "SmilingWolf/wd-eva02-large-tagger-v3",
        "revision": "b25b82a03f7282e41aa2f257a52c7583b710bd1c",
        "files": ["model.onnx", "selected_tags.csv"],
        "download": [
            {"src": "model.onnx", "dst": "model.onnx"},
            {"src": "selected_tags.csv", "dst": "selected_tags.csv"},
        ],
        "dim": None,
        "task": "tagger",
    },
    {
        "category": "clip",
        "id": "siglip2-so400m",
        "repo_id": "immich-app/ViT-SO400M-16-SigLIP2-384__webli",
        "revision": "19baa26af70bd3639ca0ca17d1560cb8056dd983",
        "files": ["visual.onnx", "textual.onnx", "tokenizer.json"],
        "download": [
            # Self-contained vision encoder.
            {"src": "visual/model.onnx", "dst": "visual.onnx"},
            # Whole text encoder: graph + its external-data tensor files + tokenizer,
            # flattened so the relative external refs resolve next to textual.onnx.
            {"folder": "textual", "rename": {"model.onnx": "textual.onnx"}},
        ],
        "dim": 1152,
        "task": "embed",
    },
]


def find_entry(model_id: str) -> dict | None:
    """Return the catalog entry with the given id, or None."""
    for entry in CATALOG:
        if entry["id"] == model_id:
            return entry
    return None
