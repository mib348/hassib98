#!/usr/bin/env python3
"""SAHI-style tiled YOLOE-26x-seg inference using ONNX Runtime.

The implementation does not wrap the ONNX graph in SAHI's model-adapter API.
YOLOE's prompt-fused segmentation output is not a standard SAHI backend, so a
small native slicer is safer: it letterboxes overlapping tiles, decodes YOLO
boxes and instance-mask prototypes, translates them into full-image space, and
runs one final class-aware NMS across tile boundaries.

Ubuntu runtime dependencies are deliberately small: ``numpy``, ``Pillow``, and
either ``onnxruntime`` (CPU) or ``onnxruntime-gpu`` (CUDA).  Ultralytics, Torch,
CLIP, and SAHI are not required after export.
"""

from __future__ import annotations

import argparse
import hashlib
import json
from pathlib import Path
import time
from typing import Any, Callable, Mapping, NamedTuple, Sequence

import numpy as np
from PIL import Image, ImageDraw, ImageFont


EXPECTED_ARCHITECTURE = "yoloe-26x-seg"
EXPECTED_PROMPTS: tuple[str, ...] = (
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
)
DEFAULT_SLICE_SIZE = 1280
DEFAULT_OVERLAP_RATIO = 0.20


class Detection(NamedTuple):
    """One globally positioned instance plus its tile-local binary mask."""

    class_id: int
    class_name: str
    score: float
    box: tuple[float, float, float, float]
    mask: np.ndarray
    tile_origin: tuple[int, int] = (0, 0)


class LetterboxTransform(NamedTuple):
    """Numbers needed to reverse padding and scale after ONNX inference."""

    scale: float
    pad_x: int
    pad_y: int
    resized_width: int
    resized_height: int
    tile_width: int
    tile_height: int
    input_width: int
    input_height: int


def sha256_file(path: Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        while chunk := stream.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def slice_starts(length: int, slice_size: int, overlap_ratio: float) -> list[int]:
    """Return deterministic starts that always cover the last source pixel."""

    if length <= 0 or slice_size <= 0:
        raise ValueError("Image length and slice size must be positive integers.")
    if not 0.0 <= overlap_ratio < 1.0:
        raise ValueError("Overlap ratio must be at least 0 and less than 1.")
    if length <= slice_size:
        return [0]
    step = max(1, int(round(slice_size * (1.0 - overlap_ratio))))
    final_start = length - slice_size
    starts = list(range(0, final_start + 1, step))
    if starts[-1] != final_start:
        starts.append(final_start)
    return starts


def _box_iou(box: tuple[float, float, float, float], others: np.ndarray) -> np.ndarray:
    """Vectorized IoU used by both per-slice and cross-slice suppression."""

    x1 = np.maximum(box[0], others[:, 0])
    y1 = np.maximum(box[1], others[:, 1])
    x2 = np.minimum(box[2], others[:, 2])
    y2 = np.minimum(box[3], others[:, 3])
    intersection = np.maximum(0.0, x2 - x1) * np.maximum(0.0, y2 - y1)
    area = max(0.0, box[2] - box[0]) * max(0.0, box[3] - box[1])
    other_area = np.maximum(0.0, others[:, 2] - others[:, 0]) * np.maximum(0.0, others[:, 3] - others[:, 1])
    return intersection / np.maximum(area + other_area - intersection, 1e-7)


def class_aware_nms(
    detections: Sequence[Detection],
    iou_threshold: float,
    max_detections: int,
) -> list[Detection]:
    """Suppress same-class duplicates while preserving nearby different sauces."""

    if not 0.0 <= iou_threshold <= 1.0:
        raise ValueError("IoU threshold must be between 0 and 1.")
    if max_detections <= 0:
        raise ValueError("Maximum detections must be positive.")
    pending = sorted(detections, key=lambda detection: detection.score, reverse=True)
    kept: list[Detection] = []
    while pending and len(kept) < max_detections:
        current = pending.pop(0)
        kept.append(current)
        if not pending:
            break
        boxes = np.asarray([item.box for item in pending], dtype=np.float32)
        ious = _box_iou(current.box, boxes)
        pending = [
            item
            for item, iou in zip(pending, ious)
            if item.class_id != current.class_id or float(iou) <= iou_threshold
        ]
    return kept


def _letterbox(image: Image.Image, input_width: int, input_height: int) -> tuple[np.ndarray, LetterboxTransform]:
    """Resize without distortion, then apply the same centered padding as YOLO."""

    tile_width, tile_height = image.size
    scale = min(input_width / tile_width, input_height / tile_height)
    resized_width = max(1, int(round(tile_width * scale)))
    resized_height = max(1, int(round(tile_height * scale)))
    pad_x = (input_width - resized_width) // 2
    pad_y = (input_height - resized_height) // 2
    resized = image.resize((resized_width, resized_height), Image.Resampling.BILINEAR)
    canvas = Image.new("RGB", (input_width, input_height), (114, 114, 114))
    canvas.paste(resized, (pad_x, pad_y))
    array = np.asarray(canvas, dtype=np.float32) / 255.0
    tensor = np.transpose(array, (2, 0, 1))[None].copy()
    return tensor, LetterboxTransform(
        scale,
        pad_x,
        pad_y,
        resized_width,
        resized_height,
        tile_width,
        tile_height,
        input_width,
        input_height,
    )


def _unletterbox_box(box: np.ndarray, transform: LetterboxTransform) -> tuple[float, float, float, float]:
    x1 = float(np.clip((box[0] - transform.pad_x) / transform.scale, 0, transform.tile_width))
    y1 = float(np.clip((box[1] - transform.pad_y) / transform.scale, 0, transform.tile_height))
    x2 = float(np.clip((box[2] - transform.pad_x) / transform.scale, 0, transform.tile_width))
    y2 = float(np.clip((box[3] - transform.pad_y) / transform.scale, 0, transform.tile_height))
    return x1, y1, x2, y2


def _resize_float(array: np.ndarray, width: int, height: int) -> np.ndarray:
    """Bilinearly resize mask logits with Pillow, avoiding OpenCV/SciPy."""

    image = Image.fromarray(np.asarray(array, dtype=np.float32), mode="F")
    return np.asarray(image.resize((width, height), Image.Resampling.BILINEAR), dtype=np.float32)


def _decode_mask(
    prototypes: np.ndarray,
    coefficients: np.ndarray,
    input_box: np.ndarray,
    transform: LetterboxTransform,
) -> np.ndarray:
    """Combine YOLO prototypes, crop to its box, then undo letterboxing."""

    channels, mask_height, mask_width = prototypes.shape
    if coefficients.shape[0] != channels:
        raise ValueError(
            f"Mask coefficient count {coefficients.shape[0]} does not match prototype channels {channels}."
        )
    logits = coefficients.astype(np.float32) @ prototypes.reshape(channels, -1).astype(np.float32)
    logits = _resize_float(logits.reshape(mask_height, mask_width), transform.input_width, transform.input_height)

    x1, y1, x2, y2 = input_box
    rows, columns = np.ogrid[: transform.input_height, : transform.input_width]
    inside_box = (columns >= x1) & (columns < x2) & (rows >= y1) & (rows < y2)
    logits = np.where(inside_box, logits, -100.0)

    cropped = logits[
        transform.pad_y : transform.pad_y + transform.resized_height,
        transform.pad_x : transform.pad_x + transform.resized_width,
    ]
    tile_logits = _resize_float(cropped, transform.tile_width, transform.tile_height)
    return tile_logits > 0.0


def _xywh_to_xyxy(boxes: np.ndarray) -> np.ndarray:
    result = boxes.astype(np.float32, copy=True)
    result[:, 0] = boxes[:, 0] - boxes[:, 2] / 2.0
    result[:, 1] = boxes[:, 1] - boxes[:, 3] / 2.0
    result[:, 2] = boxes[:, 0] + boxes[:, 2] / 2.0
    result[:, 3] = boxes[:, 1] + boxes[:, 3] / 2.0
    return result


def _prediction_rows(
    prediction: np.ndarray,
    class_count: int,
    mask_dimension: int,
    confidence_threshold: float,
    *,
    end_to_end: bool,
) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    """Normalize both YOLO26 end-to-end and legacy raw ONNX layouts.

    Returns input-space ``xyxy``, confidence, class id, and mask coefficients.
    """

    array = np.asarray(prediction, dtype=np.float32)
    if array.ndim != 3 or array.shape[0] != 1:
        raise ValueError(f"Expected one batched 3D prediction output, received {array.shape}.")

    end_to_end_width = 6 + mask_dimension
    raw_width = 4 + class_count + mask_dimension
    if end_to_end:
        if array.shape[-1] != end_to_end_width:
            raise ValueError(
                "Manifest declares YOLO26 end-to-end output, but the ONNX "
                f"prediction shape is {array.shape}; expected last dimension {end_to_end_width}."
            )
        rows = array[0]
        boxes = rows[:, :4]
        confidence = rows[:, 4]
        class_ids = np.rint(rows[:, 5]).astype(np.int64)
        coefficients = rows[:, 6:]
    else:
        if array.shape[1] == raw_width:
            rows = array[0].T
        elif array.shape[-1] == raw_width:
            rows = array[0]
        else:
            raise ValueError(
                "Unsupported YOLOE segmentation prediction layout: "
                f"shape={array.shape}, classes={class_count}, mask_dimension={mask_dimension}."
            )
        boxes = _xywh_to_xyxy(rows[:, :4])
        class_scores = rows[:, 4 : 4 + class_count]
        class_ids = class_scores.argmax(axis=1).astype(np.int64)
        confidence = class_scores[np.arange(class_scores.shape[0]), class_ids]
        coefficients = rows[:, 4 + class_count :]

    finite = (
        np.isfinite(boxes).all(axis=1)
        & np.isfinite(confidence)
        & np.isfinite(coefficients).all(axis=1)
    )
    valid = finite & (confidence >= confidence_threshold) & (class_ids >= 0) & (class_ids < class_count)
    return boxes[valid], confidence[valid], class_ids[valid], coefficients[valid]


def _coco_uncompressed_rle(mask: np.ndarray) -> dict[str, Any]:
    """Encode a full-image mask using dependency-free COCO column-major RLE."""

    binary = np.asarray(mask, dtype=np.uint8)
    flattened = binary.reshape(-1, order="F")
    # Finding run boundaries in NumPy avoids a Python iteration for every
    # pixel.  That distinction matters for refrigerator photographs that can
    # contain millions of pixels and dozens of visible objects.
    change_points = np.flatnonzero(flattened[1:] != flattened[:-1]) + 1
    boundaries = np.concatenate(([0], change_points, [flattened.size]))
    counts = np.diff(boundaries).astype(np.int64).tolist()
    if flattened.size and flattened[0] == 1:
        counts.insert(0, 0)  # COCO RLE must always begin with a zero-value run.
    return {
        "format": "coco_rle_uncompressed",
        "size": [int(binary.shape[0]), int(binary.shape[1])],
        "counts": counts,
    }


# Class ids whose WORDS are identity, not description.  `FIXED_CLASS_NAMES.index()`
# keys the packet and chopstick classes in the label factory, and the kraft bowl
# ruler keys on its name, so a prompt experiment may never move these three.
IDENTITY_ANCHOR_IDS: tuple[int, ...] = (0, 5, 6)


def _load_manifest(path: Path) -> tuple[dict[str, Any], tuple[str, ...]]:
    manifest = json.loads(path.read_text(encoding="utf-8"))
    prompts = tuple(manifest.get("prompts", ()))
    if manifest.get("architecture") != EXPECTED_ARCHITECTURE:
        raise ValueError(
            "Manifest does not describe the approved yoloe-26x-seg export. "
            "Re-run export_text_prompts.py with the trained 26x segmentation checkpoint."
        )
    # A RELEASE must carry the exact approved wording.  A declared prompt
    # variant is the one exception, because the measured blocker is cup colour
    # confusion in the text-prompt embeddings and screening a variant means
    # running this very path against different words.  Refusing every variant
    # made the documented CPU screen impossible to run.
    variant = manifest.get("prompt_variant") or "baseline"
    if variant == "baseline":
        if prompts != EXPECTED_PROMPTS:
            raise ValueError(
                "Manifest does not describe the approved yoloe-26x-seg seven-prompt "
                "export. Re-run export_text_prompts.py with the trained 26x "
                "segmentation checkpoint."
            )
    else:
        # Screening is still not a free-for-all: the shape of the class list and
        # the identity anchors are what downstream ids depend on, so they are
        # checked exactly as strictly as for a release.
        if len(prompts) != len(EXPECTED_PROMPTS):
            raise ValueError(
                f"Prompt variant {variant!r} declares {len(prompts)} prompts; "
                f"exactly {len(EXPECTED_PROMPTS)} are required and the order is fixed."
            )
        moved = [
            index
            for index in IDENTITY_ANCHOR_IDS
            if prompts[index] != EXPECTED_PROMPTS[index]
        ]
        if moved:
            raise ValueError(
                f"Prompt variant {variant!r} moved class identity at ids {moved}. "
                "Only the four cup prompts may change; ids 0, 5 and 6 are keyed on "
                "by the label factory and the frozen count gate."
            )
        if len(set(prompts)) != len(prompts):
            raise ValueError(
                f"Prompt variant {variant!r} repeats a prompt, which would collapse "
                "two classes onto one embedding."
            )
    class_names = manifest.get("class_names", {})
    if [class_names.get(str(index)) for index in range(len(prompts))] != list(prompts):
        raise ValueError("Manifest class_names do not match prompt order.")
    return manifest, prompts


def _default_session_factory(model_path: str, providers: list[str]):
    try:
        import onnxruntime as ort
    except ImportError as exc:  # pragma: no cover - Ubuntu boundary
        raise RuntimeError(
            "ONNX Runtime is required. On Ubuntu install either onnxruntime (CPU) "
            "or onnxruntime-gpu (CUDA), never both in the same environment."
        ) from exc
    options = ort.SessionOptions()
    options.graph_optimization_level = ort.GraphOptimizationLevel.ORT_ENABLE_ALL
    return ort.InferenceSession(model_path, sess_options=options, providers=providers)


def _available_onnx_providers() -> list[str]:
    try:
        import onnxruntime as ort
    except ImportError as exc:  # pragma: no cover - Ubuntu boundary
        raise RuntimeError("ONNX Runtime is not installed in this Python environment.") from exc
    return list(ort.get_available_providers())


def resolve_providers(requested: str, available: Sequence[str] | None = None) -> list[str]:
    """Map a stable CLI choice to the providers available on the Ubuntu host."""

    installed = list(available) if available is not None else _available_onnx_providers()
    if requested == "cpu":
        if "CPUExecutionProvider" not in installed:
            raise RuntimeError("CPUExecutionProvider is unavailable in this ONNX Runtime build.")
        return ["CPUExecutionProvider"]
    if requested == "cuda":
        if "CUDAExecutionProvider" not in installed:
            raise RuntimeError("CUDAExecutionProvider was requested but onnxruntime-gpu/CUDA is unavailable.")
        return ["CUDAExecutionProvider", "CPUExecutionProvider"]
    if requested != "auto":
        raise ValueError(f"Unknown provider choice: {requested}")
    if "CUDAExecutionProvider" in installed:
        return ["CUDAExecutionProvider", "CPUExecutionProvider"]
    if "CPUExecutionProvider" in installed:
        return ["CPUExecutionProvider"]
    raise RuntimeError(f"No supported ONNX Runtime execution provider found: {installed}")


def resolve_class_acceptance_thresholds(
    prompts: Sequence[str],
    detection_floor: float,
    supplied: Mapping[str, float] | None,
) -> tuple[dict[str, float], str]:
    """Return one acceptance threshold for every class in the locked prompt order.

    The ONNX confidence floor decides which candidates are decoded at all.  A
    business acceptance threshold has a different job: it identifies decoded
    instances that are uncertain enough for manual review.  When validation
    has not produced per-class thresholds yet, using the detection floor is the
    only defensible fallback.  The returned policy name makes that uncalibrated
    fallback explicit rather than silently presenting it as calibrated.
    """

    if not 0.0 <= detection_floor <= 1.0:
        raise ValueError("Detection confidence floor must be between 0 and 1.")
    prompt_names = tuple(prompts)
    if supplied is None:
        return (
            {prompt: float(detection_floor) for prompt in prompt_names},
            "detection_floor_fallback_unvalidated",
        )

    unknown = sorted(set(supplied) - set(prompt_names))
    missing = sorted(set(prompt_names) - set(supplied))
    if unknown or missing:
        details: list[str] = []
        if missing:
            details.append(f"missing={missing}")
        if unknown:
            details.append(f"unknown={unknown}")
        raise ValueError(
            "Class acceptance thresholds must contain every locked prompt exactly once: "
            + ", ".join(details)
        )

    thresholds: dict[str, float] = {}
    for prompt in prompt_names:
        if isinstance(supplied[prompt], bool):
            raise ValueError(f"Acceptance threshold for {prompt!r} must be numeric, not boolean.")
        try:
            threshold = float(supplied[prompt])
        except (TypeError, ValueError) as exc:
            raise ValueError(f"Acceptance threshold for {prompt!r} is not numeric.") from exc
        if not np.isfinite(threshold) or not 0.0 <= threshold <= 1.0:
            raise ValueError(f"Acceptance threshold for {prompt!r} must be finite and between 0 and 1.")
        thresholds[prompt] = threshold
    return thresholds, "externally_supplied_per_class_unverified"


def load_class_acceptance_thresholds(path: Path) -> Mapping[str, float]:
    """Load a direct or named per-class threshold mapping from JSON.

    Validation tooling can write either ``{"prompt": 0.5, ...}`` or wrap that
    mapping in ``{"class_acceptance_thresholds": {...}}`` alongside its own
    provenance fields.  Semantic validation remains in
    :func:`resolve_class_acceptance_thresholds`, where the locked prompt list is
    available.
    """

    threshold_path = Path(path).expanduser().resolve()
    if not threshold_path.is_file():
        raise FileNotFoundError(f"Class acceptance threshold JSON does not exist: {threshold_path}")
    payload = json.loads(threshold_path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("Class acceptance threshold JSON must contain an object.")
    values = payload.get("class_acceptance_thresholds", payload)
    if not isinstance(values, dict):
        raise ValueError("class_acceptance_thresholds must be a JSON object keyed by exact prompt name.")
    return values


class OnnxSlicedSegmenter:
    """Prompt-fused YOLOE instance segmentation with overlapping image slices."""

    def __init__(
        self,
        model_path: Path,
        manifest_path: Path,
        *,
        provider: str = "auto",
        session_factory: Callable[[str, list[str]], Any] | None = None,
    ) -> None:
        self.model_path = Path(model_path).expanduser().resolve()
        self.manifest_path = Path(manifest_path).expanduser().resolve()
        if not self.model_path.is_file():
            raise FileNotFoundError(f"ONNX model does not exist: {self.model_path}")
        if not self.manifest_path.is_file():
            raise FileNotFoundError(f"Export manifest does not exist: {self.manifest_path}")
        self.manifest, self.prompts = _load_manifest(self.manifest_path)
        self.end_to_end = bool(self.manifest.get("export", {}).get("end_to_end", True))

        expected_hash = self.manifest.get("export", {}).get("sha256")
        if expected_hash and sha256_file(self.model_path) != expected_hash:
            raise ValueError("ONNX SHA-256 does not match the export manifest.")

        if session_factory is None:
            providers = resolve_providers(provider)
            factory = _default_session_factory
        else:
            # A supplied factory is a test/integration boundary.  Its fake
            # session still reports the provider actually used in the payload.
            providers = ["CPUExecutionProvider"] if provider == "auto" else resolve_providers(provider)
            factory = session_factory
        self.session = factory(str(self.model_path), providers)
        inputs = self.session.get_inputs()
        if len(inputs) != 1:
            raise ValueError(f"Expected exactly one ONNX image input, received {len(inputs)}.")
        self.input_name = inputs[0].name
        self.input_dtype = np.float16 if getattr(inputs[0], "type", "tensor(float)") == "tensor(float16)" else np.float32
        shape = list(getattr(inputs[0], "shape", ()))
        manifest_size = int(self.manifest.get("export", {}).get("imgsz", DEFAULT_SLICE_SIZE))
        self.input_height = int(shape[2]) if len(shape) == 4 and isinstance(shape[2], int) else manifest_size
        self.input_width = int(shape[3]) if len(shape) == 4 and isinstance(shape[3], int) else manifest_size
        if self.input_height <= 0 or self.input_width <= 0:
            raise ValueError(f"Invalid ONNX input shape: {shape}")
        self.providers = list(self.session.get_providers())

    def _predict_tile(
        self,
        tile: Image.Image,
        tile_origin: tuple[int, int],
        confidence_threshold: float,
        iou_threshold: float,
        max_detections: int,
    ) -> list[Detection]:
        tensor, transform = _letterbox(tile, self.input_width, self.input_height)
        outputs = self.session.run(None, {self.input_name: tensor.astype(self.input_dtype, copy=False)})
        prototypes = next((np.asarray(output) for output in outputs if np.asarray(output).ndim == 4), None)
        prediction = next((np.asarray(output) for output in outputs if np.asarray(output).ndim == 3), None)
        if prototypes is None or prediction is None:
            shapes = [list(np.asarray(output).shape) for output in outputs]
            raise ValueError(f"Expected one prediction and one mask-prototype ONNX output; received {shapes}.")
        if prototypes.shape[0] != 1:
            raise ValueError(f"Only ONNX batch size 1 is supported; received prototypes {prototypes.shape}.")

        boxes, scores, class_ids, coefficients = _prediction_rows(
            prediction,
            class_count=len(self.prompts),
            mask_dimension=int(prototypes.shape[1]),
            confidence_threshold=confidence_threshold,
            end_to_end=self.end_to_end,
        )
        x_offset, y_offset = tile_origin
        detections: list[Detection] = []
        for box, score, class_id, mask_coefficients in zip(boxes, scores, class_ids, coefficients):
            local_box = _unletterbox_box(box, transform)
            if local_box[2] <= local_box[0] or local_box[3] <= local_box[1]:
                continue
            local_mask = _decode_mask(prototypes[0], mask_coefficients, box, transform)
            global_box = (
                local_box[0] + x_offset,
                local_box[1] + y_offset,
                local_box[2] + x_offset,
                local_box[3] + y_offset,
            )
            detections.append(
                Detection(
                    int(class_id),
                    self.prompts[int(class_id)],
                    float(score),
                    global_box,
                    local_mask,
                    tile_origin,
                )
            )
        return class_aware_nms(detections, iou_threshold, max_detections)

    @staticmethod
    def _full_mask(detection: Detection, image_width: int, image_height: int) -> np.ndarray:
        full = np.zeros((image_height, image_width), dtype=bool)
        x_offset, y_offset = detection.tile_origin
        local_height, local_width = detection.mask.shape
        copy_width = min(local_width, image_width - x_offset)
        copy_height = min(local_height, image_height - y_offset)
        if copy_width > 0 and copy_height > 0:
            full[y_offset : y_offset + copy_height, x_offset : x_offset + copy_width] = detection.mask[
                :copy_height, :copy_width
            ]
        return full

    def predict_image(
        self,
        image_path: Path,
        output_path: Path,
        *,
        slice_size: int = DEFAULT_SLICE_SIZE,
        overlap_ratio: float = DEFAULT_OVERLAP_RATIO,
        confidence_threshold: float = 0.25,
        iou_threshold: float = 0.50,
        max_detections: int = 500,
        annotated_output: Path | None = None,
        class_acceptance_thresholds: Mapping[str, float] | None = None,
    ) -> dict[str, Any]:
        """Run all slices, globally suppress duplicates, and write COCO-RLE JSON."""

        image_path = Path(image_path).expanduser().resolve()
        output_path = Path(output_path).expanduser().resolve()
        if not image_path.is_file():
            raise FileNotFoundError(f"Input image does not exist: {image_path}")
        if slice_size <= 0:
            raise ValueError("Slice size must be positive.")
        if not 0.0 <= confidence_threshold <= 1.0:
            raise ValueError("Confidence threshold must be between 0 and 1.")
        acceptance_thresholds, threshold_policy = resolve_class_acceptance_thresholds(
            self.prompts,
            confidence_threshold,
            class_acceptance_thresholds,
        )

        started = time.perf_counter()
        with Image.open(image_path) as opened:
            image = opened.convert("RGB")
        image_width, image_height = image.size
        x_starts = slice_starts(image_width, slice_size, overlap_ratio)
        y_starts = slice_starts(image_height, slice_size, overlap_ratio)
        detections: list[Detection] = []
        for y_start in y_starts:
            for x_start in x_starts:
                tile = image.crop(
                    (
                        x_start,
                        y_start,
                        min(x_start + slice_size, image_width),
                        min(y_start + slice_size, image_height),
                    )
                )
                detections.extend(
                    self._predict_tile(
                        tile,
                        (x_start, y_start),
                        confidence_threshold,
                        iou_threshold,
                        max_detections,
                    )
                )
        detections = class_aware_nms(detections, iou_threshold, max_detections)

        serialized: list[dict[str, Any]] = []
        counts_per_class = {prompt: 0 for prompt in self.prompts}
        low_confidence_count = 0
        for detection in detections:
            full_mask = self._full_mask(detection, image_width, image_height)
            acceptance_threshold = acceptance_thresholds[detection.class_name]
            low_confidence = detection.score < acceptance_threshold
            box_width = max(0.0, detection.box[2] - detection.box[0])
            box_height = max(0.0, detection.box[3] - detection.box[1])
            counts_per_class[detection.class_name] += 1
            low_confidence_count += int(low_confidence)
            serialized.append(
                {
                    "class_id": detection.class_id,
                    "class_name": detection.class_name,
                    "confidence": round(detection.score, 6),
                    "acceptance_threshold": round(acceptance_threshold, 6),
                    "low_confidence": low_confidence,
                    "box_xyxy": [round(value, 3) for value in detection.box],
                    "box_area_pixels": round(box_width * box_height, 3),
                    "mask_area_pixels": int(np.count_nonzero(full_mask)),
                    "segmentation": _coco_uncompressed_rle(full_mask),
                }
            )

        slice_count = len(x_starts) * len(y_starts)
        payload = {
            "schema_version": 1,
            "status": "success",
            "architecture": EXPECTED_ARCHITECTURE,
            "model_path": str(self.model_path),
            "manifest_path": str(self.manifest_path),
            "image_id": image_path.name,
            "image_path": str(image_path),
            "image_size": {"width": image_width, "height": image_height},
            "prompts": list(self.prompts),
            "detected_classes": [prompt for prompt in self.prompts if counts_per_class[prompt] > 0],
            "counts_per_class": counts_per_class,
            "slice_size": slice_size,
            "overlap_ratio": overlap_ratio,
            "slice_count": slice_count,
            "confidence_threshold": confidence_threshold,
            "iou_threshold": iou_threshold,
            "detection_count": len(serialized),
            "low_confidence_count": low_confidence_count,
            "acceptance_threshold_policy": {
                "source": threshold_policy,
                "thresholds": acceptance_thresholds,
                "calibration_verified_by_runtime": False,
            },
            "detections": serialized,
            "runtime": {
                "engine": "onnxruntime",
                "inference_mode": "native_overlapping_tiled_segmentation" if slice_count > 1 else "single_tile_segmentation",
                "slicer": "native_sahi_style_slicer",
                "sahi_package_used": False,
                "cross_slice_merge": "class_aware_box_nms",
                "device_provider": self.providers[0] if self.providers else "unknown",
                "providers": self.providers,
                "elapsed_seconds": round(time.perf_counter() - started, 4),
            },
        }
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
        if annotated_output is not None:
            self._save_annotated(image, detections, Path(annotated_output))
        return payload

    @staticmethod
    def _save_annotated(
        image: Image.Image,
        detections: Sequence[Detection],
        output_path: Path,
    ) -> None:
        """Write a human-reviewable overlay without introducing OpenCV."""

        palette = (
            (244, 180, 0),
            (0, 0, 0),
            (220, 30, 30),
            (245, 245, 245),
            (255, 128, 0),
            (118, 75, 42),
            (128, 86, 191),
        )
        base = np.asarray(image, dtype=np.uint8).copy()
        image_width, image_height = image.size
        for detection in detections:
            # Reconstruct and blend one full-image mask at a time.  Retaining
            # every full-resolution mask until drawing would consume several
            # gigabytes on dense high-resolution photographs.
            mask = OnnxSlicedSegmenter._full_mask(detection, image_width, image_height)
            color = np.asarray(palette[detection.class_id], dtype=np.float32)
            base[mask] = (base[mask].astype(np.float32) * 0.55 + color * 0.45).astype(np.uint8)
        annotated = Image.fromarray(base, mode="RGB")
        draw = ImageDraw.Draw(annotated)
        font = ImageFont.load_default()
        for detection in detections:
            color = palette[detection.class_id]
            draw.rectangle(detection.box, outline=color, width=3)
            label = f"{detection.class_name} {detection.score:.2f}"
            label_box = draw.textbbox((detection.box[0], detection.box[1]), label, font=font)
            draw.rectangle(label_box, fill=color)
            text_color = (255, 255, 255) if sum(color) < 380 else (0, 0, 0)
            draw.text((detection.box[0], detection.box[1]), label, fill=text_color, font=font)
        output_path = output_path.expanduser().resolve()
        output_path.parent.mkdir(parents=True, exist_ok=True)
        annotated.save(output_path, quality=92)


def parse_args(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run SAHI-style sliced YOLOE-26x-seg ONNX inference on Ubuntu or another ONNX Runtime host."
    )
    parser.add_argument("--model", type=Path, required=True, help="Prompt-fused .onnx model")
    parser.add_argument("--manifest", type=Path, required=True, help="Matching export manifest JSON")
    parser.add_argument("--image", type=Path, required=True, help="Image to segment")
    parser.add_argument("--output", type=Path, required=True, help="Detection/mask JSON output")
    parser.add_argument("--annotated-output", type=Path, help="Optional JPG/PNG mask-and-box overlay")
    parser.add_argument("--provider", choices=("auto", "cpu", "cuda"), default="auto")
    parser.add_argument("--slice-size", type=int, default=DEFAULT_SLICE_SIZE)
    parser.add_argument("--overlap", type=float, default=DEFAULT_OVERLAP_RATIO)
    parser.add_argument("--confidence", type=float, default=0.25)
    parser.add_argument(
        "--class-thresholds",
        type=Path,
        help=(
            "Optional validation-produced JSON with all seven prompt-name acceptance thresholds. "
            "Detections below their class threshold are retained but flagged for review."
        ),
    )
    parser.add_argument("--iou", type=float, default=0.50)
    parser.add_argument("--max-detections", type=int, default=500)
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv)
    segmenter = OnnxSlicedSegmenter(
        model_path=args.model,
        manifest_path=args.manifest,
        provider=args.provider,
    )
    class_acceptance_thresholds = (
        load_class_acceptance_thresholds(args.class_thresholds) if args.class_thresholds is not None else None
    )
    payload = segmenter.predict_image(
        image_path=args.image,
        output_path=args.output,
        slice_size=args.slice_size,
        overlap_ratio=args.overlap,
        confidence_threshold=args.confidence,
        iou_threshold=args.iou,
        max_detections=args.max_detections,
        annotated_output=args.annotated_output,
        class_acceptance_thresholds=class_acceptance_thresholds,
    )
    print(json.dumps({"status": payload["status"], "detection_count": payload["detection_count"]}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
