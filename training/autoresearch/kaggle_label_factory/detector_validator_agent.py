from __future__ import annotations

"""Detector validator agent: block human handoff when count accuracy is not >95%.

This agent reads a quarantine ``run_manifest.json`` (and optional correction
manifest) and scores every image with non-advisory human estimates for exact
required-item count match.  It writes ``detector_validator_report.json`` and
exits non-zero when human handoff is not allowed.

It never invents geometry, never trains, and never re-enables ``/ai``.
"""

import argparse
import importlib.util
import json
import sys
from pathlib import Path
from typing import Any


def _load_review_runtime():
    runtime_path = Path(__file__).with_name("assisted_label_review.py")
    spec = importlib.util.spec_from_file_location(
        "assisted_label_review_validator",
        runtime_path,
    )
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


def _load_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def _corrections_from_manifest(path: Path | None) -> dict[str, dict[str, Any]]:
    if path is None or not path.is_file():
        return {}
    payload = _load_json(path)
    rows = payload.get("images") or payload.get("rows") or []
    by_name: dict[str, dict[str, Any]] = {}
    for row in rows:
        name = row.get("image_name") or row.get("name")
        if not name:
            continue
        by_name[str(name)] = {
            "decision": row.get("decision"),
            "requested_counts": row.get("requested_counts") or {},
            "missing_identifications": row.get("missing_identifications") or {},
            "advisory_classes": row.get("advisory_classes") or [],
            "count_uncertainties": row.get("count_uncertainties") or [],
            # The reviewer's free-text note has to travel with the numbers.  It
            # is the only place they say an item is physically hidden ("2 are
            # stacked behind 5 front ones"), and the scorer reads that sentence
            # to decide whether a shortfall is the detector's fault or the
            # camera's.  Dropping it here would silently disable that rule on
            # the real CLI path while unit tests -- which pass corrections
            # directly -- kept passing.
            "notes": row.get("notes") or "",
        }
    return by_name


def run_on_quarantine(
    artifact_dir: Path,
    *,
    correction_manifest: Path | None = None,
    output_path: Path | None = None,
    require_handoff: bool = True,
) -> dict[str, Any]:
    """Score one quarantine directory and optionally fail closed."""

    module = _load_review_runtime()
    artifact_dir = artifact_dir.expanduser().resolve()
    run_manifest_path = artifact_dir / "run_manifest.json"
    if not run_manifest_path.is_file():
        raise FileNotFoundError(f"run_manifest.json not found under {artifact_dir}")
    run_manifest = _load_json(run_manifest_path)
    images = run_manifest.get("images") or []
    if not isinstance(images, list) or not images:
        raise RuntimeError("run_manifest.json has no images list to validate.")

    corrections = _corrections_from_manifest(correction_manifest)
    report = module.run_detector_validator_agent(
        images,
        corrections,
        minimum_accuracy=module.DETECTOR_VALIDATOR_MIN_ACCURACY,
        expected_image_count=20,
    )
    report["artifact_dir"] = str(artifact_dir)
    report["run_manifest"] = str(run_manifest_path)
    if correction_manifest is not None:
        report["correction_manifest"] = str(correction_manifest)

    destination = output_path or (artifact_dir / "detector_validator_report.json")
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    report["report_path"] = str(destination)

    if require_handoff:
        module.assert_detector_validator_allows_human_handoff(report)
    return report


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "Detector validator agent: require required-item count accuracy "
            "> 95% before human validation handoff."
        )
    )
    parser.add_argument(
        "artifact_dir",
        type=Path,
        help="Quarantine directory containing run_manifest.json",
    )
    parser.add_argument(
        "--correction-manifest",
        type=Path,
        default=None,
        help="Optional review_correction_manifest.json with human estimates",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=None,
        help="Report JSON path (default: ARTIFACT_DIR/detector_validator_report.json)",
    )
    parser.add_argument(
        "--allow-failed-handoff",
        action="store_true",
        help="Write the report and exit 0 even when handoff is blocked (for diagnostics).",
    )
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        report = run_on_quarantine(
            args.artifact_dir,
            correction_manifest=args.correction_manifest,
            output_path=args.output,
            require_handoff=not args.allow_failed_handoff,
        )
    except Exception as error:
        print(json.dumps({"status": "error", "message": str(error)}, indent=2))
        return 2
    print(
        json.dumps(
            {
                "status": report.get("status"),
                "human_handoff_allowed": report.get("human_handoff_allowed"),
                "required_item_count_accuracy": report.get(
                    "required_item_count_accuracy"
                ),
                "scored_image_count": report.get("scored_image_count"),
                "passed_image_count": report.get("passed_image_count"),
                "failed_image_count": report.get("failed_image_count"),
                "report_path": report.get("report_path"),
                "message": report.get("message"),
            },
            indent=2,
        )
    )
    return 0 if report.get("human_handoff_allowed") else 1


if __name__ == "__main__":
    sys.exit(main())
