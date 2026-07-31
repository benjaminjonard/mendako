"""Maintainer tool — export RAM++ to ONNX for the Mendako sidecar.

Upstream ships RAM++ as PyTorch weights only (Apache-2.0), so the ONNX the sidecar loads has
to be produced once and published to a mirror the image build can pull from. This script is
that step; it is NOT part of the image build (which would otherwise pull ~3.5 GB of weights
and install torch on every rebuild).

It writes the three files the catalog declares, into ``--out``:
  - ``model.onnx``      the exported graph, input ``image`` [1,3,384,384] → output ``logits`` [1,N]
  - ``tags.txt``        one tag per line, in output order
  - ``thresholds.txt``  one float per line, RAM++'s own per-tag decision threshold

Nothing is written unless the export reproduces upstream's own tagging decision on a test
image — a wrong wrapper must fail here rather than ship a silently broken model.

Usage (needs torch, and ~4 GB of downloads):

    pip install torch torchvision --index-url https://download.pytorch.org/whl/cpu
    pip install timm "transformers<5" scipy onnx onnxruntime pillow huggingface_hub fairscale
    pip install --no-deps git+https://github.com/xinyu1205/recognize-anything.git
    python ml/tools/export_ram_plus.py --out /tmp/ram-plus

torch and torchvision must come from the same index, or torchvision's C extensions fail to
register against torch's dispatcher.

Then publish the result and pin the returned commit in ml/app/catalog.py:

    hf repo create benjaminjonard/ram-plus-onnx --repo-type model   # first time only
    hf upload benjaminjonard/ram-plus-onnx /tmp/ram-plus .
"""

import argparse
import sys
import urllib.request
from pathlib import Path

import numpy as np
import torch
import torch.nn.functional as F
from huggingface_hub import hf_hub_download
from PIL import Image

# Upstream weights, pinned. Apache-2.0 (OPPO).
SOURCE_REPO = "xinyu1205/recognize-anything-plus-model"
SOURCE_REVISION = "84d4aee3a0265c4e0df1f714f0572011d1bf2ec3"
WEIGHTS_FILE = "ram_plus_swin_large_14m.pth"

# Upstream's own demo image — a busy scene, so the parity check exercises many tags.
DEMO_IMAGE_URL = "https://raw.githubusercontent.com/xinyu1205/recognize-anything/main/images/demo/demo1.jpg"

IMAGE_SIZE = 384
OPSET = 17
# Logit tolerance between the traced graph and eager PyTorch. Well under the gap that could
# flip a tag, since RAM++ thresholds sit around 0.65 in probability space.
LOGIT_TOLERANCE = 1e-3


def patch_transformers_compat() -> None:
    """Restore three symbols the vendored BERT in `ram` imports from their pre-4.7 location.

    `ram/models/bert.py` is written against transformers ~4.25, where `modeling_utils` still
    re-exported these from `pytorch_utils`. Rather than pin a 2022 transformers (which has no
    working install on current Python), put the re-export back — same objects, original module.
    """
    import transformers.modeling_utils as modeling_utils
    import transformers.pytorch_utils as pytorch_utils

    for name in ("apply_chunking_to_forward", "find_pruneable_heads_and_indices", "prune_linear_layer"):
        if not hasattr(modeling_utils, name):
            setattr(modeling_utils, name, getattr(pytorch_utils, name))


class RamPlusLogits(torch.nn.Module):
    """RAM_plus.generate_tag() truncated at the logits.

    Upstream's method ends by thresholding and formatting tag strings, neither of which belongs
    in the graph — the sidecar applies the thresholds itself. The body below mirrors it exactly,
    with one deliberate change: the per-batch Python loop that reweights the label embeddings is
    expressed as an einsum, which traces to a clean graph instead of an unrolled loop. The parity
    check is what proves the two agree.
    """

    def __init__(self, model: torch.nn.Module) -> None:
        super().__init__()
        self.model = model

    def forward(self, image: torch.Tensor) -> torch.Tensor:
        model = self.model

        image_embeds = model.image_proj(model.visual_encoder(image))
        image_atts = torch.ones(image_embeds.size()[:-1], dtype=torch.long, device=image.device)

        image_cls_embeds = image_embeds[:, 0, :]
        batch_size = image_embeds.shape[0]
        des_per_class = int(model.label_embed.shape[0] / model.num_class)

        image_cls_embeds = image_cls_embeds / image_cls_embeds.norm(dim=-1, keepdim=True)
        logits_per_image = model.reweight_scale.exp() * image_cls_embeds @ model.label_embed.t()
        logits_per_image = logits_per_image.view(batch_size, -1, des_per_class)

        weight_normalized = F.softmax(logits_per_image, dim=2)
        reshaped_value = model.label_embed.view(-1, des_per_class, 512)
        # Batched form of upstream's `(weight.unsqueeze(-1) * reshaped_value).sum(dim=1)` loop.
        label_embed_reweight = torch.einsum("bcd,cde->bce", weight_normalized, reshaped_value)

        label_embed = torch.nn.functional.relu(model.wordvec_proj(label_embed_reweight))

        tagging_embed = model.tagging_head(
            encoder_embeds=label_embed,
            encoder_hidden_states=image_embeds,
            encoder_attention_mask=image_atts,
            return_dict=False,
            mode="tagging",
        )

        return model.fc(tagging_embed[0]).squeeze(-1)


def load_model() -> torch.nn.Module:
    patch_transformers_compat()
    from ram.models import ram_plus

    print(f"Fetching {SOURCE_REPO}@{SOURCE_REVISION[:8]} :: {WEIGHTS_FILE}", flush=True)
    weights = hf_hub_download(repo_id=SOURCE_REPO, filename=WEIGHTS_FILE, revision=SOURCE_REVISION)

    print("Building RAM++ (swin_l)", flush=True)
    model = ram_plus(pretrained=weights, image_size=IMAGE_SIZE, vit="swin_l")

    return model.eval()


def load_test_image(path: str | None) -> Image.Image:
    if path is not None:
        return Image.open(path)

    print(f"Fetching the test image from {DEMO_IMAGE_URL}", flush=True)
    with urllib.request.urlopen(DEMO_IMAGE_URL) as response:  # noqa: S310 — pinned upstream URL
        import io

        return Image.open(io.BytesIO(response.read()))


def tags_from_logits(logits: np.ndarray, tags: list[str], thresholds: np.ndarray) -> set[str]:
    """The sidecar's decision rule (see ml/app/inference.py:analyze_ram)."""
    scores = 1.0 / (1.0 + np.exp(-np.clip(logits.astype(np.float64), -30.0, 30.0)))

    return {tag for tag, score, threshold in zip(tags, scores, thresholds) if score > threshold}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", required=True, type=Path, help="output directory")
    parser.add_argument("--image", default=None, help="parity-check image (defaults to upstream's demo)")
    args = parser.parse_args()

    model = load_model()
    wrapper = RamPlusLogits(model).eval()

    tags = [str(tag) for tag in model.tag_list]
    thresholds = model.class_threshold.detach().cpu().numpy().astype(np.float64).copy()

    # Upstream can blanket-disable tags via delete_tag_index. Fold that into the thresholds so the
    # sidecar needs no extra file: a threshold of 1.0 can never be exceeded by a sigmoid.
    deleted = list(getattr(model, "delete_tag_index", []) or [])
    if deleted:
        print(f"Disabling {len(deleted)} tag(s) listed in delete_tag_index", flush=True)
        thresholds[deleted] = 1.0

    print(f"{len(tags)} tags, {len(thresholds)} thresholds", flush=True)
    if len(tags) != len(thresholds):
        print("ERROR: tag/threshold count mismatch upstream", file=sys.stderr)
        return 1

    # Preprocess with upstream's own transform, so the reference and the export see one tensor.
    from ram import get_transform

    image = load_test_image(args.image)
    tensor = get_transform(image_size=IMAGE_SIZE)(image).unsqueeze(0)

    args.out.mkdir(parents=True, exist_ok=True)
    onnx_path = args.out / "model.onnx"

    print(f"Exporting to {onnx_path} (opset {OPSET})", flush=True)
    with torch.no_grad():
        torch.onnx.export(
            wrapper,
            tensor,
            str(onnx_path),
            input_names=["image"],
            output_names=["logits"],
            opset_version=OPSET,
            dynamo=False,
        )

    # --- Gate 1: the traced graph agrees numerically with eager PyTorch -----------------------
    import onnxruntime as ort

    with torch.no_grad():
        eager_logits = wrapper(tensor).cpu().numpy().reshape(-1)

    session = ort.InferenceSession(str(onnx_path), providers=["CPUExecutionProvider"])
    onnx_logits = np.asarray(
        session.run(None, {"image": tensor.numpy()})[0]
    ).reshape(-1)

    drift = float(np.max(np.abs(eager_logits - onnx_logits)))
    print(f"Max |eager - onnx| logit drift: {drift:.3e}", flush=True)
    if not np.isfinite(drift) or drift > LOGIT_TOLERANCE:
        print(f"ERROR: exported graph diverges from eager PyTorch (> {LOGIT_TOLERANCE})", file=sys.stderr)
        return 1

    # --- Gate 2: the tags match upstream's own inference path ---------------------------------
    # This is the one that matters: it validates the wrapper, the thresholds and the decision
    # rule together, against code we did not write.
    from ram import inference_ram

    with torch.no_grad():
        reference_output, _chinese = inference_ram(tensor, model)
    reference_tags = {tag.strip() for tag in reference_output.split("|") if tag.strip()}
    exported_tags = tags_from_logits(onnx_logits, tags, thresholds)

    print(f"Reference tags ({len(reference_tags)}): {sorted(reference_tags)}", flush=True)
    if reference_tags != exported_tags:
        print("ERROR: exported tags differ from upstream's inference", file=sys.stderr)
        print(f"  missing: {sorted(reference_tags - exported_tags)}", file=sys.stderr)
        print(f"  extra:   {sorted(exported_tags - reference_tags)}", file=sys.stderr)
        return 1

    # Only now is it safe to publish the sidecar's companion files.
    (args.out / "tags.txt").write_text("\n".join(tags) + "\n", encoding="utf-8")
    (args.out / "thresholds.txt").write_text(
        "\n".join(f"{value:.6f}" for value in thresholds) + "\n", encoding="utf-8"
    )

    size_mb = onnx_path.stat().st_size / 1_000_000
    print(f"OK — {len(exported_tags)} tags reproduced exactly. model.onnx is {size_mb:.0f} MB", flush=True)

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
