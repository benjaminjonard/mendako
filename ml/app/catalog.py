"""Allowlisted model catalog — one model per category.

Models are referenced by `repo_id` + a pinned `revision` and baked into the image at
build time (see ``download_models.py``); there is no runtime download.

WD is both the tagger AND the embedding encoder: its penultimate ``fc_norm`` feature
(1024-d) is a Danbooru-native image embedding. ``embed_output`` names that internal
tensor, which ``download_models.py`` exposes as a second graph output so one forward
pass yields both tags and the embedding.

Adding a new model = appending an entry here.
"""

# Each entry: category, id, repo_id, revision, files (final names in the model dir),
# download (build-time fetch spec), optional embedding dim, optional embed_output
# (internal ONNX tensor to expose as the embedding), task.
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
        "dim": 1024,
        "embed_output": "/core_model/fc_norm/LayerNormalization_output_0",
        "task": "tagger",
    },
]


def find_entry(model_id: str) -> dict | None:
    """Return the catalog entry with the given id, or None."""
    for entry in CATALOG:
        if entry["id"] == model_id:
            return entry
    return None
