#!/usr/bin/env python3
"""Bind the approved fridge vocabulary to YOLOE-26x-seg and export ONNX.

This conversion is intentionally separate from training.  It must be run only
after a reviewed YOLOE-26x-seg ``best.pt`` exists.  The text encoder is used at
conversion time; the resulting embeddings are fused into the exported graph,
so Ubuntu inference needs ONNX Runtime but does not need CLIP or a text prompt
at request time.
"""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import shutil
from typing import Any, Callable, NamedTuple, Sequence

import numpy as np


TEXT_PROMPTS: tuple[str, ...] = (
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
)

# Prompt variants for the ONE measured blocker: cup colour confusion.
#
# The four cup prompts above differ by a colour adjective plus a sauce name,
# and the sauce name is not visually observable -- it is printed on a small
# sticker at best.  Three of the four words in each phrase are therefore either
# shared ("sauce cup") or unreadable at this resolution, which leaves a single
# adjective carrying the entire discriminative burden.  That is the suspected
# mechanism behind garbe proposing 12 black soya cups where 1 exists.
#
# These variants change ONLY the words handed to the text encoder.  Class
# identity is unaffected: `FIXED_CLASS_NAMES` in the label-factory runtime keys
# the correction manifests and per-class thresholds, and the reviewer's frozen
# counts use a third vocabulary again (`sojasauce_cup`, ids 0-5), so neither
# file needs to change -- which matters, because editing the reviewer's counts
# is forbidden.  Order is load-bearing and identical in every variant.
PROMPT_VARIANTS: dict[str, tuple[str, ...]] = {
    # The shipped wording.  Selecting it must be indistinguishable from passing
    # no variant at all, which is what the tests assert.
    "baseline": TEXT_PROMPTS,
    # A: keep the object, move the colour onto the part that is actually
    # visible (the lid), and drop the unreadable sauce name.
    "lid": (
        "kraft paper bowl",
        "sauce cup with a black lid",
        "sauce cup with a red lid",
        "sauce cup with a white lid",
        "sauce cup with an orange lid",
        "wooden chopstick tip",
        "black and white soya sauce packet",
    ),
    # B: strip to colour + object, removing every unobservable word.
    "colour": (
        "kraft paper bowl",
        "black cup",
        "red cup",
        "white cup",
        "orange cup",
        "wooden chopstick tip",
        "black and white soya sauce packet",
    ),
    # C: the lid alone, which is the highest-contrast region of a stacked cup.
    "lid_only": (
        "kraft paper bowl",
        "black lid",
        "red lid",
        "white lid",
        "orange lid",
        "wooden chopstick tip",
        "black and white soya sauce packet",
    ),
}


def resolve_prompts(variant: str | None) -> tuple[str, ...]:
    """Return the prompt tuple for `variant`, defaulting to the shipped set.

    Every variant keeps the same length and the same class order, because the
    ids are load-bearing all the way through to the frozen count gate.
    """
    if not variant:
        return TEXT_PROMPTS
    try:
        prompts = PROMPT_VARIANTS[variant]
    except KeyError:
        raise SystemExit(
            f"Unknown prompt variant {variant!r}. Available: "
            + ", ".join(sorted(PROMPT_VARIANTS))
        ) from None
    if len(prompts) != len(TEXT_PROMPTS):
        raise SystemExit(
            f"Prompt variant {variant!r} has {len(prompts)} prompts; "
            f"exactly {len(TEXT_PROMPTS)} are required and the order is fixed."
        )
    return prompts
ARCHITECTURE = "yoloe-26x-seg"
EXPORT_IMGSZ = 1280
EXPORT_FILENAME = "yoloe26x_fridge_text_prompt_seg.onnx"
EMBEDDINGS_FILENAME = "yoloe26x_fridge_text_prompt_embeddings.npy"
PROMPTS_FILENAME = "yoloe26x_fridge_text_prompts.json"
MANIFEST_FILENAME = "yoloe26x_fridge_text_prompt_seg.manifest.json"


class ExportResult(NamedTuple):
    """Paths produced by one successful, atomic-enough conversion run."""

    onnx_path: Path
    embeddings_path: Path
    prompts_path: Path
    manifest_path: Path


def sha256_file(path: Path, chunk_size: int = 1024 * 1024) -> str:
    """Hash a potentially large checkpoint without loading it into memory."""

    digest = hashlib.sha256()
    with path.open("rb") as stream:
        while chunk := stream.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def _embedding_array(embeddings: Any) -> np.ndarray:
    """Convert either a Torch tensor or a NumPy-like test double to float32."""

    value = embeddings
    for method_name in ("detach", "cpu"):
        method = getattr(value, method_name, None)
        if callable(method):
            value = method()
    numpy_method = getattr(value, "numpy", None)
    if callable(numpy_method):
        value = numpy_method()
    array = np.asarray(value, dtype=np.float32)
    if array.ndim != 3 or array.shape[1] != len(TEXT_PROMPTS):
        raise ValueError(
            "YOLOE returned an invalid text embedding tensor: expected "
            f"[batch, {len(TEXT_PROMPTS)}, embedding_dim], received {array.shape}."
        )
    if not np.isfinite(array).all():
        raise ValueError("YOLOE returned non-finite text prompt embeddings.")
    return array


def _model_architecture_evidence(model: Any) -> tuple[str, str, str]:
    """Read checkpoint metadata without relying on the checkpoint filename.

    A trained checkpoint is commonly named only ``best.pt``.  Therefore the
    filename cannot prove it is the requested X-size segmentation model.  We
    validate the loaded task plus the embedded YAML scale/name or final head.
    """

    task = str(getattr(model, "task", "")).lower()
    inner_model = getattr(model, "model", None)
    yaml = getattr(inner_model, "yaml", {}) or {}
    scale = str(yaml.get("scale", "")).lower()
    yaml_file = str(yaml.get("yaml_file", "")).lower()
    head_name = ""
    layers = getattr(inner_model, "model", None)
    try:
        head_name = type(layers[-1]).__name__.lower() if layers else ""
    except (IndexError, KeyError, TypeError):
        head_name = ""
    return task, scale, f"{yaml_file} {head_name}".strip()


def validate_yoloe26x_seg_model(model: Any) -> None:
    """Refuse conversion when the loaded checkpoint is not YOLOE-26x-seg."""

    task, scale, identity = _model_architecture_evidence(model)
    is_segmentation = task == "segment" and "seg" in identity
    is_yoloe26 = "yoloe" in identity and "26" in identity
    is_x_scale = scale == "x" or "26x" in identity
    if not (is_segmentation and is_yoloe26 and is_x_scale):
        raise ValueError(
            "The checkpoint must be a trained yoloe-26x-seg model; loaded "
            f"task={task!r}, scale={scale!r}, identity={identity!r}."
        )


def _default_yoloe_factory(checkpoint: str):
    """Import the heavy training dependency only when conversion actually runs."""

    try:
        from ultralytics import YOLOE
    except ImportError as exc:  # pragma: no cover - environment boundary
        raise RuntimeError(
            "Ultralytics with YOLOE-26 support is required for PT conversion. "
            "Run this script in the same Kaggle/Linux environment used to train best.pt."
        ) from exc
    return YOLOE(checkpoint)


def _resolve_exported_path(exported: Any, checkpoint: Path) -> Path:
    """Normalize the path returned by different Ultralytics releases."""

    candidate = exported[0] if isinstance(exported, (list, tuple)) else exported
    path = Path(str(candidate)) if candidate else checkpoint.with_suffix(".onnx")
    if not path.is_absolute():
        path = (Path.cwd() / path).resolve()
    if not path.is_file():
        fallback = checkpoint.with_suffix(".onnx")
        if fallback.is_file():
            path = fallback.resolve()
        else:
            raise RuntimeError(f"Ultralytics reported an ONNX export, but no file exists at {path}.")
    return path


def export_text_prompt_model(
    checkpoint: Path,
    output_dir: Path,
    *,
    device: str = "cpu",
    yoloe_factory: Callable[[str], Any] | None = None,
    prompt_variant: str | None = None,
) -> ExportResult:
    """Embed the seven prompts, set the classes, then export a fixed 1280 ONNX.

    The function's call order is a release invariant: ``get_text_pe`` and
    ``set_classes`` must complete before ``export``.  ``nms=False`` deliberately
    leaves suppression and tiled mask merging to ``sahi_inference.py`` where
    detections from overlapping slices can be compared globally.
    """

    checkpoint = Path(checkpoint).expanduser().resolve()
    output_dir = Path(output_dir).expanduser().resolve()
    if checkpoint.suffix.lower() != ".pt" or not checkpoint.is_file():
        raise FileNotFoundError(f"A trained YOLOE-26x-seg best.pt is required: {checkpoint}")
    output_dir.mkdir(parents=True, exist_ok=True)

    factory = yoloe_factory or _default_yoloe_factory
    model = factory(str(checkpoint))
    validate_yoloe26x_seg_model(model)
    inner_layers = getattr(getattr(model, "model", None), "model", None)
    try:
        end_to_end = bool(getattr(inner_layers[-1], "end2end"))
    except (AttributeError, IndexError, KeyError, TypeError):
        # YOLOE-26 segmentation uses the end-to-end head.  Test doubles and
        # older metadata-only checkpoint wrappers may not expose the live head,
        # so the architecture invariant is the safe default.
        end_to_end = True

    prompts = list(resolve_prompts(prompt_variant))
    embeddings = model.get_text_pe(prompts)
    embedding_array = _embedding_array(embeddings)
    model.set_classes(prompts, embeddings)
    bound_names = getattr(getattr(model, "model", None), "names", {})
    if isinstance(bound_names, dict):
        ordered_names = [bound_names.get(index, bound_names.get(str(index))) for index in range(len(prompts))]
    else:
        ordered_names = list(bound_names)
    if ordered_names != prompts:
        raise RuntimeError(
            "YOLOE did not retain the requested class order after set_classes; "
            f"expected {prompts}, received {ordered_names}. Export stopped."
        )

    # A fixed square input makes the Ubuntu decoder deterministic.  Large
    # images are preserved by overlapping 1280-pixel slices instead of asking
    # ONNX Runtime to downscale the full photograph into one tensor.
    exported = model.export(
        format="onnx",
        imgsz=EXPORT_IMGSZ,
        batch=1,
        dynamic=False,
        simplify=True,
        opset=17,
        nms=False,
        device=device,
    )
    generated_path = _resolve_exported_path(exported, checkpoint)
    onnx_path = output_dir / EXPORT_FILENAME
    if generated_path != onnx_path:
        shutil.move(str(generated_path), str(onnx_path))

    embeddings_path = output_dir / EMBEDDINGS_FILENAME
    np.save(embeddings_path, embedding_array, allow_pickle=False)
    prompts_path = output_dir / PROMPTS_FILENAME
    prompts_payload = {
        "schema_version": 1,
        "architecture": ARCHITECTURE,
        "prompts": prompts,
        "class_names": {str(index): prompt for index, prompt in enumerate(prompts)},
    }
    prompts_path.write_text(json.dumps(prompts_payload, indent=2) + "\n", encoding="utf-8")

    manifest_path = output_dir / MANIFEST_FILENAME
    manifest = {
        "schema_version": 1,
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "architecture": ARCHITECTURE,
        "prompts": prompts,
        "prompt_variant": prompt_variant or "baseline",
        "class_names": {str(index): prompt for index, prompt in enumerate(prompts)},
        "model": {
            "source_path": str(checkpoint),
            "source_filename": checkpoint.name,
            "sha256": sha256_file(checkpoint),
        },
        "prompt_embeddings": {
            "filename": embeddings_path.name,
            "shape": list(embedding_array.shape),
            "dtype": str(embedding_array.dtype),
            "sha256": sha256_file(embeddings_path),
            "bound_before_export": True,
        },
        "prompt_definition": {
            "filename": prompts_path.name,
            "sha256": sha256_file(prompts_path),
        },
        "export": {
            "filename": onnx_path.name,
            "format": "onnx",
            "imgsz": EXPORT_IMGSZ,
            "batch": 1,
            "dynamic": False,
            "opset": 17,
            "nms": False,
            "end_to_end": end_to_end,
            "postprocessing": "external_sliced_nms_and_masks",
            "sha256": sha256_file(onnx_path),
        },
        "ubuntu_runtime": {
            "script": "sahi_inference.py",
            "engine": "onnxruntime",
            "text_encoder_required": False,
        },
    }
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    return ExportResult(onnx_path, embeddings_path, prompts_path, manifest_path)


def parse_args(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Bind seven fridge text prompts to trained YOLOE-26x-seg best.pt and export ONNX."
    )
    parser.add_argument("--checkpoint", type=Path, required=True, help="Trained YOLOE-26x-seg best.pt")
    parser.add_argument("--output-dir", type=Path, required=True, help="Directory for the portable release bundle")
    parser.add_argument(
        "--device",
        default="cpu",
        help="Ultralytics export device, for example cpu or 0. CPU produces the most portable FP32 ONNX.",
    )
    parser.add_argument(
        "--prompt-variant",
        default=None,
        choices=sorted(PROMPT_VARIANTS),
        help=(
            "Which WORDS to hand the text encoder. Class identity and order are "
            "unaffected; only the embedding changes. Omit for the shipped set. "
            "This is the cheap screen for cup colour confusion: export plus the "
            "frozen count gate score a variant on CPU in about two minutes, so a "
            "variant only earns a full GPU pass if it moves the gate."
        ),
    )
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv)
    result = export_text_prompt_model(
        args.checkpoint,
        args.output_dir,
        device=args.device,
        prompt_variant=args.prompt_variant,
    )
    print(
        json.dumps(
            {
                "status": "success",
                "onnx_path": str(result.onnx_path),
                "manifest_path": str(result.manifest_path),
                # Report the prompts actually embedded, not the shipped tuple —
                # printing TEXT_PROMPTS here would misreport every variant run.
                "prompt_variant": args.prompt_variant or "baseline",
                "prompts": list(resolve_prompts(args.prompt_variant)),
            },
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
