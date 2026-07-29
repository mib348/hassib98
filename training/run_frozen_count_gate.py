"""The last gate before `/ai`: does the exported model still count correctly?

WHY THIS EXISTS
---------------
Every revision of the plan has listed a "frozen count gate" as the final step
before re-enabling `/ai`, and until now nothing implemented it. The gap only
becomes visible at the worst possible moment -- after training a release model,
when there is nothing left to do but ship -- so it is written here, ahead of the
model, and can be exercised against any exported bundle.

WHAT IT CHECKS, AND WHY IT IS DIFFERENT FROM THE DETECTOR VALIDATOR
-------------------------------------------------------------------
`detector_validator_agent.py` scores PROPOSALS inside the label factory, where
SAM 3.1 and image prompts are available. This gate scores the SHIPPED artifact:
one ONNX file, seven baked-in text prompts, SAHI tiling, and nothing else --
exactly what the Ubuntu host will run. A model can look fine in the factory and
still regress once the helper lanes are gone, and that regression is the one
that would reach production.

The cases are "frozen" in the sense that they come from the reviewer's own
counts, recorded once and then never adjusted to make a model pass. Editing a
case to fix a failure defeats the entire purpose of the gate.

SCORING
-------
Per case, per required class: the predicted count must equal the reviewer's
count exactly, except for the two classes the reviewer declared tolerant
(chopstick tips and soya packets), which must merely be DETECTED when present --
their exact totals are unreliable to count by eye, which is why the reviewer
called them advisory. A case passes only when every one of its assertions
passes. `assertion_pass_rate` is passed assertions over total assertions, and
the gate opens only above 0.95.

USAGE
    python training/run_frozen_count_gate.py \
        --model release/..._seg.onnx \
        --manifest release/..._seg.manifest.json \
        --cases training/frozen_count_gate_cases.json \
        --images-dir training/sam_annotation_batch/images \
        --output gate_report.json

Exit status is 0 only when the gate opens, so CI and release scripts can depend
on it without parsing the report.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

REPO = Path(__file__).resolve().parent.parent
SAHI_SCRIPT = REPO / "training/sahi_inference.py"

# The bar, and the two classes the reviewer explicitly called advisory.
MINIMUM_ASSERTION_PASS_RATE = 0.95
COUNT_TOLERANT_CLASSES = frozenset(
    {"wooden chopstick tip", "black and white soya sauce packet"}
)


def run_one_case(
    model: Path,
    manifest: Path,
    image_path: Path,
    provider: str,
    confidence: float | None = None,
    class_thresholds: str | None = None,
) -> dict[str, int]:
    """Run the shipped inference path on one image and return per-class counts.

    Deliberately shells out to `sahi_inference.py` rather than importing it, so
    the gate measures the same entry point the production host invokes. If that
    script breaks, this gate must fail too -- importing internals would hide it.
    """

    with tempfile.TemporaryDirectory() as temporary:
        result_path = Path(temporary) / "counts.json"
        command = [
            sys.executable,
            str(SAHI_SCRIPT),
            "--model", str(model),
            "--manifest", str(manifest),
            "--image", str(image_path),
            "--output", str(result_path),
            "--provider", provider,
        ]
        # The operating point is part of what ships, so the gate has to be able
        # to score it.  Measured on the bootstrap checkpoint: at the inference
        # default of 0.25 this path returned ZERO kraft paper bowls on an image
        # holding nine of them, and zero for two whole classes across all eight
        # cases -- so a run left unconfigured scores the floor, not the model.
        if confidence is not None:
            command += ["--confidence", str(confidence)]
        if class_thresholds is not None:
            command += ["--class-thresholds", str(class_thresholds)]
        completed = subprocess.run(
            command,
            capture_output=True,
            text=True,
        )
        if completed.returncode != 0 or not result_path.is_file():
            raise RuntimeError(
                f"Inference failed for {image_path.name}: "
                f"{(completed.stderr or completed.stdout or '').strip()[:400]}"
            )
        payload = json.loads(result_path.read_text(encoding="utf-8"))

    if payload.get("status") != "success":
        raise RuntimeError(f"Inference reported {payload.get('status')!r} for {image_path.name}")
    return {str(k): int(v) for k, v in (payload.get("counts_per_class") or {}).items()}


def score_case(expected: dict[str, int], actual: dict[str, int]) -> list[dict[str, Any]]:
    """Turn one case's expected counts into individually pass/fail assertions."""

    assertions: list[dict[str, Any]] = []
    for class_name, want in sorted(expected.items()):
        want = int(want)
        got = int(actual.get(class_name, 0))
        if class_name in COUNT_TOLERANT_CLASSES:
            # Advisory class: presence is required, the exact total is not.
            passed = got > 0 if want > 0 else True
            rule = "detected_when_present"
        else:
            passed = got == want
            rule = "exact"
        assertions.append(
            {
                "class_name": class_name,
                "rule": rule,
                "expected": want,
                "actual": got,
                "passed": bool(passed),
            }
        )
    return assertions


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model", type=Path, required=True, help="Prompt-fused .onnx")
    parser.add_argument("--manifest", type=Path, required=True, help="Export manifest .json")
    parser.add_argument("--cases", type=Path, required=True, help="Frozen case file")
    parser.add_argument(
        "--images-dir",
        type=Path,
        default=REPO / "training/sam_annotation_batch/images",
    )
    parser.add_argument("--provider", default="auto", choices=["auto", "cpu", "cuda"])
    parser.add_argument("--output", type=Path, default=None, help="Where to write the report")
    parser.add_argument(
        "--confidence",
        type=float,
        default=None,
        help=(
            "Detection confidence floor handed to sahi_inference.py. Omit to use "
            "that script's own default. The floor is part of the shipped "
            "configuration, and the report records whichever value was scored."
        ),
    )
    parser.add_argument(
        "--class-thresholds",
        default=None,
        help="Per-class acceptance thresholds passed straight through to sahi_inference.py.",
    )
    args = parser.parse_args(argv)

    cases = json.loads(args.cases.read_text(encoding="utf-8"))["cases"]

    case_reports: list[dict[str, Any]] = []
    total_assertions = 0
    passed_assertions = 0

    for case in cases:
        image_name = str(case["image_name"])
        image_path = args.images_dir / image_name
        try:
            actual = run_one_case(
                args.model,
                args.manifest,
                image_path,
                args.provider,
                confidence=args.confidence,
                class_thresholds=args.class_thresholds,
            )
            assertions = score_case(case["expected_counts"], actual)
            error: str | None = None
        except Exception as failure:  # a case that cannot run is a case that fails
            assertions = [
                {
                    "class_name": name,
                    "rule": "exact",
                    "expected": int(value),
                    "actual": None,
                    "passed": False,
                }
                for name, value in sorted(case["expected_counts"].items())
            ]
            error = str(failure)

        total_assertions += len(assertions)
        passed_assertions += sum(1 for row in assertions if row["passed"])
        case_reports.append(
            {
                "image_name": image_name,
                "passed": all(row["passed"] for row in assertions),
                "error": error,
                "assertions": assertions,
            }
        )

    rate = (passed_assertions / total_assertions) if total_assertions else 0.0
    # Strictly greater than the bar, matching the detector validator's contract,
    # so "exactly 0.95" never counts as passing.
    opened = rate > MINIMUM_ASSERTION_PASS_RATE + 1e-12

    report = {
        "schema_version": 1,
        "minimum_assertion_pass_rate": MINIMUM_ASSERTION_PASS_RATE,
        # A pass rate means nothing without the operating point that produced
        # it, so the floor is recorded next to the score.
        "confidence_threshold": args.confidence,
        "class_thresholds": args.class_thresholds,
        "assertion_pass_rate": round(rate, 6),
        "total_assertions": total_assertions,
        "passed_assertions": passed_assertions,
        "case_count": len(case_reports),
        "passed_case_count": sum(1 for row in case_reports if row["passed"]),
        "gate_opened": opened,
        "cases": case_reports,
    }
    if args.output:
        args.output.write_text(json.dumps(report, indent=2), encoding="utf-8")

    for row in case_reports:
        status = "PASS" if row["passed"] else "fail"
        print(f"{row['image_name'][:44]:<46}{status}")
        for bad in (a for a in row["assertions"] if not a["passed"]):
            print(f"      {bad['class_name']}: expected {bad['expected']}, got {bad['actual']}")
        if row["error"]:
            print(f"      error: {row['error'][:160]}")

    print()
    print(
        f"assertion_pass_rate={rate:.4f} "
        f"({passed_assertions}/{total_assertions})  "
        f"cases={report['passed_case_count']}/{report['case_count']}  "
        f"gate_opened={opened}"
    )
    return 0 if opened else 1


if __name__ == "__main__":
    raise SystemExit(main())
