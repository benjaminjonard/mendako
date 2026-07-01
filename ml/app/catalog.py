"""Allowlisted model catalog — one model per category.

Models are referenced by `repo_id` + a pinned `revision` and are baked into the
image at build time (see ``download_models.py``); there is no runtime download.
We pull the original upstream ONNX export directly:

  - WD tagger : SmilingWolf/wd-eva02-large-tagger-v3 (flat ``model.onnx`` + ``selected_tags.csv``)

WD is both the tagger AND the embedding encoder: its penultimate feature (the input to
the classification head, ``fc_norm`` output, 1024-d) is a Danbooru-native image embedding.
``embed_output`` names that internal tensor; ``download_models.py`` exposes it as a second
graph output at build time so one forward pass yields both tags and the embedding. This is
why there is no separate CLIP encoder — the embedding is free.

`files` lists the final names inside the model dir (used for readiness + by the inference
pipeline). `download` describes how to materialise them from the repo.

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
