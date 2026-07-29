from __future__ import annotations

import importlib.util
import hashlib
import json
from pathlib import Path
import stat
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest import mock
import warnings
import zipfile

import numpy as np
from PIL import Image


ROOT = Path(__file__).resolve().parents[1]
PACKAGE_DIR = ROOT / "training" / "autoresearch" / "kaggle_label_factory"
SCRIPT_PATH = PACKAGE_DIR / "assisted_label_review.py"
sys.path.insert(0, str(PACKAGE_DIR))


def load_module():
    spec = importlib.util.spec_from_file_location("assisted_label_review", SCRIPT_PATH)
    if spec is None or spec.loader is None:
        raise RuntimeError(f"Could not load {SCRIPT_PATH}")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


class AssistedLabelReviewTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.module = load_module()

    def write_annotation(self, path: Path, labels: list[str]) -> None:
        path.write_text(
            json.dumps(
                {
                    "imagePath": f"{path.stem}.jpg",
                    "imageWidth": 100,
                    "imageHeight": 80,
                    "shapes": [
                        {
                            "label": label,
                            "shape_type": "rectangle",
                            "points": [[index + 2, 5], [index + 20, 30]],
                        }
                        for index, label in enumerate(labels)
                    ],
                }
            ),
            encoding="utf-8",
        )

    def write_input_archive(
        self,
        path: Path,
        *,
        mutate_manifest=None,
        extra_members: list[tuple[zipfile.ZipInfo | str, bytes]] | None = None,
    ) -> str:
        image_names = [f"fridge-{index:02d}.jpg" for index in range(20)]
        annotation_names = [f"fridge-{index:02d}.json" for index in range(6)]
        file_contents = {
            **{f"images/{name}": f"image-{index}".encode() for index, name in enumerate(image_names)},
            **{
                f"reference_annotations/{name}": f"annotation-{index}".encode()
                for index, name in enumerate(annotation_names)
            },
        }
        manifest = {
            "schema_version": 1,
            "fixed_image_count": 20,
            "reference_count": 6,
            "target_count": 14,
            "image_names": image_names,
            "reference_image_names": image_names[:6],
            "target_image_names": image_names[6:],
            "reference_annotation_names": annotation_names,
            "reference_annotation_sha256": {
                name: hashlib.sha256(file_contents[f"reference_annotations/{name}"]).hexdigest()
                for name in annotation_names
            },
            "file_sha256": {
                name: hashlib.sha256(content).hexdigest()
                for name, content in file_contents.items()
            },
            "class_names": self.module.FIXED_CLASS_NAMES,
            "training_authorized": False,
            "promotion_authorized": False,
        }
        if mutate_manifest is not None:
            mutate_manifest(manifest)
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(path, "w") as archive:
                for name, content in file_contents.items():
                    archive.writestr(name, content)
                archive.writestr(
                    "input_manifest.json",
                    json.dumps(manifest, sort_keys=True).encode(),
                )
                for member, content in extra_members or []:
                    archive.writestr(member, content)
        return hashlib.sha256(path.read_bytes()).hexdigest()

    def test_input_archive_requires_exact_embedded_hash_before_touching_work_root(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            archive = root / "inputs.bundle"
            actual_hash = self.write_input_archive(archive)
            work_root = root / "work"
            work_root.mkdir()
            sentinel = work_root / "keep.txt"
            sentinel.write_text("untouched", encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "archive SHA-256 mismatch"):
                self.module.extract_input_bundle(archive, work_root, "0" * 64)

            self.assertEqual(sentinel.read_text(encoding="utf-8"), "untouched")
            manifest = self.module.extract_input_bundle(archive, work_root, actual_hash)
            self.assertEqual(manifest["fixed_image_count"], 20)
            self.assertEqual(len(list((work_root / "images").iterdir())), 20)

    def test_input_archive_rejects_traversal_directory_symlink_extra_and_duplicate_members(self) -> None:
        symlink = zipfile.ZipInfo("images/link.jpg")
        symlink.create_system = 3
        symlink.external_attr = (stat.S_IFLNK | 0o777) << 16
        directory = zipfile.ZipInfo("unexpected/")
        directory.create_system = 3
        directory.external_attr = (stat.S_IFDIR | 0o755) << 16
        attacks = {
            "traversal": [("../escape.txt", b"escape")],
            "directory": [(directory, b"")],
            "symlink": [(symlink, b"../outside.jpg")],
            "extra": [("extra.txt", b"extra")],
            "duplicate": [("images/fridge-00.jpg", b"duplicate")],
        }
        expected_messages = {
            "traversal": "Unsafe assisted archive member path",
            "directory": "Directories are forbidden",
            "symlink": "Symlinks are forbidden",
            "extra": "differ from the frozen allow-list",
            "duplicate": "Duplicate members are forbidden",
        }
        for attack_name, extra_members in attacks.items():
            with self.subTest(attack=attack_name), tempfile.TemporaryDirectory() as temp_dir:
                root = Path(temp_dir)
                archive = root / "inputs.bundle"
                archive_hash = self.write_input_archive(archive, extra_members=extra_members)
                work_root = root / "work"
                with self.assertRaisesRegex(ValueError, expected_messages[attack_name]):
                    self.module.extract_input_bundle(archive, work_root, archive_hash)
                self.assertFalse(work_root.exists())
                self.assertFalse((root.parent / "escape.txt").exists())

    def test_input_archive_rejects_manifest_partition_and_hash_allow_list_changes(self) -> None:
        mutations = {
            "partition": lambda manifest: manifest["target_image_names"].__setitem__(
                0, manifest["reference_image_names"][0]
            ),
            "hash_allow_list": lambda manifest: manifest["file_sha256"].pop(
                "images/fridge-19.jpg"
            ),
        }
        for mutation_name, mutation in mutations.items():
            with self.subTest(mutation=mutation_name), tempfile.TemporaryDirectory() as temp_dir:
                root = Path(temp_dir)
                archive = root / "inputs.bundle"
                archive_hash = self.write_input_archive(archive, mutate_manifest=mutation)
                work_root = root / "work"
                with self.assertRaises(ValueError):
                    self.module.extract_input_bundle(archive, work_root, archive_hash)
                self.assertFalse(work_root.exists())

    def test_runtime_parser_requires_expected_input_archive_sha256(self) -> None:
        expected_hash = "a" * 64
        with mock.patch.object(
            sys,
            "argv",
            [
                "assisted_label_review.py",
                "--input-bundle",
                "inputs.bundle",
                "--expected-input-archive-sha256",
                expected_hash,
                "--correction-manifest",
                "corrections.json",
                "--expected-correction-manifest-sha256",
                "b" * 64,
            ],
        ):
            arguments = self.module.parse_args()
        self.assertEqual(arguments.expected_input_archive_sha256, expected_hash)
        self.assertFalse(arguments.enable_text_fallback)
        self.assertEqual(arguments.text_fallback_max_visual_proposals, 2)

    def test_text_fallback_requires_explicit_opt_in(self) -> None:
        with mock.patch.object(
            sys,
            "argv",
            [
                "assisted_label_review.py",
                "--input-bundle",
                "inputs.bundle",
                "--expected-input-archive-sha256",
                "a" * 64,
                "--correction-manifest",
                "corrections.json",
                "--expected-correction-manifest-sha256",
                "b" * 64,
                "--enable-text-fallback",
            ],
        ):
            arguments = self.module.parse_args()
            self.assertTrue(arguments.enable_text_fallback)

    def test_correction_manifest_is_hash_bound_fail_closed_and_exact(self) -> None:
        input_manifest = {
            "image_names": [f"fridge-{index:02d}.jpg" for index in range(20)]
            ,
            "reference_image_names": [
                f"fridge-{index:02d}.jpg" for index in range(6)
            ],
            "target_image_names": [
                f"fridge-{index:02d}.jpg" for index in range(6, 20)
            ],
        }
        rows = [
            {
                "image_name": image_name,
                "decision": "pass" if index < 6 else "reject",
                "notes": "review note",
                "requested_counts": (
                    {} if index < 6 else {"black soya sauce cup": 3}
                ),
                "missing_identifications": {},
                "issue_categories": ["count"],
                "ocr": {"issue": False, "corrected_labels": []},
                "advisory_classes": [],
                "count_uncertainties": [],
                "unquantified_observations": [],
                "proposal_counts": {
                    class_name: 0 for class_name in self.module.FIXED_CLASS_NAMES
                },
                "training_eligible": False,
            }
            for index, image_name in enumerate(input_manifest["image_names"])
        ]
        payload = {
            "schema_version": 1,
            "status": "correction_audit_only",
            "training_authorized": False,
            "promotion_authorized": False,
            "training_dataset_created": False,
            "release_gate": {"passed": False},
            "policy": {
                "proposals_are_not_labels": True,
                "human_visual_approval_required_for_training": True,
                "packet_counts_are_advisory": True,
            },
            "images": rows,
        }
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "corrections.json"
            path.write_text(json.dumps(payload), encoding="utf-8")
            digest = hashlib.sha256(path.read_bytes()).hexdigest()
            corrections = self.module.load_correction_manifest(
                path,
                digest,
                input_manifest,
            )
            self.assertEqual(len(corrections), 20)
            self.assertEqual(
                sum(row["decision"] == "pass" for row in corrections.values()),
                6,
            )
            self.assertTrue(
                all(row["training_eligible"] is False for row in corrections.values())
            )

            with self.assertRaisesRegex(ValueError, "SHA-256 mismatch"):
                self.module.load_correction_manifest(
                    path,
                    "0" * 64,
                    input_manifest,
                )

            payload["training_authorized"] = True
            path.write_text(json.dumps(payload), encoding="utf-8")
            changed_digest = hashlib.sha256(path.read_bytes()).hexdigest()
            with self.assertRaisesRegex(ValueError, "fail-closed"):
                self.module.load_correction_manifest(
                    path,
                    changed_digest,
                    input_manifest,
                )

            payload["training_authorized"] = False
            payload["images"][0]["decision"] = "reject"
            payload["images"][6]["decision"] = "pass"
            path.write_text(json.dumps(payload), encoding="utf-8")
            swapped_digest = hashlib.sha256(path.read_bytes()).hexdigest()
            with self.assertRaisesRegex(ValueError, "manual reference"):
                self.module.load_correction_manifest(
                    path,
                    swapped_digest,
                    input_manifest,
                )

    def test_correction_guided_counts_are_diagnostics_not_geometry(self) -> None:
        module = self.module

        class FakeSam:
            def __init__(self):
                self.calls = []

            def discover(self, image_path, thresholds, **kwargs):
                self.calls.append((image_path, thresholds, kwargs))
                return {
                    "raw_instance_count": 1,
                    "successful_prompt_count": 7,
                    "prompts": [
                        {"status": "success"} for _ in range(7)
                    ],
                    "instances": [
                        {
                            "class_id": 1,
                            "class_name": "black soya sauce cup",
                            "confidence": 0.9,
                            "bbox_xyxy": [1.0, 2.0, 11.0, 12.0],
                            "polygon": [[1.0, 2.0], [11.0, 2.0], [11.0, 12.0]],
                            "source": module.CORRECTION_GUIDED_SOURCE,
                            "proposal_sources": [
                                module.CORRECTION_GUIDED_SOURCE
                            ],
                        }
                    ],
                }

        fake = FakeSam()
        proposals, record = self.module.correction_guided_sam_discovery(
            fake,
            Path("reject.jpg"),
            {
                "decision": "reject",
                "requested_counts": {"black soya sauce cup": 7},
            },
            thresholds=list(self.module.CORRECTION_GUIDED_THRESHOLDS),
            max_raw_instances=128,
            max_post_nms_instances=128,
            iou=0.45,
        )
        self.assertEqual(len(proposals), 1)
        self.assertEqual(record["observed_counts"]["black soya sauce cup"], 1)
        self.assertEqual(record["count_differences"]["black soya sauce cup"], -6)
        self.assertFalse(record["count_targets_used_as_geometry"])
        self.assertEqual(fake.calls[0][2]["class_prompts"], self.module.CORRECTION_GUIDED_PROMPTS)

        proposals, record = self.module.correction_guided_sam_discovery(
            fake,
            Path("pass.jpg"),
            {"decision": "pass", "requested_counts": {}},
            thresholds=list(self.module.CORRECTION_GUIDED_THRESHOLDS),
            max_raw_instances=128,
            max_post_nms_instances=128,
            iou=0.45,
        )
        self.assertEqual(len(proposals), 1)
        self.assertEqual(record["status"], "accepted_reference_diagnostic")

    def test_reference_usefulness_gate_does_not_fail_close_on_empty_correction(
        self,
    ) -> None:
        """V39 pass rows stay packageable when correction diagnostics are empty.

        Trusted reference geometry is the review source.  A rejected or empty
        correction-guided SAM diagnostic on those six images must not count as
        a usefulness failure for the twenty-image quarantine gate (root cause
        analysis for V44 Kaggle Version 33 / script 337824597).
        """

        module = self.module
        usefulness = module.build_correction_usefulness_gate(
            {
                "decision": "pass",
                "requested_counts": {
                    "kraft paper bowl": 4,
                    "wooden chopstick tip": 10,
                },
            },
            {
                "status": "rejected",
                "rejection_reason": "correction-guided prompt contract was incomplete",
            },
            [],
            [],  # final union empty would fail a target, not a trusted reference
            is_audited_reference=True,
        )
        self.assertTrue(usefulness["passed"])
        self.assertEqual(usefulness["reasons"], [])
        self.assertTrue(usefulness["advisory_reasons"])
        self.assertTrue(usefulness["reference_diagnostic"])

    def test_target_usefulness_gate_fails_when_required_class_is_missing(
        self,
    ) -> None:
        """Rejected targets still fail closed if a required class is invisible."""

        module = self.module
        usefulness = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 3,
                    "black soya sauce cup": 2,
                },
                "missing_identifications": {},
            },
            {"status": "accepted", "rejection_reason": None},
            [
                {
                    "class_id": 0,
                    "class_name": "kraft paper bowl",
                }
            ],
            [
                {
                    "class_id": 0,
                    "class_name": "kraft paper bowl",
                }
            ],
            is_audited_reference=False,
        )
        self.assertFalse(usefulness["passed"])
        self.assertIn(
            "missing required class: black soya sauce cup",
            usefulness["reasons"],
        )

    def test_target_usefulness_gate_passes_when_required_classes_are_present(
        self,
    ) -> None:
        """Pass only when final proposal counts equal human estimates 100%.

        Presence of a class is not enough: kraft=3 and soya=2 require exactly
        that many proposal instances (geometry still model-sourced; over-count
        also fails until trim).
        """

        module = self.module
        usefulness = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 3,
                    "black soya sauce cup": 2,
                },
            },
            {"status": "accepted", "rejection_reason": None},
            [{"class_id": 0}] * 3 + [{"class_id": 1}] * 2,
            [{"class_id": 0}] * 3 + [{"class_id": 1}] * 2,
            is_audited_reference=False,
        )
        self.assertTrue(usefulness["passed"])
        self.assertEqual(usefulness["reasons"], [])
        self.assertEqual(usefulness["advisory_reasons"], [])

        short = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 3,
                    "black soya sauce cup": 2,
                },
            },
            {"status": "accepted", "rejection_reason": None},
            [{"class_id": 0}, {"class_id": 1}],
            [{"class_id": 0}, {"class_id": 1}],
            is_audited_reference=False,
        )
        self.assertFalse(short["passed"])
        self.assertTrue(
            any("below human estimate" in reason for reason in short["reasons"])
        )

    def test_v42_style_missing_soya_and_tip_usefulness_is_soft_for_packaging(
        self,
    ) -> None:
        """Usefulness shortfalls flag diagnostics; soft packaging still ships.

        V47 hard-blocked at usefulness 14/20=0.70 and prevented human review.
        Plan AC4: soft usefulness incompleteness must not alone block the
        quarantine.  Hard SAM accept/prompt contracts still fail-close.
        """

        module = self.module
        cases = [
            (
                "garbe-hafencity-2026-05-28-o3SCe5us55.jpg",
                {"black soya sauce cup": 1, "red teriyaki sauce cup": 2, "white wayo dip cup": 7},
                [{"class_id": 2}, {"class_id": 3}],  # teriyaki+wayo present; soya absent
                "missing required class: black soya sauce cup",
            ),
            (
                "mb-energy-hafencity-2026-05-27-qoyLKgpg1a.jpg",
                {
                    "black soya sauce cup": 7,
                    "wooden chopstick tip": 36,
                    "white wayo dip cup": 6,
                },
                [{"class_id": 1}, {"class_id": 3}],  # soya+wayo; tip absent
                "missing required class: wooden chopstick tip",
            ),
            (
                "techhub-lurup-2026-05-22-n7HxPKNSvB.jpg",
                {"wooden chopstick tip": 13, "black soya sauce cup": 2},
                [{"class_id": 1}],
                "missing required class: wooden chopstick tip",
            ),
        ]
        package_images = []
        for image_name, requested, final_instances, expected_reason in cases:
            usefulness = module.build_correction_usefulness_gate(
                {"decision": "reject", "requested_counts": requested},
                {"status": "accepted", "rejection_reason": None},
                list(final_instances),
                list(final_instances),
                is_audited_reference=False,
            )
            self.assertFalse(
                usefulness["passed"],
                msg=f"{image_name} should still flag usefulness failure",
            )
            self.assertIn(expected_reason, usefulness["reasons"])
            package_images.append(
                {
                    "image_name": image_name,
                    "status": "accepted",
                    "previous_decision": "reject",
                    "proposal_usefulness": usefulness,
                }
            )
        while len(package_images) < 14:
            package_images.append(
                {
                    "image_name": f"ok-target-{len(package_images)}.jpg",
                    "status": "accepted",
                    "previous_decision": "reject",
                    "proposal_usefulness": {"passed": True, "reasons": []},
                }
            )
        for index in range(6):
            package_images.append(
                {
                    "image_name": f"ok-reference-{index}.jpg",
                    "status": "accepted_reference_diagnostic",
                    "previous_decision": "pass",
                    "proposal_usefulness": {"passed": True, "reasons": []},
                }
            )
        correction_summary = {
            "enabled": True,
            "source_review_pass_count": 6,
            "source_review_reject_count": 14,
            "triggered_image_count": 20,
            "accepted_image_count": 20,
            "accepted_target_image_count": 14,
            "accepted_reference_diagnostic_count": 6,
            "prompt_attempt_count": 140,
            "successful_prompt_count": 140,
            "count_targets_used_as_geometry": False,
            "proposal_usefulness_gate": {
                "evaluated_image_count": 20,
                "passed_image_count": 17,
                "failed_image_count": 3,
            },
            "images": package_images,
        }
        # 17/20 = 0.85 is below the 0.95 *release* target but must still package.
        package = module.validate_correction_guided_notebook_package_gates(
            correction_summary
        )
        self.assertTrue(package["hard_contract_passed"])
        self.assertTrue(package["usefulness_incomplete"])
        self.assertFalse(package["usefulness_blocks_package"])
        self.assertFalse(package["meets_usefulness_release_target"])
        self.assertEqual(package["usefulness_failed_image_count"], 3)
        self.assertEqual(package["usefulness_pass_rate"], 0.85)

        # Reproduce V47 live rate (14/20) — still soft-packages.
        v47_style = dict(correction_summary)
        v47_style["proposal_usefulness_gate"] = {
            "evaluated_image_count": 20,
            "passed_image_count": 14,
            "failed_image_count": 6,
        }
        v47_package = module.validate_correction_guided_notebook_package_gates(v47_style)
        self.assertTrue(v47_package["hard_contract_passed"])
        self.assertEqual(v47_package["usefulness_failed_image_count"], 6)
        self.assertAlmostEqual(v47_package["usefulness_pass_rate"], 0.7)

        # Hard SAM accept/prompt contract still fail-closes packaging.
        broken = dict(correction_summary)
        broken["successful_prompt_count"] = 139
        with self.assertRaisesRegex(
            RuntimeError,
            "correction-guided proposal lane is incomplete",
        ):
            module.validate_correction_guided_notebook_package_gates(broken)

    def test_correction_guided_discovery_sessions_are_accounted_by_source(self) -> None:
        """The final lifecycle gate must include all seven correction sessions.

        SAM 3.1 opens and closes one isolated session per class prompt.  This
        regression test protects the V40 accounting from accidentally treating
        those real close events as unexplained sessions and failing a healthy
        Kaggle run at the final fail-closed gate.
        """

        class CorrectionPredictor:
            def __init__(self):
                self.started_session_ids = []
                self.close_requests = []

            def handle_request(self, request):
                if request["type"] == "start_session":
                    session_id = f"correction-{len(self.started_session_ids)}"
                    self.started_session_ids.append(session_id)
                    return {"session_id": session_id}
                if request["type"] == "add_prompt":
                    mask = np.zeros((20, 20), dtype=bool)
                    mask[2:8, 3:9] = True
                    return {
                        "outputs": {
                            "out_binary_masks": mask[None, ...],
                            "out_boxes_xywh": np.asarray(
                                [[0.15, 0.10, 0.30, 0.30]],
                                dtype=np.float32,
                            ),
                            "out_probs": np.asarray([0.91], dtype=np.float32),
                        }
                    }
                if request["type"] == "close_session":
                    self.close_requests.append(request)
                    return {
                        "is_success": True,
                        "gpu_mem": {"active_session_count": 0},
                    }
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "correction.jpg"
            Image.new("RGB", (20, 20), color=(30, 30, 30)).save(image_path)
            predictor = CorrectionPredictor()
            adapter = self.module.Sam31ImageAdapter(
                predictor,
                Path(temp_dir) / "sessions",
            )
            result = adapter.discover(
                str(image_path),
                list(self.module.CORRECTION_GUIDED_THRESHOLDS),
                class_prompts=list(self.module.CORRECTION_GUIDED_PROMPTS),
                semantic_source=self.module.CORRECTION_GUIDED_SOURCE,
                prompt_variant="v39_human_correction_descriptive_prompts",
            )

        self.assertEqual(result["prompt_count"], 7)
        self.assertEqual(result["successful_prompt_count"], 7)
        self.assertEqual(len(predictor.started_session_ids), 7)
        self.assertEqual(len(predictor.close_requests), 7)
        self.assertEqual(
            adapter.discovery_session_counts_by_source,
            {self.module.CORRECTION_GUIDED_SOURCE: 7},
        )
        self.assertTrue(
            all(
                row["gpu_mem"]["active_session_count"] == 0
                for row in adapter.session_close_diagnostics
            )
        )

    def _v42_instance(
        self,
        class_id: int,
        bbox: list[float],
        *,
        source: str | None = None,
    ) -> dict:
        """Build one small, valid proposal for the targeted-recovery fakes.

        The recovery lane must return ordinary proposal geometry.  Keeping this
        fixture explicit makes the tests fail if a future implementation starts
        manufacturing rows from a human count instead of forwarding model
        proposals.
        """

        x1, y1, x2, y2 = [float(value) for value in bbox]
        proposal_source = source or self.module.CORRECTION_GUIDED_TILED_SOURCE
        return {
            "class_id": class_id,
            "class_name": self.module.FIXED_CLASS_NAMES[class_id],
            "confidence": 0.91,
            "bbox_xyxy": [x1, y1, x2, y2],
            "polygon": [[x1, y1], [x2, y1], [x2, y2], [x1, y2]],
            "source": proposal_source,
            "proposal_sources": [proposal_source],
            "supporting_references": [],
            "reference_support_count": 0,
            "reference_image": None,
        }

    def test_v42_tiled_recovery_selects_only_uncovered_required_classes(self) -> None:
        """Presence facts choose a search; the requested count never makes boxes."""

        class FakeTargetedSam:
            def __init__(self, test_case):
                self.test_case = test_case
                self.calls = []

            def discover_tiled_selected(
                self,
                image_path,
                class_ids,
                class_thresholds,
                *,
                aliases_by_class,
                semantic_source,
                prompt_variant,
                max_raw_instances,
            ):
                self.calls.append(
                    {
                        "image_path": image_path,
                        "class_ids": list(class_ids),
                        "class_thresholds": list(class_thresholds),
                        "aliases_by_class": aliases_by_class,
                        "semantic_source": semantic_source,
                        "prompt_variant": prompt_variant,
                        "max_raw_instances": max_raw_instances,
                    }
                )
                # The fake model returns one proposal even though the reviewer
                # requested 99 white cups.  The implementation must preserve
                # that one observed mask, never fabricate 99 geometries.
                # Two short classes × 4 tiles × 2 aliases = 16 prompts.
                selected = list(class_ids)
                prompt_count = 4 * sum(
                    len(aliases_by_class[class_id]) for class_id in selected
                )
                return {
                    "instances": [
                        self.test_case._v42_instance(3, [30, 20, 40, 30])
                    ],
                    "prompts": [{"status": "success"} for _ in range(prompt_count)],
                    "prompt_count": prompt_count,
                    "successful_prompt_count": prompt_count,
                    "raw_instance_count": 1,
                    "kept_instance_count": 1,
                    "tile_count": 4,
                }

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "target.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            fake = FakeTargetedSam(self)
            existing = [
                # One soya cup exists, but human estimate is 3 — recovery must
                # keep searching soya (final_count < human_estimate).
                self._v42_instance(1, [5, 5, 15, 15], source="existing")
            ]
            correction = {
                "decision": "reject",
                "requested_counts": {
                    "black soya sauce cup": 3,
                    "white wayo dip cup": 99,
                    "red teriyaki sauce cup": 0,
                    "black and white soya sauce packet": 12,
                },
                # Zero in missing_identifications is not a positive estimate;
                # wayo still comes from requested_counts=99.
                "missing_identifications": {"white wayo dip cup": 0},
                "advisory_classes": ["black and white soya sauce packet"],
                "count_uncertainties": [],
            }

            proposals, record = self.module.correction_guided_tiled_recovery(
                fake,
                image_path,
                correction,
                existing,
                thresholds=list(self.module.CORRECTION_GUIDED_TILED_THRESHOLDS),
                max_raw_instances=128,
                max_post_nms_instances=128,
                iou=0.45,
            )

        self.assertEqual(len(fake.calls), 1)
        call = fake.calls[0]
        # Soya shortfall (1 < 3) and wayo shortfall (0 < 99) both search.
        self.assertEqual(call["class_ids"], [1, 3])
        self.assertEqual(
            call["aliases_by_class"][1],
            self.module.CORRECTION_GUIDED_TILED_PROMPTS[1],
        )
        self.assertEqual(
            call["aliases_by_class"][3],
            self.module.CORRECTION_GUIDED_TILED_PROMPTS[3],
        )
        self.assertEqual(
            call["semantic_source"],
            self.module.CORRECTION_GUIDED_TILED_SOURCE,
        )
        self.assertEqual(record["status"], "accepted")
        self.assertTrue(record["triggered"])
        self.assertTrue(record["accepted"])
        self.assertEqual(record["selected_class_ids"], [1, 3])
        self.assertEqual(
            record["selected_class_names"],
            ["black soya sauce cup", "white wayo dip cup"],
        )
        # 2 classes × 4 tiles × 2 aliases = 16 prompts.
        self.assertEqual(record["prompt_attempt_count"], 16)
        self.assertEqual(record["successful_prompt_count"], 16)
        self.assertEqual(record["raw_instance_count"], 1)
        self.assertEqual(record["proposal_count"], 1)
        self.assertFalse(record["count_targets_used_as_geometry"])
        self.assertEqual(len(proposals), 1)
        # Fake model only returned the one observed wayo mask — never 99.
        self.assertEqual(proposals[0]["class_id"], 3)

    def test_v42_tiled_recovery_does_not_trigger_for_reference_zero_or_covered_notes(
        self,
    ) -> None:
        """Reference/advisory/zero/covered notes must not spend SAM sessions."""

        class NeverCalledSam:
            def __init__(self):
                self.calls = 0

            def discover_tiled_selected(self, *args, **kwargs):
                self.calls += 1
                raise AssertionError("unnecessary targeted recovery call")

        cases = [
            (
                "reference",
                {
                    "decision": "pass",
                    "requested_counts": {"white wayo dip cup": 4},
                    "missing_identifications": {"white wayo dip cup": 1},
                    "advisory_classes": [],
                    "count_uncertainties": [],
                },
                [],
                "not_applicable_reference",
                [],
            ),
            (
                "covered",
                {
                    "decision": "reject",
                    "requested_counts": {"white wayo dip cup": 4},
                    "missing_identifications": {},
                    "advisory_classes": [],
                    "count_uncertainties": [],
                },
                # Meet the human estimate (4) so recovery must stay off.
                [
                    self._v42_instance(3, [5 + index * 10, 5, 15 + index * 10, 15], source="existing")
                    for index in range(4)
                ],
                "not_triggered",
                [],
            ),
            (
                "zero",
                {
                    "decision": "reject",
                    "requested_counts": {"red teriyaki sauce cup": 0},
                    "missing_identifications": {},
                    "advisory_classes": [],
                    "count_uncertainties": [],
                },
                [],
                "not_triggered",
                [],
            ),
            (
                "packet-advisory",
                {
                    "decision": "reject",
                    "requested_counts": {"black and white soya sauce packet": 20},
                    "missing_identifications": {},
                    "advisory_classes": ["black and white soya sauce packet"],
                    "count_uncertainties": [],
                },
                [],
                "not_triggered",
                [],
            ),
        ]

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "not-needed.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            for name, correction, existing, expected_status, expected_ids in cases:
                with self.subTest(case=name):
                    fake = NeverCalledSam()
                    proposals, record = (
                        self.module.correction_guided_tiled_recovery(
                            fake,
                            image_path,
                            correction,
                            existing,
                            thresholds=list(
                                self.module.CORRECTION_GUIDED_TILED_THRESHOLDS
                            ),
                            max_raw_instances=128,
                            max_post_nms_instances=128,
                            iou=0.45,
                        )
                    )
                    self.assertEqual(proposals, [])
                    self.assertEqual(record["status"], expected_status)
                    self.assertEqual(record["selected_class_ids"], expected_ids)
                    self.assertFalse(record["triggered"])
                    self.assertFalse(record["accepted"])
                    self.assertEqual(fake.calls, 0)

    def test_v42_selected_tiles_translate_geometry_and_keep_provenance(self) -> None:
        """Every crop-local proposal returns bounded full-image coordinates."""

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "tiled.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            adapter = self.module.Sam31ImageAdapter(
                object(),
                Path(temp_dir) / "sessions",
            )
            calls = []

            def fake_single_image(
                crop_path,
                class_thresholds,
                *,
                class_prompts,
                class_ids,
                semantic_source,
                prompt_variant,
                max_raw_instances,
            ):
                calls.append(
                    {
                        "crop_path": crop_path,
                        "class_thresholds": list(class_thresholds),
                        "class_prompts": list(class_prompts),
                        "class_ids": list(class_ids),
                        "semantic_source": semantic_source,
                        "prompt_variant": prompt_variant,
                        "max_raw_instances": max_raw_instances,
                    }
                )
                return {
                    "instances": [
                        self._v42_instance(
                            class_ids[0],
                            [1, 2, 11, 12],
                            source=semantic_source,
                        )
                    ],
                    "prompts": [
                        {
                            "status": "success",
                            "raw_instance_count": 1,
                            "kept_instance_count": 1,
                        }
                    ],
                    "prompt_count": 1,
                    "successful_prompt_count": 1,
                    "raw_instance_count": 1,
                    "kept_instance_count": 1,
                }

            with mock.patch.object(
                adapter,
                "_discover_single_image",
                side_effect=fake_single_image,
            ):
                result = adapter.discover_tiled_selected(
                    str(image_path),
                    [1],
                    list(self.module.CORRECTION_GUIDED_TILED_THRESHOLDS),
                    aliases_by_class={
                        1: list(self.module.CORRECTION_GUIDED_TILED_PROMPTS[1])
                    },
                    semantic_source=self.module.CORRECTION_GUIDED_TILED_SOURCE,
                    prompt_variant="test_v42",
                    max_raw_instances=128,
                )

        self.assertEqual(result["selected_class_ids"], [1])
        self.assertEqual(result["tile_count"], 4)
        self.assertEqual(result["prompt_count"], 8)
        self.assertEqual(result["successful_prompt_count"], 8)
        self.assertEqual(result["raw_instance_count"], 8)
        self.assertEqual(len(calls), 8)
        self.assertTrue(all(call["class_ids"] == [1] for call in calls))
        self.assertEqual(
            {call["class_prompts"][1] for call in calls},
            set(self.module.CORRECTION_GUIDED_TILED_PROMPTS[1]),
        )
        self.assertEqual(len(result["instances"]), 8)
        self.assertTrue(
            any(
                row["sam3_prompt_tile_offset_xy"] != [0, 0]
                for row in result["instances"]
            )
        )
        for row in result["instances"]:
            x1, y1, x2, y2 = row["bbox_xyxy"]
            self.assertGreaterEqual(x1, 0)
            self.assertGreaterEqual(y1, 0)
            self.assertLessEqual(x2, 100)
            self.assertLessEqual(y2, 80)
            self.assertTrue(row["sam3_prompt_tiled_retry"])
            self.assertEqual(
                row["source"],
                self.module.CORRECTION_GUIDED_TILED_SOURCE,
            )
            self.assertIn(
                self.module.CORRECTION_GUIDED_TILED_SOURCE,
                row["proposal_sources"],
            )
            self.assertIn("sam3_prompt_tile_index", row)
            self.assertIn("sam3_prompt_alias", row)

    def test_v42_tiled_recovery_rejects_raw_and_post_nms_bounds_without_fabrication(
        self,
    ) -> None:
        """Any bound breach fails closed and emits no unbounded proposal set."""

        class BoundedFakeSam:
            def __init__(self, raw_count, instances):
                self.raw_count = raw_count
                self.instances = instances
                self.calls = 0

            def discover_tiled_selected(self, *args, **kwargs):
                self.calls += 1
                return {
                    "instances": list(self.instances),
                    "prompts": [
                        {"status": "success"} for _ in range(8)
                    ],
                    "prompt_count": 8,
                    "successful_prompt_count": 8,
                    "raw_instance_count": self.raw_count,
                    "tile_count": 4,
                }

        correction = {
            "decision": "reject",
            "requested_counts": {"white wayo dip cup": 2},
            "missing_identifications": {},
            "advisory_classes": [],
            "count_uncertainties": [],
        }
        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "bounded.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            raw_fake = BoundedFakeSam(
                5,
                [self._v42_instance(3, [10, 10, 20, 20])],
            )
            raw_proposals, raw_record = (
                self.module.correction_guided_tiled_recovery(
                    raw_fake,
                    image_path,
                    correction,
                    [],
                    thresholds=list(self.module.CORRECTION_GUIDED_TILED_THRESHOLDS),
                    max_raw_instances=4,
                    max_post_nms_instances=128,
                    iou=0.45,
                )
            )

            post_fake = BoundedFakeSam(
                2,
                [
                    self._v42_instance(3, [10, 10, 20, 20]),
                    self._v42_instance(3, [40, 40, 50, 50]),
                ],
            )
            post_proposals, post_record = (
                self.module.correction_guided_tiled_recovery(
                    post_fake,
                    image_path,
                    correction,
                    [],
                    thresholds=list(self.module.CORRECTION_GUIDED_TILED_THRESHOLDS),
                    max_raw_instances=128,
                    max_post_nms_instances=1,
                    iou=0.45,
                )
            )

        self.assertEqual(raw_fake.calls, 1)
        self.assertEqual(raw_proposals, [])
        self.assertEqual(raw_record["status"], "rejected")
        self.assertIn("raw_instance_count", raw_record["rejection_reason"])
        self.assertFalse(raw_record["accepted"])
        self.assertEqual(post_fake.calls, 1)
        self.assertEqual(post_proposals, [])
        self.assertEqual(post_record["status"], "rejected")
        self.assertIn("post_nms_instance_count", post_record["rejection_reason"])
        self.assertFalse(post_record["accepted"])
        self.assertFalse(post_record["count_targets_used_as_geometry"])

    def test_text_fallback_threshold_is_rejected_by_the_parser_outside_zero_to_two(self) -> None:
        with mock.patch.object(
            sys,
            "argv",
            [
                "assisted_label_review.py",
                "--input-bundle",
                "inputs.bundle",
                "--expected-input-archive-sha256",
                "a" * 64,
                "--correction-manifest",
                "corrections.json",
                "--expected-correction-manifest-sha256",
                "b" * 64,
                "--text-fallback-max-visual-proposals",
                "3",
            ],
        ), self.assertRaises(SystemExit):
            self.module.parse_args()

    def test_run_passes_expected_archive_hash_to_extractor_before_model_work(self) -> None:
        expected_hash = "b" * 64
        input_bundle = Path("frozen-inputs.bundle")
        arguments = SimpleNamespace(
            input_bundle=input_bundle,
            expected_input_archive_sha256=expected_hash,
        )
        extraction_stopped = RuntimeError("stop after archive boundary")
        with (
            mock.patch.object(self.module, "validate_kaggle_runtime"),
            mock.patch.object(
                self.module,
                "extract_input_bundle",
                side_effect=extraction_stopped,
            ) as extractor,
            self.assertRaisesRegex(RuntimeError, "stop after archive boundary"),
        ):
            self.module.run(arguments)
        extractor.assert_called_once_with(
            input_bundle,
            Path("/kaggle/working/assisted_label_inputs"),
            expected_hash,
        )

    def test_reference_reader_maps_all_seven_classes_and_quarantines_only_aggregate_chopstick(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            annotation = Path(temp_dir) / "reference.json"
            self.write_annotation(
                annotation,
                [
                    "Kraft Box",
                    "Soya Sauce Cup",
                    "Teriyaki Sauce Cup",
                    "Wayo Dip Sauce Cup",
                    "Chili Mayo Sauce Cup",
                    "Chopstick Tip",
                    "Soya Sauce Packet",
                    "Chopstick",
                ],
            )

            reference = self.module.read_reference_annotation(annotation)

            self.assertEqual([row["class_id"] for row in reference["instances"]], list(range(7)))
            self.assertEqual(
                [row["human_shape_index"] for row in reference["instances"]],
                list(range(7)),
            )
            self.assertEqual(
                reference["instances"][-1]["class_name"],
                "black and white soya sauce packet",
            )
            self.assertEqual(
                {row["raw_label"] for row in reference["quarantined_shapes"]},
                {"Chopstick"},
            )
            self.assertTrue(
                all(row["source"] == "human_rectangle_seed" for row in reference["instances"])
            )

    def test_three_complete_references_create_three_isolated_visual_prompt_plans(self) -> None:
        complete = []
        for name, offset in (("z.jpg", 0), ("a.jpg", 100), ("m.jpg", 200)):
            complete.append(
                {
                    "image_name": name,
                    "instances": [
                        {
                            "class_id": class_id,
                            "bbox_xyxy": [offset + class_id, 1, offset + class_id + 5, 8],
                        }
                        for class_id in range(7)
                    ],
                }
            )
        incomplete = {
            "image_name": "incomplete.jpg",
            "instances": [{"class_id": 0, "bbox_xyxy": [0, 0, 5, 5]}],
        }

        references = self.module.choose_complete_references([*complete, incomplete], minimum=3)
        plans = self.module.build_visual_prompt_plans(references, Path("/fixed/images"))

        self.assertEqual([row["image_name"] for row in references], ["a.jpg", "m.jpg", "z.jpg"])
        self.assertEqual(len(plans), 3)
        self.assertEqual([path.name for path, _boxes, _classes in plans], ["a.jpg", "m.jpg", "z.jpg"])
        self.assertEqual([classes.tolist() for _path, _boxes, classes in plans], [list(range(7))] * 3)
        # Each plan keeps only boxes from its own reference coordinate system.
        self.assertTrue(all(float(value) >= 100 for value in plans[0][1][:, 0]))
        self.assertTrue(all(200 <= float(value) < 300 for value in plans[1][1][:, 0]))
        self.assertTrue(all(float(value) < 100 for value in plans[2][1][:, 0]))

    def test_complete_reference_selection_fails_closed_when_consensus_bank_is_too_small(self) -> None:
        one_complete = {
            "image_name": "only.jpg",
            "instances": [
                {"class_id": class_id, "bbox_xyxy": [class_id, 1, class_id + 3, 6]}
                for class_id in range(7)
            ],
        }
        with self.assertRaisesRegex(ValueError, "at least 3"):
            self.module.choose_complete_references([one_complete], minimum=3)

    def test_packet_may_exist_in_only_three_of_six_references_but_must_cover_the_prompt_bank(self) -> None:
        references = []
        for index in range(6):
            class_ids = range(7) if index < 3 else range(6)
            references.append(
                {
                    "image_name": f"reference-{index}.jpg",
                    "instances": [
                        {
                            "class_id": class_id,
                            "bbox_xyxy": [class_id, 1, class_id + 3, 6],
                        }
                        for class_id in class_ids
                    ],
                }
            )

        selected = self.module.choose_complete_references(references, minimum=3)

        self.assertEqual(len(selected), 3)
        self.assertTrue(
            all(
                {row["class_id"] for row in reference["instances"]} == set(range(7))
                for reference in selected
            )
        )

    def test_tiles_cover_edges_and_prediction_coordinates_map_back_to_full_image(self) -> None:
        self.assertEqual(self.module.tile_starts(2500, 1280, 0.25), [0, 960, 1220])

        class Scalar:
            def __init__(self, value):
                self.value = value

            def item(self):
                return self.value

        class Array:
            def __init__(self, value):
                self.value = value

            def __getitem__(self, _index):
                return self

            def tolist(self):
                return self.value

        box = type(
            "Box",
            (),
            {"cls": Scalar(5), "conf": Scalar(0.8), "xyxy": Array([10, 20, 30, 40])},
        )()
        result = type(
            "Result",
            (),
            {
                "boxes": [box],
                "masks": type("Masks", (), {"xy": [[[10, 20], [30, 20], [30, 40]]]}),
            },
        )()

        rows = self.module.result_instances(result, left=960, top=1220, reference_name="ref.jpg")

        self.assertEqual(rows[0]["bbox_xyxy"], [970.0, 1240.0, 990.0, 1260.0])
        self.assertEqual(rows[0]["polygon"][0], [970.0, 1240.0])
        self.assertEqual(rows[0]["reference_image"], "ref.jpg")

    def test_pil_rgb_tile_is_converted_to_bgr_numpy_for_ultralytics(self) -> None:
        tile = Image.new("RGB", (1, 1), color=(11, 22, 33))

        source = self.module.pil_rgb_to_ultralytics_bgr(tile)

        self.assertEqual(source.dtype, np.uint8)
        self.assertEqual(source.shape, (1, 1, 3))
        self.assertEqual(source[0, 0].tolist(), [33, 22, 11])

    def test_visual_prompt_prediction_passes_bgr_array_into_model_predict(self) -> None:
        class FakeModel:
            def __init__(self):
                self.sources = []
                self.calls = []

            def predict(self, **arguments):
                self.calls.append(arguments)
                self.sources.append(arguments["source"])
                return [
                    type(
                        "Result",
                        (),
                        {"boxes": [], "masks": type("Masks", (), {"xy": []})()},
                    )()
                ]

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            target = root / "target.jpg"
            # PNG encoding under a .jpg-independent temporary path would be
            # misleading, so save a lossless BMP value and use its real suffix.
            target = root / "target.bmp"
            Image.new("RGB", (1, 1), color=(11, 22, 33)).save(target)
            model = FakeModel()

            predictions = self.module.visual_prompt_targets(
                model=model,
                visual_prompt_plans=[
                    (
                        root / "reference.jpg",
                        np.asarray([[0, 0, 1, 1]], dtype=np.float32),
                        np.asarray([0], dtype=np.int64),
                    )
                ],
                target_images=[target],
                device="0",
                tile_size=1280,
                overlap=0.25,
                confidence=0.05,
                iou=0.45,
                minimum_reference_support=2,
                predictor_class=object,
            )

            self.assertEqual(predictions[target.name], [])
            self.assertEqual(model.sources[0][0, 0].tolist(), [33, 22, 11])
            self.assertTrue(
                all(call["agnostic_nms"] is False for call in model.calls)
            )

    def test_audited_visual_recovery_reference_crops_preserve_small_prompt_scale(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            images_root = root / "images"
            crop_root = root / "crops"
            images_root.mkdir()
            references = []
            for reference_index in range(3):
                image_name = f"reference-{reference_index}.jpg"
                Image.new("RGB", (2000, 3000), color=(20, 30, 40)).save(
                    images_root / image_name
                )
                references.append(
                    {
                        "image_name": image_name,
                        "instances": [
                            {
                                "class_id": 5,
                                "bbox_xyxy": [
                                    800 + reference_index,
                                    1200,
                                    820 + reference_index,
                                    1220,
                                ],
                                "human_shape_index": reference_index,
                            },
                            {
                                "class_id": 1,
                                "bbox_xyxy": [
                                    700,
                                    1700,
                                    900,
                                    1820,
                                ],
                                "human_shape_index": 10 + reference_index,
                            },
                        ],
                    }
                )

            plans = self.module.build_audited_visual_recovery_plans(
                references,
                images_root,
                crop_root,
            )

            self.assertEqual(sorted(plans), [1, 5])
            self.assertEqual(len(plans[1]), 3)
            self.assertEqual(len(plans[5]), 3)
            for class_id, class_plans in plans.items():
                for plan in class_plans:
                    self.assertEqual(plan["class_id"], class_id)
                    self.assertTrue(plan["reference_crop"].is_file())
                    self.assertEqual(len(plan["reference_crop_sha256"]), 64)
                    self.assertTrue(np.all(plan["prompt_classes"] == 0))
                    crop_width = (
                        plan["reference_crop_xyxy"][2]
                        - plan["reference_crop_xyxy"][0]
                    )
                    self.assertLessEqual(
                        crop_width,
                        self.module.AUDITED_VISUAL_RECOVERY_REFERENCE_MAX_SIDE,
                    )
                    self.assertGreaterEqual(
                        float(plan["prompt_boxes"][0][0]),
                        0.0,
                    )

    def test_audited_tip_reference_selection_prefers_rich_individual_banks(self) -> None:
        """Dense audited tip banks must beat a few oversized rectangles.

        This protects the V44 diagnosis: selection reads only trusted reference
        annotations, but should not let five unusually large rectangles replace
        dozens of clearly labeled individual chopstick tips.
        """
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            images_root = root / "images"
            crop_root = root / "crops"
            images_root.mkdir()
            definitions = (
                ("sparse-oversized.jpg", 5, [10, 10, 100, 80]),
                ("dense-alpha.jpg", 18, [10, 10, 30, 30]),
                ("dense-beta.jpg", 39, [10, 10, 30, 30]),
                ("dense-gamma.jpg", 48, [10, 10, 30, 30]),
            )
            references = []
            for image_name, count, base_box in definitions:
                Image.new("RGB", (1200, 1200), color=(20, 30, 40)).save(
                    images_root / image_name
                )
                instances = []
                for index in range(count):
                    x1 = base_box[0] + (index % 8) * 35
                    y1 = base_box[1] + (index // 8) * 35
                    width = base_box[2] - base_box[0]
                    height = base_box[3] - base_box[1]
                    instances.append(
                        {
                            "class_id": 5,
                            "bbox_xyxy": [x1, y1, x1 + width, y1 + height],
                            "human_shape_index": index,
                        }
                    )
                references.append({"image_name": image_name, "instances": instances})

            plans = self.module.build_audited_visual_recovery_plans(
                references,
                images_root,
                crop_root,
                class_ids=(5,),
            )

            self.assertEqual(
                [plan["reference_image"] for plan in plans[5]],
                ["dense-gamma.jpg", "dense-beta.jpg", "dense-alpha.jpg"],
            )
            self.assertEqual(
                [plan["reference_class_instance_count"] for plan in plans[5]],
                [48, 39, 18],
            )
            self.assertTrue(
                all(
                    plan["reference_selection_policy"]
                    == "richest_audited_individual_tip_bank_then_anchor_area"
                    for plan in plans[5]
                )
            )

    def test_triggered_contact_sheet_embeds_the_exact_audited_reference_crops(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            target = root / "target.jpg"
            Image.new("RGB", (320, 240), color=(40, 50, 60)).save(target)
            plans = []
            for reference_index in range(3):
                crop_path = root / f"reference-{reference_index}.jpg"
                Image.new(
                    "RGB",
                    (120, 100),
                    color=(150, 20 + reference_index * 30, 20),
                ).save(crop_path)
                plans.append(
                    {
                        "class_id": 1,
                        "reference_image": f"reference-{reference_index}.jpg",
                        "reference_crop": crop_path,
                    }
                )
            instance = {
                "class_id": 1,
                "bbox_xyxy": [90, 70, 150, 140],
                "polygon": [[90, 70], [150, 70], [150, 140], [90, 140]],
                "sam3_refinement_status": "success",
            }
            plain_sheet = root / "plain.jpg"
            recovered_sheet = root / "recovered.jpg"
            self.module.draw_contact_sheet(target, [instance], plain_sheet)
            self.module.draw_contact_sheet(
                target,
                [instance],
                recovered_sheet,
                audited_visual_recovery_record={
                    "triggered": True,
                    "selected_class_ids": [1],
                },
                audited_visual_recovery_plans={1: plans},
            )

            with Image.open(plain_sheet) as plain, Image.open(recovered_sheet) as recovered:
                self.assertEqual(plain.width, recovered.width)
                self.assertGreater(recovered.height, plain.height)

    def test_audited_visual_recovery_uses_presence_only_and_two_reference_consensus(self) -> None:
        class Scalar:
            def __init__(self, value):
                self.value = value

            def item(self):
                return self.value

        class Array:
            def __init__(self, value):
                self.value = value

            def __getitem__(self, _index):
                return self

            def tolist(self):
                return self.value

        class FakeModel:
            def __init__(self):
                self.calls = []

            def predict(self, **arguments):
                self.calls.append(arguments)
                reference_name = Path(arguments["refer_image"]).name
                # Two independent references agree on the same target box; the
                # third deliberately returns nothing.
                if reference_name.startswith(("a", "b")):
                    box = type(
                        "Box",
                        (),
                        {
                            "cls": Scalar(0),
                            "conf": Scalar(0.8),
                            "xyxy": Array([10, 20, 30, 40]),
                        },
                    )()
                    masks = type(
                        "Masks",
                        (),
                        {"xy": [[[10, 20], [30, 20], [30, 40]]]},
                    )()
                    return [type("Result", (), {"boxes": [box], "masks": masks})()]
                return [
                    type(
                        "Result",
                        (),
                        {"boxes": [], "masks": type("Masks", (), {"xy": []})()},
                    )()
                ]

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            target = root / "target.jpg"
            Image.new("RGB", (100, 100)).save(target)
            plans = {
                5: [
                    {
                        "class_id": 5,
                        "reference_image": f"{name}.jpg",
                        "reference_crop": root / f"{name}.jpg",
                        "prompt_boxes": np.asarray([[1, 1, 10, 10]], dtype=np.float32),
                        "prompt_classes": np.asarray([0], dtype=np.int64),
                    }
                    for name in ("a", "b", "c")
                ]
            }
            model = FakeModel()
            predictions, records = self.module.audited_visual_prompt_recovery(
                model=model,
                plans_by_class=plans,
                target_images=[target],
                corrections={
                    target.name: {
                        "decision": "reject",
                        # A large value proves count magnitude does not control
                        # the single consensus proposal that is emitted.
                        "requested_counts": {"wooden chopstick tip": 36},
                        "missing_identifications": {},
                        "advisory_classes": [],
                        "count_uncertainties": [],
                    }
                },
                existing_predictions={target.name: []},
                device="0",
                tile_size=1280,
                overlap=0.25,
                confidence=0.05,
                iou=0.45,
                minimum_reference_support=2,
                max_raw_instances=20,
                max_post_nms_instances=10,
                predictor_class=object,
            )

            self.assertEqual(len(predictions[target.name]), 1)
            proposal = predictions[target.name][0]
            self.assertEqual(proposal["class_id"], 5)
            self.assertEqual(proposal["reference_support_count"], 2)
            self.assertEqual(
                proposal["source"],
                self.module.AUDITED_VISUAL_RECOVERY_SOURCE,
            )
            self.assertTrue(records[target.name]["accepted"])
            self.assertFalse(records[target.name]["count_targets_used_as_geometry"])
            self.assertEqual(records[target.name]["proposal_count"], 1)
            self.assertEqual(len(model.calls), 3)
            per_class = records[target.name]["per_class"]
            # Assert against the configured tile settings rather than a literal,
            # so tuning the class-5 tile for magnification does not require
            # editing a test that is really about presence-only consensus.
            expected_tiles = self.module.AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS[5]
            self.assertEqual(
                per_class[0]["target_tile_size"], expected_tiles["tile_size"]
            )
            self.assertEqual(
                per_class[0]["target_tile_overlap"], expected_tiles["overlap"]
            )
            self.assertEqual(
                per_class[0]["target_inference_imgsz"], expected_tiles["inference_imgsz"]
            )
            # Magnification alone is NOT the thing to maximise here, and an
            # earlier version of this test asserted that it was. Halving the tile
            # to 480 doubled magnification and made class 5 strictly worse
            # (mega-eg / techhub / startup-labs went from 1 raw tip to 0, the gate
            # fell 6/12 -> 5/12), because magnification parity ties the exemplar
            # crop to the tile, so a smaller tile also means a smaller crop
            # containing fewer tip prompt boxes. The prompt evidence mattered more
            # than the zoom.
            #
            # So the invariant worth protecting is that the exemplar and the
            # target are presented at the SAME scale — parity — not that the
            # scale is large. Anything that decouples them (raising imgsz, or
            # cropping to the holder) can revisit magnification on its own.
            self.assertEqual(
                expected_tiles["tile_size"],
                960,
                "960 is the measured best tile for class 5; 480 was tried and regressed",
            )
            for call in model.calls:
                self.assertEqual(call["visual_prompts"]["cls"].tolist(), [0])
                self.assertTrue(Path(call["refer_image"]).name in {"a.jpg", "b.jpg", "c.jpg"})
                self.assertEqual(call["imgsz"], 1280)

    def test_audited_visual_recovery_keeps_default_tiles_for_black_cups(self) -> None:
        """V44 must not silently change the existing black-cup search lane."""
        class EmptyModel:
            def __init__(self):
                self.calls = []

            def predict(self, **arguments):
                self.calls.append(arguments)
                return [
                    type(
                        "Result",
                        (),
                        {"boxes": [], "masks": type("Masks", (), {"xy": []})()},
                    )()
                ]

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            target = root / "target.jpg"
            Image.new("RGB", (1400, 1000)).save(target)
            plans = {
                1: [
                    {
                        "class_id": 1,
                        "reference_image": f"cup-{name}.jpg",
                        "reference_crop": root / f"cup-{name}.jpg",
                        "prompt_boxes": np.asarray([[1, 1, 10, 10]], dtype=np.float32),
                        "prompt_classes": np.asarray([0], dtype=np.int64),
                    }
                    for name in ("a", "b", "c")
                ]
            }
            model = EmptyModel()
            _predictions, records = self.module.audited_visual_prompt_recovery(
                model=model,
                plans_by_class=plans,
                target_images=[target],
                corrections={
                    target.name: {
                        "decision": "reject",
                        "requested_counts": {"black soya sauce cup": 1},
                        "missing_identifications": {},
                        "advisory_classes": [],
                        "count_uncertainties": [],
                    }
                },
                existing_predictions={target.name: []},
                device="0",
                tile_size=1280,
                overlap=0.25,
                confidence=0.05,
                iou=0.45,
                minimum_reference_support=2,
                max_raw_instances=20,
                max_post_nms_instances=10,
                predictor_class=object,
            )

            self.assertEqual(len(model.calls), 6)
            self.assertTrue(all(call["imgsz"] == 1280 for call in model.calls))
            per_class = records[target.name]["per_class"]
            self.assertEqual(per_class[0]["target_tile_size"], 1280)
            self.assertEqual(per_class[0]["target_tile_overlap"], 0.25)
            self.assertEqual(per_class[0]["target_inference_imgsz"], 1280)


    def test_audited_visual_recovery_keeps_required_chopsticks_active_despite_old_advisory_notes(
        self,
    ) -> None:
        """Legacy "advisory" wording must not suppress required chopstick recovery."""

        correction = {
            "decision": "reject",
            "requested_counts": {
                "black soya sauce cup": 7,
                "wooden chopstick tip": 36,
            },
            "missing_identifications": {},
            "advisory_classes": ["wooden chopstick tip"],
            "count_uncertainties": [],
        }
        # Soya already meets the human estimate of 7, but chopsticks remain a
        # required shortfall.  The stale advisory note must not suppress class 5.
        existing = [{"class_id": 1} for _ in range(7)]

        selected = self.module.audited_visual_recovery_class_ids(
            correction,
            existing,
        )

        self.assertEqual(selected, [5])

        # One soya cup with estimate 7 still needs soya recovery, and chopsticks
        # are still required too.
        short = self.module.audited_visual_recovery_class_ids(
            correction,
            [{"class_id": 1}],
        )
        self.assertEqual(short, [1, 5])

    def test_text_prompt_model_is_freshly_loaded_and_binds_exact_fixed_embeddings(self) -> None:
        class FakeYOLOE:
            instances = []

            def __init__(self, path):
                self.path = path
                self.get_calls = []
                self.set_calls = []
                self.__class__.instances.append(self)

            def get_text_pe(self, names):
                self.get_calls.append(list(names))
                return "fixed-text-embeddings"

            def set_classes(self, names, embeddings):
                self.set_calls.append((list(names), embeddings))
                self.names = list(names)

        first = self.module.load_text_prompt_model(Path("yoloe-26x-seg.pt"), FakeYOLOE)
        second = self.module.load_text_prompt_model(Path("yoloe-26x-seg.pt"), FakeYOLOE)

        self.assertIsNot(first, second)
        self.assertEqual(first.path, "yoloe-26x-seg.pt")
        self.assertEqual(first.get_calls, [self.module.FIXED_CLASS_NAMES])
        self.assertEqual(
            first.set_calls,
            [(self.module.FIXED_CLASS_NAMES, "fixed-text-embeddings")],
        )

    def test_text_prompt_model_rejects_a_wrong_order_set_classes_no_op(self) -> None:
        class WrongOrderYOLOE:
            def __init__(self, _path):
                names = list(self.module_names)
                names[0], names[1] = names[1], names[0]
                self.names = dict(enumerate(names))

            def get_text_pe(self, _names):
                return "fixed-text-embeddings"

            def set_classes(self, _names, _embeddings):
                # Reproduce the 8.4.93 same-name-set early return: the live
                # names remain in their incorrect positional order.
                return None

        WrongOrderYOLOE.module_names = self.module.FIXED_CLASS_NAMES

        with self.assertRaisesRegex(RuntimeError, "exact fixed class order"):
            self.module.load_text_prompt_model(
                Path("yoloe-26x-seg.pt"),
                WrongOrderYOLOE,
            )

    def test_text_prompt_tiles_use_normal_predictor_and_full_image_coordinates(self) -> None:
        class Scalar:
            def __init__(self, value):
                self.value = value

            def item(self):
                return self.value

        class Array:
            def __init__(self, value):
                self.value = value

            def __getitem__(self, _index):
                return self

            def tolist(self):
                return self.value

        box = SimpleNamespace(
            cls=Scalar(6),
            conf=Scalar(0.81),
            xyxy=Array([10, 20, 30, 40]),
        )
        result = SimpleNamespace(
            boxes=[box],
            masks=SimpleNamespace(xy=[[[10, 20], [30, 20], [30, 40]]]),
        )

        class FakeTextModel:
            def __init__(self):
                self.calls = []

            def predict(self, **arguments):
                self.calls.append(arguments)
                return [result]

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "weak.bmp"
            Image.new("RGB", (2500, 1), color=(11, 22, 33)).save(image_path)
            model = FakeTextModel()

            predictions = self.module.text_prompt_targets(
                model=model,
                target_images=[image_path],
                device="0",
                tile_size=1280,
                overlap=0.25,
                confidence=0.04,
                iou=0.45,
            )

        self.assertEqual(len(model.calls), 3)
        self.assertTrue(
            all(
                "refer_image" not in call
                and "visual_prompts" not in call
                and "predictor" not in call
                and call["agnostic_nms"] is False
                for call in model.calls
            )
        )
        rows = predictions[image_path.name]
        self.assertEqual(len(rows), 3)
        self.assertEqual(rows[1]["bbox_xyxy"], [970.0, 20.0, 990.0, 40.0])
        self.assertEqual(rows[1]["source"], "yoloe26x_text_prompt_tiled")
        self.assertEqual(rows[1]["proposal_sources"], ["yoloe26x_text_prompt_tiled"])
        self.assertEqual(rows[1]["reference_support_count"], 0)

    def test_exif_oriented_reference_is_materialized_before_yoloe_receives_its_path(self) -> None:
        class FakeModel:
            def __init__(self):
                self.reference_sizes = []
                self.reference_orientations = []

            def predict(self, **arguments):
                with Image.open(arguments["refer_image"]) as reference:
                    self.reference_sizes.append(reference.size)
                    self.reference_orientations.append(reference.getexif().get(274))
                return [
                    type(
                        "Result",
                        (),
                        {"boxes": [], "masks": type("Masks", (), {"xy": []})()},
                    )()
                ]

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            raw_images = root / "raw"
            raw_images.mkdir()
            reference = raw_images / "reference.jpg"
            target = raw_images / "target.jpg"
            exif = Image.Exif()
            exif[274] = 6
            Image.new("RGB", (4, 2), color=(11, 22, 33)).save(reference, exif=exif)
            Image.new("RGB", (4, 2), color=(44, 55, 66)).save(target, exif=exif)
            oriented_root = root / "oriented"

            materialized = self.module.materialize_oriented_images(
                raw_images,
                oriented_root,
                [reference.name, target.name],
            )
            model = FakeModel()
            self.module.visual_prompt_targets(
                model=model,
                visual_prompt_plans=[
                    (
                        materialized[reference.name],
                        np.asarray([[0, 0, 1, 1]], dtype=np.float32),
                        np.asarray([0], dtype=np.int64),
                    )
                ],
                target_images=[materialized[target.name]],
                device="0",
                tile_size=1280,
                overlap=0.25,
                confidence=0.05,
                iou=0.45,
                minimum_reference_support=2,
                predictor_class=object,
            )

            # The raw JPEG is landscape, while AnyLabeling showed and labeled
            # the EXIF-correct portrait image. Ultralytics must therefore see a
            # real portrait file with no remaining orientation tag to apply.
            with Image.open(reference) as raw_reference:
                self.assertEqual(raw_reference.size, (4, 2))
            self.assertEqual(model.reference_sizes, [(2, 4)])
            self.assertEqual(model.reference_orientations, [None])

    def test_exif_oriented_sam_source_is_materialized_in_the_prompt_coordinate_space(self) -> None:
        class FakeTensor:
            """Small CUDA-like wrapper used to exercise the production conversion path."""

            def __init__(self, value):
                self.value = value

            def detach(self):
                return self

            def cpu(self):
                return self

            def numpy(self):
                return self.value

        class FakeSam31Predictor:
            def __init__(self):
                self.source_size = None
                self.source_orientation = None
                self.session_id = "session"

            def handle_request(self, request):
                if request["type"] == "start_session":
                    with Image.open(Path(request["resource_path"])) as source:
                        self.source_size = source.size
                        self.source_orientation = source.getexif().get(274)
                    return {"session_id": self.session_id}
                if request["type"] == "add_prompt":
                    self.assert_instance_box_request(request)
                    return {
                        "outputs": {
                            # SAM may return accelerator tensors instead of
                            # NumPy arrays; the adapter must detach/copy them.
                            "out_binary_masks": FakeTensor(
                                np.asarray([[[1, 1], [1, 1]]], dtype=bool)
                            ),
                            "out_boxes_xywh": FakeTensor(
                                np.asarray([[0.0, 0.0, 0.5, 0.5]], dtype=np.float32)
                            ),
                        }
                    }
                if request["type"] == "reset_session":
                    return {"is_success": True}
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

            @staticmethod
            def assert_instance_box_request(request):
                if request.get("point_labels") != [2, 3]:
                    raise AssertionError(request)
                if request.get("obj_id") != 0 or "bounding_boxes" in request:
                    raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            raw_images = root / "raw"
            raw_images.mkdir()
            raw = raw_images / "reference.jpg"
            exif = Image.Exif()
            exif[274] = 6
            Image.new("RGB", (4, 2), color=(11, 22, 33)).save(raw, exif=exif)
            oriented = self.module.materialize_oriented_images(
                raw_images,
                root / "oriented",
                [raw.name],
            )[raw.name]
            predictor = FakeSam31Predictor()
            model = self.module.Sam31ImageAdapter(predictor, root / "sessions")

            refined = self.module.sam3_refine_image(
                model,
                oriented,
                [{
                    "class_id": 0,
                    "bbox_xyxy": [0, 0, 1, 2],
                    "source": "human_rectangle_seed",
                }],
                "0",
            )

            self.assertEqual(predictor.source_size, (2, 4))
            self.assertIsNone(predictor.source_orientation)
            self.assertEqual(len(refined), 1)

    def test_sam3_refinement_submits_one_box_at_a_time_and_selects_best_match(self) -> None:
        first_mask = np.zeros((80, 80), dtype=bool)
        first_mask[0:10, 0:10] = True
        second_mask = np.zeros((80, 80), dtype=bool)
        second_mask[50:70, 50:70] = True

        class PermutedSam31Predictor:
            def __init__(self):
                self.add_prompt_requests = []
                self.reset_count = 0

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "add_prompt":
                    self.add_prompt_requests.append(request)
                    # A single visual exemplar can return several concept
                    # matches. Return them in the opposite geometric order and
                    # verify that the adapter keeps only the best-IoU instance.
                    return {
                        "outputs": {
                            "out_binary_masks": np.asarray(
                                [second_mask, first_mask], dtype=bool
                            ),
                            "out_boxes_xywh": np.asarray(
                                [[0.625, 0.625, 0.25, 0.25], [0.0, 0.0, 0.125, 0.125]],
                                dtype=np.float32,
                            ),
                        }
                    }
                if request["type"] == "reset_session":
                    self.reset_count += 1
                    return {"is_success": True}
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (80, 80), color=(11, 22, 33)).save(image_path)
            instances = [
                {"class_id": 1, "bbox_xyxy": [0, 0, 10, 10], "source": "human_rectangle_seed"},
                {"class_id": 4, "bbox_xyxy": [50, 50, 70, 70], "source": "human_rectangle_seed"},
            ]
            predictor = PermutedSam31Predictor()
            model = self.module.Sam31ImageAdapter(predictor, Path(temp_dir) / "sessions")
            refined = self.module.sam3_refine_image(model, image_path, instances, "0")

        self.assertEqual([row["class_id"] for row in refined], [1, 4])
        self.assertEqual([row["sam3_prompt_match_iou"] for row in refined], [1.0, 1.0])
        self.assertEqual(len(predictor.add_prompt_requests), 2)
        self.assertEqual(predictor.reset_count, 2)
        self.assertTrue(
            all(
                len(request["points"]) == 2
                and request["point_labels"] == [2, 3]
                and request["clear_old_points"] is True
                and request["obj_id"] == 0
                and "bounding_boxes" not in request
                for request in predictor.add_prompt_requests
            )
        )

    def test_sam31_keeps_successful_instance_masks_and_marks_only_failed_prompt_fallback(self) -> None:
        valid_mask = np.zeros((100, 100), dtype=bool)
        valid_mask[10:30, 10:30] = True

        class OneSuccessOneEmptyPredictor:
            def __init__(self):
                self.prompt_count = 0

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "reset_session":
                    return {"is_success": True}
                if request["type"] == "add_prompt":
                    self.prompt_count += 1
                    if self.prompt_count == 1:
                        return {
                            "outputs": {
                                "out_binary_masks": np.asarray([valid_mask]),
                                "out_boxes_xywh": np.asarray(
                                    [[0.1, 0.1, 0.2, 0.2]], dtype=np.float32
                                ),
                            }
                        }
                    return {
                        "outputs": {
                            "out_binary_masks": np.zeros((0, 100, 100), dtype=bool),
                            "out_boxes_xywh": np.zeros((0, 4), dtype=np.float32),
                        }
                    }
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (100, 100), color=(11, 22, 33)).save(image_path)
            model = self.module.Sam31ImageAdapter(
                OneSuccessOneEmptyPredictor(), Path(temp_dir) / "sessions"
            )
            proposals = [
                {
                    "class_id": 0,
                    "class_name": "kraft paper bowl",
                    "bbox_xyxy": [10, 10, 30, 30],
                    "polygon": [],
                    "source": "human_rectangle_seed",
                },
                {
                    "class_id": 1,
                    "class_name": "black soya sauce cup",
                    "bbox_xyxy": [50, 50, 70, 70],
                    "polygon": [[50, 50], [70, 50], [70, 70]],
                    "source": "yoloe26x_visual_prompt_tiled",
                },
            ]
            refined, status, error = self.module.sam3_refine_or_preserve(
                model, image_path, proposals, "0"
            )

        self.assertEqual(status, "success_with_instance_fallbacks")
        self.assertIn("prompt 1", error)
        self.assertEqual(refined[0]["sam3_refinement_status"], "success")
        self.assertEqual(
            refined[1]["sam3_refinement_status"], "fallback_yoloe_polygon"
        )
        self.assertEqual(refined[1]["polygon"], proposals[1]["polygon"])

    def test_sam31_failed_audited_seed_uses_visible_rectangle_review_fallback(self) -> None:
        class EmptyPredictor:
            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "reset_session":
                    return {"is_success": True}
                if request["type"] == "add_prompt":
                    return {
                        "outputs": {
                            "out_binary_masks": np.zeros((0, 20, 20), dtype=bool),
                            "out_boxes_xywh": np.zeros((0, 4), dtype=np.float32),
                        }
                    }
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "reference.jpg"
            Image.new("RGB", (20, 20), color=(11, 22, 33)).save(image_path)
            model = self.module.Sam31ImageAdapter(
                EmptyPredictor(), Path(temp_dir) / "sessions"
            )
            proposals = [{
                "class_id": 0,
                "class_name": "kraft paper bowl",
                "bbox_xyxy": [2, 3, 12, 13],
                "source": "human_rectangle_seed",
            }]
            refined, status, _error = self.module.sam3_refine_or_preserve(
                model, image_path, proposals, "0"
            )

        self.assertEqual(status, "success_with_instance_fallbacks")
        self.assertEqual(
            refined[0]["sam3_refinement_status"], "fallback_audited_rectangle"
        )
        self.assertEqual(
            refined[0]["polygon"],
            [[2.0, 3.0], [12.0, 3.0], [12.0, 13.0], [2.0, 13.0]],
        )

    def test_sam31_refines_more_than_twenty_boxes_without_joint_prompt_cap(self) -> None:
        class OneBoxPredictor:
            def __init__(self):
                self.prompt_count = 0

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "add_prompt":
                    self.prompt_count += 1
                    self.assert_single_instance_box(request)
                    (x1, y1), (x2, y2) = request["points"]
                    mask = np.ones((8, 8), dtype=bool)
                    return {
                        "outputs": {
                            "out_binary_masks": np.asarray([mask]),
                            "out_boxes_xywh": np.asarray(
                                [[x1, y1, x2 - x1, y2 - y1]], dtype=np.float32
                            ),
                        }
                    }
                if request["type"] == "reset_session":
                    return {"is_success": True}
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

            @staticmethod
            def assert_single_instance_box(request):
                if len(request["points"]) != 2:
                    raise AssertionError("SAM 3.1 instance prompt is not one box.")
                if request["point_labels"] != [2, 3]:
                    raise AssertionError("SAM 3.1 box corner labels must be 2 and 3.")
                if request.get("obj_id") != 0 or "bounding_boxes" in request:
                    raise AssertionError("SAM 3.1 semantic box path was used.")

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (100, 100), color=(11, 22, 33)).save(image_path)
            boxes = [[index, index, index + 2, index + 2] for index in range(21)]
            predictor = OneBoxPredictor()
            model = self.module.Sam31ImageAdapter(
                predictor, Path(temp_dir) / "sessions"
            )
            result = model.refine(str(image_path), boxes)

        self.assertEqual(predictor.prompt_count, 21)
        self.assertEqual(len(result["boxes"]), 21)
        self.assertEqual(len(result["polygons"]), 21)

    def test_sam31_semantic_discovery_queries_all_fixed_prompts_and_converts_outputs(self) -> None:
        class SemanticPredictor:
            def __init__(self):
                self.requests = []
                self.started_session_ids = []
                self.close_requests = []

            def handle_request(self, request):
                request_type = request["type"]
                if request_type == "start_session":
                    session_id = f"semantic-session-{len(self.started_session_ids)}"
                    self.started_session_ids.append(session_id)
                    return {"session_id": session_id}
                if request_type == "add_prompt":
                    self.requests.append(request)
                    prompt_index = len(self.requests) - 1
                    mask = np.zeros((80, 100), dtype=bool)
                    mask[10 + prompt_index:20 + prompt_index, 20:35] = True
                    return {
                        "outputs": {
                            "out_binary_masks": mask[None, ...],
                            "out_boxes_xywh": np.asarray(
                                [[0.2, 0.125, 0.15, 0.125]],
                                dtype=np.float32,
                            ),
                            "out_probs": np.asarray([0.91], dtype=np.float32),
                        }
                    }
                if request_type == "close_session":
                    self.close_requests.append(request)
                    return {
                        "is_success": True,
                        "gpu_mem": {
                            "active_session_count": 0,
                            "free_bytes": 123,
                        },
                    }
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "semantic.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            predictor = SemanticPredictor()
            adapter = self.module.Sam31ImageAdapter(
                predictor, Path(temp_dir) / "sessions"
            )
            thresholds = [0.41 + index * 0.01 for index in range(7)]
            result = adapter.discover(str(image_path), thresholds)

        self.assertEqual(len(predictor.started_session_ids), 7)
        self.assertEqual(len(predictor.close_requests), 7)
        self.assertEqual(
            [request["session_id"] for request in predictor.requests],
            predictor.started_session_ids,
        )
        self.assertEqual(
            [request["session_id"] for request in predictor.close_requests],
            predictor.started_session_ids,
        )
        self.assertTrue(
            all(request["run_gc_collect"] is True for request in predictor.close_requests)
        )
        self.assertTrue(
            all(
                request["clear_cache_threshold"] == 0
                for request in predictor.close_requests
            )
        )
        self.assertEqual(
            adapter.session_close_diagnostics,
            [{
                "is_success": True,
                "gpu_mem": {
                    "active_session_count": 0,
                    "free_bytes": 123,
                },
            }] * 7,
        )
        self.assertEqual(len(predictor.requests), 7)
        self.assertEqual(
            [request["text"] for request in predictor.requests],
            self.module.FIXED_CLASS_NAMES,
        )
        self.assertEqual(
            [request["output_prob_thresh"] for request in predictor.requests],
            thresholds,
        )
        self.assertEqual(result["prompt_count"], 7)
        self.assertEqual(result["successful_prompt_count"], 7)
        self.assertEqual(len(result["instances"]), 7)
        self.assertEqual(
            [row["class_id"] for row in result["instances"]],
            list(range(7)),
        )
        self.assertEqual(result["instances"][0]["bbox_xyxy"], [20.0, 10.0, 35.0, 20.0])
        self.assertEqual(
            result["instances"][0]["source"],
            self.module.SAM31_SEMANTIC_SOURCE,
        )
        self.assertEqual(
            result["instances"][0]["sam3_prompt_method"],
            "semantic_text_prompt",
        )

    def test_sam31_semantic_discovery_closes_failed_prompt_session_before_raising(self) -> None:
        class FailingSemanticPredictor:
            def __init__(self):
                self.close_requests = []

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "failing-semantic-session"}
                if request["type"] == "add_prompt":
                    raise RuntimeError("simulated semantic decoder failure")
                if request["type"] == "close_session":
                    self.close_requests.append(request)
                    return {
                        "is_success": True,
                        "gpu_mem": {"active_session_count": 0},
                    }
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "semantic.jpg"
            Image.new("RGB", (20, 20), color=(30, 30, 30)).save(image_path)
            predictor = FailingSemanticPredictor()
            adapter = self.module.Sam31ImageAdapter(
                predictor, Path(temp_dir) / "sessions"
            )
            with self.assertRaisesRegex(RuntimeError, "decoder failure"):
                adapter.discover(
                    str(image_path),
                    list(self.module.SAM31_SEMANTIC_THRESHOLDS),
                )

        self.assertEqual(len(predictor.close_requests), 1)
        self.assertEqual(
            predictor.close_requests[0]["session_id"],
            "failing-semantic-session",
        )
        self.assertIs(predictor.close_requests[0]["run_gc_collect"], True)
        self.assertEqual(
            predictor.close_requests[0]["clear_cache_threshold"],
            0,
        )

    def test_sam31_semantic_discovery_retries_cuda_oom_as_overlapping_tiles(self) -> None:
        class OutOfMemoryError(RuntimeError):
            pass

        class OomThenHealthyPredictor:
            def __init__(self):
                self.started_session_ids = []
                self.close_requests = []
                self.prompt_count = 0

            def handle_request(self, request):
                request_type = request["type"]
                if request_type == "start_session":
                    session_id = f"session-{len(self.started_session_ids)}"
                    self.started_session_ids.append(session_id)
                    return {"session_id": session_id}
                if request_type == "add_prompt":
                    self.prompt_count += 1
                    if self.prompt_count == 1:
                        raise OutOfMemoryError(
                            "CUDA out of memory. Tried to allocate 2.18 GiB."
                        )
                    mask = np.zeros((40, 50), dtype=bool)
                    mask[5:20, 8:25] = True
                    return {
                        "outputs": {
                            "out_binary_masks": mask[None, ...],
                            "out_boxes_xywh": np.asarray(
                                [[0.16, 0.125, 0.34, 0.375]],
                                dtype=np.float32,
                            ),
                            "out_probs": np.asarray([0.95], dtype=np.float32),
                        }
                    }
                if request_type == "close_session":
                    self.close_requests.append(request)
                    return {
                        "is_success": True,
                        "gpu_mem": {"active_session_count": 0},
                    }
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "dense.jpg"
            Image.new("RGB", (100, 80), color=(30, 30, 30)).save(image_path)
            predictor = OomThenHealthyPredictor()
            adapter = self.module.Sam31ImageAdapter(
                predictor,
                Path(temp_dir) / "sessions",
            )
            result = adapter.discover(
                str(image_path),
                list(self.module.SAM31_SEMANTIC_THRESHOLDS),
            )

        self.assertTrue(result["tiled_retry"])
        self.assertEqual(result["tile_count"], 4)
        self.assertEqual(result["prompt_count"], 7)
        self.assertEqual(result["successful_prompt_count"], 7)
        self.assertEqual(len(result["instances"]), 28)
        self.assertEqual(
            adapter.discovery_tile_retry_counts_by_source,
            {self.module.SAM31_SEMANTIC_SOURCE: 1},
        )
        self.assertEqual(
            adapter.discovery_session_counts_by_source[
                self.module.SAM31_SEMANTIC_SOURCE
            ],
            29,
        )
        self.assertEqual(len(predictor.close_requests), 29)
        self.assertTrue(
            all(
                request["run_gc_collect"] is True
                and request["clear_cache_threshold"] == 0
                for request in predictor.close_requests
            )
        )

    def test_sam31_semantic_discovery_rejects_the_object_ceiling_and_closes_session(self) -> None:
        class CeilingSemanticPredictor:
            def __init__(self):
                self.close_requests = []

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "ceiling-semantic-session"}
                if request["type"] == "add_prompt":
                    object_count = self.module.SAM31_MAX_OBJECTS_PER_PROMPT
                    return {
                        "outputs": {
                            "out_binary_masks": np.zeros(
                                (object_count, 20, 20),
                                dtype=bool,
                            ),
                            "out_boxes_xywh": np.zeros(
                                (object_count, 4),
                                dtype=np.float32,
                            ),
                            "out_probs": np.ones(
                                (object_count,),
                                dtype=np.float32,
                            ),
                        }
                    }
                if request["type"] == "close_session":
                    self.close_requests.append(request)
                    return {
                        "is_success": True,
                        "gpu_mem": {"active_session_count": 0},
                    }
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "semantic.jpg"
            Image.new("RGB", (20, 20), color=(30, 30, 30)).save(image_path)
            predictor = CeilingSemanticPredictor()
            # Let the small fake predictor read the same frozen ceiling as the
            # adapter.  This verifies the exact boundary without duplicating a
            # magic number in the regression test.
            predictor.module = self.module
            adapter = self.module.Sam31ImageAdapter(
                predictor, Path(temp_dir) / "sessions"
            )
            with self.assertRaisesRegex(
                RuntimeError,
                "reached its per-prompt object ceiling",
            ):
                adapter.discover(
                    str(image_path),
                    list(self.module.SAM31_SEMANTIC_THRESHOLDS),
                )

        self.assertEqual(len(predictor.close_requests), 1)
        self.assertEqual(
            predictor.close_requests[0]["session_id"],
            "ceiling-semantic-session",
        )
        self.assertIs(predictor.close_requests[0]["run_gc_collect"], True)
        self.assertEqual(
            predictor.close_requests[0]["clear_cache_threshold"],
            0,
        )

    def test_sam31_semantic_discovery_accepts_zero_instances_but_rejects_misaligned_outputs(self) -> None:
        class EmptyThenMalformedPredictor:
            def __init__(self):
                self.prompt_count = 0

            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "add_prompt":
                    self.prompt_count += 1
                    if self.prompt_count == 1:
                        return {
                            "outputs": {
                                "out_binary_masks": np.zeros((0, 20, 20), dtype=bool),
                                "out_boxes_xywh": np.zeros((0, 4), dtype=np.float32),
                                "out_probs": np.zeros((0,), dtype=np.float32),
                            }
                        }
                    return {
                        "outputs": {
                            "out_binary_masks": np.zeros((1, 20, 20), dtype=bool),
                            "out_boxes_xywh": np.zeros((0, 4), dtype=np.float32),
                            "out_probs": np.ones((1,), dtype=np.float32),
                        }
                    }
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "semantic.jpg"
            Image.new("RGB", (20, 20), color=(30, 30, 30)).save(image_path)
            adapter = self.module.Sam31ImageAdapter(
                EmptyThenMalformedPredictor(), Path(temp_dir) / "sessions"
            )
            with self.assertRaisesRegex(RuntimeError, "counts differ"):
                adapter.discover(
                    str(image_path),
                    list(self.module.SAM31_SEMANTIC_THRESHOLDS),
                )

    def test_semantic_union_keeps_nonoverlap_and_prefers_successful_visual_refinement(self) -> None:
        refined = [{
            "class_id": 1,
            "class_name": self.module.FIXED_CLASS_NAMES[1],
            "confidence": 0.35,
            "bbox_xyxy": [10, 10, 30, 30],
            "polygon": [[10, 10], [30, 10], [30, 30]],
            "source": "yoloe26x_visual_prompt_tiled+sam31_instance_box_refinement",
            "proposal_sources": ["yoloe26x_visual_prompt_tiled"],
            "supporting_references": ["reference.jpg"],
            "reference_image": "reference.jpg",
            "sam3_refinement_status": "success",
        }]
        semantic = [
            {
                "class_id": 1,
                "class_name": self.module.FIXED_CLASS_NAMES[1],
                "confidence": 0.99,
                "bbox_xyxy": [11, 11, 31, 31],
                "polygon": [[11, 11], [31, 11], [31, 31]],
                "source": self.module.SAM31_SEMANTIC_SOURCE,
                "proposal_sources": [self.module.SAM31_SEMANTIC_SOURCE],
                "supporting_references": [],
                "reference_image": None,
                "sam3_refinement_status": "success",
            },
            {
                "class_id": 1,
                "class_name": self.module.FIXED_CLASS_NAMES[1],
                "confidence": 0.80,
                "bbox_xyxy": [50, 50, 60, 60],
                "polygon": [[50, 50], [60, 50], [60, 60]],
                "source": self.module.SAM31_SEMANTIC_SOURCE,
                "proposal_sources": [self.module.SAM31_SEMANTIC_SOURCE],
                "supporting_references": [],
                "reference_image": None,
                "sam3_refinement_status": "success",
            },
        ]

        merged = self.module.union_refined_and_semantic_proposals(
            refined, semantic, iou=0.5
        )

        self.assertEqual(len(merged), 2)
        overlap = next(row for row in merged if row["bbox_xyxy"] == [10, 10, 30, 30])
        self.assertEqual(overlap["supporting_references"], ["reference.jpg"])
        self.assertIn(self.module.SAM31_SEMANTIC_SOURCE, overlap["proposal_sources"])
        self.assertIn("yoloe26x_visual_prompt_tiled", overlap["proposal_sources"])
        self.assertTrue(any(row["bbox_xyxy"] == [50, 50, 60, 60] for row in merged))

    def test_trusted_reference_selector_preserves_human_rows_without_semantic_union(self) -> None:
        """Overlapping audited rows must not be merged or supplemented."""
        refined = [
            {
                "human_shape_index": 2,
                "class_id": 1,
                "class_name": self.module.FIXED_CLASS_NAMES[1],
                "confidence": 1.0,
                "bbox_xyxy": [10, 10, 30, 30],
                "polygon": [[10, 10], [30, 10], [30, 30]],
                "source": "human_rectangle_seed+sam31_instance_box_refinement",
            },
            {
                "human_shape_index": 3,
                "class_id": 1,
                "class_name": self.module.FIXED_CLASS_NAMES[1],
                "confidence": 1.0,
                "bbox_xyxy": [11, 11, 31, 31],
                "polygon": [[11, 11], [31, 11], [31, 31]],
                "source": "human_rectangle_seed+sam31_instance_box_refinement",
            },
        ]
        semantic = [{
            "class_id": 1,
            "class_name": self.module.FIXED_CLASS_NAMES[1],
            "confidence": 0.99,
            "bbox_xyxy": [60, 60, 80, 80],
            "polygon": [[60, 60], [80, 60], [80, 80]],
            "source": self.module.SAM31_SEMANTIC_SOURCE,
        }]

        selected = self.module.select_final_review_proposals(
            refined,
            semantic,
            is_audited_reference=True,
            iou=0.5,
        )

        self.assertEqual(selected, refined)
        self.assertEqual(
            [row["human_shape_index"] for row in selected],
            [2, 3],
        )

    def test_trusted_reference_label_audit_allows_geometry_change_and_stable_reorder_but_rejects_identity_membership_drift(self) -> None:
        seeds = [
            {
                "human_shape_index": 4,
                "class_id": 0,
                "class_name": self.module.FIXED_CLASS_NAMES[0],
                "bbox_xyxy": [1, 1, 10, 10],
            },
            {
                "human_shape_index": 5,
                "class_id": 1,
                "class_name": self.module.FIXED_CLASS_NAMES[1],
                "bbox_xyxy": [11, 1, 20, 10],
            },
        ]
        refined = [
            {
                **seeds[0],
                "bbox_xyxy": [2, 2, 11, 11],
                "polygon": [[2, 2], [11, 2], [11, 11]],
            },
            {
                **seeds[1],
                "bbox_xyxy": [12, 2, 21, 11],
                "polygon": [[12, 2], [21, 2], [21, 11]],
            },
        ]

        passed = self.module.audit_trusted_reference_labels(seeds, refined)
        self.assertTrue(passed["applicable"])
        self.assertTrue(passed["passed"])

        drifted = [dict(refined[1]), dict(refined[0])]
        reordered = self.module.audit_trusted_reference_labels(seeds, drifted)
        self.assertTrue(reordered["passed"])

        drifted_membership = [
            dict(refined[0]),
            {
                **refined[1],
                "human_shape_index": 99,
            },
        ]
        failed = self.module.audit_trusted_reference_labels(seeds, drifted_membership)
        self.assertFalse(failed["passed"])
        self.assertIn("human_reference_identity_membership_changed", failed["failure_reasons"])

        failed_count = self.module.audit_trusted_reference_labels(seeds, refined[:1])
        self.assertFalse(failed_count["passed"])
        self.assertIn("human_reference_instance_count_changed", failed_count["failure_reasons"])

    def test_reference_image_audit_includes_trusted_label_failure(self) -> None:
        audit = self.module.audit_image_for_review(
            image_name="reference.jpg",
            instances=[{
                "class_id": 0,
                "polygon": [[1, 1], [5, 1], [5, 5]],
            }],
            width=10,
            height=10,
            is_audited_reference=True,
            sam3_refinement_status="success",
            trusted_reference_audit={
                "applicable": True,
                "passed": False,
                "failure_reasons": ["human_reference_class_counts_changed"],
            },
        )
        self.assertFalse(audit["passed"])
        self.assertIn(
            "trusted_reference_human_reference_class_counts_changed",
            audit["failure_reasons"],
        )

    def test_sam31_primes_pinned_clean_frame_cache_before_new_box_prompt(self) -> None:
        """Regression for Meta SAM 3.1 returning an empty new-object output.

        The pinned multiplex implementation discards a freshly computed SAM2
        mask when ``cached_frame_outputs`` has no entry for the prompted frame.
        A clean session is exactly that state, so the adapter must create the
        neutral empty frame mapping after each reset and before ``add_prompt``.
        """

        class PinnedCleanSessionPredictor:
            def __init__(self):
                self._all_inference_states = {}

            def handle_request(self, request):
                if request["type"] == "start_session":
                    self._all_inference_states["session"] = {
                        "state": {"cached_frame_outputs": {}}
                    }
                    return {"session_id": "session"}
                if request["type"] == "reset_session":
                    self._all_inference_states["session"]["state"][
                        "cached_frame_outputs"
                    ].clear()
                    return {"is_success": True}
                if request["type"] == "add_prompt":
                    cache = self._all_inference_states["session"]["state"][
                        "cached_frame_outputs"
                    ]
                    if 0 not in cache:
                        return {
                            "outputs": {
                                "out_binary_masks": np.zeros((0, 20, 20), dtype=bool),
                                "out_boxes_xywh": np.zeros((0, 4), dtype=np.float32),
                            }
                        }
                    mask = np.zeros((20, 20), dtype=bool)
                    mask[2:12, 3:13] = True
                    return {
                        "outputs": {
                            "out_binary_masks": np.asarray([mask]),
                            "out_boxes_xywh": np.asarray(
                                [[0.15, 0.1, 0.5, 0.5]], dtype=np.float32
                            ),
                        }
                    }
                if request["type"] == "close_session":
                    self._all_inference_states.pop("session", None)
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "reference.jpg"
            Image.new("RGB", (20, 20), color=(11, 22, 33)).save(image_path)
            adapter = self.module.Sam31ImageAdapter(
                PinnedCleanSessionPredictor(), Path(temp_dir) / "sessions"
            )
            result = adapter.refine(str(image_path), [[3, 2, 13, 12]])

        self.assertEqual(result["outcomes"][0]["status"], "success")
        self.assertEqual(
            result["outcomes"][0]["cache_compatibility"],
            "pinned_sam31_clean_frame_cache_primed",
        )
        self.assertEqual(result["outcomes"][0]["mask_shape"], [20, 20])
        self.assertEqual(result["outcomes"][0]["mask_dtype"], "bool")
        self.assertEqual(result["outcomes"][0]["mask_nonzero_pixel_count"], 100)
        self.assertEqual(result["outcomes"][0]["output_instance_count"], 1)
        self.assertGreaterEqual(len(result["polygons"][0]), 3)

    def test_sam31_cache_shim_directly_primes_an_empty_frame_mapping(self) -> None:
        predictor = SimpleNamespace(
            _all_inference_states={
                "session": {"state": {"cached_frame_outputs": {}}}
            }
        )
        adapter = self.module.Sam31ImageAdapter(predictor, Path("sessions"))

        status = adapter._prime_clean_instance_output_cache(
            "session",
            frame_index=0,
            require_clean=True,
        )

        self.assertEqual(status, "pinned_sam31_clean_frame_cache_primed")
        self.assertEqual(
            predictor._all_inference_states["session"]["state"][
                "cached_frame_outputs"
            ],
            {0: {}},
        )

    def test_sam31_cache_shim_preserves_an_existing_well_formed_frame(self) -> None:
        existing_frame = {7: "mask"}
        predictor = SimpleNamespace(
            _all_inference_states={
                "session": {
                    "state": {"cached_frame_outputs": {0: existing_frame}}
                }
            }
        )
        adapter = self.module.Sam31ImageAdapter(predictor, Path("sessions"))

        status = adapter._prime_clean_instance_output_cache("session", 0)

        self.assertEqual(status, "pinned_sam31_clean_frame_cache_primed")
        self.assertIs(
            predictor._all_inference_states["session"]["state"][
                "cached_frame_outputs"
            ][0],
            existing_frame,
        )

    def test_sam31_cache_shim_retires_for_predictors_without_private_registry(self) -> None:
        adapter = self.module.Sam31ImageAdapter(SimpleNamespace(), Path("sessions"))

        status = adapter._prime_clean_instance_output_cache("session", 0)

        self.assertEqual(status, "public_api_no_internal_session_cache")

    def test_sam31_cache_shim_rejects_malformed_existing_frame_output(self) -> None:
        predictor = SimpleNamespace(
            _all_inference_states={
                "session": {
                    "state": {"cached_frame_outputs": {0: ["not-a-mapping"]}}
                }
            }
        )
        adapter = self.module.Sam31ImageAdapter(predictor, Path("sessions"))

        with self.assertRaisesRegex(
            RuntimeError, "cached frame output has an unexpected structure"
        ):
            adapter._prime_clean_instance_output_cache("session", 0)

    def test_sam31_cache_shim_requires_reset_to_leave_a_clean_cache(self) -> None:
        predictor = SimpleNamespace(
            _all_inference_states={
                "session": {
                    "state": {"cached_frame_outputs": {1: {}}}
                }
            }
        )
        adapter = self.module.Sam31ImageAdapter(predictor, Path("sessions"))

        with self.assertRaisesRegex(
            RuntimeError, "frame cache was unexpectedly populated after reset"
        ):
            adapter._prime_clean_instance_output_cache(
                "session",
                frame_index=0,
                require_clean=True,
            )

    def test_sam31_cache_shim_requires_the_pinned_session_layout(self) -> None:
        malformed_predictors = [
            (SimpleNamespace(_all_inference_states=[]), "session registry"),
            (
                SimpleNamespace(_all_inference_states={"session": []}),
                "session entry",
            ),
            (
                SimpleNamespace(
                    _all_inference_states={"session": {"state": []}}
                ),
                "inference state",
            ),
            (
                SimpleNamespace(
                    _all_inference_states={"session": {"state": {}}}
                ),
                "frame cache is unavailable",
            ),
            (
                SimpleNamespace(
                    _all_inference_states={
                        "session": {"state": {"cached_frame_outputs": []}}
                    }
                ),
                "cached_frame_outputs",
            ),
        ]

        for predictor, message in malformed_predictors:
            with self.subTest(message=message):
                adapter = self.module.Sam31ImageAdapter(
                    predictor, Path("sessions")
                )
                with self.assertRaisesRegex(RuntimeError, message):
                    adapter._prime_clean_instance_output_cache("session", 0)

    def test_sam31_session_filters_the_pinned_unsupported_offload_keyword(self) -> None:
        class PinnedMultiplexModel:
            def __init__(self):
                self.init_calls = []

            # This is the important pinned SAM 3.1 signature: unlike the base
            # predictor wrapper, the model does not accept offload_state_to_cpu.
            def init_state(
                self,
                resource_path,
                offload_video_to_cpu=False,
                async_loading_frames=False,
            ):
                self.init_calls.append(
                    {
                        "resource_path": resource_path,
                        "offload_video_to_cpu": offload_video_to_cpu,
                        "async_loading_frames": async_loading_frames,
                    }
                )
                return {"resource_path": resource_path}

        class PinnedPredictor:
            def __init__(self):
                self.model = PinnedMultiplexModel()
                self._all_inference_states = {}
                self.async_loading_frames = False
                self.world_size = 1

            def handle_request(self, request):
                if request["type"] == "start_session":
                    raise AssertionError(
                        "The incompatible public start_session path must be bypassed."
                    )
                if request["type"] == "close_session":
                    self._all_inference_states.pop(request["session_id"], None)
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (20, 20), color=(11, 22, 33)).save(image_path)
            predictor = PinnedPredictor()
            adapter = self.module.Sam31ImageAdapter(
                predictor, Path(temp_dir) / "sessions"
            )
            oriented_copy = Path(temp_dir) / "oriented.jpg"
            Image.open(image_path).save(oriented_copy)
            session = adapter._start_image_session(oriented_copy)

        self.assertEqual(
            session["compatibility"], "filtered_sam31_init_state_kwargs"
        )
        self.assertEqual(len(predictor.model.init_calls), 1)
        self.assertEqual(
            predictor.model.init_calls[0],
            {
                "resource_path": str(oriented_copy),
                "offload_video_to_cpu": False,
                "async_loading_frames": False,
            },
        )
        self.assertIn(session["session_id"], predictor._all_inference_states)

    def test_sam31_xywh_output_is_top_left_not_center_based(self) -> None:
        class TopLeftPredictor:
            def handle_request(self, request):
                if request["type"] == "start_session":
                    return {"session_id": "session"}
                if request["type"] == "add_prompt":
                    return {
                        "outputs": {
                            "out_binary_masks": np.asarray(
                                [[[1, 1], [1, 1]]], dtype=bool
                            ),
                            # x=0.25,y=0.25,w=0.25,h=0.25 is the
                            # top-left box [20,20,40,40] on an 80x80 frame.
                            "out_boxes_xywh": np.asarray(
                                [[0.25, 0.25, 0.25, 0.25]], dtype=np.float32
                            ),
                        }
                    }
                if request["type"] == "reset_session":
                    return {"is_success": True}
                if request["type"] == "close_session":
                    return {"is_success": True}
                raise AssertionError(request)

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (80, 80), color=(11, 22, 33)).save(image_path)
            model = self.module.Sam31ImageAdapter(
                TopLeftPredictor(), Path(temp_dir) / "sessions"
            )
            refined = self.module.sam3_refine_image(
                model,
                image_path,
                [{"class_id": 0, "bbox_xyxy": [20, 20, 40, 40], "source": "seed"}],
                "0",
            )

        self.assertEqual(refined[0]["sam3_prompt_match_iou"], 1.0)

    def test_sam3_prompt_assignment_rejects_ambiguous_unmatched_and_duplicate_outputs(self) -> None:
        polygon_a = [[0, 0], [10, 0], [10, 10]]
        polygon_b = [[20, 20], [30, 20], [30, 30]]
        cases = [
            (
                "ambiguous",
                [[0, 0, 10, 10], [0.2, 0, 10.2, 10]],
                [[0, 0, 10, 10], [0.1, 0, 10.1, 10]],
                [polygon_a, polygon_b],
                "ambiguous",
            ),
            (
                "unmatched",
                [[0, 0, 10, 10]],
                [[100, 100, 110, 110]],
                [polygon_a],
                "unmatched",
            ),
            (
                "duplicate_boxes",
                [[0, 0, 10, 10], [20, 20, 30, 30]],
                [[0, 0, 10, 10], [0, 0, 10, 10]],
                [polygon_a, polygon_b],
                "duplicate output boxes",
            ),
            (
                "duplicate_masks",
                [[0, 0, 10, 10], [20, 20, 30, 30]],
                [[0, 0, 10, 10], [20, 20, 30, 30]],
                [polygon_a, polygon_a],
                "duplicate masks",
            ),
            (
                "dropped_mask",
                [[0, 0, 10, 10], [20, 20, 30, 30]],
                [[0, 0, 10, 10]],
                [polygon_a],
                "output count differs",
            ),
        ]
        for name, prompts, outputs, polygons, message in cases:
            with self.subTest(case=name), self.assertRaisesRegex(RuntimeError, message):
                self.module.assign_sam3_outputs_to_prompts(prompts, outputs, polygons)

    def test_classwise_nms_merges_reference_support_without_cross_class_suppression(self) -> None:
        rows = [
            {
                "class_id": 1,
                "confidence": 0.9,
                "bbox_xyxy": [10, 10, 30, 30],
                "reference_image": "a.jpg",
            },
            {
                "class_id": 1,
                "confidence": 0.8,
                "bbox_xyxy": [11, 11, 31, 31],
                "reference_image": "b.jpg",
            },
            {
                "class_id": 2,
                "confidence": 0.7,
                "bbox_xyxy": [11, 11, 31, 31],
                "reference_image": "c.jpg",
            },
        ]

        kept = self.module.classwise_nms(rows, threshold=0.5)

        self.assertEqual(len(kept), 2)
        soya = next(row for row in kept if row["class_id"] == 1)
        self.assertEqual(soya["supporting_references"], ["a.jpg", "b.jpg"])
        self.assertEqual(soya["reference_support_count"], 2)

    def test_classwise_nms_accepts_an_empty_proposal_set(self) -> None:
        self.assertEqual(self.module.classwise_nms([], threshold=0.45), [])

    def test_classwise_nms_preserves_suppressed_correction_geometry(self) -> None:
        visual = {
            "class_id": 1,
            "class_name": self.module.FIXED_CLASS_NAMES[1],
            "confidence": 0.55,
            "bbox_xyxy": [10, 10, 30, 30],
            "polygon": [[10, 10], [30, 10], [30, 30]],
            "source": "yoloe26x_visual_prompt_tiled",
            "proposal_sources": ["yoloe26x_visual_prompt_tiled"],
            "sam3_refinement_status": "success",
        }
        correction = {
            "class_id": 1,
            "class_name": self.module.FIXED_CLASS_NAMES[1],
            "confidence": 0.99,
            "bbox_xyxy": [11, 11, 31, 31],
            "polygon": [[11, 11], [31, 11], [31, 31]],
            "source": self.module.CORRECTION_GUIDED_SOURCE,
            "proposal_sources": [self.module.CORRECTION_GUIDED_SOURCE],
            "sam3_refinement_status": "success",
        }
        kept = self.module.classwise_nms([visual, correction], threshold=0.5)
        self.assertEqual(len(kept), 1)
        self.assertEqual(kept[0]["bbox_xyxy"], visual["bbox_xyxy"])
        self.assertIn(
            self.module.CORRECTION_GUIDED_SOURCE,
            kept[0]["proposal_sources"],
        )
        self.assertEqual(
            kept[0]["suppressed_proposals"][0]["bbox_xyxy"],
            correction["bbox_xyxy"],
        )
        self.assertEqual(
            kept[0]["suppressed_proposals"][0]["proposal_priority"],
            4,
        )

    def test_classwise_nms_does_not_mutate_or_accumulate_suppressed_metadata(self) -> None:
        first = {
            "class_id": 1,
            "confidence": 0.8,
            "bbox_xyxy": [0, 0, 10, 10],
            "source": "a",
        }
        second = {
            "class_id": 1,
            "confidence": 0.7,
            "bbox_xyxy": [1, 1, 11, 11],
            "source": self.module.CORRECTION_GUIDED_SOURCE,
        }
        rows = [first, second]
        result_one = self.module.classwise_nms(rows, threshold=0.5)
        result_two = self.module.classwise_nms(rows, threshold=0.5)
        self.assertNotIn("suppressed_proposals", first)
        self.assertEqual(
            len(result_one[0]["suppressed_proposals"]),
            len(result_two[0]["suppressed_proposals"]),
        )

    def test_weak_target_selection_runs_text_fallback_only_at_configured_threshold(self) -> None:
        targets = [Path("zero.jpg"), Path("two.jpg"), Path("three.jpg")]
        visual = {
            "zero.jpg": [],
            "two.jpg": [{"class_id": 0}, {"class_id": 1}],
            "three.jpg": [{"class_id": index} for index in range(3)],
        }

        selected = self.module.weak_visual_prompt_targets(
            visual,
            targets,
            maximum_visual_proposals=2,
        )

        self.assertEqual([path.name for path in selected], ["zero.jpg", "two.jpg"])
        with self.assertRaisesRegex(ValueError, "between zero and two"):
            self.module.weak_visual_prompt_targets(visual, targets, -1)
        with self.assertRaisesRegex(ValueError, "between zero and two"):
            self.module.weak_visual_prompt_targets(visual, targets, 3)

    def test_visual_text_union_keeps_visual_geometry_and_all_provenance(self) -> None:
        visual_geometry = [10, 10, 30, 30]
        visual = {
            "weak.jpg": [{
                "class_id": 1,
                "class_name": "black soya sauce cup",
                "confidence": 0.55,
                "bbox_xyxy": visual_geometry,
                "polygon": [[10, 10], [30, 10], [30, 30]],
                "source": "yoloe26x_visual_prompt_tiled",
                "proposal_sources": ["yoloe26x_visual_prompt_tiled"],
                "supporting_references": ["a.jpg", "b.jpg"],
                "reference_support_count": 2,
                "reference_image": "a.jpg",
            }],
        }
        text = {
            "weak.jpg": [{
                "class_id": 1,
                "class_name": "black soya sauce cup",
                "confidence": 0.99,
                "bbox_xyxy": [11, 11, 31, 31],
                "polygon": [[11, 11], [31, 11], [31, 31]],
                "source": "yoloe26x_text_prompt_tiled",
                "proposal_sources": ["yoloe26x_text_prompt_tiled"],
                "reference_image": None,
            }],
        }

        union = self.module.union_visual_and_text_predictions(visual, text, iou=0.5)

        self.assertEqual(len(union["weak.jpg"]), 1)
        kept = union["weak.jpg"][0]
        self.assertEqual(kept["bbox_xyxy"], visual_geometry)
        self.assertEqual(kept["supporting_references"], ["a.jpg", "b.jpg"])
        self.assertEqual(kept["reference_support_count"], 2)
        self.assertEqual(
            kept["proposal_sources"],
            ["yoloe26x_text_prompt_tiled", "yoloe26x_visual_prompt_tiled"],
        )
        self.assertEqual(
            self.module.proposal_source_partition(union["weak.jpg"]),
            {
                "visual_only_proposal_count": 0,
                "text_only_proposal_count": 0,
                "dual_supported_proposal_count": 1,
            },
        )

    def test_kraft_bowl_ocr_reads_any_readable_white_sticker_dish_name(self) -> None:
        """Any readable white sticker counts, including off-menu dish names.

        Regression guard for the springer-quartier failure: the old code only
        accepted dishes from a hard-coded allow-list, so real stickers such as
        MARVEL BOWL / CRISPY BOWL / TEMPURA GARNELE were read correctly and then
        discarded.  Nine bowls reported five stickers and the kraft count gate
        could never pass.  Shelf noise must still be rejected.
        """

        class FakeRapidOCR:
            def __init__(self) -> None:
                self.crop_calls: list[tuple[int, ...]] = []
                # White-sticker multi-view OCR may call several times per bowl;
                # advance dish phase only after a readable sticker is returned.
                self.bowl_phase = 0

            def __call__(self, image):
                if isinstance(image, str):
                    # Full-image dish lines stay diagnostics + geometric
                    # fallback only; they do not invent extra kraft boxes.
                    return [
                        ([[200, 200], [400, 200], [400, 250], [200, 250]], "TOKYO", 0.97),
                    ], 0.01

                self.crop_calls.append(image.shape)
                # A real OCR engine returns boxes in the coordinates of the view
                # it was handed, and the runtime pads then upscales each bowl
                # crop, so the view size changes from call to call.  Place every
                # box as a FRACTION of the actual view: the bowl's own sticker
                # sits in the middle, and neighbour text lands in the padding
                # band, which is exactly what the crop-geometry gate keys on.
                # Hard-coding 1000x1000 pixel boxes here would test the gate
                # against a crop shape that never occurs.
                height, width = image.shape[:2]

                def band(x0, y0, x1, y1):
                    return [
                        [x0 * width, y0 * height],
                        [x1 * width, y0 * height],
                        [x1 * width, y1 * height],
                        [x0 * width, y1 * height],
                    ]

                crop_results = [
                    [
                        # RapidOCR can split one sticker into separate lines;
                        # their reading-order text must resolve as one dish.
                        (band(0.20, 0.40, 0.75, 0.50), "CHICKEN", 0.98),
                        (band(0.22, 0.52, 0.70, 0.62), "BOWL", 0.97),
                        # Sale tag high in the padding: a NEIGHBOUR's text, and
                        # also shelf noise. Must be dropped on both counts.
                        (band(0.20, 0.02, 0.80, 0.09), "SOFORTKAUF", 0.99),
                    ],
                    [
                        # Off-menu dish: on no allow-list, must still be read in
                        # full.  This is the exact case that broke springer.
                        (band(0.20, 0.40, 0.75, 0.50), "MARVEL BOWL", 0.96),
                    ],
                    [
                        # Sauce copy and a weak dish line below the crop floor
                        # must not invent an exact dish label.
                        (band(0.20, 0.40, 0.75, 0.50), "SOJA SAUCE", 0.99),
                        (band(0.20, 0.60, 0.75, 0.70), "LACHS BOWL", 0.50),
                    ],
                ]
                phase = min(self.bowl_phase, len(crop_results) - 1)
                rows = crop_results[phase]
                # Successful readable dishes advance to the next bowl; failed
                # sauce-only crops stay on the last phase for multi-view retries.
                if phase < 2:
                    self.bowl_phase += 1
                return rows, 0.01

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            # Near-white canvas mimics real white sticker paper behind black ink.
            Image.new("RGB", (1000, 1000), color=(235, 235, 235)).save(image_path)
            ocr_engine = FakeRapidOCR()
            evidence = self.module.extract_kraft_bowl_sticker_evidence(
                image_path=image_path,
                segmentation_bowl_count=3,
                ocr_engine=ocr_engine,
                bowl_instances=[
                    {"class_id": 0, "bbox_xyxy": [100, 100, 300, 300]},
                    {"class_id": 0, "bbox_xyxy": [350, 100, 550, 300]},
                    {"class_id": 0, "bbox_xyxy": [600, 100, 800, 300]},
                    # Non-bowl proposals are not OCR-count candidates.
                    {"class_id": 6, "bbox_xyxy": [100, 400, 300, 500]},
                ],
            )

        self.assertEqual(evidence["status"], "available")
        # Readable stickers only — third bowl is sauce copy, so count is 2 not 3.
        self.assertEqual(evidence["sticker_count"], 2)
        self.assertEqual(evidence["recognized_texts"], ["CHICKEN BOWL", "MARVEL BOWL"])
        self.assertEqual(
            [row["dish_name"] for row in evidence["recognized_stickers"]],
            ["CHICKEN BOWL", "MARVEL BOWL"],
        )
        self.assertEqual(evidence["dish_text_read_count"], 2)
        self.assertEqual(evidence["unread_kraft_box_count"], 1)
        self.assertEqual(evidence["bowl_crop_attempt_count"], 3)
        self.assertEqual(evidence["bowl_crop_failure_count"], 0)
        self.assertGreaterEqual(len(ocr_engine.crop_calls), 3)
        self.assertEqual(evidence["full_image_diagnostics"]["status"], "available")
        self.assertEqual(evidence["full_image_diagnostics"]["recognized_texts"], ["TOKYO"])
        self.assertEqual(evidence["full_image_diagnostics"]["recognized_sticker_count"], 1)
        self.assertEqual(evidence["segmentation_bowl_count"], 3)
        self.assertEqual(evidence["count_difference"], -1)
        self.assertTrue(evidence["disagrees_with_segmentation"])
        self.assertTrue(evidence["advisory_only"])
        self.assertFalse(evidence["used_as_ground_truth"])
        self.assertIn("v5", evidence["method"])
        self.assertIn("white_sticker", evidence["method"])

    def test_kraft_bowl_dish_name_reads_off_menu_labels_and_rejects_shelf_text(
        self,
    ) -> None:
        """Dish names are read, not recognised; fridge furniture is rejected.

        The menu changes constantly, so no allow-list may gate a sticker.  The
        reject side must stay strict: dropping only the LOCATION token from the
        header sign would leave "SPRINGER QUARTIER" looking like a real dish.
        """

        for text, expected in [
            # Real stickers observed on springer / saco / stroeer photos.
            ("LACHS BOWL", "LACHS BOWL"),
            ("MARVEL BOWL", "MARVEL BOWL"),
            ("CRISPY BOWL", "CRISPY BOWL"),
            ("TEMPURA GARNELE", "TEMPURA GARNELE"),
            ("FALAFEL BOWL", "FALAFEL BOWL"),
            ("GARDEN BOWL", "GARDEN BOWL"),
            # Full label survives sale copy glued on by RapidOCR.
            ("CHICKEN BOWL SOFORTKAUF HERGESTELLT MITTWOCH", "CHICKEN BOWL"),
            # Merged base+BOWL token is split back into the full label.
            ("LACHSBOWL", "LACHS BOWL"),
        ]:
            with self.subTest(sticker=text):
                self.assertEqual(self.module.kraft_bowl_dish_name(text), expected)

        for text in [
            "LOCATION SPRINGER QUARTIER",
            "LOCATION ZEISEHOF 22765",
            "SOJA SAUCE",
            "TERIYAKI SAUCE",
            "CHILI MAYO",
            "WAYO DIP",
            "BITTE SCANNE DEINEN QR CODE",
            "PRO ARTIKEL EINE SOJA SAUCE NACH WAHL ENTNEHMBAR",
            "JETZT BESTELLEN UNTER WWW SUSHI CATERING",
            "SOFORTKAUF",
            "BOWL",
            "22765",
        ]:
            with self.subTest(noise=text):
                self.assertIsNone(self.module.kraft_bowl_dish_name(text))

    def test_kraft_bowl_ocr_does_not_fabricate_count_when_dishes_unread(self) -> None:
        """Unread white stickers must not invent OCR counts (zeisehof-style)."""

        class BlankDishOCR:
            def __call__(self, image):
                return [], 0.01

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "zeisehof-like.jpg"
            Image.new("RGB", (1200, 1600), color=(235, 235, 235)).save(image_path)
            evidence = self.module.extract_kraft_bowl_sticker_evidence(
                image_path,
                segmentation_bowl_count=9,
                ocr_engine=BlankDishOCR(),
                bowl_instances=[
                    {"class_id": 0, "bbox_xyxy": [50 + i * 80, 100, 120 + i * 80, 220]}
                    for i in range(9)
                ],
            )

        self.assertEqual(evidence["sticker_count"], 0)
        self.assertEqual(evidence["segmentation_bowl_count"], 9)
        self.assertEqual(evidence["unread_kraft_box_count"], 9)
        self.assertEqual(evidence["dish_text_read_count"], 0)
        self.assertEqual(evidence["recognized_texts"], [])
        self.assertTrue(evidence["disagrees_with_segmentation"])
        self.assertFalse(evidence["used_as_ground_truth"])
        self.assertTrue(evidence["advisory_only"])

    def test_enforce_exact_human_estimate_trims_overcount_to_100_percent(self) -> None:
        """Reviewer rejects any image whose proposals != provided human estimates."""

        module = self.module
        instances = [
            {"class_id": 0, "confidence": 0.9, "source": "sam31_semantic_text_prompt"},
            {"class_id": 0, "confidence": 0.8, "source": "sam31_semantic_text_prompt"},
            {"class_id": 0, "confidence": 0.4, "source": "sam31_semantic_text_prompt"},
            {"class_id": 1, "confidence": 0.7, "source": "sam31_semantic_text_prompt"},
            {"class_id": 1, "confidence": 0.6, "source": "sam31_semantic_text_prompt"},
            {"class_id": 5, "confidence": 0.5, "source": "sam31_semantic_text_prompt"},
        ]
        trimmed, record = module.enforce_exact_human_estimate_counts(
            instances,
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 2,
                    "black soya sauce cup": 2,
                    "wooden chopstick tip": 36,
                },
            },
        )
        counts: dict[int, int] = {}
        for row in trimmed:
            counts[int(row["class_id"])] = counts.get(int(row["class_id"]), 0) + 1
        self.assertEqual(counts.get(0), 2)
        self.assertEqual(counts.get(1), 2)
        # Tip shortfall stays short — never invent tip boxes from the estimate.
        self.assertEqual(counts.get(5), 1)
        self.assertEqual(record["classes"]["kraft paper bowl"]["trimmed"], 1)
        self.assertEqual(record["classes"]["wooden chopstick tip"]["shortfall"], 35)
        self.assertFalse(record["counts_used_as_geometry"])

        usefulness = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 2,
                    "black soya sauce cup": 2,
                    "wooden chopstick tip": 36,
                },
            },
            {"status": "accepted", "rejection_reason": None},
            trimmed,
            trimmed,
            is_audited_reference=False,
        )
        self.assertFalse(usefulness["passed"])
        self.assertTrue(
            any("wooden chopstick tip" in reason for reason in usefulness["reasons"])
        )
        # After exact kraft/soya match, only tip shortfall remains.
        kraft_row = usefulness["count_validation"]["kraft paper bowl"]
        self.assertTrue(kraft_row["exact_match_required"])
        self.assertEqual(kraft_row["status"], "exact_match")
        self.assertEqual(kraft_row["occlusion_policy"], "exact_human_estimate")

        exact_ok = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {
                    "kraft paper bowl": 2,
                    "black soya sauce cup": 2,
                },
            },
            {"status": "accepted", "rejection_reason": None},
            [{"class_id": 0}] * 2 + [{"class_id": 1}] * 2,
            [{"class_id": 0}] * 2 + [{"class_id": 1}] * 2,
            is_audited_reference=False,
        )
        self.assertTrue(exact_ok["passed"])
        self.assertEqual(
            exact_ok["count_validation"]["kraft paper bowl"]["status"],
            "exact_match",
        )

        # Over-count without trim must fail 100% match (reviewer will reject).
        over = module.build_correction_usefulness_gate(
            {
                "decision": "reject",
                "requested_counts": {"kraft paper bowl": 2},
            },
            {"status": "accepted", "rejection_reason": None},
            [{"class_id": 0}] * 5,
            [{"class_id": 0}] * 5,
            is_audited_reference=False,
        )
        self.assertFalse(over["passed"])
        self.assertTrue(
            any("above human estimate" in reason for reason in over["reasons"])
        )

    def test_required_class_advisory_note_does_not_disable_exact_match_or_zero_targets(
        self,
    ) -> None:
        """Required-class counts stay binding even when legacy notes said advisory."""

        module = self.module
        correction = {
            "decision": "reject",
            "requested_counts": {
                "wooden chopstick tip": 13,
                "red teriyaki sauce cup": 0,
                "black and white soya sauce packet": 8,
            },
            "missing_identifications": {},
            "advisory_classes": [
                "wooden chopstick tip",
                "black and white soya sauce packet",
            ],
            "count_uncertainties": [],
        }

        estimates = module.non_advisory_human_estimates(correction)
        self.assertEqual(
            estimates,
            {
                "red teriyaki sauce cup": 0,
                "wooden chopstick tip": 13,
            },
        )

        usefulness = module.build_correction_usefulness_gate(
            correction,
            {"status": "accepted", "rejection_reason": None},
            [{"class_id": 2}],
            [{"class_id": 2}],
            is_audited_reference=False,
        )

        tip_row = usefulness["count_validation"]["wooden chopstick tip"]
        teriyaki_row = usefulness["count_validation"]["red teriyaki sauce cup"]
        packet_row = usefulness["count_validation"]["black and white soya sauce packet"]

        self.assertTrue(tip_row["exact_match_required"])
        self.assertEqual(tip_row["status"], "missing")
        self.assertEqual(tip_row["mode"], "exact_human_estimate")
        self.assertTrue(teriyaki_row["exact_match_required"])
        self.assertEqual(teriyaki_row["status"], "unexpected_positive")
        self.assertEqual(teriyaki_row["mode"], "exact_human_estimate")
        self.assertFalse(packet_row["exact_match_required"])
        self.assertEqual(packet_row["mode"], "advisory_only")
        self.assertFalse(usefulness["passed"])
        self.assertTrue(
            any("missing required class: wooden chopstick tip" in reason for reason in usefulness["reasons"])
        )
        self.assertTrue(
            any(
                "proposal count above human estimate: red teriyaki sauce cup has 1 need 0"
                in reason
                for reason in usefulness["reasons"]
            )
        )

    def test_detector_validator_blocks_handoff_when_accuracy_not_above_95_percent(
        self,
    ) -> None:
        """Pre-submit agent must block human review when required accuracy ≤ 0.95."""

        module = self.module
        # 20 images; 12 with estimates, 11 exact + 1 fail => accuracy 11/12 < 0.95
        records = []
        corrections = {}
        for index in range(20):
            name = f"fridge-{index:02d}.jpg"
            if index < 12:
                # Human wants 2 kraft bowls.
                corrections[name] = {
                    "decision": "reject",
                    "requested_counts": {"kraft paper bowl": 2},
                }
                kraft_count = 2 if index < 11 else 1  # last estimate image fails
                records.append(
                    {
                        "image_name": name,
                        "final_class_counts": {
                            "kraft paper bowl": kraft_count,
                            "black soya sauce cup": 0,
                            "red teriyaki sauce cup": 0,
                            "white wayo dip cup": 0,
                            "orange chili mayo cup": 0,
                            "wooden chopstick tip": 0,
                            "black and white soya sauce packet": 0,
                        },
                        "ocr_sticker_count": kraft_count,
                    }
                )
            else:
                records.append(
                    {
                        "image_name": name,
                        "final_class_counts": {
                            name: 0 for name in module.FIXED_CLASS_NAMES
                        },
                    }
                )

        blocked = module.run_detector_validator_agent(records, corrections)
        self.assertFalse(blocked["human_handoff_allowed"])
        self.assertLessEqual(blocked["required_item_count_accuracy"], 0.95)
        self.assertEqual(blocked["failed_image_count"], 1)
        self.assertEqual(blocked["status"], "fail")
        with self.assertRaises(RuntimeError):
            module.assert_detector_validator_allows_human_handoff(blocked)

        # Fix the failing image so all 12 estimate images exact-match.
        records[11]["final_class_counts"]["kraft paper bowl"] = 2
        records[11]["ocr_sticker_count"] = 2
        allowed = module.run_detector_validator_agent(records, corrections)
        self.assertTrue(allowed["human_handoff_allowed"])
        self.assertGreater(allowed["required_item_count_accuracy"], 0.95)
        self.assertEqual(allowed["failed_image_count"], 0)
        self.assertEqual(allowed["status"], "pass")
        module.assert_detector_validator_allows_human_handoff(allowed)

    def _twenty_image_records(self, module, kraft=2):
        """20 records, 12 carrying human estimates, all exact-matching."""

        records, corrections = [], {}
        for index in range(20):
            name = f"fridge-{index:02d}.jpg"
            if index < 12:
                corrections[name] = {
                    "decision": "reject",
                    "requested_counts": {"kraft paper bowl": kraft},
                }
                records.append(
                    {
                        "image_name": name,
                        "final_class_counts": {
                            **{cls: 0 for cls in module.FIXED_CLASS_NAMES},
                            "kraft paper bowl": kraft,
                        },
                        "ocr_sticker_count": kraft,
                    }
                )
            else:
                records.append(
                    {
                        "image_name": name,
                        "final_class_counts": {
                            cls: 0 for cls in module.FIXED_CLASS_NAMES
                        },
                    }
                )
        return records, corrections

    def test_a_photo_ocr_cannot_read_at_all_is_unmeasured_not_a_disagreement(
        self,
    ) -> None:
        """Reading ZERO stickers is a failed measurement, not a contradiction.

        Measured case: zeisehof-2026-05-27 is motion-blurred, so OCR reads 0 of
        9 stickers while the much larger QR-panel signage still reads at 0.96.
        Crop OCR, 4x upscale with unsharp masking, and Richardson-Lucy over 216
        motion PSFs all recover nothing. Counting that as a DISAGREEMENT scores
        the photograph rather than the detector — and on that image the human
        had already written "kraft paper bowl 9", agreeing with the detector.

        The rule is general, not a special case: wherever OCR functions it reads
        at least N-1 of N, so only total reader failure reaches this branch.
        """

        module = self.module
        records, corrections = self._twenty_image_records(module)
        # One photo the reader could not read at all.
        records[0]["ocr_sticker_count"] = 0

        report = module.run_detector_validator_agent(records, corrections)

        # Excluded from the ratio rather than counted as a mismatch.
        self.assertEqual(report["kraft_ocr_comparable_image_count"], 11)
        self.assertEqual(report["kraft_ocr_unmeasured_image_count"], 1)
        self.assertEqual(report["kraft_ocr_consistency"], 1.0)
        self.assertTrue(report["kraft_ocr_gate_passed"])
        self.assertEqual(report["kraft_ocr_inconsistent_images"], [])

        # Never silently dropped: named, with the kraft count it went unread on.
        unmeasured = report["kraft_ocr_unmeasured_images"]
        self.assertEqual(len(unmeasured), 1)
        self.assertEqual(unmeasured[0]["image_name"], "fridge-00.jpg")
        self.assertEqual(unmeasured[0]["kraft_proposal_count"], 2)
        self.assertIn("no sticker text", unmeasured[0]["reason"])
        # ...and surfaced on the PASSING message, not just in a JSON field.
        self.assertIn("WARNING", report["message"])
        self.assertIn("fridge-00.jpg", report["message"])

    def test_a_broken_reader_cannot_open_the_gate_vacuously(self) -> None:
        """The exclusion must never become a way to pass by reading nothing.

        This is the attack the rule invites: if "unreadable" is excluded, then
        an OCR stage that fails everywhere would leave an empty comparable set,
        and 0/0 must NOT be treated as agreement. The gate requires a non-empty
        comparable set precisely so total failure blocks instead of passing.
        """

        module = self.module
        records, corrections = self._twenty_image_records(module)
        for record in records:
            if "ocr_sticker_count" in record:
                record["ocr_sticker_count"] = 0

        report = module.run_detector_validator_agent(records, corrections)

        self.assertEqual(report["kraft_ocr_comparable_image_count"], 0)
        self.assertEqual(report["kraft_ocr_unmeasured_image_count"], 12)
        self.assertFalse(report["kraft_ocr_gate_passed"])
        self.assertFalse(report["human_handoff_allowed"])
        with self.assertRaises(RuntimeError):
            module.assert_detector_validator_allows_human_handoff(report)

    def test_a_genuine_ocr_disagreement_still_blocks(self) -> None:
        """Only a ZERO reading is excused; a real mismatch still fails.

        Guards the boundary the rule turns on. An off-by-one — the shape of the
        four real disagreements (sankt-georg 6v7, mega-eg 3v4, mutabor 16v17,
        statista 18v19) — must keep counting against the gate.
        """

        module = self.module
        records, corrections = self._twenty_image_records(module)
        records[0]["ocr_sticker_count"] = 1  # 1 read against 2 bowls

        report = module.run_detector_validator_agent(records, corrections)

        self.assertEqual(report["kraft_ocr_comparable_image_count"], 12)
        self.assertEqual(report["kraft_ocr_unmeasured_image_count"], 0)
        self.assertFalse(report["kraft_ocr_gate_passed"])
        self.assertEqual(
            [row["image_name"] for row in report["kraft_ocr_inconsistent_images"]],
            ["fridge-00.jpg"],
        )

    def test_zero_stickers_on_zero_bowls_is_agreement_not_an_excuse(self) -> None:
        """0 == 0 is a real measurement and must stay comparable.

        The exclusion is scoped to photos that HOLD kraft bowls. A photo with no
        bowls and no stickers is genuine agreement, and quietly dropping it would
        shrink the evidence base for no reason.
        """

        module = self.module
        records, corrections = self._twenty_image_records(module, kraft=0)
        report = module.run_detector_validator_agent(records, corrections)

        self.assertEqual(report["kraft_ocr_unmeasured_image_count"], 0)
        self.assertEqual(report["kraft_ocr_comparable_image_count"], 12)
        self.assertTrue(report["kraft_ocr_gate_passed"])

    def test_chopstick_and_packet_counts_are_tolerant_but_must_be_detected(
        self,
    ) -> None:
        """Classes 5 and 6 may miscount, but may never come back empty.

        The reviewer's rule was: "No compromise on incorrect counts unless its a
        chopstick count or black and white soya sauce packet."  Read together
        with requirements 4 and 5 ("chopsticks should not escape detection, they
        should get counted") that means two different failures must behave
        differently:

          * off-by-one on tips  -> still a PASS  (barmbek: human wrote 40 tips
            while personally drawing only 39 boxes; the detector found 39)
          * zero tips found     -> still a FAIL  (mb-energy: human says 36, the
            detector proposed none at all)

        A sauce-cup class is checked here too, to prove the tolerance did not
        leak into the classes that must stay exact.
        """

        module = self.module

        def score(final_tips: int, final_soya: int) -> dict:
            return module.score_image_required_count_accuracy(
                {
                    "image_name": "fridge.jpg",
                    "final_class_counts": {
                        **{name: 0 for name in module.FIXED_CLASS_NAMES},
                        "wooden chopstick tip": final_tips,
                        "black soya sauce cup": final_soya,
                    },
                },
                {
                    "decision": "reject",
                    "requested_counts": {
                        "wooden chopstick tip": 40,
                        "black soya sauce cup": 3,
                    },
                },
            )

        # Off by one on tips, sauce cups exact -> passes.
        near_miss = score(39, 3)
        self.assertTrue(near_miss["passed"])
        self.assertEqual(near_miss["mismatches"], [])
        tip_row = near_miss["class_results"]["wooden chopstick tip"]
        self.assertEqual(tip_row["count_policy"], "detected_not_exact")
        self.assertFalse(tip_row["exact_match"])
        self.assertTrue(tip_row["satisfied"])

        # Tips missing entirely -> fails, with a message that says why.
        undetected = score(0, 3)
        self.assertFalse(undetected["passed"])
        self.assertTrue(
            any(
                "wooden chopstick tip" in reason and "found none" in reason
                for reason in undetected["mismatches"]
            ),
            undetected["mismatches"],
        )

        # Tolerance must NOT apply to an exact-count class: one soya cup short
        # is still a failure even though the tips are fine.
        soya_short = score(39, 2)
        self.assertFalse(soya_short["passed"])
        self.assertEqual(
            soya_short["class_results"]["black soya sauce cup"]["count_policy"],
            "exact",
        )
        self.assertTrue(
            any(
                "black soya sauce cup: final=2 human=3" in reason
                for reason in soya_short["mismatches"]
            ),
            soya_short["mismatches"],
        )

    def test_visible_counts_stay_exact_while_declared_occlusion_is_tolerated(
        self,
    ) -> None:
        """"No compromise for the visible ones" — only stated occlusion relaxes.

        Two real reviewer notes drive this, and they must behave differently:

          byteclub  "red teriyaki sauce cup are 7 (2 are stacked behind 5 front
                     ones)"      -> 5 visible, 2 physically out of view.
                                    Finding 5 is a PASS; finding 4 is a FAIL.

          statista  "black soya sauce cup same level 2 columns = 12"
                     -> both columns sit on the same shelf and are visible, so
                        12 must be matched exactly.  The word "column" must
                        never be read as an occlusion excuse.
        """

        module = self.module

        def score(note: str, class_name: str, total: int, found: int) -> dict:
            return module.score_image_required_count_accuracy(
                {
                    "image_name": "fridge.jpg",
                    "final_class_counts": {
                        **{name: 0 for name in module.FIXED_CLASS_NAMES},
                        class_name: found,
                    },
                },
                {
                    "decision": "reject",
                    "requested_counts": {class_name: total},
                    "notes": note,
                },
            )

        byteclub_note = (
            "red teriyaki sauce cup are 7 (2 are stacked behind 5 front ones)"
        )
        # Exactly the visible five -> passes, and says so in the class row.
        visible_only = score(byteclub_note, "red teriyaki sauce cup", 7, 5)
        self.assertTrue(visible_only["passed"], visible_only["mismatches"])
        teriyaki = visible_only["class_results"]["red teriyaki sauce cup"]
        self.assertEqual(teriyaki["count_policy"], "visible_exact_hidden_tolerant")
        self.assertEqual(teriyaki["visible_floor"], 5)
        self.assertFalse(teriyaki["exact_match"])

        # Finding all seven is still fine (better, as the reviewer asked).
        self.assertTrue(score(byteclub_note, "red teriyaki sauce cup", 7, 7)["passed"])

        # Missing one of the VISIBLE five -> still a failure. This is the half
        # of the rule that must not be weakened.
        short = score(byteclub_note, "red teriyaki sauce cup", 7, 4)
        self.assertFalse(short["passed"])
        self.assertTrue(
            any("at least 5 are visible" in reason for reason in short["mismatches"]),
            short["mismatches"],
        )

        # Over-counting past the reviewer's total is a failure too.
        self.assertFalse(score(byteclub_note, "red teriyaki sauce cup", 7, 9)["passed"])

        # "2 columns" is NOT occlusion language: statista must stay exact.
        statista_note = "black soya sauce cup same level 2 columns = 12"
        partial = score(statista_note, "black soya sauce cup", 12, 6)
        self.assertFalse(partial["passed"])
        self.assertEqual(
            partial["class_results"]["black soya sauce cup"]["count_policy"],
            "exact",
        )

        # A note that claims occlusion but names no visible number must NOT
        # relax anything — we fail closed rather than guess the visible subset.
        vague = score(
            "black soya sauce cup are 12, some are stacked behind",
            "black soya sauce cup",
            12,
            6,
        )
        self.assertFalse(vague["passed"])
        self.assertEqual(
            vague["class_results"]["black soya sauce cup"]["count_policy"],
            "exact",
        )

    def test_cross_class_duplicates_cannot_buy_an_exact_count_match(self) -> None:
        """An image must not pass its count by over-proposing wrong boxes.

        ``enforce_exact_human_estimate_counts`` trims a class down to the
        reviewer's number whenever the detector proposes too many.  That is only
        safe while the surplus are plausible instances of that class.  A box
        already claimed by another class is not: it would be counted, trimmed to
        the reviewer's figure, and the image would report an exact match on
        geometry that is provably wrong.

        This is the exact attack that exposed the hole: take a photo's kraft-bowl
        boxes, relabel copies of them as sauce cups, and watch the trimmer hand
        back precisely the reviewer's number.
        """

        module = self.module

        def box(class_id, x1, y1, x2, y2, source, confidence=0.9):
            return {
                "class_id": class_id,
                "bbox_xyxy": [float(x1), float(y1), float(x2), float(y2)],
                "confidence": confidence,
                "source": source,
            }

        # Two genuine sauce cups, plus four kraft bowls.
        real = [
            box(1, 0, 0, 100, 100, "real"),
            box(1, 400, 0, 500, 100, "real"),
            box(0, 0, 300, 300, 500, "real"),
            box(0, 400, 300, 700, 500, "real"),
            box(0, 800, 300, 1100, 500, "real"),
            box(0, 1200, 300, 1500, 500, "real"),
        ]
        # The attack: copy every bowl box and relabel it a sauce cup.
        forged = [
            {**dict(row), "class_id": 1, "source": "forged", "confidence": 0.5}
            for row in real
            if int(row["class_id"]) == 0
        ]
        correction = {
            "decision": "reject",
            "requested_counts": {"black soya sauce cup": 6},
        }

        # Without the guard the trimmer would hand back exactly six.
        unguarded, _ = module.enforce_exact_human_estimate_counts(
            real + forged, correction
        )
        self.assertEqual(
            sum(1 for r in unguarded if int(r["class_id"]) == 1),
            6,
            "fixture no longer reproduces the loophole it is guarding",
        )

        # With the guard, the forged boxes are gone and the image fails honestly.
        deduped, record = module.drop_cross_class_duplicate_proposals(real + forged)
        self.assertEqual(record["cross_class_duplicate_rejected_count"], 4)
        self.assertEqual(sum(1 for r in deduped if r.get("source") == "forged"), 0)
        guarded, _ = module.enforce_exact_human_estimate_counts(deduped, correction)
        self.assertEqual(sum(1 for r in guarded if int(r["class_id"]) == 1), 2)

        # And every genuine box survives -- the guard must not eat real items.
        self.assertEqual(sum(1 for r in deduped if int(r["class_id"]) == 0), 4)
        self.assertEqual(
            sum(1 for r in deduped if int(r["class_id"]) == 1), 2
        )

        # A cup merely standing in FRONT of a bowl overlaps a lot but not
        # near-perfectly, and must be kept.
        overlapping = [
            box(0, 0, 0, 300, 300, "bowl"),
            box(1, 60, 60, 240, 240, "cup_in_front"),
        ]
        kept, rec = module.drop_cross_class_duplicate_proposals(overlapping)
        self.assertEqual(len(kept), 2, rec)
        self.assertEqual(rec["cross_class_duplicate_rejected_count"], 0)

    def test_small_object_merge_never_collapses_real_sauce_cups(self) -> None:
        """The tip-merging rule must not touch sauce cups.

        Chopstick tips are so small that two exemplars can never confirm the
        same tip by box overlap, so they are confirmed by centre distance
        instead.  That rule has to stay strictly inside tip scale: sauce cups
        stand in vertical columns, so two DIFFERENT cups sit close together and
        would be collapsed into one if the size gate were set too high.

        The gate is measured against the boxes on the fourteen TARGET images
        (the six approved reference photos never reach this lane).  There the
        smallest real sauce-cup box is 110 px on its longest side, so the gate
        must stay clearly below that.  It must also compare the LONGEST side:
        statista's cup boxes are only 43 px on their short axis.
        """

        module = self.module
        gate = module.AUDITED_VISUAL_RECOVERY_SMALL_OBJECT_MAX_SIDE
        ratio = module.AUDITED_VISUAL_RECOVERY_SUPPORT_CENTER_RATIO
        self.assertLess(
            gate,
            110,
            "small-object gate reaches real sauce-cup boxes (smallest measured 110 px)",
        )

        def instance(class_id, x1, y1, x2, y2, reference):
            return {
                "class_id": class_id,
                "bbox_xyxy": [x1, y1, x2, y2],
                "confidence": 0.9,
                "supporting_references": [reference],
                "reference_support_count": 1,
            }

        # statista's real geometry: 110 px wide, 43 px tall, stacked 17 px apart.
        cups = [
            instance(1, 0.0, 0.0, 110.0, 43.0, "refA"),
            instance(1, 0.0, 17.0, 110.0, 60.0, "refB"),
        ]
        self.assertEqual(
            len(module.merge_small_object_reference_support(
                cups, max_object_side=gate, center_ratio=ratio
            )),
            2,
            "two distinct stacked sauce cups were merged into one",
        )

        # Tip scale: two exemplars seeing the SAME 22 px tip must merge and the
        # survivor must gain the second reference, or it gets deleted later.
        tips = [
            instance(5, 0.0, 0.0, 22.0, 22.0, "refA"),
            instance(5, 2.0, 2.0, 24.0, 24.0, "refB"),
        ]
        merged = module.merge_small_object_reference_support(
            tips, max_object_side=gate, center_ratio=ratio
        )
        self.assertEqual(len(merged), 1)
        self.assertEqual(merged[0]["reference_support_count"], 2)

        # Two DIFFERENT tips, far enough apart, must stay two.
        apart = [
            instance(5, 0.0, 0.0, 22.0, 22.0, "refA"),
            instance(5, 40.0, 0.0, 62.0, 22.0, "refB"),
        ]
        self.assertEqual(
            len(module.merge_small_object_reference_support(
                apart, max_object_side=gate, center_ratio=ratio
            )),
            2,
        )

    def test_kraft_filter_ruler_survives_merged_and_fragmented_cup_boxes(
        self,
    ) -> None:
        """The sauce-cup ruler must not be fooled by either kind of bad cup box.

        The filter deletes a "kraft paper bowl" box when it is narrower than 1.6
        times one sauce cup, measuring "one sauce cup" from the cup boxes in the
        same photo.  Real detector output pollutes that measurement at BOTH ends:

          merged boxes   one box over two cups (zeisehof really has a 283 px cup
                         box next to 134 px ones).  A plain median drifts up, and
                         the filter starts deleting real bowls.
          fragment boxes one box over half a cup (techhub really has two 51 px
                         cup boxes next to 106-117 px ones).  A low quantile
                         drifts down, and cup stacks survive as fake bowls.

        Deleting a real bowl is silent and unrecoverable, so wherever the ruler
        cannot be trusted the filter must hand every bowl back untouched.
        """

        module = self.module

        def box(class_id: int, width: float) -> dict:
            return {"class_id": class_id, "bbox_xyxy": [0.0, 0.0, width, 200.0]}

        def kraft_kept(cup_widths, bowl_widths) -> int:
            instances = [box(1, float(w)) for w in cup_widths]
            instances += [box(0, float(w)) for w in bowl_widths]
            kept, _record = module.filter_sauce_cup_stack_kraft_bowls(instances)
            return sum(1 for row in kept if int(row["class_id"]) == 0)

        # Real zeisehof numbers: 9 bowls, and its kraft count already equals the
        # reviewer's 9 exactly.  Nothing may take that away.
        zeisehof_bowls = [266, 272, 277, 277, 277, 279, 285, 286, 291]
        zeisehof_cups = [109, 134, 134, 134, 283]
        self.assertEqual(kraft_kept(zeisehof_cups, zeisehof_bowls), 9)
        # ...even when more two-cup merges appear, up to and past the point where
        # the merges outnumber the single cups.
        for merged in ([199] * 3, [283] * 3, [400] * 3, [283] * 6):
            self.assertEqual(
                kraft_kept(zeisehof_cups + merged, zeisehof_bowls),
                9,
                f"merged cup boxes {merged} destroyed real bowls",
            )

        # Real techhub numbers: the two 51 px fragments must NOT halve the ruler.
        # Its three ~110 px boxes are cup stacks and must still be rejected; the
        # 285 px box is the one real bowl.
        self.assertEqual(
            kraft_kept([51, 51, 106, 110, 117], [107, 110, 114, 285]),
            1,
        )

        # A photo where the cup boxes agree on nothing gives no ruler at all, so
        # a genuine wide bowl must survive rather than be guessed away.
        for cups in ([100, 100, 600], [100, 100, 600, 640], [100, 100, 600, 620, 640]):
            self.assertEqual(
                kraft_kept(cups, [500]),
                1,
                f"cup widths {cups} produced a ruler that deleted a real bowl",
            )

        # Too few cups to measure anything -> stand down, keep everything.
        stood_down = kraft_kept([120], [300, 130])
        self.assertEqual(stood_down, 2)

    def test_detector_validator_agent_cli_writes_report_and_exit_codes(self) -> None:
        """CLI agent path scores a real run_manifest directory."""

        import importlib.util
        import subprocess

        agent_path = (
            Path(__file__).resolve().parents[1]
            / "training"
            / "autoresearch"
            / "kaggle_label_factory"
            / "detector_validator_agent.py"
        )
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            # Minimal 20-image run_manifest with exact kraft matches.
            images = []
            for index in range(20):
                images.append(
                    {
                        "image_name": f"fridge-{index:02d}.jpg",
                        "final_class_counts": {
                            "kraft paper bowl": 2 if index < 5 else 0,
                            "black soya sauce cup": 0,
                            "red teriyaki sauce cup": 0,
                            "white wayo dip cup": 0,
                            "orange chili mayo cup": 0,
                            "wooden chopstick tip": 0,
                            "black and white soya sauce packet": 0,
                        },
                        "ocr_sticker_count": 2 if index < 5 else None,
                        "correction_guided_requested_counts": (
                            {"kraft paper bowl": 2} if index < 5 else {}
                        ),
                    }
                )
            (root / "run_manifest.json").write_text(
                json.dumps(
                    {
                        "status": "awaiting_twenty_image_pass_reject",
                        "images": images,
                        "training_authorized": False,
                        "promotion_authorized": False,
                    },
                    indent=2,
                )
                + "\n",
                encoding="utf-8",
            )
            # Fail case: one shortfall via override on image 0.
            fail_dir = root / "fail_batch"
            fail_dir.mkdir()
            fail_images = json.loads(
                (root / "run_manifest.json").read_text(encoding="utf-8")
            )
            fail_images["images"][0]["final_class_counts"]["kraft paper bowl"] = 1
            fail_images["images"][0]["ocr_sticker_count"] = 1
            (fail_dir / "run_manifest.json").write_text(
                json.dumps(fail_images, indent=2) + "\n", encoding="utf-8"
            )
            fail_proc = subprocess.run(
                [sys.executable, str(agent_path), str(fail_dir), "--allow-failed-handoff"],
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(fail_proc.returncode, 1)
            fail_report = json.loads(
                (fail_dir / "detector_validator_report.json").read_text(encoding="utf-8")
            )
            self.assertFalse(fail_report["human_handoff_allowed"])

            ok_proc = subprocess.run(
                [sys.executable, str(agent_path), str(root)],
                capture_output=True,
                text=True,
                check=False,
            )
            self.assertEqual(ok_proc.returncode, 0)
            ok_report = json.loads(
                (root / "detector_validator_report.json").read_text(encoding="utf-8")
            )
            self.assertTrue(ok_report["human_handoff_allowed"])
            self.assertGreater(ok_report["required_item_count_accuracy"], 0.95)

    def test_kraft_bowl_ocr_keeps_repeated_names_on_separate_stickers(self) -> None:
        class RepeatedDishOCR:
            def __init__(self) -> None:
                self.bowl_phase = 0
                self.crop_call_count = 0

            def __call__(self, image):
                if isinstance(image, str):
                    return [], 0.01
                self.crop_call_count += 1
                # Boxes are expressed as a FRACTION of the view actually handed
                # in, because the runtime pads and upscales each bowl crop, so
                # the view size differs from call to call.  Each sticker sits in
                # the middle of its own crop, which is where a real sticker is.
                height, width = image.shape[:2]

                def band(x0, y0, x1, y1):
                    return [
                        [x0 * width, y0 * height],
                        [x1 * width, y0 * height],
                        [x1 * width, y1 * height],
                        [x0 * width, y1 * height],
                    ]

                results = [
                    [(band(0.20, 0.42, 0.72, 0.52), "OSAKA", 0.96)],
                    [(band(0.20, 0.42, 0.72, 0.52), "OSAKA", 0.95)],
                    [
                        (band(0.20, 0.42, 0.72, 0.52), "Chicken", 0.94),
                        (band(0.20, 0.54, 0.72, 0.64), "Karaage", 0.93),
                    ],
                ]
                phase = min(self.bowl_phase, len(results) - 1)
                result = results[phase]
                # Advance after each successful vocabulary dish (all three succeed).
                if self.bowl_phase < len(results):
                    self.bowl_phase += 1
                return result, 0.01

        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "fridge.jpg"
            Image.new("RGB", (1000, 1000), color=(235, 235, 235)).save(image_path)
            evidence = self.module.extract_kraft_bowl_sticker_evidence(
                image_path,
                segmentation_bowl_count=3,
                ocr_engine=RepeatedDishOCR(),
                bowl_instances=[
                    {"class_id": 0, "bbox_xyxy": [100, 100, 300, 300]},
                    {"class_id": 0, "bbox_xyxy": [350, 100, 550, 300]},
                    {"class_id": 0, "bbox_xyxy": [600, 100, 800, 300]},
                ],
            )

        self.assertEqual(evidence["sticker_count"], 3)
        self.assertEqual(evidence["recognized_texts"], ["OSAKA", "OSAKA", "CHICKEN KARAAGE"])
        self.assertFalse(evidence["disagrees_with_segmentation"])
        self.assertEqual(evidence["count_difference"], 0)

    def test_kraft_bowl_ocr_unavailability_is_explicit_and_never_a_zero_count(self) -> None:
        evidence = self.module.extract_kraft_bowl_sticker_evidence(
            Path("not-opened.jpg"),
            segmentation_bowl_count=4,
            ocr_engine=None,
            unavailable_reason="ImportError",
        )

        self.assertEqual(evidence["status"], "unavailable")
        self.assertEqual(evidence["unavailable_reason"], "ImportError")
        self.assertIsNone(evidence["sticker_count"])
        self.assertIsNone(evidence["disagrees_with_segmentation"])
        self.assertEqual(evidence["recognized_texts"], [])
        self.assertFalse(evidence["used_as_ground_truth"])

    def test_run_manifest_records_model_hashes_parameters_and_twenty_pending_decisions(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            yoloe_model = root / "yoloe-26x-seg.pt"
            sam3_model = root / "sam3.1_multiplex.pt"
            yoloe_model.write_bytes(b"yoloe")
            sam3_model.write_bytes(b"sam3")
            manifest = self.module.build_run_manifest(
                input_manifest={
                    "image_names": [f"fridge-{index:02d}.jpg" for index in range(20)],
                    "reference_annotation_sha256": {"one.json": "abc"},
                },
                records=[
                    {
                        "image_name": f"fridge-{index:02d}.jpg",
                        "instance_count": index,
                        "proposal_polygon": f"p-{index}.txt",
                        "contact_sheet": f"c-{index}.jpg",
                        "sam3_refinement_status": "success",
                        "kraft_bowl_sticker_evidence": {
                            "status": "available",
                            "advisory_only": True,
                            "used_as_ground_truth": False,
                            "sticker_count": index,
                            "recognized_texts": [f"dish-{index}"],
                            "disagrees_with_segmentation": False,
                        },
                        "ocr_sticker_count": index,
                        "ocr_sticker_texts": [f"dish-{index}"],
                        "ocr_status": "available",
                        "visual_prompt_instance_count": 2 if index < 3 else 0,
                        "text_prompt_fallback_ran": index < 3,
                        "text_prompt_instance_count": index if index < 3 else 0,
                        "proposal_union_instance_count": index + 1 if index < 3 else 0,
                        "yoloe_proposal_union_instance_count": index + 1 if index < 3 else 0,
                        "visual_only_proposal_count": 1 if index < 3 else 0,
                        "text_only_proposal_count": index if index < 3 else 0,
                        "dual_supported_proposal_count": 0,
                        "review_decision": "pending",
                    }
                    for index in range(20)
                ],
                visual_prompt_plans=[],
                sam3_status="available",
                yoloe_model=yoloe_model,
                sam3_model=sam3_model,
                parameters={
                    "device": "0",
                    "tile_size": 1280,
                    "overlap": 0.25,
                    "confidence": 0.05,
                    "iou": 0.45,
                    "text_fallback_enabled": True,
                    # The manifest must state, explicitly, which text mode ran.
                    # There are two sanctioned ones now: the original
                    # low-visual-proposal fallback, and --text-prompt-primary,
                    # which runs the text lane on every image and is what a
                    # fine-tuned checkpoint needs. Neither may be inferred from a
                    # missing key, because "absent" and "off" would then look the
                    # same in an approval artifact.
                    "text_prompt_primary": False,
                    "text_fallback_target_count": 3,
                    "text_fallback_target_images": [
                        "fridge-00.jpg",
                        "fridge-01.jpg",
                        "fridge-02.jpg",
                    ],
                },
            )

            self.assertEqual(manifest["fixed_image_count"], 20)
            self.assertEqual(manifest["models"]["yoloe26x"]["sha256"], self.module.sha256_file(yoloe_model))
            self.assertEqual(manifest["models"]["sam31"]["sha256"], self.module.sha256_file(sam3_model))
            self.assertEqual(manifest["inference_parameters"]["tile_size"], 1280)
            self.assertEqual(len(manifest["images"]), 20)
            self.assertTrue(all(row["review_decision"] == "pending" for row in manifest["images"]))
            self.assertEqual(manifest["images"][7]["ocr_sticker_count"], 7)
            self.assertEqual(manifest["images"][7]["ocr_sticker_texts"], ["dish-7"])
            self.assertEqual(manifest["images"][7]["ocr_status"], "available")
            self.assertTrue(
                manifest["images"][7]["kraft_bowl_sticker_evidence"]["advisory_only"]
            )
            self.assertFalse(
                manifest["images"][7]["kraft_bowl_sticker_evidence"]["used_as_ground_truth"]
            )
            self.assertFalse(manifest["release_gate"]["passed"])
            self.assertEqual(
                manifest["text_prompt_fallback_summary"],
                {
                    "triggered_image_count": 3,
                    "text_proposal_count_before_union": 3,
                    "visual_proposal_count_on_triggered_images": 6,
                    "union_proposal_count_on_triggered_images": 6,
                    "visual_only_union_proposal_count": 3,
                    "text_only_union_proposal_count": 3,
                    "dual_supported_union_proposal_count": 0,
                    "target_images": [
                        "fridge-00.jpg",
                        "fridge-01.jpg",
                        "fridge-02.jpg",
                    ],
                },
            )
            self.assertEqual(
                manifest["sam3_instance_summary"],
                {
                    "total_instance_count": 190,
                    "successful_instance_count": 190,
                    "fallback_instance_count": 0,
                    "successful_instance_rate": 1.0,
                    "all_instances_fell_back": False,
                },
            )

            decisions = self.module.build_review_decision_manifest(manifest)
            self.assertEqual(decisions["status"], "awaiting_twenty_image_pass_reject")
            self.assertEqual(len(decisions["images"]), 20)
            self.assertTrue(all(row["decision"] == "pending" for row in decisions["images"]))
            self.assertFalse(decisions["training_authorized"])

    def test_runtime_uses_explicit_sam3_checkpoint_and_releases_yoloe_before_loading_it(self) -> None:
        source = SCRIPT_PATH.read_text(encoding="utf-8")

        self.assertIn("build_sam3_predictor(", source)
        self.assertIn('checkpoint_path=str(args.sam3_model)', source)
        self.assertIn('version="sam3.1"', source)
        self.assertIn('"--sam3-model"', source)
        self.assertIn("del visual_model", source)
        self.assertIn("del text_model", source)
        self.assertIn("gc.collect()", source)
        self.assertIn("torch.cuda.empty_cache()", source)
        self.assertIn("text_model = load_text_prompt_model(", source)
        self.assertIn("args.yoloe_model, YOLOE, text_prompts=args.text_prompt or None", source)
        # The prompt WORDS are parameterised so the cup-colour experiment can
        # move them, but the default is still the fixed class bank and the
        # readback below still fails closed on any reordering.
        self.assertIn("prompts = list(text_prompts or FIXED_CLASS_NAMES)", source)
        self.assertIn("model.get_text_pe(prompts)", source)
        self.assertIn("model.set_classes(prompts, embeddings)", source)
        self.assertIn("if ordered_names != prompts:", source)
        self.assertIn(
            '"text_prompt_model_reloaded_after_visual_prompts": bool(weak_target_paths)',
            source,
        )
        self.assertLess(
            source.index("del visual_model"),
            source.index("args.yoloe_model, YOLOE, text_prompts=args.text_prompt or None"),
        )
        self.assertLess(source.index("del text_model"), source.index("build_sam3_predictor("))

    def test_sam3_refinement_failure_returns_original_quarantined_proposals(self) -> None:
        proposals = [
            {
                "class_id": 5,
                "class_name": "wooden chopstick tip",
                "confidence": 0.88,
                "bbox_xyxy": [10, 20, 30, 40],
                "polygon": [[10, 20], [30, 20], [30, 40]],
                "source": "yoloe26x_visual_prompt_tiled",
            }
        ]

        class BrokenSam3:
            def predict(self, **_arguments):
                raise RuntimeError("simulated gated checkpoint failure")

        refined, status, error = self.module.sam3_refine_or_preserve(
            BrokenSam3(),
            Path("fridge.jpg"),
            proposals,
            "0",
        )

        self.assertEqual(refined, proposals)
        self.assertEqual(status, "failed_yoloe_proposal_preserved")
        self.assertEqual(error, "AttributeError: 'BrokenSam3' object has no attribute 'refine'")

    def test_polygon_audit_rejects_missing_nonfinite_out_of_bounds_and_roundtrip_loss(self) -> None:
        valid = {
            "class_id": 5,
            "polygon": [[10, 20], [30, 20], [30, 40]],
        }
        audit = self.module.audit_polygon_output([valid], 100, 80)
        self.assertTrue(audit["passed"])
        self.assertEqual(audit["instance_count"], 1)
        self.assertEqual(audit["emitted_row_count"], 1)

        bad_cases = [
            [{"class_id": 5, "polygon": []}],
            [{"class_id": 5, "polygon": [[10, 20], [float("nan"), 20], [30, 40]]}],
            [{"class_id": 5, "polygon": [[10, 20], [130, 20], [30, 40]]}],
            [{"class_id": 5, "polygon": [[10, 20], [10, 20], [10, 20]]}],
            [{"class_id": 5, "polygon": [[10, 20], [20, 20], [30, 20]]}],
        ]
        for instances in bad_cases:
            with self.subTest(instances=instances):
                failed = self.module.audit_polygon_output(instances, 100, 80)
                self.assertFalse(failed["passed"])

    def test_audited_reference_with_instances_requires_successful_sam3_refinement(self) -> None:
        audit = self.module.audit_image_for_review(
            image_name="reference.jpg",
            instances=[{"class_id": 0, "polygon": [[1, 1], [5, 1], [5, 5]]}],
            width=10,
            height=10,
            is_audited_reference=True,
            sam3_refinement_status="not_run",
        )
        self.assertFalse(audit["passed"])
        self.assertIn("sam3_refinement_required", audit["failure_reasons"])

    def test_empty_inventory_target_is_not_ready_for_human_review(self) -> None:
        audit = self.module.audit_image_for_review(
            image_name="empty-target.jpg",
            instances=[],
            width=100,
            height=80,
            is_audited_reference=False,
            sam3_refinement_status="success",
        )
        self.assertFalse(audit["passed"])
        self.assertEqual(audit["instance_count"], 0)
        self.assertIn("inventory_image_has_no_instances", audit["failure_reasons"])

    def test_consensus_filter_defaults_to_two_reference_support_for_targets(self) -> None:
        instances = [
            {"class_id": 0, "reference_support_count": 1},
            {"class_id": 1, "reference_support_count": 2},
            {"class_id": 2, "reference_support_count": 3},
        ]
        filtered = self.module.filter_by_reference_support(instances, minimum=2)
        self.assertEqual([row["class_id"] for row in filtered], [1, 2])

    def test_image_shards_cover_every_target_exactly_once(self) -> None:
        """Chunking must partition the targets, not sample them.

        A full pass is ~4.5 min/image, so 20 images is ~90 minutes -- too long
        for a Colab runtime that can be reclaimed mid-pass, and far too long a
        feedback loop. Shards make that ~13-minute pieces, but only if the
        shards form a true partition: an image dropped by every shard would
        silently never be proposed, and one counted twice would be processed at
        double cost. Both failures are invisible in a single shard's output.
        """
        import argparse

        parse = self.module.parse_image_shard
        self.assertEqual(parse("1/7"), (1, 7))
        self.assertEqual(parse("7/7"), (7, 7))
        for malformed in ("0/7", "8/7", "abc", "3", "1/0"):
            with self.subTest(value=malformed):
                # Rejected at parse time, before any model loads -- on a metered
                # GPU, failing late costs real money.
                with self.assertRaises(argparse.ArgumentTypeError):
                    parse(malformed)

        for target_count in (14, 20):
            for shard_count in (2, 3, 7):
                covered: list[int] = []
                for shard_index in range(1, shard_count + 1):
                    covered += [
                        position
                        for position in range(target_count)
                        if position % shard_count == (shard_index - 1)
                    ]
                with self.subTest(targets=target_count, shards=shard_count):
                    self.assertEqual(sorted(covered), list(range(target_count)))

    def test_packet_box_on_a_kraft_bowls_own_label_is_dropped(self) -> None:
        """One object, one outline: the bowl's dish label is not a sachet.

        A kraft bowl's printed label is a white sticker with black text, and so
        is a soya sachet, so the detector outlines the label a second time and
        calls it a packet -- 160 of 473 packet boxes in V55.  The reviewer's own
        rectangles decide the case: 0 of their 20 packet rectangles fall inside
        a kraft bowl, because sachets are stocked beside the bowls, never on
        them.  A packet that keeps clear of every bowl must still survive.
        """
        bowl = {"class_id": 0, "bbox_xyxy": [100.0, 100.0, 400.0, 340.0]}
        label_on_the_bowl = {"class_id": 6, "bbox_xyxy": [180.0, 180.0, 280.0, 250.0]}
        real_packet_beside_it = {"class_id": 6, "bbox_xyxy": [520.0, 180.0, 620.0, 250.0]}

        kept, record = self.module.filter_implausible_packet_proposals(
            [bowl, label_on_the_bowl, real_packet_beside_it], 1000
        )

        self.assertEqual(record["inside_kraft_bowl_rejected_count"], 1)
        self.assertEqual(record["kraft_bowl_box_count"], 1)
        kept_boxes = [row["bbox_xyxy"] for row in kept]
        self.assertIn(real_packet_beside_it["bbox_xyxy"], kept_boxes)
        self.assertNotIn(label_on_the_bowl["bbox_xyxy"], kept_boxes)
        # The bowl itself is another class and must pass straight through.
        self.assertIn(bowl["bbox_xyxy"], kept_boxes)

    def _cabinet_photo(self, path, post_x_fraction=None):
        """Build a lit fridge frame, optionally with a dark frame post in it.

        Mirrors the real geometry the filter keys on: a mid-grey cabinet
        interior, and -- when asked for -- a narrow near-black vertical band at
        the given fraction of the width, which is what two cabinets standing
        side by side look like where their frames meet.
        """
        from PIL import Image

        width, height = 400, 300
        photo = Image.new("RGB", (width, height), color=(150, 150, 150))
        if post_x_fraction is not None:
            post_x = int(width * post_x_fraction)
            for x in range(post_x - 2, post_x + 3):
                for y in range(height):
                    photo.putpixel((x, y), (12, 12, 12))
        photo.save(path)
        return width

    def test_adjacent_cabinet_boxes_are_dropped_only_when_a_frame_post_exists(
        self,
    ) -> None:
        """The neighbouring fridge's stock must go; a real edge item must stay.

        Both halves matter equally.  Dropping the neighbour's boxes is the fix;
        keeping saco's genuine right-edge items is the thing that fix must not
        break, and the only difference between the two situations is whether a
        dark frame post stands between the shelf and those boxes.
        """
        import tempfile
        from pathlib import Path

        # Eight boxes on our shelf, two beyond x=0.88 in the neighbour.
        instances = [
            {"class_id": 1, "bbox_xyxy": [40.0 + i * 30, 100.0, 70.0 + i * 30, 140.0]}
            for i in range(8)
        ] + [
            {"class_id": 1, "bbox_xyxy": [360.0, 100.0, 390.0, 140.0]},
            {"class_id": 2, "bbox_xyxy": [362.0, 160.0, 392.0, 200.0]},
        ]

        with tempfile.TemporaryDirectory() as temp_dir:
            with_post = Path(temp_dir) / "neighbouring_cabinet.jpg"
            self._cabinet_photo(with_post, post_x_fraction=0.88)
            kept, record = self.module.filter_adjacent_cabinet_instances(
                with_post, instances
            )
            self.assertEqual(record["status"], "available")
            self.assertEqual(record["adjacent_cabinet_rejected_count"], 2)
            self.assertEqual(len(kept), 8)
            self.assertAlmostEqual(record["frame_post_x_fraction"], 0.88, places=1)

            # Same boxes, no post -> this is saco, and nothing may be touched.
            without_post = Path(temp_dir) / "single_cabinet.jpg"
            self._cabinet_photo(without_post, post_x_fraction=None)
            kept, record = self.module.filter_adjacent_cabinet_instances(
                without_post, instances
            )
            self.assertEqual(record["status"], "skipped_no_frame_post")
            self.assertEqual(len(kept), len(instances))

    def test_adjacent_cabinet_filter_refuses_to_eat_the_shelf(self) -> None:
        """A post found deep inside the shelf must change nothing at all.

        If the darkest column lands somewhere that would cut away a large share
        of the frame, the post was mis-located -- no neighbouring cabinet ever
        accounts for a quarter of a photo's inventory.  Deleting most of a
        fridge is never the correct response to a brightness threshold, so the
        filter stands down and the reviewer sees the unmodified proposals.
        """
        import tempfile
        from pathlib import Path

        # Every box sits beyond the post, so the cut would remove 100%.
        instances = [
            {"class_id": 1, "bbox_xyxy": [330.0 + i * 5, 100.0 + i * 10, 360.0 + i * 5, 140.0 + i * 10]}
            for i in range(6)
        ]
        with tempfile.TemporaryDirectory() as temp_dir:
            image_path = Path(temp_dir) / "mislocated_post.jpg"
            self._cabinet_photo(image_path, post_x_fraction=0.78)
            kept, record = self.module.filter_adjacent_cabinet_instances(
                image_path, instances
            )
            self.assertEqual(
                record["status"], "skipped_group_too_large_to_be_a_neighbour"
            )
            self.assertEqual(len(kept), len(instances))
            self.assertEqual(record["adjacent_cabinet_rejected_count"], 0)


if __name__ == "__main__":
    unittest.main()


class ImageShardAppliesToTheExpensiveLoopTest(unittest.TestCase):
    """--image-shard must narrow the loop that actually costs the time.

    The flag originally narrowed only `target_paths`, which feeds the
    visual-prompt lane. SAM 3.1 semantic discovery, rescue, correction-guided
    recovery and L3 refinement all run in the per-image loop over
    manifest["image_names"], which was NOT narrowed - so `--image-shard 1/14`
    still processed all twenty images. Measured on a Colab T4: 60+ minutes for
    a "one image" shard, while also producing an incomplete result because the
    other thirteen targets were written without their visual-prompt lane.
    """

    def setUp(self) -> None:
        self.source = (
            ROOT
            / "training"
            / "autoresearch"
            / "kaggle_label_factory"
            / "assisted_label_review.py"
        ).read_text(encoding="utf-8")

    def test_expensive_per_image_loop_skips_images_outside_the_shard(self) -> None:
        self.assertIn("sharded_target_names", self.source)
        loop = self.source.index('for image_name in manifest["image_names"]:')
        body = self.source[loop : loop + 1800]
        self.assertIn("sharded_target_names is not None", body)
        self.assertIn("image_name not in sharded_target_names", body)
        self.assertIn("continue", body)

    def test_references_are_never_skipped_by_a_shard(self) -> None:
        loop = self.source.index('for image_name in manifest["image_names"]:')
        body = self.source[loop : loop + 1800]
        # A shard that dropped reference images would not be comparable with
        # any other shard, because every lane calibrates against them.
        self.assertIn("image_name not in reference_image_names", body)

    def test_a_full_pass_is_completely_unaffected(self) -> None:
        # sharded_target_names is None unless --image-shard was passed, so the
        # guard cannot change a full pass.
        self.assertIn("sharded_target_names: set[str] | None = None", self.source)

    def test_help_no_longer_promises_a_saving_it_cannot_deliver(self) -> None:
        self.assertNotIn("roughly 13 minutes instead of 90", self.source)
        self.assertIn("do NOT divide", self.source)
