from __future__ import annotations

"""Build a local, fail-closed review page for one extracted Kaggle artifact.

The Kaggle notebook deliberately leaves its twenty proposals in quarantine.  This
small tool makes those proposals easy to inspect without starting a web server or
changing a source manifest.  The generated page stores only the reviewer's local
working state in ``localStorage`` and emits a separate ``review_decisions.local.json``
file when the reviewer explicitly downloads it.
"""

import argparse
from dataclasses import dataclass, field
from collections import Counter
import hashlib
import html
import json
import math
import os
from pathlib import Path
import posixpath
from typing import Any


EXPECTED_IMAGE_COUNT = 20
VALID_DECISIONS = {"pending", "pass", "reject"}
IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".bmp"}
DISPLAY_CLASS_NAMES = (
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
)
DISPLAY_CLASS_COLORS = (
    "#3a86ff",
    "#5a3416",
    "#da3230",
    "#eee8b2",
    "#ec7e26",
    "#2abe78",
    "#8056bf",
)
MANUAL_REFERENCE_DIR = Path(__file__).resolve().parent / "sam_annotation_batch" / "images"
AUDITED_VISUAL_RECOVERY_SOURCE = "yoloe26x_audited_exemplar_recovery_tiled"
AUDITED_VISUAL_RECOVERY_CLASS_IDS = {1, 5}
AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT = 3
AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT = 2


class ReviewArtifactError(ValueError):
    """Raised when an extracted artifact is not safe to review."""


@dataclass(frozen=True)
class ReviewRow:
    """One immutable source row used to render the page."""

    image_name: str
    contact_sheet: str
    polygon: str
    class_counts: tuple[int, ...]
    sam_success_count: int
    fallback_count: int
    ocr_sticker_count: int | None = None
    ocr_sticker_texts: tuple[str, ...] = ()
    ocr_status: str = "unavailable"
    readiness_reasons: tuple[str, ...] = ()
    manual_reference_available: bool = False
    manual_reference_name: str | None = None
    decision: str = "pending"
    reviewer: str = ""
    notes: str = ""
    previous_review: dict[str, Any] = field(default_factory=dict)
    correction_usefulness: dict[str, Any] = field(default_factory=dict)
    correction_visible_count: int = 0
    correction_suppressed_count: int = 0
    audited_visual_recovery_triggered: bool = False
    audited_visual_recovery_selected_class_names: tuple[str, ...] = ()
    audited_visual_recovery_proposal_count: int = 0
    audited_visual_recovery_unresolved_class_names: tuple[str, ...] = ()


@dataclass(frozen=True)
class ReviewArtifact:
    """Validated artifact metadata; source files are never rewritten."""

    root: Path
    source_run_manifest_sha256: str
    rows: tuple[ReviewRow, ...]
    review_ready: bool
    readiness_reasons: tuple[str, ...]
    manual_reference_count: int


def _read_object(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as error:
        raise ReviewArtifactError(f"Could not read JSON manifest {path}: {error}") from error
    if not isinstance(value, dict):
        raise ReviewArtifactError(f"Manifest {path} must contain a JSON object.")
    return value


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    try:
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
    except OSError as error:
        raise ReviewArtifactError(f"Could not hash {path}: {error}") from error
    return digest.hexdigest()


def _require_false(manifest: dict[str, Any], key: str, path: Path) -> None:
    if manifest.get(key) is not False:
        raise ReviewArtifactError(f"{path.name} must keep {key}=false.")


def _require_release_gate_closed(manifest: dict[str, Any], path: Path) -> None:
    gate = manifest.get("release_gate")
    if gate is not None and (not isinstance(gate, dict) or gate.get("passed") is not False):
        raise ReviewArtifactError(f"{path.name} must keep release_gate.passed=false.")


def _read_review_hold(root: Path, run_manifest_path: Path) -> str | None:
    """Read an optional hash-bound human-quality withdrawal sidecar.

    A structurally valid model export can still contain semantically wrong
    boundaries.  This sidecar lets us withdraw such a run without rewriting
    its immutable proposals, while the manifest hash prevents a stale hold
    from being applied to a different run.
    """

    hold_path = root / "review_hold.json"
    if not hold_path.exists():
        return None
    hold = _read_object(hold_path)
    if hold.get("status") != "withdrawn":
        raise ReviewArtifactError("review_hold.json must declare status=withdrawn.")
    reason = hold.get("reason")
    if not isinstance(reason, str) or not reason.strip():
        raise ReviewArtifactError("review_hold.json must contain a non-empty reason.")
    if hold.get("source_run_manifest_sha256") != _sha256(run_manifest_path):
        raise ReviewArtifactError(
            "review_hold.json source_run_manifest_sha256 does not match run_manifest.json."
        )
    for key in ("training_authorized", "promotion_authorized"):
        if hold.get(key, False) is not False:
            raise ReviewArtifactError(f"review_hold.json must keep {key}=false.")
    return reason.strip()


def _review_evidence_reasons(
    root: Path,
    run_manifest: dict[str, Any],
    run_manifest_path: Path,
) -> list[str]:
    """Check the notebook's independent generation evidence before rendering READY.

    A contact sheet and a syntactically valid polygon file are not enough to
    call a proposal run ready.  Earlier artifacts could contain twenty cards
    while the SAM semantic prompt gate had failed or the rescue diagnostics
    were missing.  The page is a second, local fail-closed boundary: it keeps
    those artifacts visible for diagnosis, but never presents them as a clean
    review set.
    """

    reasons: list[str] = []
    diagnostics_path = root / "generation_diagnostics.json"
    if not diagnostics_path.is_file():
        return ["generation_diagnostics.json is missing; the notebook gate cannot be verified."]
    try:
        diagnostics = _read_object(diagnostics_path)
    except ReviewArtifactError as error:
        return [str(error)]

    if diagnostics.get("status") != "generation_diagnostics":
        reasons.append("generation_diagnostics.json has an unexpected status.")
    for key in ("training_authorized", "promotion_authorized"):
        if diagnostics.get(key) is not False:
            reasons.append(f"generation_diagnostics.json must keep {key}=false.")
    release_gate = diagnostics.get("release_gate")
    if not isinstance(release_gate, dict) or release_gate.get("passed") is not False:
        reasons.append("generation diagnostics release gate is not closed.")

    semantic = run_manifest.get("sam31_semantic_discovery_summary")
    if not isinstance(semantic, dict):
        reasons.append("SAM 3.1 semantic discovery summary is missing.")
    else:
        expected_prompts = EXPECTED_IMAGE_COUNT * 7
        if semantic.get("enabled") is not True:
            reasons.append("SAM 3.1 semantic discovery is not enabled.")
        if semantic.get("image_count") != EXPECTED_IMAGE_COUNT:
            reasons.append("SAM 3.1 semantic discovery does not cover all 20 images.")
        if semantic.get("prompt_attempt_count") != expected_prompts:
            reasons.append("SAM 3.1 did not attempt all 20 x 7 prompts.")
        if semantic.get("prompt_success_count") != expected_prompts:
            reasons.append("SAM 3.1 did not complete all 20 x 7 prompts.")
        if semantic.get("failed_prompt_count") != 0:
            reasons.append("SAM 3.1 reported failed semantic prompts.")
        semantic_rows = semantic.get("images")
        if not isinstance(semantic_rows, list) or len(semantic_rows) != EXPECTED_IMAGE_COUNT:
            reasons.append("SAM 3.1 semantic summary has fewer than 20 image rows.")
        else:
            for row in semantic_rows:
                if not isinstance(row, dict) or (
                    row.get("status") != "success"
                    or row.get("prompt_count") != 7
                    or row.get("successful_prompt_count") != 7
                    or row.get("failed_prompt_count") != 0
                ):
                    reasons.append("At least one image failed the SAM 3.1 semantic prompt gate.")
                    break

    rescue = run_manifest.get("sam31_bounded_rescue_summary")
    if not isinstance(rescue, dict):
        reasons.append("bounded SAM 3.1 rescue summary is missing.")
    else:
        if rescue.get("enabled") is not True:
            reasons.append("bounded SAM 3.1 rescue is not enabled.")
        if rescue.get("image_count") != EXPECTED_IMAGE_COUNT:
            reasons.append("bounded SAM 3.1 rescue does not cover all 20 images.")
        rescue_rows = rescue.get("images")
        if not isinstance(rescue_rows, list) or len(rescue_rows) != EXPECTED_IMAGE_COUNT:
            reasons.append("bounded SAM 3.1 rescue summary has fewer than 20 image rows.")
        else:
            for row in rescue_rows:
                if not isinstance(row, dict):
                    reasons.append("bounded SAM 3.1 rescue summary contains an invalid image row.")
                    break
                if row.get("status") in {"failed", "rejected"}:
                    reasons.append(
                        "At least one triggered bounded SAM 3.1 rescue failed or was rejected."
                    )
                    break

    lifecycle = run_manifest.get("sam31_session_lifecycle")
    if not isinstance(lifecycle, dict):
        reasons.append("SAM 3.1 session lifecycle evidence is missing.")
    else:
        expected_close_count = lifecycle.get("expected_close_count")
        observed_close_count = lifecycle.get("observed_close_count")
        if (
            type(expected_close_count) is not int
            or expected_close_count < 0
            or type(observed_close_count) is not int
            or observed_close_count < 0
        ):
            reasons.append(
                "SAM 3.1 session lifecycle close counts are missing or invalid."
            )
        elif expected_close_count != observed_close_count:
            reasons.append(
                "SAM 3.1 session lifecycle close counts do not match."
            )
        if lifecycle.get("all_reported_active_session_counts_zero") is not True:
            reasons.append(
                "SAM 3.1 session lifecycle reported active sessions are not all zero."
            )
        if lifecycle.get("passed") is not True:
            reasons.append("SAM 3.1 session lifecycle gate did not pass.")

    sam_status = run_manifest.get("sam3_status")
    if sam_status not in {"available", "available_with_instance_fallbacks"}:
        reasons.append("SAM 3.1 refinement did not complete for the review run.")
    instance_summary = run_manifest.get("sam3_instance_summary")
    if not isinstance(instance_summary, dict):
        reasons.append("SAM 3.1 instance summary is missing.")
    elif instance_summary.get("all_instances_fell_back") is True:
        reasons.append("All SAM 3.1 instances fell back to rectangles; review is blocked.")

    correction_summary = run_manifest.get("correction_guided_summary")
    if not isinstance(correction_summary, dict):
        reasons.append("Correction-guided proposal summary is missing.")
    else:
        if correction_summary.get("triggered_image_count") != EXPECTED_IMAGE_COUNT:
            reasons.append("Correction-guided diagnostics do not cover all 20 images.")
        if correction_summary.get("accepted_target_image_count") != 14:
            reasons.append("Correction-guided diagnostics did not complete all 14 target images.")
        if correction_summary.get("accepted_reference_diagnostic_count") != 6:
            reasons.append("Correction-guided diagnostics did not cover all six references.")
        if correction_summary.get("prompt_attempt_count") != EXPECTED_IMAGE_COUNT * 7:
            reasons.append("Correction-guided diagnostics did not attempt all 20 x 7 prompts.")
        if correction_summary.get("successful_prompt_count") != EXPECTED_IMAGE_COUNT * 7:
            reasons.append("Correction-guided diagnostics did not complete all 20 x 7 prompts.")
        # Usefulness is advisory for the review UI.  V41/V42/V44 package runs
        # failed closed here on reject targets still missing black soya or tips
        # after recovery; those images are exactly what humans must complete.
        usefulness = correction_summary.get("proposal_usefulness_gate")
        if not isinstance(usefulness, dict):
            reasons.append("Correction proposal usefulness summary is missing.")
        if correction_summary.get("count_targets_used_as_geometry") is not False:
            reasons.append("Human correction counts were incorrectly used as geometry.")

    # V42 adds one narrowly scoped recovery lane for genuinely missing,
    # human-required small objects.  It divides the source photo into four
    # overlapping tiles and runs only the still-absent required classes.  The
    # Kaggle notebook checks this summary before it creates the downloadable
    # ZIP; repeat the same checks here so a copied, incomplete, or mismatched
    # archive can never be presented as ready for human approval.
    tiled_recovery = run_manifest.get("correction_guided_tiled_recovery_summary")
    if not isinstance(tiled_recovery, dict):
        reasons.append("Targeted tiled correction recovery summary is missing.")
    else:
        if tiled_recovery.get("enabled") is not True:
            reasons.append("Targeted tiled correction recovery is not enabled.")
        if tiled_recovery.get("image_count") != EXPECTED_IMAGE_COUNT:
            reasons.append("Targeted tiled correction recovery does not cover all 20 images.")
        if tiled_recovery.get("failed_image_count") != 0:
            reasons.append("Targeted tiled correction recovery reported failed images.")
        if (
            tiled_recovery.get("successful_prompt_count")
            != tiled_recovery.get("prompt_attempt_count")
        ):
            reasons.append("Targeted tiled correction recovery has incomplete prompts.")
        if tiled_recovery.get("count_targets_used_as_geometry") is not False:
            reasons.append(
                "Human correction counts were incorrectly used as targeted tiled correction recovery geometry."
            )
        tiled_rows = tiled_recovery.get("images")
        if not isinstance(tiled_rows, list) or len(tiled_rows) != EXPECTED_IMAGE_COUNT:
            reasons.append(
                "Targeted tiled correction recovery summary has fewer than 20 image rows."
            )
        elif any(
            not isinstance(row, dict) or row.get("status") in {"failed", "rejected"}
            for row in tiled_rows
        ):
            reasons.append(
                "At least one targeted tiled correction recovery failed or was rejected."
            )

    # The audited recovery lane may use tightly cropped YOLOE image prompts for black soy
    # cups and chopstick tips that were still absent from the primary visual
    # union.  This is a useful recovery aid only when every crop remains
    # traceable in the downloaded artifact, every triggered target finished its
    # calls, and no reviewer count was turned into geometry.  Keep this local
    # page fail-closed if any part of that evidence contract is missing.
    audited_visual = run_manifest.get("audited_visual_recovery_summary")
    if not isinstance(audited_visual, dict):
        reasons.append("Audited visual recovery summary is missing.")
    else:
        if audited_visual.get("enabled") is not True:
            reasons.append("Audited visual recovery is not enabled.")
        if audited_visual.get("source") != AUDITED_VISUAL_RECOVERY_SOURCE:
            reasons.append("Audited visual recovery source is not recognized.")
        if audited_visual.get("image_count") != EXPECTED_IMAGE_COUNT:
            reasons.append("Audited visual recovery does not cover all 20 images.")
        if audited_visual.get("target_image_count") != EXPECTED_IMAGE_COUNT - 6:
            reasons.append("Audited visual recovery does not cover all 14 target images.")
        if audited_visual.get("failed_image_count") != 0:
            reasons.append("Audited visual recovery reported failed images.")
        if (
            audited_visual.get("successful_inference_call_count")
            != audited_visual.get("inference_call_count")
        ):
            reasons.append("Audited visual recovery has incomplete inference calls.")
        if audited_visual.get("count_targets_used_as_geometry") is not False:
            reasons.append("Human correction counts were incorrectly used as audited visual recovery geometry.")
        reference_plans = audited_visual.get("reference_plans")
        if not isinstance(reference_plans, dict):
            reasons.append("Audited visual recovery reference plans are missing.")
        else:
            for class_id in sorted(AUDITED_VISUAL_RECOVERY_CLASS_IDS):
                plans = reference_plans.get(str(class_id))
                if not isinstance(plans, list) or len(plans) != AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT:
                    reasons.append(
                        "Audited visual recovery does not retain three reference crops for every class."
                    )
                    break
                for plan in plans:
                    if not isinstance(plan, dict) or plan.get("class_id") != class_id:
                        reasons.append("Audited visual recovery reference crop metadata is invalid.")
                        break
                    crop_name = _basename(
                        plan.get("reference_crop"),
                        "audited_visual_recovery.reference_crop",
                    )
                    if crop_name is None or not (root / "audited_visual_reference_crops" / crop_name).is_file():
                        reasons.append("An audited visual recovery reference crop is missing from the artifact.")
                        break
                if reasons and reasons[-1].startswith("An audited visual recovery"):
                    break
        audited_rows = audited_visual.get("images")
        if not isinstance(audited_rows, list) or len(audited_rows) != EXPECTED_IMAGE_COUNT:
            reasons.append("Audited visual recovery summary has fewer than 20 image rows.")
        else:
            for row in audited_rows:
                if not isinstance(row, dict) or row.get("status") in {"failed", "rejected"}:
                    reasons.append("At least one audited visual recovery target failed or was rejected.")
                    break
                if row.get("count_targets_used_as_geometry") is not False:
                    reasons.append("An audited visual recovery row used human count targets as geometry.")
                    break
                if row.get("triggered") is True:
                    selected = row.get("selected_class_ids")
                    if (
                        not isinstance(selected, list)
                        or not selected
                        or any(type(class_id) is not int or class_id not in AUDITED_VISUAL_RECOVERY_CLASS_IDS for class_id in selected)
                    ):
                        reasons.append("A triggered audited visual recovery row has invalid classes.")
                        break
                    if row.get("minimum_reference_support") != AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT:
                        reasons.append("A triggered audited visual recovery row has the wrong reference consensus threshold.")
                        break

    diagnostic_manifest = diagnostics.get("run_manifest")
    if isinstance(diagnostic_manifest, dict):
        diagnostic_hash = diagnostic_manifest.get("run_manifest_sha256")
        if diagnostic_hash is not None and diagnostic_hash != _sha256(run_manifest_path):
            reasons.append("generation diagnostics are bound to a different run manifest.")

    return list(dict.fromkeys(reasons))


def _basename(value: Any, field: str) -> str | None:
    """Extract a filename from Kaggle's absolute paths without trusting them."""

    if value is None:
        return None
    if not isinstance(value, str) or not value.strip():
        raise ReviewArtifactError(f"{field} must be a non-empty path string.")
    normalized = value.replace("\\", "/")
    name = posixpath.basename(normalized.rstrip("/"))
    if not name or name in {".", ".."}:
        raise ReviewArtifactError(f"{field} does not contain a filename.")
    return name


def _safe_relative(directory: str, filename: str) -> str:
    """Return the only paths the page and exported JSON are allowed to expose."""

    if Path(filename).name != filename or filename in {".", ".."}:
        raise ReviewArtifactError(f"Unsafe artifact filename: {filename!r}")
    return f"{directory}/{filename}"


def _validate_rows(manifest: dict[str, Any], path: Path, label: str) -> list[dict[str, Any]]:
    rows = manifest.get("images")
    if not isinstance(rows, list) or len(rows) != EXPECTED_IMAGE_COUNT:
        count = len(rows) if isinstance(rows, list) else 0
        raise ReviewArtifactError(
            f"{path.name} must contain exactly {EXPECTED_IMAGE_COUNT} {label} rows; found {count}."
        )
    if any(not isinstance(row, dict) for row in rows):
        raise ReviewArtifactError(f"{path.name} contains a non-object image row.")
    names = [row.get("image_name") for row in rows]
    if any(not isinstance(name, str) or not name.strip() for name in names):
        raise ReviewArtifactError(f"{path.name} contains an image row without image_name.")
    if len({str(name) for name in names}) != EXPECTED_IMAGE_COUNT:
        raise ReviewArtifactError(f"{path.name} contains duplicate image names.")
    stems = [Path(str(name)).stem.casefold() for name in names]
    if len(set(stems)) != EXPECTED_IMAGE_COUNT:
        raise ReviewArtifactError(f"{path.name} contains image names with duplicate stems.")
    return rows


def _file_map(directory: Path, suffixes: set[str], label: str) -> dict[str, Path]:
    if not directory.is_dir():
        raise ReviewArtifactError(f"Missing {label} directory: {directory}")
    files = sorted(
        path for path in directory.iterdir() if path.is_file() and path.suffix.casefold() in suffixes
    )
    if len(files) != EXPECTED_IMAGE_COUNT:
        raise ReviewArtifactError(
            f"{label} must contain exactly {EXPECTED_IMAGE_COUNT} files; found {len(files)}."
        )
    names = [path.name for path in files]
    if len(set(names)) != EXPECTED_IMAGE_COUNT:
        raise ReviewArtifactError(f"{label} contains duplicate filenames.")
    return {path.name: path for path in files}


def _manual_reference_map() -> dict[str, Path]:
    """Find AnyLabeling/LabelMe references without treating them as approval.

    The six files currently saved beside the fixed source images are useful
    visual references for the proposal reviewer.  They are deliberately kept
    separate from ``labels/`` and ``approval_manifest.json``: merely finding a
    JSON file must never authorize training.
    """

    if not MANUAL_REFERENCE_DIR.is_dir():
        return {}
    references: dict[str, Path] = {}
    for path in MANUAL_REFERENCE_DIR.glob("*.json"):
        try:
            payload = _read_object(path)
        except ReviewArtifactError:
            continue
        image_name = payload.get("imagePath")
        if not isinstance(image_name, str) or not image_name.strip():
            image_name = path.stem
        image_name = Path(image_name.replace("\\", "/")).name
        if Path(image_name).suffix.casefold() not in IMAGE_SUFFIXES:
            image_name = f"{Path(image_name).stem}.jpg"
        references[image_name.casefold()] = path
    return references


def _row_path(row: dict[str, Any], keys: tuple[str, ...], fallback: str, field: str) -> str:
    for key in keys:
        if key in row and row[key] is not None:
            name = _basename(row[key], f"{field}.{key}")
            if name is not None:
                return name
    return fallback


def _proposal_summary(source_row: dict[str, Any], image_name: str) -> tuple[tuple[int, ...], int, int]:
    """Derive visible proposal totals from the immutable per-instance audit rows.

    The page calls these *proposal* counts because neither model output nor a
    structurally valid polygon is human truth.  Requiring every outcome to use
    the declared class order also prevents a changed model vocabulary from
    being silently rendered under the wrong color or label.
    """

    outcomes = source_row.get("sam3_instance_outcomes")
    if not isinstance(outcomes, list):
        raise ReviewArtifactError(f"{image_name} has no per-instance proposal outcomes.")
    expected_total = source_row.get("instance_count")
    if not isinstance(expected_total, int) or expected_total < 0 or len(outcomes) != expected_total:
        raise ReviewArtifactError(f"{image_name} proposal outcome count is inconsistent.")

    counts: Counter[int] = Counter()
    sam_success_count = 0
    fallback_count = 0
    for outcome in outcomes:
        if not isinstance(outcome, dict):
            raise ReviewArtifactError(f"{image_name} contains a non-object proposal outcome.")
        class_id = outcome.get("class_id")
        if not isinstance(class_id, int) or not 0 <= class_id < len(DISPLAY_CLASS_NAMES):
            raise ReviewArtifactError(f"{image_name} contains an unsupported class ID: {class_id!r}.")
        class_name = outcome.get("class_name")
        if class_name != DISPLAY_CLASS_NAMES[class_id]:
            raise ReviewArtifactError(
                f"{image_name} class {class_id} is {class_name!r}, expected {DISPLAY_CLASS_NAMES[class_id]!r}."
            )
        counts[class_id] += 1
        if outcome.get("status") == "success":
            sam_success_count += 1
        else:
            fallback_count += 1
    if sam_success_count + fallback_count != expected_total:
        raise ReviewArtifactError(f"{image_name} refinement totals are inconsistent.")
    return tuple(counts.get(class_id, 0) for class_id in range(len(DISPLAY_CLASS_NAMES))), sam_success_count, fallback_count


def _validate_polygon_file(
    path: Path,
    source_row: dict[str, Any],
    image_name: str,
    expected_class_counts: tuple[int, ...],
    declared_class_count: int,
) -> None:
    """Prove that the downloaded polygon file still matches its manifest row.

    ``polygon_audit_passed`` is only a claim stored in JSON.  The local review
    page must also inspect the actual downloaded YOLO-seg file so a missing,
    truncated, or replaced polygon cannot be presented as review-ready.
    """

    expected_total = source_row.get("instance_count")
    emitted_total = source_row.get("emitted_polygon_row_count")
    if type(expected_total) is not int or expected_total < 0:
        raise ReviewArtifactError(f"{image_name} instance_count must be a non-negative integer.")
    if type(emitted_total) is not int or emitted_total < 0:
        raise ReviewArtifactError(
            f"{image_name} emitted_polygon_row_count must be a non-negative integer."
        )
    if emitted_total != expected_total:
        raise ReviewArtifactError(
            f"{image_name} emitted polygon count does not match instance_count."
        )
    try:
        raw_lines = path.read_text(encoding="utf-8").splitlines()
    except (OSError, UnicodeError) as error:
        raise ReviewArtifactError(f"Could not read polygon file {path}: {error}") from error
    lines = [(line_number, line.strip()) for line_number, line in enumerate(raw_lines, 1) if line.strip()]
    if len(lines) != expected_total:
        raise ReviewArtifactError(
            f"{image_name} polygon file has {len(lines)} rows; expected {expected_total}."
        )

    parsed_counts: Counter[int] = Counter()
    for line_number, line in lines:
        tokens = line.split()
        # A YOLO segmentation row is one class ID followed by at least three
        # x/y vertex pairs.  An odd coordinate count cannot form pairs.
        if len(tokens) < 7 or (len(tokens) - 1) % 2:
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} is not a valid YOLO segmentation row."
            )
        try:
            class_id = int(tokens[0])
        except ValueError as error:
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} has an invalid class ID."
            ) from error
        if not 0 <= class_id < declared_class_count:
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} has unsupported class ID {class_id}."
            )
        try:
            coordinates = [float(value) for value in tokens[1:]]
        except ValueError as error:
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} has a non-numeric coordinate."
            ) from error
        if any(not math.isfinite(value) for value in coordinates):
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} has a non-finite coordinate."
            )
        if any(value < 0.0 or value > 1.0 for value in coordinates):
            raise ReviewArtifactError(
                f"{image_name} polygon row {line_number} has a coordinate outside [0, 1]."
            )
        parsed_counts[class_id] += 1

    parsed_class_counts = tuple(
        parsed_counts.get(class_id, 0) for class_id in range(len(DISPLAY_CLASS_NAMES))
    )
    if parsed_class_counts != expected_class_counts:
        raise ReviewArtifactError(
            f"{image_name} polygon class counts do not match the per-instance proposal outcomes."
        )


def _ocr_summary(source_row: dict[str, Any]) -> tuple[int | None, tuple[str, ...], str]:
    """Read optional OCR evidence without treating it as an approval decision."""

    raw_count = source_row.get("ocr_sticker_count")
    raw_texts = source_row.get("ocr_sticker_texts", [])
    raw_status = source_row.get("ocr_status", "unavailable")
    if not isinstance(raw_status, str) or not raw_status:
        raise ReviewArtifactError("ocr_status must be a non-empty string.")
    if not isinstance(raw_texts, list) or any(not isinstance(value, str) for value in raw_texts):
        raise ReviewArtifactError("ocr_sticker_texts must be a list of strings.")
    if raw_count is None:
        if raw_status == "available":
            raise ReviewArtifactError(
                "ocr_status=available requires a non-negative ocr_sticker_count."
            )
        return None, tuple(raw_texts), raw_status
    if type(raw_count) is not int or raw_count < 0:
        raise ReviewArtifactError("ocr_sticker_count must be a non-negative integer.")
    if raw_status == "available" and len(raw_texts) != raw_count:
        raise ReviewArtifactError(
            "ocr_status=available requires one recognized sticker text per counted sticker."
        )
    return raw_count, tuple(raw_texts), raw_status


def _audited_visual_recovery_row(
    source_row: dict[str, Any],
    image_name: str,
) -> tuple[bool, tuple[str, ...], int, tuple[str, ...], list[str]]:
    """Read one audited visual evidence row without letting it look clean.

    The rendered contact sheet itself carries the crop thumbnails.  These
    compact fields let the reviewer see whether those thumbnails were actually
    used for this target, how many consensus proposals survived, and whether a
    required class still needs special attention.
    """

    reasons: list[str] = []
    triggered = source_row.get("audited_visual_recovery_triggered")
    if type(triggered) is not bool:
        reasons.append("audited visual recovery status is missing")
        triggered = False
    selected = source_row.get("audited_visual_recovery_selected_class_names")
    if not isinstance(selected, list) or any(not isinstance(value, str) or not value for value in selected):
        reasons.append("audited visual recovery class evidence is invalid")
        selected_names: tuple[str, ...] = ()
    else:
        selected_names = tuple(selected)
    proposal_count = source_row.get("audited_visual_recovery_proposal_count")
    if type(proposal_count) is not int or proposal_count < 0:
        reasons.append("audited visual recovery proposal count is invalid")
        proposal_count = 0
    unresolved = source_row.get("audited_visual_recovery_unresolved_class_names")
    if not isinstance(unresolved, list) or any(
        not isinstance(value, str) or not value for value in unresolved
    ):
        reasons.append("audited visual recovery unresolved-class evidence is invalid")
        unresolved_names: tuple[str, ...] = ()
    else:
        unresolved_names = tuple(unresolved)
    if triggered and not selected_names:
        reasons.append("triggered audited visual recovery has no selected class")
    if not triggered and (selected_names or proposal_count or unresolved_names):
        reasons.append("untriggered audited visual recovery contains proposal evidence")
    return bool(triggered), selected_names, proposal_count, unresolved_names, reasons


def validate_artifact(artifact_dir: Path) -> ReviewArtifact:
    """Validate a quarantine extraction and return immutable render metadata."""

    root = Path(artifact_dir).expanduser().resolve()
    if not root.is_dir():
        raise ReviewArtifactError(f"Artifact directory does not exist: {root}")
    run_path = root / "run_manifest.json"
    decision_path = root / "review_decision_manifest.json"
    run_manifest = _read_object(run_path)
    decision_manifest = _read_object(decision_path)
    class_names = run_manifest.get("class_names")
    # V27 used six classes.  It remains inspectable while visibly showing a
    # zero packet count, but every replacement candidate must declare all
    # seven.  Unknown orders are rejected instead of guessed.
    valid_class_orders = [list(DISPLAY_CLASS_NAMES[:-1]), list(DISPLAY_CLASS_NAMES)]
    if class_names not in valid_class_orders:
        raise ReviewArtifactError("run_manifest class_names do not match the supported review order.")
    for manifest, path in ((run_manifest, run_path), (decision_manifest, decision_path)):
        _require_false(manifest, "training_authorized", path)
        _require_false(manifest, "promotion_authorized", path)
        _require_release_gate_closed(manifest, path)

    run_rows = _validate_rows(run_manifest, run_path, "run image")
    decision_rows = _validate_rows(decision_manifest, decision_path, "decision image")
    decision_by_name = {str(row["image_name"]): row for row in decision_rows}
    run_names = {str(row["image_name"]) for row in run_rows}
    if run_names != set(decision_by_name):
        raise ReviewArtifactError("run_manifest and review_decision_manifest image names differ.")

    sheet_files = _file_map(root / "final_contact_sheets", IMAGE_SUFFIXES, "contact sheets")
    polygon_files = _file_map(root / "sam3_refined_polygons", {".txt"}, "polygon files")
    manual_references = _manual_reference_map()
    rows: list[ReviewRow] = []
    artifact_readiness_reasons: list[str] = []
    review_hold_reason = _read_review_hold(root, run_path)
    if review_hold_reason is not None:
        artifact_readiness_reasons.append(
            f"Explicit human-quality hold: {review_hold_reason}"
        )
    artifact_readiness_reasons.extend(
        _review_evidence_reasons(root, run_manifest, run_path)
    )
    if class_names != list(DISPLAY_CLASS_NAMES):
        artifact_readiness_reasons.append("The run does not include the black/white soya packet class.")
    for source_row in run_rows:
        image_name = str(source_row["image_name"])
        stem = Path(image_name).stem
        decision_row = decision_by_name[image_name]
        sheet_name = _row_path(
            source_row,
            ("contact_sheet", "contact_sheet_path", "final_contact_sheet"),
            f"{stem}__review.jpg",
            "run image",
        )
        polygon_name = _row_path(
            source_row,
            ("proposal_polygon", "polygon", "polygon_path", "sam3_refined_polygon"),
            f"{stem}.txt",
            "run image",
        )
        if sheet_name not in sheet_files:
            raise ReviewArtifactError(f"Missing contact sheet for {image_name}: {sheet_name}")
        if polygon_name not in polygon_files:
            raise ReviewArtifactError(f"Missing polygon file for {image_name}: {polygon_name}")
        expected_stem = Path(image_name).stem.casefold()
        sheet_stem = Path(sheet_name).stem.casefold()
        polygon_stem = Path(polygon_name).stem.casefold()
        if sheet_stem.removesuffix("__review") != expected_stem:
            raise ReviewArtifactError(
                f"Contact sheet {sheet_name} does not belong to image {image_name}."
            )
        if polygon_stem != expected_stem:
            raise ReviewArtifactError(
                f"Polygon file {polygon_name} does not belong to image {image_name}."
            )
        decision = decision_row.get("decision", "pending")
        if decision not in VALID_DECISIONS:
            raise ReviewArtifactError(f"Invalid decision for {image_name}: {decision!r}")
        class_counts, sam_success_count, fallback_count = _proposal_summary(source_row, image_name)
        _validate_polygon_file(
            polygon_files[polygon_name],
            source_row,
            image_name,
            class_counts,
            len(class_names),
        )
        ocr_sticker_count, ocr_sticker_texts, ocr_status = _ocr_summary(source_row)
        previous_review = source_row.get("previous_review")
        if not isinstance(previous_review, dict):
            previous_review = {}
        correction_usefulness = source_row.get("correction_guided_proposal_usefulness")
        if not isinstance(correction_usefulness, dict):
            correction_usefulness = {}
        (
            audited_visual_recovery_triggered,
            audited_visual_recovery_selected_class_names,
            audited_visual_recovery_proposal_count,
            audited_visual_recovery_unresolved_class_names,
            audited_visual_recovery_reasons,
        ) = _audited_visual_recovery_row(source_row, image_name)
        manual_reference = manual_references.get(image_name.casefold())
        row_readiness_reasons: list[str] = []
        if sum(class_counts) == 0:
            row_readiness_reasons.append("zero proposals")
        if source_row.get("polygon_audit_passed") is not True:
            row_readiness_reasons.append("polygon audit not passed")
        if ocr_status != "available":
            row_readiness_reasons.append("OCR sticker check unavailable")
        row_readiness_reasons.extend(audited_visual_recovery_reasons)
        if row_readiness_reasons:
            artifact_readiness_reasons.append(
                f"{image_name}: {', '.join(row_readiness_reasons)}."
            )
        rows.append(
            ReviewRow(
                image_name=image_name,
                contact_sheet=_safe_relative("final_contact_sheets", sheet_name),
                polygon=_safe_relative("sam3_refined_polygons", polygon_name),
                class_counts=class_counts,
                sam_success_count=sam_success_count,
                fallback_count=fallback_count,
                ocr_sticker_count=ocr_sticker_count,
                ocr_sticker_texts=ocr_sticker_texts,
                ocr_status=ocr_status,
                readiness_reasons=tuple(row_readiness_reasons),
                manual_reference_available=manual_reference is not None,
                manual_reference_name=manual_reference.name if manual_reference is not None else None,
                decision=str(decision),
                reviewer=str(decision_row.get("reviewer") or ""),
                notes=str(decision_row.get("notes") or ""),
                previous_review=dict(previous_review),
                correction_usefulness=dict(correction_usefulness),
                correction_visible_count=int(
                    source_row.get("correction_guided_visible_count", 0)
                ),
                correction_suppressed_count=int(
                    source_row.get("correction_guided_suppressed_count", 0)
                ),
                audited_visual_recovery_triggered=(
                    audited_visual_recovery_triggered
                ),
                audited_visual_recovery_selected_class_names=(
                    audited_visual_recovery_selected_class_names
                ),
                audited_visual_recovery_proposal_count=(
                    audited_visual_recovery_proposal_count
                ),
                audited_visual_recovery_unresolved_class_names=(
                    audited_visual_recovery_unresolved_class_names
                ),
            )
        )

    used_sheets = {row.contact_sheet.rsplit("/", 1)[-1] for row in rows}
    used_polygons = {row.polygon.rsplit("/", 1)[-1] for row in rows}
    if used_sheets != set(sheet_files) or used_polygons != set(polygon_files):
        raise ReviewArtifactError("Contact sheets or polygon files do not map one-to-one to the 20 images.")
    return ReviewArtifact(
        root,
        _sha256(run_path),
        tuple(rows),
        not artifact_readiness_reasons,
        tuple(artifact_readiness_reasons),
        sum(row.manual_reference_available for row in rows),
    )


def _html_path(output_path: Path, artifact: ReviewArtifact, relative_path: str) -> str:
    target = artifact.root / Path(relative_path)
    return Path(os.path.relpath(target, start=output_path.parent)).as_posix()


def _json_for_page(artifact: ReviewArtifact, output_path: Path) -> dict[str, Any]:
    return {
        "source_run_manifest": "run_manifest.json",
        "source_run_manifest_sha256": artifact.source_run_manifest_sha256,
        "review_ready": artifact.review_ready,
        "readiness_reasons": list(artifact.readiness_reasons),
        "manual_reference_count": artifact.manual_reference_count,
        "rows": [
            {
                "image_name": row.image_name,
                # The page may be written outside the extracted artifact.  Use
                # paths relative to the HTML file so both the card and its
                # full-size dialog resolve the same real asset.
                "contact_sheet": _html_path(output_path, artifact, row.contact_sheet),
                "polygon": _html_path(output_path, artifact, row.polygon),
                "class_counts": list(row.class_counts),
                "sam_success_count": row.sam_success_count,
                "fallback_count": row.fallback_count,
                "ocr_sticker_count": row.ocr_sticker_count,
                "ocr_sticker_texts": list(row.ocr_sticker_texts),
                "ocr_status": row.ocr_status,
                "readiness_reasons": list(row.readiness_reasons),
                # This is only the automated structural/proposal check for one
                # image.  It deliberately does not mean that a person has
                # inspected or approved the boundaries.
                "proposal_ready": not row.readiness_reasons,
                "manual_reference_available": row.manual_reference_available,
                "manual_reference_name": row.manual_reference_name,
                "decision": row.decision,
                "reviewer": row.reviewer,
                "notes": row.notes,
                "previous_review": row.previous_review,
                "correction_usefulness": row.correction_usefulness,
                "correction_visible_count": row.correction_visible_count,
                "correction_suppressed_count": row.correction_suppressed_count,
                "audited_visual_recovery_triggered": row.audited_visual_recovery_triggered,
                "audited_visual_recovery_selected_class_names": list(
                    row.audited_visual_recovery_selected_class_names
                ),
                "audited_visual_recovery_proposal_count": (
                    row.audited_visual_recovery_proposal_count
                ),
                "audited_visual_recovery_unresolved_class_names": list(
                    row.audited_visual_recovery_unresolved_class_names
                ),
            }
            for row in artifact.rows
        ],
        "training_authorized": False,
        "promotion_authorized": False,
        "release_gate": {
            "metric": "assertion_pass_rate",
            "minimum_assertion_pass_rate": 0.95,
            "current_assertion_pass_rate": None,
            "passed": False,
        },
    }


def _render_html(artifact: ReviewArtifact, output_path: Path) -> str:
    page_data = _json_for_page(artifact, output_path)
    # JSON is embedded inside a script element.  Escape ``<`` so a filename or
    # note from an untrusted extraction cannot terminate that element early.
    serialized = json.dumps(page_data, ensure_ascii=True, separators=(",", ":")).replace("<", "\\u003c")
    readiness_heading = (
        "READY FOR YOUR REVIEW — no image has been human-verified yet"
        if artifact.review_ready
        else "NOT APPROVABLE — all 20 images are present, but this proposal run has been withdrawn"
    )
    readiness_class = "ready" if artifact.review_ready else "blocked"
    if artifact.review_ready:
        readiness_detail = (
            "All 20 image cards and contact sheets are present and passed the automated completeness checks. "
            f"{artifact.manual_reference_count} of 20 images also have saved AnyLabeling reference annotations. "
            "Those references are not approved labels. You still need to inspect every boundary and choose "
            "Pass or Reject; automated readiness and reference availability are not human verification."
        )
    else:
        reasons = "".join(
            f"<li>{html.escape(reason)}</li>" for reason in artifact.readiness_reasons
        )
        readiness_detail = (
            "All 20 image cards and contact sheets are present. Pass is disabled because this run failed "
            "the proposal quality gate. Automated proposal availability and human-verification status are "
            f"shown separately on every image. {artifact.manual_reference_count} of 20 images have saved "
            "AnyLabeling reference annotations, but none is an approved training label; do not approve these labels."
            f"<ul>{reasons}</ul>"
        )
    proposal_ready_count = sum(not row.readiness_reasons for row in artifact.rows)
    manual_reference_count = artifact.manual_reference_count
    cards: list[str] = []
    for index, row in enumerate(artifact.rows):
        image_name = html.escape(row.image_name, quote=True)
        src = html.escape(_html_path(output_path, artifact, row.contact_sheet), quote=True)
        count_rows = []
        for class_id, (class_name, color, count) in enumerate(
            zip(DISPLAY_CLASS_NAMES, DISPLAY_CLASS_COLORS, row.class_counts)
        ):
            advisory = " <span class=\"advisory\">advisory</span>" if class_id == 6 else ""
            count_rows.append(
                f'<tr><th><span class="swatch" style="--swatch:{color}"></span>{html.escape(class_name)}</th>'
                f'<td>{count}{advisory}</td></tr>'
            )
        count_rows_html = "".join(count_rows)
        zero_warning = (
            '<p class="zero-warning">Not review-ready: this image has zero proposals. Do not pass it.</p>'
            if sum(row.class_counts) == 0 else ""
        )
        fallback_warning = (
            f'<p class="fallback-warning">{row.fallback_count} boundary/boundaries are model fallbacks; inspect them closely.</p>'
            if row.fallback_count else ""
        )
        row_blocked = bool(row.readiness_reasons) or not artifact.review_ready
        pass_disabled = " disabled" if row_blocked else ""
        proposal_status_class = "proposal-ready" if not row.readiness_reasons else "proposal-blocked"
        proposal_status_text = (
            "Automated proposal: READY"
            if not row.readiness_reasons
            else "Automated proposal: NOT READY"
        )
        approval_status_class = "approval-enabled" if not row_blocked else "approval-disabled"
        approval_status_text = (
            "Pass approval: AVAILABLE AFTER INSPECTION"
            if not row_blocked
            else "Pass approval: DISABLED"
        )
        manual_status_class = "manual-reference" if row.manual_reference_available else "manual-missing"
        manual_status_text = (
            "Manual reference: AVAILABLE (not approved)"
            if row.manual_reference_available
            else "Manual reference: NOT AVAILABLE"
        )
        row_readiness_html = (
            '<p class="zero-warning">Not review-ready: '
            + html.escape(", ".join(row.readiness_reasons))
            + ". Do not pass this image.</p>"
            if row.readiness_reasons else ""
        )
        if row.ocr_sticker_count is None:
            ocr_html = '<p class="ocr-summary">OCR sticker count: unavailable in this run.</p>'
        else:
            disagreement = " disagreement" if row.ocr_sticker_count != row.class_counts[0] else ""
            texts = ", ".join(row.ocr_sticker_texts) or "no readable names"
            ocr_html = (
                f'<p class="ocr-summary{disagreement}"><strong>OCR sticker count: {row.ocr_sticker_count}</strong> '
                f'(segmentation bowls: {row.class_counts[0]})<br><span>{html.escape(texts)}</span></p>'
            )
        previous_counts = row.previous_review.get("requested_counts", {})
        previous_advisories = set(row.previous_review.get("advisory_classes", []))
        previous_rows: list[str] = []
        if isinstance(previous_counts, dict):
            for class_name in DISPLAY_CLASS_NAMES:
                if class_name not in previous_counts:
                    continue
                estimate = previous_counts[class_name]
                current = row.class_counts[DISPLAY_CLASS_NAMES.index(class_name)]
                difference = current - int(estimate)
                mode = (
                    "advisory"
                    if class_name in previous_advisories
                    or class_name == DISPLAY_CLASS_NAMES[-1]
                    else "diagnostic"
                )
                previous_rows.append(
                    f"<tr><th>{html.escape(class_name)}</th>"
                    f"<td>{int(estimate)}</td><td>{current}</td>"
                    f"<td>{difference:+d}</td><td>{mode}</td></tr>"
                )
        correction_html = ""
        if previous_rows:
            correction_html = (
                '<section class="correction-summary" aria-label="Prior human notes">'
                "<h3>Prior human notes (diagnostic; not labels)</h3>"
                "<table><thead><tr><th>Class</th><th>Human estimate</th>"
                "<th>Current proposal</th><th>Diff</th><th>Mode</th></tr></thead>"
                f"<tbody>{''.join(previous_rows)}</tbody></table>"
                f"<p>Correction candidates visible: {row.correction_visible_count}; "
                f"suppressed alternates retained for audit: {row.correction_suppressed_count}. "
                "Counts never create or delete polygons.</p></section>"
            )
        elif row.previous_review.get("notes"):
            correction_html = (
                '<section class="correction-summary"><h3>Prior human note</h3>'
                f"<p>{html.escape(str(row.previous_review['notes']))}</p>"
                "</section>"
            )
        audited_visual_html = ""
        if row.audited_visual_recovery_triggered:
            selected_classes = ", ".join(
                html.escape(value)
                for value in row.audited_visual_recovery_selected_class_names
            )
            unresolved_classes = ", ".join(
                html.escape(value)
                for value in row.audited_visual_recovery_unresolved_class_names
            )
            unresolved_detail = (
                f"<p class=\"audited-visual-unresolved\">Still unresolved after two-reference consensus: {unresolved_classes}.</p>"
                if unresolved_classes
                else ""
            )
            audited_visual_html = (
                '<section class="audited-visual-summary" aria-label="Audited visual prompt evidence">'
                "<h3>Audited visual prompt evidence</h3>"
                f"<p>Triggered for: {selected_classes}. Consensus proposals retained: "
                f"{row.audited_visual_recovery_proposal_count}.</p>"
                "<p>The contact sheet includes the three human-audited reference crops used for each triggered class. "
                "Counts did not create or delete geometry.</p>"
                f"{unresolved_detail}</section>"
            )
        cards.append(
            f'''<article class="review-card" data-index="{index}" data-image-name="{image_name}">
  <h2>{index + 1}. {image_name}</h2>
  <div class="per-image-status" aria-label="Readiness and human-verification status for {image_name}">
    <span class="status-pill {proposal_status_class}" data-proposal-status="{index}">{proposal_status_text}</span>
    <span class="status-pill {manual_status_class}" data-manual-reference-status="{index}">{manual_status_text}</span>
    <span class="status-pill human-pending" data-human-status="{index}">Human verification: PENDING</span>
    <span class="status-pill {approval_status_class}">{approval_status_text}</span>
  </div>
  <button type="button" class="image-button" data-zoom="{index}" aria-label="Open full-size image for {image_name}">
    <!--
      Every contact sheet is intentionally loaded eagerly.  This page is the
      human-review gate, so a card must be visibly inspectable as soon as the
      page opens; browser lazy-loading otherwise leaves later cards blank until
      the reviewer happens to scroll over them.
    -->
    <img src="{src}" alt="Contact sheet for {image_name}" loading="eager">
  </button>
  <button type="button" class="zoom" data-zoom="{index}">Open full size / zoom</button>
  <section class="proposal-summary" aria-label="Proposal counts for {image_name}">
    <h3>Proposal counts</h3>
    <table><tbody>{count_rows_html}</tbody></table>
    <p class="refinement">SAM 3.1 masks: {row.sam_success_count} · Fallback boundaries: {row.fallback_count}</p>
    {ocr_html}
    {correction_html}
    {audited_visual_html}
    {zero_warning}{row_readiness_html}
    {fallback_warning}
  </section>
  <div class="decision" role="group" aria-label="Decision for {image_name}">
    <label><input type="radio" name="decision-{index}" value="pass"{pass_disabled} data-pass-input="{index}" data-artifact-disabled="{'true' if row_blocked else 'false'}"> Pass</label>
    <label><input type="radio" name="decision-{index}" value="reject"> Reject</label>
    <button type="button" class="clear" data-clear="{index}">Pending / clear</button>
  </div>
  <label class="notes">Notes
    <textarea data-notes="{index}" rows="3" placeholder="What needs correction?"></textarea>
  </label>
</article>'''
        )
    cards_html = "\n".join(cards)
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quarantined 20-image review</title>
<style>
  :root {{ color-scheme: dark; font-family: system-ui, sans-serif; }}
  body {{ margin: 0; background: #111827; color: #f3f4f6; }}
  header {{ position: sticky; top: 0; z-index: 2; padding: 1rem; background: #1f2937; border-bottom: 1px solid #374151; }}
  h1 {{ margin: 0 0 .5rem; font-size: 1.35rem; }}
  .warning {{ color: #fbbf24; margin: .4rem 0; }}
  .toolbar {{ display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }}
  input[type=text], textarea {{ width: 100%; box-sizing: border-box; padding: .5rem; background: #030712; color: #f9fafb; border: 1px solid #4b5563; border-radius: .35rem; }}
  #reviewer {{ max-width: 22rem; }}
  button {{ cursor: pointer; border: 1px solid #6b7280; border-radius: .35rem; padding: .5rem .75rem; color: #fff; background: #374151; }}
  button.primary {{ background: #047857; border-color: #10b981; font-weight: 700; }}
  main {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 680px), 1fr)); gap: 1rem; padding: 1rem; }}
  .review-card {{ background: #1f2937; border: 1px solid #374151; border-radius: .5rem; padding: .75rem; }}
  .review-card h2 {{ font-size: .95rem; overflow-wrap: anywhere; margin: 0 0 .5rem; }}
  .per-image-status {{ display: flex; flex-wrap: wrap; gap: .4rem; margin: 0 0 .65rem; }}
  .status-pill {{ display: inline-block; padding: .3rem .5rem; border: 1px solid; border-radius: 999px; font-size: .78rem; font-weight: 800; letter-spacing: .01em; }}
  .proposal-ready {{ color: #d1fae5; background: #064e3b; border-color: #34d399; }}
  .proposal-blocked, .approval-disabled, .human-reject {{ color: #fee2e2; background: #7f1d1d; border-color: #f87171; }}
  .approval-enabled, .human-pass {{ color: #d1fae5; background: #065f46; border-color: #6ee7b7; }}
  .human-pending {{ color: #fef3c7; background: #78350f; border-color: #fbbf24; }}
  .manual-reference {{ color: #dbeafe; background: #1e3a8a; border-color: #60a5fa; }}
  .manual-missing {{ color: #e5e7eb; background: #374151; border-color: #9ca3af; }}
  .image-button {{ display: block; width: 100%; padding: 0; border: 0; background: #030712; }}
  .review-card img {{ display: block; width: 100%; height: auto; background: #030712; border-radius: .25rem; }}
  .zoom {{ margin-top: .5rem; font-weight: 700; }}
  .proposal-summary {{ margin-top: .75rem; padding: .75rem; background: #111827; border: 1px solid #4b5563; border-radius: .4rem; }}
  .proposal-summary h3 {{ margin: 0 0 .5rem; font-size: 1.1rem; }}
  table {{ width: 100%; border-collapse: collapse; font-size: 1rem; }}
  th, td {{ padding: .42rem .35rem; border-bottom: 1px solid #374151; text-align: left; }}
  td {{ width: 8rem; font-size: 1.15rem; font-weight: 800; }}
  .swatch {{ display: inline-block; width: 1rem; height: 1rem; margin-right: .55rem; vertical-align: -.12rem; border: 2px solid #fff; background: var(--swatch); }}
  .advisory {{ color: #fbbf24; font-size: .72rem; font-weight: 600; }}
  .refinement, .ocr-summary {{ margin: .65rem 0 0; }}
  .correction-summary {{ margin: .7rem 0 0; padding: .65rem; background: #172033; border: 1px solid #64748b; border-radius: .35rem; }}
  .correction-summary h3 {{ margin: 0 0 .45rem; font-size: 1rem; }}
  .correction-summary table {{ font-size: .9rem; }}
  .correction-summary td {{ font-size: .95rem; }}
  .audited-visual-summary {{ margin: .7rem 0 0; padding: .65rem; background: #172554; border: 1px solid #60a5fa; border-radius: .35rem; }}
  .audited-visual-summary h3 {{ margin: 0 0 .45rem; font-size: 1rem; color: #dbeafe; }}
  .audited-visual-summary p {{ margin: .45rem 0 0; }}
  .audited-visual-unresolved {{ color: #fde68a; font-weight: 800; }}
  .ocr-summary span {{ color: #d1d5db; }}
  .ocr-summary.disagreement {{ border-left: 4px solid #f59e0b; padding-left: .6rem; }}
  .zero-warning {{ color: #fff; background: #991b1b; padding: .65rem; font-weight: 800; }}
  .fallback-warning {{ color: #fef3c7; background: #78350f; padding: .65rem; font-weight: 700; }}
  .decision {{ display: flex; gap: .6rem; align-items: center; margin: .7rem 0; flex-wrap: wrap; }}
  .decision label {{ padding: .35rem .5rem; border-radius: .3rem; background: #111827; }}
  .notes {{ display: block; font-size: .85rem; color: #d1d5db; }}
  .notes textarea {{ margin-top: .25rem; }}
  .status {{ font-variant-numeric: tabular-nums; }}
  .readiness {{ margin: .75rem 0; padding: .75rem; border-radius: .4rem; font-weight: 650; }}
  .readiness.ready {{ background: #064e3b; border: 2px solid #34d399; }}
  .readiness.blocked {{ background: #7f1d1d; border: 2px solid #f87171; }}
  .readiness h2 {{ margin: 0 0 .35rem; font-size: 1.05rem; }}
  .readiness p, .readiness ul {{ margin: .35rem 0; }}
  input:disabled {{ cursor: not-allowed; opacity: .45; }}
  dialog {{ width: min(96vw, 1800px); max-width: none; height: 94vh; padding: .75rem; box-sizing: border-box; overflow: auto; background: #111827; color: #f9fafb; border: 2px solid #6b7280; border-radius: .5rem; }}
  dialog::backdrop {{ background: rgba(0, 0, 0, .88); }}
  .dialog-close {{ position: sticky; top: 0; z-index: 2; font-size: 1rem; background: #991b1b; }}
  #zoom-image {{ display: block; width: auto; max-width: none; height: auto; margin-top: .75rem; background: #030712; }}
</style>
</head>
<body>
<header>
  <h1>Quarantined proposals — review all 20 contact sheets</h1>
  <p class="warning">Review only. Training, promotion, and the 95% release gate are permanently closed in this export.</p>
  <section class="readiness {readiness_class}"><h2>{readiness_heading}</h2><p>{readiness_detail}</p></section>
  <div class="toolbar">
    <label for="reviewer">Reviewer (required)</label>
    <input id="reviewer" type="text" required autocomplete="name" placeholder="Your name">
    <span id="progress" class="status">0 / 20 reviewed</span>
    <span id="counts" class="status">Pending: 20 · Pass: 0 · Reject: 0</span>
    <span id="contact-sheets-generated" class="status">Contact sheets generated: 20 / 20</span>
    <span id="contact-sheets-loaded" class="status">Contact sheets loaded in browser: 0 / 20</span>
    <span id="pass-readiness" class="status">Pass controls: waiting for all 20 images to load</span>
    <span id="proposal-readiness" class="status">Automated proposals ready: {proposal_ready_count} / 20</span>
    <span id="manual-references" class="status">Manual reference annotations available: {manual_reference_count} / 20 (not approvals)</span>
    <span id="human-verification" class="status">Human verification: 0 / 20 decided</span>
    <button id="download" class="primary" type="button">Download review_decisions.local.json</button>
    <button id="reset" type="button">Reset local review</button>
  </div>
</header>
<main>
{cards_html}
</main>
<dialog id="zoom-dialog">
  <form method="dialog"><button type="submit" class="dialog-close">Close</button></form>
  <h2 id="zoom-title"></h2>
  <img id="zoom-image" alt="">
</dialog>
<script>
(() => {{
  'use strict';
  const artifact = {serialized};
  const storageKey = `sushi-quarantine-review:${{artifact.source_run_manifest_sha256}}`;
  const valid = new Set(['pending', 'pass', 'reject']);
  const dialog = document.getElementById('zoom-dialog');
  const zoomImage = document.getElementById('zoom-image');
  const zoomTitle = document.getElementById('zoom-title');
  const reviewImages = Array.from(document.querySelectorAll('.review-card img'));
  const loadedReviewImages = new Set();
  const failedReviewImages = new Set();
  // A reviewer must never be able to pass a card whose contact sheet failed
  // to load.  The server-side artifact gate still controls whether a proposal
  // is eligible at all; this browser-side gate adds the missing last check:
  // the actual pixels must be present in this browser first.
  const updatePassControls = () => {{
    document.querySelectorAll('input[data-pass-input]').forEach(input => {{
      const index = Number(input.dataset.passInput);
      const artifactDisabled = input.dataset.artifactDisabled === 'true';
      input.disabled = artifactDisabled || !loadedReviewImages.has(index);
    }});
    const loaded = loadedReviewImages.size;
    const failed = failedReviewImages.size;
    const suffix = failed ? ` · Failed: ${{failed}}` : '';
    document.getElementById('pass-readiness').textContent =
      loaded === 20 && failed === 0
        ? 'Pass controls: enabled after all 20 images loaded'
        : `Pass controls: waiting for images (${{loaded}} / 20 loaded${{suffix}})`;
  }};
  const renderImageLoadSummary = () => {{
    const suffix = failedReviewImages.size ? ` · Failed: ${{failedReviewImages.size}}` : '';
    document.getElementById('contact-sheets-loaded').textContent =
      `Contact sheets loaded in browser: ${{loadedReviewImages.size}} / 20${{suffix}}`;
  }};
  reviewImages.forEach((image, index) => {{
    const recordLoaded = () => {{
      failedReviewImages.delete(index);
      loadedReviewImages.add(index);
      renderImageLoadSummary();
      updatePassControls();
    }};
    const recordFailed = () => {{
      loadedReviewImages.delete(index);
      failedReviewImages.add(index);
      renderImageLoadSummary();
      updatePassControls();
    }};
    image.addEventListener('load', recordLoaded);
    image.addEventListener('error', recordFailed);
    if (image.complete) {{
      if (image.naturalWidth > 0) recordLoaded();
      else recordFailed();
    }}
  }});
  updatePassControls();
  const defaultRows = artifact.rows.map(row => ({{ image_name: row.image_name, contact_sheet: row.contact_sheet, polygon: row.polygon, decision: row.decision, notes: row.notes }}));
  let state = {{ reviewer: '', rows: defaultRows }};
  try {{
    const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
    if (saved && Array.isArray(saved.rows)) {{
      state.reviewer = String(saved.reviewer || '');
      const savedByName = new Map(saved.rows.map(row => [row.image_name, row]));
      state.rows = defaultRows.map(row => {{
        const savedRow = savedByName.get(row.image_name) || {{}};
        return {{ ...row, decision: valid.has(savedRow.decision) ? savedRow.decision : row.decision, notes: String(savedRow.notes || row.notes || '') }};
      }});
    }}
  }} catch (_) {{ /* A private-mode browser may disable localStorage. */ }}
  const persist = () => {{
    try {{ localStorage.setItem(storageKey, JSON.stringify(state)); }} catch (_) {{ /* best effort */ }}
  }};
  const saveCard = index => {{
    const card = document.querySelector(`[data-index="${{index}}"]`);
    const selected = card.querySelector(`input[name="decision-${{index}}"]:checked`);
    state.rows[index].decision = selected ? selected.value : 'pending';
    state.rows[index].notes = card.querySelector(`[data-notes="${{index}}"]`).value;
    persist(); renderSummary();
  }};
  const renderSummary = () => {{
    const counts = state.rows.reduce((result, row) => {{ result[row.decision] += 1; return result; }}, {{ pending: 0, pass: 0, reject: 0 }});
    const decided = 20 - counts.pending;
    document.getElementById('progress').textContent = `${{decided}} / 20 reviewed`;
    document.getElementById('counts').textContent = `Pending: ${{counts.pending}} · Pass: ${{counts.pass}} · Reject: ${{counts.reject}}`;
    document.getElementById('human-verification').textContent = `Human verification: ${{decided}} / 20 decided`;
    state.rows.forEach((row, index) => {{
      const status = document.querySelector(`[data-human-status="${{index}}"]`);
      status.classList.remove('human-pending', 'human-pass', 'human-reject');
      status.classList.add(`human-${{row.decision}}`);
      status.textContent = `Human verification: ${{row.decision.toUpperCase()}}`;
    }});
  }};
  const render = () => {{
    document.getElementById('reviewer').value = state.reviewer;
    state.rows.forEach((row, index) => {{
      const card = document.querySelector(`[data-index="${{index}}"]`);
      const radio = card.querySelector(`input[name="decision-${{index}}"][value="${{row.decision}}"]`);
      if (radio) radio.checked = true;
      card.querySelector(`[data-notes="${{index}}"]`).value = row.notes || '';
    }});
    renderSummary();
  }};
  document.getElementById('reviewer').addEventListener('input', event => {{ state.reviewer = event.target.value; persist(); }});
  document.querySelectorAll('input[type=radio]').forEach(input => input.addEventListener('change', event => saveCard(Number(event.target.closest('.review-card').dataset.index))));
  document.querySelectorAll('textarea[data-notes]').forEach(textarea => textarea.addEventListener('input', event => saveCard(Number(event.target.closest('.review-card').dataset.index))));
  document.querySelectorAll('[data-clear]').forEach(button => button.addEventListener('click', () => {{
    const index = Number(button.dataset.clear); state.rows[index].decision = 'pending';
    document.querySelectorAll(`input[name="decision-${{index}}"]`).forEach(input => input.checked = false);
    persist(); renderSummary();
  }}));
  document.querySelectorAll('[data-zoom]').forEach(button => button.addEventListener('click', () => {{
    const index = Number(button.dataset.zoom);
    zoomImage.src = artifact.rows[index].contact_sheet;
    zoomImage.alt = `Full-size contact sheet for ${{artifact.rows[index].image_name}}`;
    zoomTitle.textContent = artifact.rows[index].image_name;
    dialog.showModal();
  }}));
  document.getElementById('reset').addEventListener('click', () => {{
    if (!window.confirm("Clear this browser's saved review?")) return;
    try {{ localStorage.removeItem(storageKey); }} catch (_) {{}}
    state = {{ reviewer: '', rows: defaultRows.map(row => ({{ ...row, decision: 'pending', notes: '' }})) }};
    render();
  }});
  document.getElementById('download').addEventListener('click', () => {{
    state.reviewer = document.getElementById('reviewer').value.trim();
    if (!state.reviewer) {{
      window.alert('Enter your name in Reviewer before downloading the decisions.');
      document.getElementById('reviewer').focus();
      return;
    }}
    persist();
    const counts = state.rows.reduce((result, row) => {{ result[row.decision] += 1; return result; }}, {{ pending: 0, pass: 0, reject: 0 }});
    const payload = {{
      schema_version: 1,
      source_run_manifest: artifact.source_run_manifest,
      source_run_manifest_sha256: artifact.source_run_manifest_sha256,
      reviewer: state.reviewer,
      reviewed_at: new Date().toISOString(),
      rows: state.rows.map(row => ({{ image_name: row.image_name, contact_sheet: row.contact_sheet, polygon: row.polygon, decision: valid.has(row.decision) ? row.decision : 'pending', notes: row.notes || '' }})),
      counts: {{ total: state.rows.length, pending: counts.pending, pass: counts.pass, reject: counts.reject }},
      all_pass: artifact.review_ready && counts.pass === 20,
      review_ready: artifact.review_ready,
      readiness_reasons: artifact.readiness_reasons,
      training_authorized: false,
      promotion_authorized: false,
      release_gate: {{ metric: 'assertion_pass_rate', minimum_assertion_pass_rate: 0.95, current_assertion_pass_rate: null, passed: false }}
    }};
    const blob = new Blob([JSON.stringify(payload, null, 2) + '\\n'], {{ type: 'application/json' }});
    const url = URL.createObjectURL(blob); const link = document.createElement('a');
    link.href = url; link.download = 'review_decisions.local.json'; document.body.appendChild(link); link.click(); link.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
  }});
  render();
}})();
</script>
</body>
</html>
'''


def generate_review_page(artifact_dir: Path, output_path: Path | None = None) -> Path:
    """Validate ``artifact_dir`` and write one standalone local HTML page."""

    artifact = validate_artifact(artifact_dir)
    output = Path(output_path) if output_path is not None else artifact.root / "review.html"
    output = output.expanduser().resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(_render_html(artifact, output), encoding="utf-8", newline="\n")
    return output


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Generate a local review page for an extracted 20-image quarantine artifact.")
    parser.add_argument("artifact_dir", nargs="?", type=Path, help="Extracted artifact directory")
    parser.add_argument("--artifact-dir", dest="artifact_dir_option", type=Path, help="Extracted artifact directory")
    parser.add_argument("--output", type=Path, help="HTML output path (defaults to artifact_dir/review.html)")
    parser.add_argument(
        "--correction-manifest",
        type=Path,
        default=None,
        help="Optional correction manifest used by the pre-submit detector validator.",
    )
    parser.add_argument(
        "--require-detector-validator",
        action="store_true",
        help=(
            "Run the detector validator agent before marking the page ready for "
            # argparse expands help text through %-formatting, so a literal
            # percent sign must be doubled. Written as a single '%' this raised
            # "ValueError: incomplete format" and crashed --help outright.
            "human validation. Blocks handoff when required-item accuracy is not >95%%."
        ),
    )
    return parser


def main() -> None:
    args = build_parser().parse_args()
    artifact_dir = args.artifact_dir_option or args.artifact_dir
    if artifact_dir is None:
        raise SystemExit("Provide an artifact directory (positional or --artifact-dir).")
    detector_report = None
    if args.require_detector_validator:
        # Import the shipped agent path only when the human-handoff gate is requested.
        from pathlib import Path as _Path
        import importlib.util

        agent_path = (
            _Path(__file__).resolve().parent
            / "autoresearch"
            / "kaggle_label_factory"
            / "detector_validator_agent.py"
        )
        spec = importlib.util.spec_from_file_location(
            "detector_validator_agent",
            agent_path,
        )
        agent = importlib.util.module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(agent)
        try:
            detector_report = agent.run_on_quarantine(
                artifact_dir,
                correction_manifest=args.correction_manifest,
                require_handoff=True,
            )
        except Exception as error:
            raise SystemExit(
                f"Detector validator blocked human handoff: {error}"
            ) from error
    try:
        output = generate_review_page(artifact_dir, args.output)
    except ReviewArtifactError as error:
        raise SystemExit(f"Review artifact rejected: {error}") from error
    artifact = validate_artifact(artifact_dir)
    payload = {
        "status": (
            "ready_for_local_review"
            if artifact.review_ready
            else "withdrawn_not_ready_for_review"
        ),
        "output": str(output),
        "image_count": len(artifact.rows),
        "review_ready": artifact.review_ready,
        "readiness_reasons": artifact.readiness_reasons,
        "source_run_manifest_sha256": artifact.source_run_manifest_sha256,
        "training_authorized": False,
        "promotion_authorized": False,
        "release_gate_passed": False,
    }
    if detector_report is not None:
        payload["detector_validator"] = {
            "human_handoff_allowed": detector_report.get("human_handoff_allowed"),
            "required_item_count_accuracy": detector_report.get(
                "required_item_count_accuracy"
            ),
            "report_path": detector_report.get("report_path"),
            "message": detector_report.get("message"),
        }
    print(json.dumps(payload, indent=2))


if __name__ == "__main__":
    main()
