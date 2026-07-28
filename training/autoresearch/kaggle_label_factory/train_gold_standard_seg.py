from __future__ import annotations

"""Validate human-reviewed polygons and train YOLOE-26x segmentation on Kaggle.

The safety boundary in this module is intentional: a folder containing labels
is not, by itself, permission to train.  The companion approval manifest must
state that a human reviewed the polygons and that pseudo-labels were not
accepted.  Running the script without ``--train`` performs validation only.
"""

import argparse
from dataclasses import asdict, dataclass
from datetime import datetime, timezone
import hashlib
import json
import math
import os
from pathlib import Path
import re
import shutil
from typing import Any, Callable


MODEL_FILENAME = "yoloe-26x-seg.pt"
DEFAULT_IMGSZ = 1280
MIN_APPROVED_IMAGES = 20
# The bootstrap path trains on the six reference photos the reviewer annotated by
# hand. Six is not a number chosen for convenience: it is every image in this
# project that carries human-verified geometry. The leakage-safe split needs at
# least three independent capture sites and those six are six different
# locations, so the split stays honest.
MIN_BOOTSTRAP_IMAGES = 6
BOOTSTRAP_DATASET_KIND = "bootstrap_human_rectangles"
APPROVAL_STATUS = "approved_for_training"
LABEL_FORMAT = "yolo_segmentation_polygon"
IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}

# These names intentionally match the text prompts that will be embedded before
# export.  Class IDs in every reviewed polygon file therefore have one stable,
# human-readable meaning from annotation through Ubuntu inference.
CLASS_NAMES = [
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
]


@dataclass(frozen=True)
class ApprovedSample:
    image_name: str
    image_path: Path
    label_path: Path
    polygon_count: int
    class_polygon_counts: dict[str, int]


@dataclass(frozen=True)
class DatasetValidation:
    dataset_root: Path
    approval_manifest: Path
    reviewer: str
    approved_image_count: int
    label_file_count: int
    polygon_count: int
    class_polygon_counts: dict[str, int]
    samples: tuple[ApprovedSample, ...]

    def to_json(self) -> dict[str, Any]:
        return {
            "dataset_root": str(self.dataset_root),
            "approval_manifest": str(self.approval_manifest),
            "reviewer": self.reviewer,
            "approved_image_count": self.approved_image_count,
            "label_file_count": self.label_file_count,
            "polygon_count": self.polygon_count,
            "class_polygon_counts": dict(self.class_polygon_counts),
            "samples": [
                {
                    **asdict(sample),
                    "image_path": str(sample.image_path),
                    "label_path": str(sample.label_path),
                }
                for sample in self.samples
            ],
        }


@dataclass(frozen=True)
class TrainingConfig:
    dataset_root: Path
    approval_manifest: Path
    output_root: Path
    imgsz: int = DEFAULT_IMGSZ
    epochs: int = 100
    batch: int = -1
    device: str = "0"
    workers: int = 4
    patience: int = 30
    seed: int = 20260713
    val_fraction: float = 0.20
    test_fraction: float = 0.20
    min_approved_images: int = MIN_APPROVED_IMAGES
    # Bootstrap runs train on the six hand-annotated reference photos to break
    # the label/review circularity. Never promotable; see
    # validate_gold_standard_dataset for why the two modes are exclusive.
    bootstrap: bool = False
    overwrite_output: bool = False


def write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, indent=2, sort_keys=True, allow_nan=False) + "\n",
        encoding="utf-8",
    )


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def running_inside_kaggle() -> bool:
    """Return true only for a real Kaggle worker filesystem/environment."""
    return bool(os.environ.get("KAGGLE_KERNEL_RUN_TYPE")) or Path("/kaggle/working").is_dir()


def require_cuda() -> None:
    """Fail before loading the 26x checkpoint when the Kaggle GPU is disabled."""
    try:
        import torch
    except ImportError as exc:
        raise RuntimeError("PyTorch is required in the Kaggle runtime before training can start.") from exc
    if not torch.cuda.is_available():
        raise RuntimeError("CUDA is unavailable. Enable a Kaggle GPU accelerator before training YOLOE-26x-seg.")


def read_approval_manifest(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise FileNotFoundError(f"Human approval manifest is missing: {path}")
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"Human approval manifest is not valid JSON: {path}: {exc}") from exc
    if not isinstance(payload, dict):
        raise ValueError("Human approval manifest must be a JSON object.")
    return payload


def validate_approval_manifest(payload: dict[str, Any], min_approved_images: int) -> tuple[str, list[str]]:
    if payload.get("status") != APPROVAL_STATUS:
        raise ValueError(f"Approval manifest status must be {APPROVAL_STATUS!r}.")
    if payload.get("human_reviewed") is not True:
        raise ValueError("Approval manifest human_reviewed must be true.")
    if payload.get("pseudo_labels_accepted") is not False:
        raise ValueError("Approval manifest pseudo_labels_accepted must be false.")
    if payload.get("label_format") != LABEL_FORMAT:
        raise ValueError(f"Approval manifest label_format must be {LABEL_FORMAT!r}.")

    reviewer = payload.get("reviewer")
    if not isinstance(reviewer, str) or not reviewer.strip():
        raise ValueError("Approval manifest reviewer must name the human who checked the polygons.")
    if payload.get("class_names") != CLASS_NAMES:
        raise ValueError(f"Approval manifest class_names must exactly match: {CLASS_NAMES!r}")

    raw_names = payload.get("approved_image_names")
    if not isinstance(raw_names, list) or any(not isinstance(name, str) or not name.strip() for name in raw_names):
        raise ValueError("Approval manifest approved_image_names must be a non-empty JSON string list.")
    approved_names = [name.strip() for name in raw_names]
    if len(approved_names) < min_approved_images:
        raise ValueError(
            f"Human approval manifest contains {len(approved_names)} images; at least {min_approved_images} are required."
        )
    if len({name.casefold() for name in approved_names}) != len(approved_names):
        raise ValueError("Approval manifest contains duplicate approved_image_names.")
    approved_stems = [Path(name).stem.casefold() for name in approved_names]
    if len(set(approved_stems)) != len(approved_stems):
        raise ValueError("Approved images must have unique filename stems so each image has exactly one polygon label.")
    for name in approved_names:
        if Path(name).name != name or Path(name).suffix.lower() not in IMAGE_SUFFIXES:
            raise ValueError(f"Approved image names must be plain image filenames, not paths: {name!r}")
    return reviewer.strip(), approved_names


def indexed_files(root: Path, suffixes: set[str], kind: str) -> dict[str, Path]:
    if not root.is_dir():
        raise FileNotFoundError(f"{kind} directory is missing: {root}")
    index: dict[str, Path] = {}
    for path in sorted(candidate for candidate in root.rglob("*") if candidate.is_file()):
        if path.suffix.lower() not in suffixes:
            continue
        key = path.name.casefold() if kind == "Image" else path.stem.casefold()
        if key in index:
            raise ValueError(f"Duplicate {kind.lower()} key {key!r}: {index[key]} and {path}")
        index[key] = path.resolve()
    return index


def polygon_area(points: list[tuple[float, float]]) -> float:
    return abs(
        sum(
            (x1 * y2) - (x2 * y1)
            for (x1, y1), (x2, y2) in zip(points, points[1:] + points[:1])
        )
    ) / 2.0


def validate_polygon_label(path: Path) -> tuple[int, dict[str, int]]:
    """Validate YOLO segmentation rows without converting them to boxes.

    YOLO detection rows contain exactly four normalized numbers after the class
    ID.  Requiring at least three x/y point pairs makes those old box labels
    impossible to feed into this instance-segmentation run by accident.
    """
    class_counts = {name: 0 for name in CLASS_NAMES}
    polygon_count = 0
    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw_line.strip()
        if not line:
            continue
        parts = line.split()
        if len(parts) < 7:
            raise ValueError(
                f"{path}:{line_number} must be a polygon with at least three x/y points; detection boxes are forbidden."
            )
        try:
            class_id = int(parts[0])
        except ValueError as exc:
            raise ValueError(f"{path}:{line_number} class ID must be an integer.") from exc
        if str(class_id) != parts[0] or not 0 <= class_id < len(CLASS_NAMES):
            raise ValueError(f"{path}:{line_number} class ID {parts[0]!r} is outside 0..{len(CLASS_NAMES) - 1}.")

        coordinate_fields = parts[1:]
        if len(coordinate_fields) % 2 != 0:
            raise ValueError(f"{path}:{line_number} polygon must contain complete x/y coordinate pairs.")
        try:
            coordinates = [float(value) for value in coordinate_fields]
        except ValueError as exc:
            raise ValueError(f"{path}:{line_number} polygon contains a non-numeric coordinate.") from exc
        if any(not math.isfinite(value) or value < 0.0 or value > 1.0 for value in coordinates):
            raise ValueError(f"{path}:{line_number} polygon coordinates must be finite and normalized to 0..1.")

        points = list(zip(coordinates[0::2], coordinates[1::2]))
        if len(set(points)) < 3 or polygon_area(points) <= 1e-8:
            raise ValueError(f"{path}:{line_number} polygon must have three distinct points and non-zero area.")
        class_counts[CLASS_NAMES[class_id]] += 1
        polygon_count += 1

    if polygon_count == 0:
        raise ValueError(f"Approved label file contains no polygon masks: {path}")
    return polygon_count, class_counts


def validate_image_file(path: Path) -> None:
    """Make a corrupt upload fail during review validation, not mid-epoch."""
    try:
        from PIL import Image

        with Image.open(path) as image:
            image.verify()
    except (ImportError, OSError, SyntaxError, ValueError) as exc:
        raise ValueError(f"Approved file is not a readable image: {path}") from exc


def validate_gold_standard_dataset(
    dataset_root: Path,
    approval_manifest: Path,
    min_approved_images: int = MIN_APPROVED_IMAGES,
    bootstrap: bool = False,
) -> DatasetValidation:
    dataset_root = Path(dataset_root).resolve()
    approval_manifest = Path(approval_manifest).resolve()
    manifest = read_approval_manifest(approval_manifest)

    # BOOTSTRAP MODE — a deliberately separate, non-promotable path.
    #
    # Why it exists: the stock checkpoint has never seen these fridges, so it
    # returns one box for a stack of six sauce cups. That granularity has to be
    # learned; four attempts to recover it in post-processing all failed on
    # measurement. Learning it needs labels, labels need the twenty-image review,
    # and the review is blocked until the detector is already good — a circle.
    # Fine-tuning on the reviewer's own hand-drawn rectangles breaks it.
    #
    # The two modes are MUTUALLY EXCLUSIVE, by manifest content rather than by a
    # flag alone. A bootstrap manifest declares `dataset_kind` and marks itself
    # release-ineligible; a gold-standard manifest does neither. So the twenty-
    # image release bar cannot be dodged by passing --bootstrap, and a bootstrap
    # dataset cannot be slipped through the release path by omitting it.
    declared_kind = manifest.get("dataset_kind")
    release_ineligible = manifest.get("release_gate_eligible") is False
    if bootstrap:
        if declared_kind != BOOTSTRAP_DATASET_KIND or not release_ineligible:
            raise ValueError(
                "--bootstrap requires a manifest with "
                f"dataset_kind={BOOTSTRAP_DATASET_KIND!r} and "
                "release_gate_eligible=false. Refusing to run the reduced-size "
                "path against a dataset that claims to be gold standard."
            )
        min_approved_images = min(min_approved_images, MIN_BOOTSTRAP_IMAGES)
    else:
        if declared_kind is not None or release_ineligible:
            raise ValueError(
                "This dataset is marked as a bootstrap dataset "
                f"(dataset_kind={declared_kind!r}). It carries coarse rectangular "
                "geometry and is never promotable; rerun with --bootstrap."
            )
        if min_approved_images < MIN_APPROVED_IMAGES:
            raise ValueError(
                f"min_approved_images cannot be lower than the audited batch size of {MIN_APPROVED_IMAGES}."
            )

    reviewer, approved_names = validate_approval_manifest(manifest, min_approved_images)
    image_index = indexed_files(dataset_root / "images", IMAGE_SUFFIXES, "Image")
    label_index = indexed_files(dataset_root / "labels", {".txt"}, "Label")
    approved_label_stems = {Path(name).stem.casefold() for name in approved_names}
    unapproved_labels = sorted(set(label_index) - approved_label_stems)
    if unapproved_labels:
        raise ValueError(
            "Gold Standard labels directory contains an unapproved polygon label: "
            + ", ".join(label_index[stem].name for stem in unapproved_labels)
        )

    class_totals = {name: 0 for name in CLASS_NAMES}
    samples: list[ApprovedSample] = []
    for image_name in sorted(approved_names, key=str.casefold):
        image_path = image_index.get(image_name.casefold())
        if image_path is None:
            raise FileNotFoundError(f"Approved image is missing from {dataset_root / 'images'}: {image_name}")
        validate_image_file(image_path)
        label_path = label_index.get(Path(image_name).stem.casefold())
        if label_path is None:
            raise FileNotFoundError(f"Approved polygon label is missing for {image_name}.")
        polygon_count, class_counts = validate_polygon_label(label_path)
        for class_name, count in class_counts.items():
            class_totals[class_name] += count
        samples.append(
            ApprovedSample(
                image_name=image_name,
                image_path=image_path,
                label_path=label_path,
                polygon_count=polygon_count,
                class_polygon_counts=class_counts,
            )
        )

    return DatasetValidation(
        dataset_root=dataset_root,
        approval_manifest=approval_manifest,
        reviewer=reviewer,
        approved_image_count=len(samples),
        label_file_count=len(samples),
        polygon_count=sum(sample.polygon_count for sample in samples),
        class_polygon_counts=class_totals,
        samples=tuple(samples),
    )


CAPTURE_SUFFIX_PATTERN = re.compile(r"-\d{4}-\d{2}-\d{2}-[^-]+$")


def scene_key(image_name: str) -> str:
    """Return the capture-site portion of a fridge image filename.

    The reviewed batch uses ``site-YYYY-MM-DD-random-token.jpg`` names. Two
    photographs from the same site can be almost identical even when their
    dates or upload tokens differ. Keeping the whole site together prevents a
    near-duplicate view from leaking into validation or the untouched test set.
    Filenames outside that convention safely fall back to their complete stem,
    so unrelated images are never grouped merely because parsing failed.
    """
    stem = Path(image_name).stem.casefold()
    site = CAPTURE_SUFFIX_PATTERN.sub("", stem)
    return site or stem


def split_samples(
    samples: tuple[ApprovedSample, ...],
    val_fraction: float,
    test_fraction: float = 0.20,
) -> dict[str, list[ApprovedSample]]:
    if not 0.05 <= val_fraction <= 0.50:
        raise ValueError("val_fraction must be between 0.05 and 0.50.")
    if not 0.05 <= test_fraction <= 0.50:
        raise ValueError("test_fraction must be between 0.05 and 0.50.")
    if val_fraction + test_fraction >= 0.80:
        raise ValueError("val_fraction plus test_fraction must leave at least 20% of images for training.")

    groups: dict[str, list[ApprovedSample]] = {}
    for sample in samples:
        groups.setdefault(scene_key(sample.image_name), []).append(sample)
    if len(groups) < 3:
        raise ValueError(
            "At least three independent capture sites are required for leakage-safe train, validation, and test splits."
        )

    validation_target = max(1, round(len(samples) * val_fraction))
    test_target = max(1, round(len(samples) * test_fraction))
    # Rank stable site groups rather than individual files. The result is
    # deterministic when a ZIP is unpacked in a different filesystem order,
    # while every near-duplicate capture from one site stays in one split.
    remaining_groups = sorted(
        groups.items(),
        key=lambda item: hashlib.sha256(item[0].encode("utf-8")).hexdigest(),
    )
    selected_groups: dict[str, set[str]] = {"train": set(), "val": set(), "test": set()}
    selected_counts = {"val": 0, "test": 0}
    for split, target, groups_to_reserve in (
        ("test", test_target, 2),
        ("val", validation_target, 1),
    ):
        while selected_counts[split] < target and len(remaining_groups) > groups_to_reserve:
            group_name, group_samples = remaining_groups.pop(0)
            selected_groups[split].add(group_name)
            selected_counts[split] += len(group_samples)
    selected_groups["train"] = {group_name for group_name, _ in remaining_groups}

    splits = {
        split: [sample for sample in samples if scene_key(sample.image_name) in selected_groups[split]]
        for split in ("train", "val", "test")
    }
    if any(not splits[split] for split in splits):
        raise ValueError("Leakage-safe splitting produced an empty train, validation, or test split.")
    return splits


def write_dataset_yaml(path: Path, dataset_root: Path) -> None:
    names = "\n".join(f"  {class_id}: {json.dumps(name)}" for class_id, name in enumerate(CLASS_NAMES))
    path.write_text(
        f"path: {json.dumps(dataset_root.resolve().as_posix())}\n"
        "train: images/train\n"
        "val: images/val\n"
        "test: images/test\n"
        f"names:\n{names}\n",
        encoding="utf-8",
    )


def stage_approved_dataset(
    validation: DatasetValidation,
    output_root: Path,
    val_fraction: float,
    test_fraction: float = 0.20,
) -> Path:
    stage_root = output_root / "staged_gold_standard_dataset"
    if stage_root.exists():
        shutil.rmtree(stage_root)
    splits = split_samples(validation.samples, val_fraction, test_fraction)
    staged_rows: list[dict[str, Any]] = []
    class_counts_by_split = {
        split: {class_name: 0 for class_name in CLASS_NAMES}
        for split in splits
    }
    for split, samples in splits.items():
        for sample in samples:
            image_destination = stage_root / "images" / split / sample.image_name
            label_destination = stage_root / "labels" / split / f"{Path(sample.image_name).stem}.txt"
            image_destination.parent.mkdir(parents=True, exist_ok=True)
            label_destination.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(sample.image_path, image_destination)
            shutil.copy2(sample.label_path, label_destination)
            staged_rows.append(
                {
                    "image_name": sample.image_name,
                    "split": split,
                    "source_image_sha256": sha256_file(sample.image_path),
                    "source_label_sha256": sha256_file(sample.label_path),
                }
            )
            for class_name, count in sample.class_polygon_counts.items():
                class_counts_by_split[split][class_name] += count

    globally_present_classes = {
        class_name
        for class_name, count in validation.class_polygon_counts.items()
        if count > 0
    }
    missing_present_classes_by_split = {
        split: sorted(
            class_name
            for class_name in globally_present_classes
            if class_counts[class_name] == 0
        )
        for split, class_counts in class_counts_by_split.items()
    }
    if missing_present_classes_by_split["train"]:
        raise ValueError(
            "The leakage-safe training split is missing approved classes: "
            + ", ".join(missing_present_classes_by_split["train"])
            + ". Add independently captured approved images before training."
        )

    write_dataset_yaml(stage_root / "data.yaml", stage_root)
    write_json(
        stage_root / "staging_manifest.json",
        {
            "label_provenance": "human_approved_polygon_labels_only",
            "pseudo_labels_accepted": False,
            "source_approval_manifest": str(validation.approval_manifest),
            "source_approval_manifest_sha256": sha256_file(validation.approval_manifest),
            "class_names": CLASS_NAMES,
            "split_image_counts": {split: len(samples) for split, samples in splits.items()},
            "class_polygon_counts_by_split": class_counts_by_split,
            "missing_present_classes_by_split": missing_present_classes_by_split,
            "scene_keys_by_split": {
                split: sorted({scene_key(sample.image_name) for sample in samples})
                for split, samples in splits.items()
            },
            "samples": staged_rows,
        },
    )
    return stage_root


def default_model_factory(model_source: str) -> Any:
    try:
        from ultralytics import YOLOE
    except ImportError as exc:
        raise RuntimeError("Ultralytics with YOLOE support is required in the Kaggle runtime.") from exc
    return YOLOE(model_source)


def yoloe_pe_seg_trainer() -> type[Any]:
    """Load the official trainer only after the Kaggle and CUDA guards pass.

    A pretrained YOLOE segmentation checkpoint needs the prompt-embedding
    segmentation trainer. Keeping this import lazy lets local label validation
    remain lightweight while the real Kaggle training call follows the
    Ultralytics fine-tuning contract explicitly.
    """
    try:
        from ultralytics.models.yolo.yoloe import YOLOEPESegTrainer
    except ImportError as exc:
        raise RuntimeError(
            "Ultralytics with YOLOEPESegTrainer is required in the Kaggle runtime."
        ) from exc
    return YOLOEPESegTrainer


def trainer_best_path(model: Any, expected_run_dir: Path) -> Path:
    trainer = getattr(model, "trainer", None)
    configured_best = getattr(trainer, "best", None)
    if configured_best:
        best_path = Path(configured_best)
        if best_path.is_file():
            return best_path
    fallback = expected_run_dir / "weights" / "best.pt"
    if not fallback.is_file():
        raise FileNotFoundError(f"YOLOE training finished without the required best.pt artifact: {fallback}")
    return fallback


def train_gold_standard_seg(
    config: TrainingConfig,
    model_factory: Callable[[str], Any] = default_model_factory,
) -> dict[str, Any]:
    if config.imgsz < DEFAULT_IMGSZ:
        raise ValueError(f"imgsz must be at least {DEFAULT_IMGSZ} to preserve small-object pixels.")
    if config.epochs < 1 or config.workers < 0 or config.patience < 0:
        raise ValueError("epochs must be positive; workers and patience cannot be negative.")

    validation = validate_gold_standard_dataset(
        config.dataset_root,
        config.approval_manifest,
        min_approved_images=config.min_approved_images,
        bootstrap=config.bootstrap,
    )
    # Keep this guard ahead of both checkpoint loading and dataset staging so a
    # local validation never downloads weights or mutates a training output.
    if not running_inside_kaggle():
        raise RuntimeError("Training is Kaggle-only. Upload the approved polygons and run this command in Kaggle.")
    require_cuda()

    output_root = Path(config.output_root).resolve()
    run_name = "yoloe_26x_seg_gold_standard"
    run_dir = output_root / "runs" / run_name
    artifacts_dir = output_root / "artifacts"
    if (run_dir.exists() or artifacts_dir.exists()) and not config.overwrite_output:
        raise FileExistsError(
            f"Training output already exists under {output_root}. Use --overwrite-output only for an intentional rerun."
        )
    if config.overwrite_output:
        shutil.rmtree(run_dir, ignore_errors=True)
        shutil.rmtree(artifacts_dir, ignore_errors=True)

    staged_root = stage_approved_dataset(
        validation,
        output_root,
        config.val_fraction,
        config.test_fraction,
    )
    model = model_factory(MODEL_FILENAME)
    if getattr(model, "task", "segment") != "segment":
        raise RuntimeError(f"{MODEL_FILENAME} did not load as an instance-segmentation model.")

    train_args: dict[str, Any] = {
        "data": str(staged_root / "data.yaml"),
        "imgsz": config.imgsz,
        "epochs": config.epochs,
        "batch": config.batch,
        "device": config.device,
        "workers": config.workers,
        "patience": config.patience,
        "seed": config.seed,
        "deterministic": True,
        "project": str(output_root / "runs"),
        "name": run_name,
        "exist_ok": False,
        "plots": True,
        "save": True,
    }

    # WHICH TRAINER, AND WHY IT DECIDES WHETHER THIS WORKS AT ALL.
    #
    # YOLOEPESegTrainer is a LINEAR PROBE. Read from the Ultralytics source: it
    # deletes `model.model[-1].savpe` and re-enables gradients on exactly three
    # tensors -- cv3[0][2], cv3[1][2], cv3[2][2], the final class-prediction
    # convolutions. The backbone, the neck, the box-regression head and the mask
    # head all stay frozen, and there is no argument to change that.
    #
    # That is the right recipe when the geometry is already correct and only the
    # class vocabulary needs re-aligning. It is the WRONG recipe here. The defect
    # this project is fixing is that a stack of six sauce cups comes back as one
    # box -- an instance-separation failure that lives entirely in the frozen
    # parts. Measured: a linear-probe bootstrap moved cup instances by +3 against
    # a shortfall of ~147, and not one image changed pass/fail. That was not bad
    # luck, it was structurally guaranteed.
    #
    # Passing no trainer lets YOLOE load its own task_map default for "segment",
    # `YOLOESegTrainer`, which trains the whole network. The bootstrap path needs
    # that. The release path keeps the official linear probe, because changing
    # the release recipe is a separate decision that should be made on evidence
    # from this run rather than bundled into it.
    if config.bootstrap:
        train_args["freeze"] = None
    else:
        train_args["trainer"] = yoloe_pe_seg_trainer()
    model.train(**train_args)
    trained_best = trainer_best_path(model, run_dir)
    artifacts_dir.mkdir(parents=True, exist_ok=True)
    packaged_best = artifacts_dir / "best.pt"
    shutil.copy2(trained_best, packaged_best)

    metadata = {
        "schema_version": 1,
        "created_at_utc": datetime.now(timezone.utc).isoformat(),
        "model_source": MODEL_FILENAME,
        "task": "instance_segmentation",
        "imgsz": config.imgsz,
        "epochs": config.epochs,
        "batch": config.batch,
        "device": config.device,
        "val_fraction": config.val_fraction,
        "test_fraction": config.test_fraction,
        "class_names": CLASS_NAMES,
        "approved_image_count": validation.approved_image_count,
        "polygon_count": validation.polygon_count,
        "class_polygon_counts": validation.class_polygon_counts,
        "human_reviewer": validation.reviewer,
        "label_provenance": "human_approved_polygon_labels_only",
        "pseudo_labels_accepted": False,
        "approval_manifest": str(validation.approval_manifest),
        "approval_manifest_sha256": sha256_file(validation.approval_manifest),
        "dataset_yaml": str(staged_root / "data.yaml"),
        "training_run_dir": str(run_dir),
        "best_pt": str(packaged_best),
        "best_pt_sha256": sha256_file(packaged_best),
        # Trainer classes cannot be encoded as JSON directly. Persisting the
        # fully qualified class name keeps the exact training recipe auditable —
        # and on the bootstrap path no trainer is passed at all, so record which
        # default YOLOE will load. Reading a checkpoint's metadata must make it
        # obvious whether it was a linear probe or a full fine-tune, because that
        # single fact explains whether it could have learned instance separation.
        "train_args": {
            **train_args,
            "trainer": (
                "ultralytics.models.yolo.yoloe.YOLOESegTrainer (task_map default, full fine-tune)"
                if config.bootstrap
                else f"{train_args['trainer'].__module__}.{train_args['trainer'].__name__}"
            ),
        },
        "trainable_scope": (
            "full_network" if config.bootstrap else "linear_probe_cv3_class_convs_only"
        ),
    }
    metadata_path = artifacts_dir / "training_metadata.json"
    write_json(metadata_path, metadata)
    return {
        "status": "trained",
        "best_pt": str(packaged_best),
        "metadata": str(metadata_path),
        "validation": validation.to_json(),
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Validate human-approved YOLO polygons; train yoloe-26x-seg.pt only with explicit --train on Kaggle."
    )
    parser.add_argument("--dataset-root", type=Path, default=Path("training/sam_annotation_batch"))
    parser.add_argument(
        "--bootstrap",
        action="store_true",
        help=(
            "Train the non-promotable bootstrap checkpoint on the hand-annotated "
            "reference photos. Requires a manifest declaring dataset_kind="
            f"{BOOTSTRAP_DATASET_KIND!r} and release_gate_eligible=false."
        ),
    )
    parser.add_argument(
        "--approval-manifest",
        type=Path,
        default=None,
        help="Defaults to <dataset-root>/approval_manifest.json.",
    )
    parser.add_argument("--output-root", type=Path, default=Path("/kaggle/working/yoloe26x_gold_standard"))
    parser.add_argument("--imgsz", type=int, default=DEFAULT_IMGSZ)
    parser.add_argument("--epochs", type=int, default=100)
    parser.add_argument("--batch", type=int, default=-1)
    parser.add_argument("--device", default="0")
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--patience", type=int, default=30)
    parser.add_argument("--seed", type=int, default=20260713)
    parser.add_argument("--val-fraction", type=float, default=0.20)
    parser.add_argument("--test-fraction", type=float, default=0.20)
    parser.add_argument("--overwrite-output", action="store_true")
    parser.add_argument(
        "--train",
        action="store_true",
        help="Explicitly authorize Kaggle GPU training. Without this flag the script validates only.",
    )
    return parser


def main() -> None:
    args = build_parser().parse_args()
    approval_manifest = args.approval_manifest or args.dataset_root / "approval_manifest.json"
    if not args.train:
        validation = validate_gold_standard_dataset(
            args.dataset_root, approval_manifest, bootstrap=args.bootstrap
        )
        print(json.dumps({"status": "validated_only", "training_started": False, **validation.to_json()}, indent=2))
        return

    result = train_gold_standard_seg(
        TrainingConfig(
            dataset_root=args.dataset_root,
            approval_manifest=approval_manifest,
            bootstrap=args.bootstrap,
            output_root=args.output_root,
            imgsz=args.imgsz,
            epochs=args.epochs,
            batch=args.batch,
            device=args.device,
            workers=args.workers,
            patience=args.patience,
            seed=args.seed,
            val_fraction=args.val_fraction,
            test_fraction=args.test_fraction,
            overwrite_output=args.overwrite_output,
        )
    )
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
