from __future__ import annotations

import json
from pathlib import Path
from types import SimpleNamespace
import sys
import tempfile
import unittest
from unittest.mock import patch

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import training.autoresearch.kaggle_label_factory.train_gold_standard_seg as trainer


class TrainGoldStandardSegTest(unittest.TestCase):
    def write_approved_dataset(self, root: Path, image_count: int = 20) -> tuple[Path, Path]:
        images_dir = root / "images"
        labels_dir = root / "labels"
        images_dir.mkdir(parents=True)
        labels_dir.mkdir(parents=True)

        image_names: list[str] = []
        for index in range(image_count):
            image_name = f"approved-{index:02d}.jpg"
            Image.new("RGB", (80, 60), color=(index, 30, 60)).save(images_dir / image_name)
            # A four-corner polygon, not a YOLO detection bounding box.
            (labels_dir / f"approved-{index:02d}.txt").write_text(
                f"{index % len(trainer.CLASS_NAMES)} 0.10 0.10 0.80 0.10 0.80 0.80 0.10 0.80\n",
                encoding="utf-8",
            )
            image_names.append(image_name)

        approval_manifest = root / "approval_manifest.json"
        approval_manifest.write_text(
            json.dumps(
                {
                    "schema_version": 1,
                    "status": "approved_for_training",
                    "human_reviewed": True,
                    "pseudo_labels_accepted": False,
                    "label_format": "yolo_segmentation_polygon",
                    "reviewer": "human-reviewer",
                    "class_names": trainer.CLASS_NAMES,
                    "approved_image_names": image_names,
                }
            ),
            encoding="utf-8",
        )
        return root, approval_manifest

    def test_bootstrap_and_release_paths_are_mutually_exclusive(self) -> None:
        """A small bootstrap set must never reach the 20-image release path.

        Bootstrap training exists to break a circularity: the detector cannot
        propose clean boxes until it has trained, and it cannot train until a
        human approves proposals.  Fine-tuning on the six photos the reviewer
        annotated by hand breaks it — but that dataset carries coarse
        rectangular geometry, so it must never be mistaken for the gold standard.

        The two modes are separated by MANIFEST CONTENT, not by a flag alone, so
        neither direction can be crossed by accident:
          * ``--bootstrap`` against a gold-standard manifest -> refused, so the
            20-image bar cannot be dodged by passing a flag;
          * a bootstrap manifest without ``--bootstrap`` -> refused, so coarse
            geometry cannot slip into the release path by omitting one.
        """

        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)

            # A real gold-standard dataset: 20 images, no bootstrap markers.
            gold_root, gold_manifest = self.write_approved_dataset(root / "gold")
            # Passing --bootstrap here must NOT unlock a smaller batch.
            with self.assertRaises(ValueError) as caught:
                trainer.validate_gold_standard_dataset(
                    gold_root, gold_manifest, bootstrap=True
                )
            self.assertIn("dataset_kind", str(caught.exception))

            # A bootstrap dataset: 6 images, explicitly marked and ineligible.
            boot_root, boot_manifest = self.write_approved_dataset(
                root / "boot", image_count=trainer.MIN_BOOTSTRAP_IMAGES
            )
            payload = json.loads(boot_manifest.read_text(encoding="utf-8"))
            payload["dataset_kind"] = trainer.BOOTSTRAP_DATASET_KIND
            payload["release_gate_eligible"] = False
            boot_manifest.write_text(json.dumps(payload), encoding="utf-8")

            # Without the flag it must be refused, however well-formed it is.
            with self.assertRaises(ValueError) as caught:
                trainer.validate_gold_standard_dataset(boot_root, boot_manifest)
            self.assertIn("never promotable", str(caught.exception))

            # With the flag it validates, at the reduced size.
            validation = trainer.validate_gold_standard_dataset(
                boot_root, boot_manifest, bootstrap=True
            )
            self.assertEqual(
                validation.approved_image_count, trainer.MIN_BOOTSTRAP_IMAGES
            )

            # The release floor itself is untouched: six images still fail the
            # normal path even once the bootstrap markers are removed.
            payload.pop("dataset_kind")
            payload.pop("release_gate_eligible")
            boot_manifest.write_text(json.dumps(payload), encoding="utf-8")
            with self.assertRaises(ValueError) as caught:
                trainer.validate_gold_standard_dataset(boot_root, boot_manifest)
            self.assertIn(str(trainer.MIN_APPROVED_IMAGES), str(caught.exception))

    def test_contract_is_strictly_yoloe_26x_seg_at_1280(self) -> None:
        self.assertEqual(trainer.MODEL_FILENAME, "yoloe-26x-seg.pt")
        self.assertEqual(trainer.DEFAULT_IMGSZ, 1280)
        self.assertEqual(
            trainer.CLASS_NAMES,
            [
                "kraft paper bowl",
                "black soya sauce cup",
                "red teriyaki sauce cup",
                "white wayo dip cup",
                "orange chili mayo cup",
                "wooden chopstick tip",
                "black and white soya sauce packet",
            ],
        )
        args = trainer.build_parser().parse_args([])
        self.assertFalse(args.train)
        self.assertEqual(args.imgsz, 1280)
        self.assertEqual(args.val_fraction, 0.20)
        self.assertEqual(args.test_fraction, 0.20)

    def test_validation_accepts_twenty_human_approved_polygon_label_files(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))

            validation = trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

            self.assertEqual(validation.approved_image_count, 20)
            self.assertEqual(validation.label_file_count, 20)
            self.assertEqual(validation.polygon_count, 20)
            self.assertEqual(len(validation.samples), 20)
            self.assertEqual(sum(validation.class_polygon_counts.values()), 20)

    def test_validation_rejects_detection_boxes_instead_of_polygon_masks(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))
            (dataset_root / "labels" / "approved-00.txt").write_text(
                "0 0.50 0.50 0.40 0.40\n",
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "polygon.*at least three"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_validation_rejects_a_corrupt_approved_image_before_kaggle_training(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))
            (dataset_root / "images" / "approved-00.jpg").write_bytes(b"not-an-image")

            with self.assertRaisesRegex(ValueError, "not a readable image"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_validation_rejects_two_approved_images_that_share_one_label_stem(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))
            Image.new("RGB", (80, 60), color=(30, 60, 90)).save(dataset_root / "images" / "approved-00.png")
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            manifest["approved_image_names"].append("approved-00.png")
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "unique filename stems"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_validation_rejects_unapproved_labels_in_the_gold_directory(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))
            (dataset_root / "labels" / "not-approved.txt").write_text(
                "0 0.10 0.10 0.80 0.10 0.80 0.80\n",
                encoding="utf-8",
            )

            with self.assertRaisesRegex(ValueError, "unapproved polygon label"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_validation_rejects_fewer_than_twenty_approved_label_files(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir), image_count=19)

            with self.assertRaisesRegex(ValueError, "at least 20"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_validation_rejects_pseudo_or_unreviewed_labels(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            dataset_root, manifest_path = self.write_approved_dataset(Path(temp_dir))
            manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
            manifest["pseudo_labels_accepted"] = True
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")

            with self.assertRaisesRegex(ValueError, "pseudo_labels_accepted must be false"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

            manifest["pseudo_labels_accepted"] = False
            manifest["human_reviewed"] = False
            manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "human_reviewed must be true"):
                trainer.validate_gold_standard_dataset(dataset_root, manifest_path)

    def test_split_keeps_same_capture_site_out_of_other_splits(self) -> None:
        samples = tuple(
            trainer.ApprovedSample(
                image_name=image_name,
                image_path=Path(image_name),
                label_path=Path(image_name).with_suffix(".txt"),
                polygon_count=1,
                class_polygon_counts={name: 1 for name in trainer.CLASS_NAMES},
            )
            for image_name in (
                "same-site-2026-05-01-one.jpg",
                "same-site-2026-05-02-two.jpg",
                *[f"independent-{index:02d}-2026-05-01-token.jpg" for index in range(18)],
            )
        )

        splits = trainer.split_samples(samples, val_fraction=0.20, test_fraction=0.20)
        split_for_same_site = {
            split
            for split, split_samples in splits.items()
            if any(sample.image_name.startswith("same-site-") for sample in split_samples)
        }

        self.assertEqual(split_for_same_site, {next(iter(split_for_same_site))})
        self.assertEqual(sum(len(split_samples) for split_samples in splits.values()), 20)
        self.assertTrue(all(splits[split] for split in ("train", "val", "test")))
        scene_sets = {
            split: {trainer.scene_key(sample.image_name) for sample in split_samples}
            for split, split_samples in splits.items()
        }
        self.assertFalse(scene_sets["train"] & scene_sets["val"])
        self.assertFalse(scene_sets["train"] & scene_sets["test"])
        self.assertFalse(scene_sets["val"] & scene_sets["test"])

    def test_training_stages_only_approved_polygons_and_writes_best_pt_metadata(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            dataset_root, manifest_path = self.write_approved_dataset(root / "approved")
            output_root = root / "output"
            fake_model = SimpleNamespace(task="segment")
            train_calls: list[dict[str, object]] = []

            def fake_train(**kwargs: object) -> object:
                train_calls.append(kwargs)
                run_dir = Path(str(kwargs["project"])) / str(kwargs["name"])
                best_path = run_dir / "weights" / "best.pt"
                best_path.parent.mkdir(parents=True)
                best_path.write_bytes(b"gold-standard-26x-seg")
                fake_model.trainer = SimpleNamespace(best=best_path, save_dir=run_dir)
                return SimpleNamespace()

            fake_model.train = fake_train
            loaded_models: list[str] = []

            def fake_model_factory(model_source: str) -> object:
                loaded_models.append(model_source)
                return fake_model

            config = trainer.TrainingConfig(
                dataset_root=dataset_root,
                approval_manifest=manifest_path,
                output_root=output_root,
                epochs=2,
                batch=1,
            )
            with (
                patch.object(trainer, "running_inside_kaggle", return_value=True),
                patch.object(trainer, "require_cuda"),
            ):
                result = trainer.train_gold_standard_seg(config, model_factory=fake_model_factory)

            self.assertEqual(loaded_models, ["yoloe-26x-seg.pt"])
            self.assertEqual(len(train_calls), 1)
            self.assertEqual(train_calls[0]["imgsz"], 1280)
            self.assertEqual(train_calls[0]["epochs"], 2)
            self.assertEqual(Path(str(train_calls[0]["data"])).name, "data.yaml")
            self.assertEqual(train_calls[0]["trainer"].__name__, "YOLOEPESegTrainer")
            self.assertTrue((output_root / "artifacts" / "best.pt").is_file())
            dataset_yaml = (
                output_root / "staged_gold_standard_dataset" / "data.yaml"
            ).read_text(encoding="utf-8")
            self.assertIn("test: images/test", dataset_yaml)
            staging_manifest = json.loads(
                (
                    output_root
                    / "staged_gold_standard_dataset"
                    / "staging_manifest.json"
                ).read_text(encoding="utf-8")
            )
            self.assertEqual(sum(staging_manifest["split_image_counts"].values()), 20)
            self.assertGreater(staging_manifest["split_image_counts"]["test"], 0)
            self.assertIn("class_polygon_counts_by_split", staging_manifest)
            metadata = json.loads((output_root / "artifacts" / "training_metadata.json").read_text(encoding="utf-8"))
            self.assertEqual(metadata["model_source"], "yoloe-26x-seg.pt")
            self.assertEqual(metadata["task"], "instance_segmentation")
            self.assertEqual(metadata["imgsz"], 1280)
            self.assertEqual(metadata["test_fraction"], 0.20)
            self.assertEqual(metadata["approved_image_count"], 20)
            self.assertEqual(metadata["label_provenance"], "human_approved_polygon_labels_only")
            self.assertFalse(metadata["pseudo_labels_accepted"])
            self.assertEqual(Path(result["best_pt"]), output_root / "artifacts" / "best.pt")

    def test_training_refuses_to_run_outside_kaggle(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            dataset_root, manifest_path = self.write_approved_dataset(root / "approved")
            config = trainer.TrainingConfig(
                dataset_root=dataset_root,
                approval_manifest=manifest_path,
                output_root=root / "output",
            )

            with patch.object(trainer, "running_inside_kaggle", return_value=False):
                with self.assertRaisesRegex(RuntimeError, "Kaggle"):
                    trainer.train_gold_standard_seg(config, model_factory=lambda _: self.fail("model loaded locally"))


if __name__ == "__main__":
    unittest.main()
