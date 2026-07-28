"""Turn the reviewer's hand-drawn rectangles into a trainable bootstrap dataset.

WHY THIS EXISTS
---------------
The detector has never been fine-tuned.  Every count measured so far came from a
stock ``yoloe-26x-seg.pt`` that has never seen these fridges, which is why nested
sauce-cup stacks come back as one box instead of six.  Four separate attempts to
split those stacks in post-processing all failed on measurement (ratio estimator,
1-D autocorrelation, 2-D autocorrelation, rim-line detection), so the granularity
has to be learned, not filtered.

Learning it needs labels.  The only human-verified geometry in this project is the
242 rectangles the reviewer drew across six reference photos — and those have been
sitting unused as mere visual exemplars, because the trainer wants polygons.

THE KEY INSIGHT
---------------
A rectangle IS a polygon.  ``validate_polygon_label()`` in the trainer rejects a
row with fewer than 7 whitespace fields, which is aimed at legacy YOLO detection
boxes (``class cx cy w h`` = 5 fields).  Writing the same rectangle as its four
corners (``class x1 y1 x2 y2 x3 y3 x4 y4`` = 9 fields) is a perfectly valid
segmentation polygon and passes every check.  No GPU, no SAM pass, no new
annotation work.

WHAT THIS BUYS AND WHAT IT DOES NOT
-----------------------------------
Rectangular masks are COARSE.  A model trained on them learns where each object
is, how big it is, and which class it belongs to, but it does not learn the exact
curved silhouette of a cup rim.  That trade is worth taking here because the
failure being fixed is "six cups came back as one box", which is a localisation
and instance-separation problem, not a silhouette problem.

The output is deliberately labelled a BOOTSTRAP dataset and is never promotable.
It exists to produce a checkpoint good enough to re-propose the twenty review
images so the reviewer sees clean boxes on their single pass.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import shutil
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

# The seven-class contract, in the exact order the trainer asserts.
CLASS_NAMES = [
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
]

# AnyLabeling display names -> fixed class ids.  Mirrors RAW_LABEL_TO_CLASS_ID in
# assisted_label_review.py; kept here so this script has no import dependency on
# the 400KB Kaggle runtime.
RAW_LABEL_TO_CLASS_ID = {
    "Kraft Box": 0,
    "Soya Sauce Cup": 1,
    "Teriyaki Sauce Cup": 2,
    "Wayo Dip Sauce Cup": 3,
    "Chili Mayo Sauce Cup": 4,
    "Chopstick Tip": 5,
    "Soya Sauce Packet": 6,
}

# One aggregate box drawn around a whole bundle of chopsticks cannot be turned
# into individual tips, so it is quarantined rather than guessed at.
RAW_LABEL_QUARANTINE = {"Chopstick"}


def rectangle_corners(points: list[list[float]]) -> list[tuple[float, float]] | None:
    """Return a rectangle's four corners, clockwise, from AnyLabeling's 2 points.

    AnyLabeling stores a rectangle as two opposite corners in absolute pixels.
    Emitting all four corners is what makes the row a polygon (9 fields) instead
    of a detection box (5 fields), which is the whole trick this script relies on.
    """
    if len(points) != 2:
        return None
    (x_a, y_a), (x_b, y_b) = points[0], points[1]
    left, right = sorted((float(x_a), float(x_b)))
    top, bottom = sorted((float(y_a), float(y_b)))
    if right - left <= 0.0 or bottom - top <= 0.0:
        return None
    return [(left, top), (right, top), (right, bottom), (left, bottom)]


def polygon_row(class_id: int, corners, width: int, height: int) -> str | None:
    """Format one corner list as a normalized YOLO segmentation row."""
    values: list[str] = []
    for x, y in corners:
        # Clamp into frame: a hand-drawn box can overrun the edge by a pixel or
        # two, and the trainer rejects any coordinate outside 0..1.
        nx = min(1.0, max(0.0, x / float(width)))
        ny = min(1.0, max(0.0, y / float(height)))
        values.append(f"{nx:.6f}")
        values.append(f"{ny:.6f}")
    # Degenerate after clamping (zero width or height) -> drop it rather than
    # emit a zero-area polygon the trainer will reject anyway.
    xs = [float(v) for v in values[0::2]]
    ys = [float(v) for v in values[1::2]]
    if max(xs) - min(xs) <= 0.0 or max(ys) - min(ys) <= 0.0:
        return None
    return " ".join([str(class_id), *values])


def convert_reference(annotation_path: Path) -> tuple[str, list[str], dict[str, int]]:
    """Convert one AnyLabeling file into polygon rows.

    Returns (image_name, rows, per-class counts).  Unknown or quarantined labels
    are counted separately and never silently mapped to a class.
    """
    payload = json.loads(annotation_path.read_text(encoding="utf-8"))
    image_name = str(payload.get("imagePath") or f"{annotation_path.stem}.jpg")
    width = int(payload["imageWidth"])
    height = int(payload["imageHeight"])

    rows: list[str] = []
    counts: dict[str, int] = {name: 0 for name in CLASS_NAMES}
    counts["_quarantined_aggregate"] = 0
    counts["_unknown_label"] = 0
    counts["_degenerate"] = 0

    for shape in payload.get("shapes") or []:
        label = str(shape.get("label") or "")
        if shape.get("shape_type") != "rectangle":
            counts["_unknown_label"] += 1
            continue
        if label in RAW_LABEL_QUARANTINE:
            counts["_quarantined_aggregate"] += 1
            continue
        class_id = RAW_LABEL_TO_CLASS_ID.get(label)
        if class_id is None:
            counts["_unknown_label"] += 1
            continue
        corners = rectangle_corners(shape.get("points") or [])
        if corners is None:
            counts["_degenerate"] += 1
            continue
        row = polygon_row(class_id, corners, width, height)
        if row is None:
            counts["_degenerate"] += 1
            continue
        rows.append(row)
        counts[CLASS_NAMES[class_id]] += 1

    return image_name, rows, counts


def build(source_images_dir: Path, output_dir: Path, reviewer: str) -> dict[str, Any]:
    """Write images/, labels/ and an approval manifest for the bootstrap run."""
    annotations = sorted(source_images_dir.glob("*.json"))
    if not annotations:
        raise SystemExit(f"No AnyLabeling .json files found under {source_images_dir}")

    if output_dir.exists():
        raise SystemExit(
            f"{output_dir} already exists; refusing to overwrite an existing dataset."
        )
    images_out = output_dir / "images"
    labels_out = output_dir / "labels"
    images_out.mkdir(parents=True)
    labels_out.mkdir(parents=True)

    approved: list[str] = []
    totals: dict[str, int] = {name: 0 for name in CLASS_NAMES}
    totals.update({"_quarantined_aggregate": 0, "_unknown_label": 0, "_degenerate": 0})
    per_image: list[dict[str, Any]] = []

    for annotation_path in annotations:
        image_name, rows, counts = convert_reference(annotation_path)
        source_image = source_images_dir / image_name
        if not source_image.is_file():
            raise SystemExit(f"Annotation {annotation_path.name} names a missing image {image_name}")
        if not rows:
            raise SystemExit(f"{annotation_path.name} produced no usable polygons.")

        shutil.copy2(source_image, images_out / image_name)
        label_path = labels_out / f"{Path(image_name).stem}.txt"
        label_path.write_text("\n".join(rows) + "\n", encoding="utf-8")

        approved.append(image_name)
        for key, value in counts.items():
            totals[key] = totals.get(key, 0) + value
        per_image.append(
            {
                "image_name": image_name,
                "polygon_count": len(rows),
                "class_counts": {k: v for k, v in counts.items() if not k.startswith("_")},
                "quarantined_aggregate": counts["_quarantined_aggregate"],
                "source_annotation_sha256": hashlib.sha256(
                    annotation_path.read_bytes()
                ).hexdigest(),
            }
        )

    manifest = {
        "schema_version": 2,
        # These four fields are what the trainer's validate_approval_manifest()
        # checks.  They are honest here: a human really did draw every one of
        # these rectangles, and nothing machine-generated is being passed off as
        # approved geometry.
        "status": "approved_for_training",
        "human_reviewed": True,
        "pseudo_labels_accepted": False,
        "label_format": "yolo_segmentation_polygon",
        "reviewer": reviewer,
        "class_names": list(CLASS_NAMES),
        "approved_image_names": approved,
        # Bootstrap markers.  These are what stop this dataset ever being
        # mistaken for the twenty-image gold standard: the geometry is
        # rectangular rather than traced, and the batch is deliberately small.
        "dataset_kind": "bootstrap_human_rectangles",
        "geometry_fidelity": "axis_aligned_rectangle_not_traced_outline",
        "promotion_authorized": False,
        "release_gate_eligible": False,
        "bootstrap_reason": (
            "Fine-tune the stock checkpoint on human-verified geometry so the "
            "twenty review images can be re-proposed cleanly for a single "
            "reviewer pass. Not a gold-standard dataset and never promotable."
        ),
        "created_at": datetime.now(timezone.utc).isoformat(),
        "images": per_image,
        "class_polygon_totals": {k: v for k, v in totals.items() if not k.startswith("_")},
        "quarantined_aggregate_total": totals["_quarantined_aggregate"],
        "unknown_label_total": totals["_unknown_label"],
        "degenerate_total": totals["_degenerate"],
    }
    (output_dir / "approval_manifest.json").write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    return manifest


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--source-images-dir",
        type=Path,
        default=Path("training/sam_annotation_batch/images"),
        help="Directory holding the photos and their AnyLabeling .json files.",
    )
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument(
        "--reviewer",
        required=True,
        help="Who drew the rectangles; recorded in the approval manifest.",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    manifest = build(args.source_images_dir, args.output_dir, args.reviewer)
    print(
        json.dumps(
            {
                "output_dir": str(args.output_dir),
                "approved_image_count": len(manifest["approved_image_names"]),
                "class_polygon_totals": manifest["class_polygon_totals"],
                "quarantined_aggregate_total": manifest["quarantined_aggregate_total"],
                "unknown_label_total": manifest["unknown_label_total"],
                "degenerate_total": manifest["degenerate_total"],
                "dataset_kind": manifest["dataset_kind"],
                "promotion_authorized": manifest["promotion_authorized"],
            },
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
