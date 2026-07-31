"""Allowlisted model catalog — one model per category.

Models are referenced by `repo_id` + a pinned `revision` and baked into the image at
build time (see ``download_models.py``); there is no runtime download.

Adding a new model = appending an entry here.
"""

# Each entry: category, id, repo_id, revision, files (final names in the model dir),
# download (build-time fetch spec), task.
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
        "task": "tagger",
    },
    {
        # Upstream (xinyu1205/recognize-anything-plus-model, Apache-2.0) publishes PyTorch weights
        # only; this is our own ONNX export of them — see ml/tools/export_ram_plus.py.
        "category": "ram",
        "id": "ram-plus",
        "repo_id": "benjaminjonard/ram-plus-onnx",
        "revision": "e22cfb689ba3a3e02f23925e1f581e3e07166c75",
        "files": ["model.onnx", "tags.txt", "thresholds.txt"],
        "download": [
            {"src": "model.onnx", "dst": "model.onnx"},
            {"src": "tags.txt", "dst": "tags.txt"},
            {"src": "thresholds.txt", "dst": "thresholds.txt"},
        ],
        "task": "tagger",
    },
]


def find_entry(model_id: str) -> dict | None:
    """Return the catalog entry with the given id, or None."""
    for entry in CATALOG:
        if entry["id"] == model_id:
            return entry
    return None
