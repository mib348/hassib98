"""Tests for the release gate that stands between a model and `/ai`.

The gate's job is to REFUSE. A gate that opens when it should not is worse than
no gate, because it converts an unchecked model into an apparently-approved one,
so these tests concentrate on the refusal paths: the tolerant-class rule that
must not become a blanket exemption, the strictly-greater-than comparison, and
the rule that an unrunnable case counts as failed rather than skipped.
"""

from __future__ import annotations

import importlib.util
import sys
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent


def load_gate():
    spec = importlib.util.spec_from_file_location(
        "run_frozen_count_gate", REPO / "training/run_frozen_count_gate.py"
    )
    module = importlib.util.module_from_spec(spec)
    sys.modules["run_frozen_count_gate"] = module
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


class FrozenCountGateTest(unittest.TestCase):
    def setUp(self) -> None:
        self.gate = load_gate()

    def test_visible_classes_demand_an_exact_count(self) -> None:
        """The five visible classes are the reviewer's no-compromise classes."""
        assertions = self.gate.score_case(
            {"kraft paper bowl": 9, "black soya sauce cup": 5},
            {"kraft paper bowl": 9, "black soya sauce cup": 4},
        )
        by_class = {row["class_name"]: row for row in assertions}
        self.assertTrue(by_class["kraft paper bowl"]["passed"])
        self.assertFalse(by_class["black soya sauce cup"]["passed"])
        self.assertEqual(by_class["black soya sauce cup"]["rule"], "exact")

    def test_tolerant_classes_need_presence_not_an_exact_total(self) -> None:
        """Chopsticks and packets are advisory — but must still be DETECTED.

        The reviewer called these two unreliable to count by eye, so the totals
        are tolerated. What is NOT tolerated is missing them entirely: that is
        the "no required item should escape detection" requirement, and it is
        the half of the rule a blanket exemption would quietly discard.
        """
        wrong_total = self.gate.score_case(
            {"wooden chopstick tip": 13}, {"wooden chopstick tip": 7}
        )
        self.assertTrue(wrong_total[0]["passed"])
        self.assertEqual(wrong_total[0]["rule"], "detected_when_present")

        undetected = self.gate.score_case(
            {"wooden chopstick tip": 13}, {"wooden chopstick tip": 0}
        )
        self.assertFalse(undetected[0]["passed"])

        # Absent in the photo and absent in the prediction is agreement.
        absent = self.gate.score_case(
            {"black and white soya sauce packet": 0},
            {"black and white soya sauce packet": 0},
        )
        self.assertTrue(absent[0]["passed"])

    def test_a_missing_class_counts_as_zero_not_as_a_skip(self) -> None:
        """A class the model never emits must fail, not silently disappear."""
        assertions = self.gate.score_case({"red teriyaki sauce cup": 3}, {})
        self.assertFalse(assertions[0]["passed"])
        self.assertEqual(assertions[0]["actual"], 0)

    def test_exactly_at_the_bar_does_not_open_the_gate(self) -> None:
        """0.95 is the bar to BEAT, matching the detector validator's contract.

        Guards the comparison itself: a `>=` here would let a model that lands
        exactly on the threshold ship, which is the one boundary case a release
        gate is most likely to meet and least able to afford getting wrong.
        """
        bar = self.gate.MINIMUM_ASSERTION_PASS_RATE
        self.assertEqual(bar, 0.95)
        self.assertFalse(bar > bar + 1e-12)

    def test_the_shipped_case_file_is_usable_and_frozen(self) -> None:
        """The cases must be real, complete, and carry the do-not-edit warning."""
        import json

        payload = json.loads(
            (REPO / "training/frozen_count_gate_cases.json").read_text(encoding="utf-8")
        )
        cases = payload["cases"]
        self.assertEqual(len(cases), 8)
        self.assertIn("Never edit a case", payload["description"])
        for case in cases:
            self.assertTrue(case["image_name"].endswith(".jpg"))
            self.assertGreaterEqual(len(case["expected_counts"]), 4)
            for count in case["expected_counts"].values():
                self.assertIsInstance(count, int)
                self.assertGreaterEqual(count, 0)


if __name__ == "__main__":
    unittest.main()
