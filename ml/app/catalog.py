"""Allowlisted model catalog.

A category holds one or more models, and `ModelCatalog.php` mirrors that as a list. Two entries
of the same category are alternatives a board picks between, not a conflict.

Models are referenced by `repo_id` + a pinned `revision` and baked into the image at
build time (see ``download_models.py``); there is no runtime download.

Adding a new model = appending an entry here.
"""

CATALOG: list[dict] = [
    {
        "category": "wd",
        "id": "mendako-tagger",
        "repo_id": "benjaminjonard/mendako-tagger-onnx",
        "revision": "5943b98d08f54f58481c5b66193ee5418fdf1880",
        "files": ["model.onnx", "selected_tags.csv", "thresholds.csv",
                  "implications.csv", "character_copyright.csv"],
        "download": [
            {"src": "model.onnx", "dst": "model.onnx"},
            {"src": "selected_tags.csv", "dst": "selected_tags.csv"},
            {"src": "thresholds.csv", "dst": "thresholds.csv"},
            {"src": "implications.csv", "dst": "implications.csv"},
            {"src": "character_copyright.csv", "dst": "character_copyright.csv"},
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
