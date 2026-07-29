from __future__ import annotations

"""Package the fixed 20-image, 6-reference assisted review for Kaggle."""

import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import shutil
from typing import Any
import zipfile


RUNTIME_PATH = Path(__file__).with_name("assisted_label_review.py")
NOTEBOOK_FILENAME = "assisted_label_review.ipynb"
INPUT_ARCHIVE_FILENAME = "assisted_label_inputs.bundle"
DEFAULT_DATASET_ID = "mib348/sushi-yoloe26x-assisted-label-inputs"
DEFAULT_DATASET_TITLE = "Sushi YOLOE26X Assisted Label Inputs"
SAM3_REPO_ID = "facebook/sam3.1"
SAM3_FILENAME = "sam3.1_multiplex.pt"
SAM3_REVISION = "daa63191845a41281374e725f4c9e51c7a824460"
SAM3_EXPECTED_SIZE = 3_502_755_717
# Kaggle's model-input mount is the primary checkpoint source.  It is a
# versioned, account-visible artifact, so the review run does not depend on a
# notebook secret being attached to a newly imported kernel.  The exact
# framework/variation/version suffix is required by Kaggle's kernel metadata
# contract and is intentionally pinned alongside the official HF revision.
SAM3_KAGGLE_MODEL_SOURCE = "safebet1034/sam3-1/pytorch/default/1"
# --- Colab target ----------------------------------------------------------
# Kaggle's GPU quota is weekly; Colab's free T4 is the fallback compute.  The
# notebook body is NOT rewritten for Colab.  Instead one preamble cell makes a
# Colab VM look like a Kaggle kernel, so a Colab result stays directly
# comparable with the Kaggle runs it has to be compared against.
#
# Colab differs from Kaggle in exactly four ways that matter here:
#   1. /kaggle/input and /kaggle/working do not exist  -> created (Colab is uid 0)
#   2. dataset and model inputs are not mounted        -> pulled with kagglehub
#   3. the `kaggle_secrets` module does not exist      -> shimmed onto Colab userdata
#   4. the base image ships NumPy 2.x                  -> pinned back below
#
# The pinned official SAM 3.1 dependency contract requires NumPy <2, and the
# notebook's own preflight refuses to continue otherwise.  Kaggle's image
# already satisfies this, so this pin only ever takes effect on Colab.  1.26.4
# is the last 1.x release and was verified on Colab to import cleanly alongside
# the preinstalled CUDA torch/torchvision.
COLAB_NUMPY_PIN = "numpy==1.26.4"
# Colab's session-storage upload lands here.  kagglehub reads the token from
# ~/.kaggle/access_token, so the preamble moves it there rather than asking for
# the secret VALUE to be pasted into a cell.
COLAB_UPLOADED_TOKEN_PATH = "/content/access_token"
COLAB_TOKEN_DESTINATION = "/root/.kaggle/access_token"
# Free Colab reclaims a session after a couple of hours and the VM disk dies
# with it.  Re-fetching 3.5 GB of SAM 3.1 plus a 171 MB checkpoint every time
# costs more than some shards do, so the big read-only inputs are cached on
# Google Drive, which outlives the runtime.  Drive is mounted read-write
# because populating the cache is the entire point.
COLAB_DRIVE_MOUNT = "/content/drive"
COLAB_DRIVE_ROOT = "/content/drive/MyDrive"
SAM31_SEMANTIC_THRESHOLDS = [
    0.45,
    0.45,
    0.45,
    0.45,
    0.45,
    0.40,  # wooden chopstick tip — must not escape dense holders
    0.45,
]
SAM31_RESCUE_PROMPTS = [
    "brown kraft paper food container with a label",
    "black lidded sauce cup",
    "red lidded sauce cup",
    "white lidded sauce cup",
    "orange lidded sauce cup",
    "visible end of a wooden chopstick",
    "black and white soy sauce sachet",
]
SAM31_RESCUE_THRESHOLDS = [
    0.30,
    0.35,
    0.35,
    0.35,
    0.35,
    0.30,  # wooden chopstick tip
    0.35,
]
SAM31_RESCUE_TRIGGER_MAX_PRIMARY_INSTANCES = 2
SAM31_RESCUE_MAX_RAW_INSTANCES = 128
SAM31_RESCUE_MAX_POST_NMS_INSTANCES = 128
CORRECTION_GUIDED_PROMPTS = [
    "stacked brown kraft paper takeaway bowl with a printed dish name sticker on its front",
    "small round clear lidded sauce cup filled with nearly black soy sauce",
    "small round clear lidded sauce cup filled with bright red teriyaki sauce",
    "small round clear lidded sauce cup filled with white creamy wayo dip",
    "small round clear lidded sauce cup filled with orange chili mayonnaise",
    "individual visible wooden chopstick tip or exposed wooden chopstick end",
    "flat black and white printed soy sauce sachet packet",
]
CORRECTION_GUIDED_THRESHOLDS = [0.30, 0.30, 0.30, 0.30, 0.30, 0.28, 0.30]
# V42 adds a bounded, correction-target-only tiled retry for classes that the
# full-image correction prompt could not recover.  These values are part of
# the bundle contract so a Kaggle notebook cannot silently drift from the
# reviewed runtime.  The human counts remain audit metadata only; they are
# never used to create geometry.
CORRECTION_GUIDED_TILED_SOURCE = "sam31_correction_guided_text_prompt_tiled"
CORRECTION_GUIDED_TILED_TRIGGER_POLICY = (
    "required_positive_class_with_zero_final_union_candidates_only"
)
CORRECTION_GUIDED_TILED_THRESHOLDS = [0.25, 0.25, 0.25, 0.25, 0.25, 0.15, 0.25]
CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES = SAM31_RESCUE_MAX_RAW_INSTANCES
CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES = SAM31_RESCUE_MAX_POST_NMS_INSTANCES
# V44 keeps the V43 tightly cropped human-audited visual-exemplar lane, but
# fixes its diagnosed tiny-tip reference choice.  It triggers only when a
# human-required, non-advisory class is absent from the primary visual union,
# then keeps only boxes supported by two independent reference images.
AUDITED_VISUAL_RECOVERY_SOURCE = "yoloe26x_audited_exemplar_recovery_tiled"
AUDITED_VISUAL_RECOVERY_CLASS_IDS = [1, 5]
AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY = (
    "positive_non_advisory_class_absent_from_primary_visual_union"
)
AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT = 3
AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT = 2
AUDITED_VISUAL_RECOVERY_CONFIDENCE = 0.05
AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES = 512
AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES = 128
# This policy is duplicated into the saved notebook manifest so reviewers can
# prove that V44 used a fixed class-level small-object resolution policy rather
# than a target-specific count or geometry hint.
AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS = {
    5: {"tile_size": 960, "overlap": 0.35, "inference_imgsz": 1280},
}
FIXED_CLASS_NAMES = [
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
]
RAW_LABEL_QUARANTINE = {
    "Chopstick": "unsupported_aggregate_label_use_Chopstick_Tip_only",
}
IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png"}
EXPECTED_OUTPUT_FILES = {
    NOTEBOOK_FILENAME,
    "bundle_manifest.json",
    "kernel-metadata.json",
    "review_decision_manifest.json",
}
EXPECTED_DATASET_FILES = {INPUT_ARCHIVE_FILENAME, "dataset-metadata.json"}
FORBIDDEN_CODE_MARKERS = (".train(", "approved_for_training", "pseudo_labels_accepted")


class BundleConfig:
    def __init__(
        self,
        repo_root: Path,
        batch_root: Path,
        output_dir: Path,
        dataset_output_dir: Path,
        kernel_id: str,
        kernel_title: str,
        dataset_id: str,
        dataset_title: str,
        clean: bool,
        sam31_smoke_only: bool = False,
        correction_manifest: Path | None = None,
        text_prompt_primary: bool = False,
        visual_prompt_model: str | None = None,
        raw_proposal_dump: str | None = None,
        yoloe_text_checkpoint_glob: str | None = None,
        kernel_sources: list[str] | None = None,
        colab: bool = False,
        colab_checkpoint_source: str | None = None,
        image_shard: str | None = None,
        colab_drive_cache: str | None = None,
        text_prompts: list[str] | None = None,
        proposal_confidence: float | None = None,
    ) -> None:
        self.repo_root = Path(repo_root).resolve()
        self.batch_root = Path(batch_root).resolve()
        self.output_dir = Path(output_dir).resolve()
        self.dataset_output_dir = Path(dataset_output_dir).resolve()
        self.kernel_id = kernel_id
        self.kernel_title = kernel_title
        self.dataset_id = dataset_id
        self.dataset_title = dataset_title
        self.clean = bool(clean)
        self.sam31_smoke_only = bool(sam31_smoke_only)
        self.text_prompt_primary = bool(text_prompt_primary)
        self.visual_prompt_model = visual_prompt_model or None
        self.raw_proposal_dump = raw_proposal_dump or None
        self.yoloe_text_checkpoint_glob = yoloe_text_checkpoint_glob or None
        self.kernel_sources = list(kernel_sources or [])
        self.colab = bool(colab)
        self.colab_checkpoint_source = colab_checkpoint_source or None
        self.colab_drive_cache = colab_drive_cache or None
        self.proposal_confidence = proposal_confidence
        if self.proposal_confidence is not None and not 0.0 < self.proposal_confidence <= 1.0:
            raise ValueError(
                "--proposal-confidence must be within (0, 1]; got "
                f"{self.proposal_confidence}."
            )
        self.text_prompts = list(text_prompts or [])
        # Validate at BUILD time. A wrong-length prompt bank found on the GPU
        # costs a runtime; found here it costs nothing. The runtime checks it
        # again, because a hand-run of the runtime must be safe too.
        if self.text_prompts and len(self.text_prompts) != len(FIXED_CLASS_NAMES):
            raise ValueError(
                f"--text-prompt must be repeated exactly {len(FIXED_CLASS_NAMES)} "
                f"times in fixed class order; got {len(self.text_prompts)}."
            )
        if self.text_prompts and len(set(self.text_prompts)) != len(self.text_prompts):
            raise ValueError(
                "--text-prompt values must be distinct; a repeat would collapse "
                "two classes onto one embedding."
            )
        self.image_shard = image_shard or None
        # Validate the shard shape here so a typo fails at build time rather
        # than after a GPU runtime has already been allocated.  The runtime
        # performs the authoritative range check against the real image list.
        if self.image_shard:
            parts = str(self.image_shard).split("/")
            if len(parts) != 2 or not all(p.strip().isdigit() for p in parts):
                raise ValueError(
                    f"--image-shard expects K/N, for example 1/7, not {self.image_shard!r}."
                )
            shard_index, shard_count = (int(p) for p in parts)
            if shard_count < 1 or not 1 <= shard_index <= shard_count:
                raise ValueError(
                    f"--image-shard {self.image_shard} is out of range; "
                    "the index must be between 1 and the count."
                )
        # Validate the checkpoint reference at BUILD time.  A malformed source
        # discovered on Colab costs a runtime allocation; discovered here it
        # costs nothing.
        if self.colab_checkpoint_source:
            parse_colab_checkpoint_source(self.colab_checkpoint_source)
        if self.colab_checkpoint_source and not self.colab:
            raise ValueError(
                "--colab-checkpoint-source only applies to a Colab bundle; "
                "pass --colab as well, or drop it."
            )
        self.correction_manifest = (
            Path(correction_manifest).resolve()
            if correction_manifest is not None
            else None
        )


def resolve_default_correction_manifest(repo_root: Path) -> Path:
    """Return the newest safe correction manifest for assisted-label packaging.

    The bundle must never silently fall back to an older review snapshot when a
    newer local review export already exists.  That is exactly how stale
    advisory flags leaked into the previous V49 Kaggle bundle.  The rule here
    is intentionally simple and fail-closed:

    1. Look for the newest results directory that has ``review_decisions.local.json``.
    2. Require its sibling ``review_corrections_current/review_correction_manifest.json``
       to exist and to be at least as new as the decisions export.
    3. Only if no review export exists at all, fall back to the newest already
       materialized correction manifest anywhere under the results root.
    """

    results_root = repo_root / "training" / "autoresearch" / "results"
    legacy_fallback = (
        results_root
        / "yoloe26x_sam31_assisted_review_kaggle_v39_20260724"
        / "review_corrections_current"
        / "review_correction_manifest.json"
    )
    if not results_root.is_dir():
        return legacy_fallback

    latest_review_run: tuple[float, Path, Path, Path] | None = None
    fallback_candidates: list[tuple[float, Path]] = []
    preferred_manifest_locations = (
        Path("review_corrections_current") / "review_correction_manifest.json",
        Path("review_corrections") / "review_correction_manifest.json",
        Path("review_corrections_final") / "review_correction_manifest.json",
        Path("review_correction_manifest.json"),
    )

    for child in results_root.iterdir():
        if not child.is_dir():
            continue
        decisions_path = child / "review_decisions.local.json"
        current_manifest_path = (
            child / "review_corrections_current" / "review_correction_manifest.json"
        )
        if decisions_path.is_file():
            decision_mtime = decisions_path.stat().st_mtime
            candidate = (
                decision_mtime,
                child,
                decisions_path,
                current_manifest_path,
            )
            if latest_review_run is None or decision_mtime > latest_review_run[0]:
                latest_review_run = candidate
        for relative_path in preferred_manifest_locations:
            manifest_path = child / relative_path
            if manifest_path.is_file():
                fallback_candidates.append((manifest_path.stat().st_mtime, manifest_path))
                break

    if latest_review_run is not None:
        _, run_dir, decisions_path, current_manifest_path = latest_review_run
        if not current_manifest_path.is_file():
            raise FileNotFoundError(
                "Latest review export requires a fresh correction manifest before packaging: "
                f"{run_dir.name} has {decisions_path.name} but is missing "
                f"{current_manifest_path.relative_to(run_dir)}."
            )
        if current_manifest_path.stat().st_mtime < decisions_path.stat().st_mtime:
            raise FileNotFoundError(
                "Latest review export is newer than its current correction manifest. "
                "Rebuild the correction manifest before packaging: "
                f"{current_manifest_path} < {decisions_path}"
            )
        return current_manifest_path

    if fallback_candidates:
        fallback_candidates.sort(key=lambda item: item[0], reverse=True)
        return fallback_candidates[0][1]
    return legacy_fallback


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, indent=2, sort_keys=True, allow_nan=False) + "\n",
        encoding="utf-8",
    )


def validate_owner_slug(value: str, resource_name: str) -> None:
    if not re.fullmatch(r"[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*", value):
        raise ValueError(f"Kaggle {resource_name} id must be an owner/slug value.")


def prepare_output_dir(config: BundleConfig) -> None:
    protected_roots = {config.repo_root, config.batch_root}
    if config.output_dir in protected_roots or config.dataset_output_dir in protected_roots:
        raise ValueError("Refusing to replace a repo or batch root.")
    if config.output_dir == config.dataset_output_dir:
        raise ValueError("Kernel and dataset upload directories must be separate.")
    if (
        config.output_dir in config.dataset_output_dir.parents
        or config.dataset_output_dir in config.output_dir.parents
    ):
        raise ValueError("Kernel and dataset upload directories must not contain one another.")
    for directory in (config.output_dir, config.dataset_output_dir):
        if directory.exists() and any(directory.iterdir()):
            if not config.clean:
                raise FileExistsError(f"Bundle directory is not empty: {directory}")
            shutil.rmtree(directory)
        directory.mkdir(parents=True, exist_ok=True)


def batch_files(batch_root: Path) -> tuple[list[Path], list[Path]]:
    images_dir = batch_root / "images"
    if not images_dir.is_dir():
        raise FileNotFoundError(f"Fixed image directory was not found: {images_dir}")
    images = sorted(
        path for path in images_dir.iterdir()
        if path.is_file() and path.suffix.lower() in IMAGE_SUFFIXES
    )
    annotations = sorted(path for path in images_dir.glob("*.json") if path.is_file())
    if len(images) != 20:
        raise ValueError(f"Expected exactly 20 frozen images; found {len(images)}.")
    if len(annotations) != 6:
        raise ValueError(f"Expected exactly 6 human reference annotations; found {len(annotations)}.")
    image_stems = {path.stem.casefold() for path in images}
    if any(path.stem.casefold() not in image_stems for path in annotations):
        raise ValueError("A reference annotation has no matching frozen image.")
    return images, annotations


def verify_known_backup(batch_root: Path, annotations: list[Path]) -> None:
    backup_manifests = sorted((batch_root / "annotation_backups").glob("**/backup_manifest.json"))
    if not backup_manifests:
        return
    expected_names = {path.name for path in annotations}
    matching_payload = None
    for path in reversed(backup_manifests):
        payload = json.loads(path.read_text(encoding="utf-8"))
        if {row["name"] for row in payload.get("files", [])} == expected_names:
            matching_payload = payload
            break
    if matching_payload is None:
        raise ValueError("No annotation backup manifest matches the current six reference files.")
    expected_hashes = {
        row["name"]: str(row["sha256"]).lower()
        for row in matching_payload["files"]
    }
    for annotation in annotations:
        if sha256_file(annotation).lower() != expected_hashes[annotation.name]:
            raise ValueError(f"Reference annotation differs from its immutable backup: {annotation.name}")


def make_input_manifest(images: list[Path], annotations: list[Path]) -> dict[str, Any]:
    reference_stems = {path.stem.casefold() for path in annotations}
    reference_images = [path.name for path in images if path.stem.casefold() in reference_stems]
    target_images = [path.name for path in images if path.stem.casefold() not in reference_stems]
    file_hashes = {
        f"images/{path.name}": sha256_file(path)
        for path in images
    }
    file_hashes.update(
        {
            f"reference_annotations/{path.name}": sha256_file(path)
            for path in annotations
        }
    )
    return {
        "schema_version": 1,
        # The input archive is deliberately frozen at the already-attached
        # V31 dataset contract.  Bounded rescue is a runtime policy, not a
        # change to the 20 images or six audited annotations, so keeping this
        # manifest workflow stable lets the new runtime reuse that verified
        # private Kaggle input without publishing a duplicate dataset.
        "workflow": "yoloe26x_visual_prompt_tiled_plus_sam31_semantic_discovery_review_only",
        "fixed_image_count": 20,
        "reference_count": 6,
        "target_count": 14,
        "image_names": [path.name for path in images],
        "reference_image_names": reference_images,
        "target_image_names": target_images,
        "reference_annotation_names": [path.name for path in annotations],
        "reference_annotation_sha256": {
            path.name: sha256_file(path) for path in annotations
        },
        "file_sha256": file_hashes,
        "class_names": FIXED_CLASS_NAMES,
        "raw_label_quarantine": RAW_LABEL_QUARANTINE,
        "output_policy": "quarantined_proposals_and_contact_sheets_only",
        "training_authorized": False,
        "promotion_authorized": False,
    }


def write_input_archive(path: Path, images: list[Path], annotations: list[Path], manifest: dict[str, Any]) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=6) as archive:
        for image in images:
            write_deterministic_zip_member(archive, image, f"images/{image.name}")
        for annotation in annotations:
            write_deterministic_zip_member(
                archive, annotation, f"reference_annotations/{annotation.name}"
            )
        write_deterministic_zip_bytes(
            archive,
            "input_manifest.json",
            (json.dumps(manifest, indent=2, sort_keys=True, allow_nan=False) + "\n").encode(
                "utf-8"
            ),
        )


def write_deterministic_zip_bytes(archive: zipfile.ZipFile, name: str, content: bytes) -> None:
    """Write stable ZIP metadata so unchanged inputs produce an identical archive."""
    info = zipfile.ZipInfo(filename=name, date_time=(1980, 1, 1, 0, 0, 0))
    info.compress_type = zipfile.ZIP_DEFLATED
    info.create_system = 3
    info.external_attr = 0o100644 << 16
    archive.writestr(info, content, compress_type=zipfile.ZIP_DEFLATED, compresslevel=6)


def write_deterministic_zip_member(archive: zipfile.ZipFile, path: Path, name: str) -> None:
    write_deterministic_zip_bytes(archive, name, path.read_bytes())


def notebook_cell(cell_type: str, source: str) -> dict[str, Any]:
    cell = {"cell_type": cell_type, "metadata": {}, "source": source.strip("\n").splitlines(keepends=True)}
    if cell_type == "code":
        cell.update({"execution_count": None, "outputs": []})
    return cell


def parse_colab_checkpoint_source(value: str) -> tuple[str, str, str]:
    """Split ``owner/kernel:path/inside/output`` into its three usable parts.

    The bootstrap checkpoint lives in a Kaggle *kernel output*, which Colab
    cannot mount.  It can still be downloaded, but only at its true path.  The
    flat file listing returned by Kaggle's API hides directory prefixes, which
    is why ``artifacts/best.pt`` looks right and returns 404 while the real
    ``yoloe26x_bootstrap/artifacts/best.pt`` returns the file.  Making the
    caller state the full path keeps that trap out of the code.

    Returns ``(owner_slug, kernel_slug, output_path)``.
    """
    if ":" not in value:
        raise ValueError(
            "A Colab checkpoint source must be 'owner/kernel:path/in/output', "
            "e.g. 'mib348/v53-bootstrap-train:yoloe26x_bootstrap/artifacts/best.pt'. "
            f"Got: {value!r}"
        )
    kernel_ref, output_path = value.split(":", 1)
    kernel_ref = kernel_ref.strip("/")
    output_path = output_path.strip("/")
    if kernel_ref.count("/") != 1 or not all(kernel_ref.split("/")):
        raise ValueError(f"Expected 'owner/kernel' before the colon; got {kernel_ref!r}")
    if not output_path:
        raise ValueError("The path inside the kernel output must not be empty.")
    owner_slug, kernel_slug = kernel_ref.split("/")
    return owner_slug, kernel_slug, output_path


def colab_preamble_cell(
    dataset_id: str,
    colab_checkpoint_source: str | None = None,
    colab_drive_cache: str | None = None,
) -> dict[str, Any]:
    """Build the one cell that turns a Colab VM into a Kaggle-shaped kernel.

    Every cell AFTER this one is byte-identical to the Kaggle notebook.  That
    is the whole point: the comparison against V55/V56 stays honest because the
    pipeline did not change, only the machine under it.
    """
    # Without a configured cache the Drive block is omitted entirely rather
    # than emitted as dead `if None:` code, so the generated notebook only
    # ever contains machinery it will actually use.
    drive_block = "_drive_cache = None\n"
    if colab_drive_cache:
        drive_block = f"""
# Drive-backed cache for the two big read-only inputs.  A reclaimed session
# destroys the VM disk, and re-fetching 3.5 GB of SAM 3.1 plus the checkpoint
# can cost more than a shard does.  Drive survives the runtime, so a fresh
# session becomes a copy instead of a download.  Entirely optional: if Drive
# will not mount, the run continues and simply pays the download.
_drive_cache = None
try:
    from google.colab import drive as _colab_drive

    if not pathlib.Path({COLAB_DRIVE_ROOT!r}).is_dir():
        # ALWAYS bound the mount.  Drive access needs an interactive consent,
        # and if that consent is declined or simply never given, an unbounded
        # mount() blocks the cell forever - observed hanging a run for 12+
        # minutes with no output.  The cache is an optimisation, so a timeout
        # that falls through to downloading is strictly better than a hang.
        _colab_drive.mount({COLAB_DRIVE_MOUNT!r}, timeout_ms=90000)
    _drive_cache = pathlib.Path({COLAB_DRIVE_ROOT!r}) / {colab_drive_cache!r}
    _drive_cache.mkdir(parents=True, exist_ok=True)
    print("Drive cache:", _drive_cache)
except Exception as exc:
    print("Drive cache unavailable, falling back to downloads:", exc)
    _drive_cache = None
"""
    checkpoint_block = """
# (6) No bootstrap checkpoint was requested, so the text lane runs on whatever
#     the notebook's own glob resolves to.
print("No Colab checkpoint source configured.")
"""
    if colab_checkpoint_source:
        owner_slug, kernel_slug, output_path = parse_colab_checkpoint_source(
            colab_checkpoint_source
        )
        # Mirror the file under a directory whose tail matches the notebook's
        # existing glob, so the SAME --text-prompt-checkpoint-glob value works
        # unchanged on both platforms.
        checkpoint_block = f"""
# (6) The bootstrap checkpoint lives in a Kaggle kernel OUTPUT, which Colab
#     cannot mount.  Download it to the path the notebook's glob expects.
#
#     Two measured hazards make this more than a one-line fetch:
#       * The prefix is load-bearing.  Kaggle's flat file listing hides
#         directory prefixes, so a shorter-looking path returns 404.
#       * This endpoint serves at only ~1.3 MB/s and CLOSES THE CONNECTION
#         EARLY.  A plain read() therefore returns a short file with no
#         exception - a silently truncated checkpoint, which is worse than a
#         failure.  So: ask for the total up front, resume with Range on every
#         reconnect, and refuse to continue unless the final size matches.
_ckpt_dest = COLAB_INPUT_ROOT / {kernel_slug!r} / {str(Path(output_path).parent).replace(chr(92), "/")!r}
_ckpt_dest.mkdir(parents=True, exist_ok=True)
_ckpt_file = _ckpt_dest / {Path(output_path).name!r}
_ckpt_url = (
    "https://www.kaggle.com/api/v1/kernels/output/download/"
    + {owner_slug!r} + "/" + {kernel_slug!r} + "/" + {output_path!r}
)
_auth = {{"Authorization": "Bearer " + _kaggle_token}}


def _checkpoint_total_bytes():
    # Total size the server reports, so truncation is detectable.
    try:
        with urllib.request.urlopen(
            urllib.request.Request(_ckpt_url, headers=_auth), timeout=120
        ) as probe:
            length = probe.headers.get("Content-Length")
            return int(length) if length else None
    except urllib.error.HTTPError as exc:
        raise RuntimeError(
            "Could not reach the bootstrap checkpoint at "
            + _ckpt_url
            + " (HTTP " + str(exc.code) + "). Kaggle's flat listing hides "
            "directory prefixes, so verify the FULL path with "
            "GET /api/v1/kernels/output?userName=...&kernelSlug=..."
        ) from exc


_expected_bytes = _checkpoint_total_bytes()
if _expected_bytes is None:
    raise RuntimeError(
        "Kaggle did not report a Content-Length for the bootstrap checkpoint, "
        "so a truncated download could not be detected. Refusing to continue."
    )

# A cached checkpoint skips the slow endpoint entirely.  Size must match what
# Kaggle just reported, so a truncated cache entry is rejected, not trusted.
if not _ckpt_file.is_file() and _drive_cache is not None:
    _cached_ckpt = _drive_cache / {Path(output_path).name!r}
    if _cached_ckpt.is_file() and _cached_ckpt.stat().st_size == _expected_bytes:
        shutil.copy(_cached_ckpt, _ckpt_file)
        print("Adopted cached checkpoint from Drive:", _cached_ckpt)

# Escape hatch for a slow endpoint.  Measured at ~1.3 MB/s, this one file can
# cost 20+ minutes of a preemptible free runtime - longer than the shard it is
# meant to serve.  So if a copy has been uploaded to Colab session storage,
# adopt it instead, but ONLY when its size matches what Kaggle reports, so a
# hand-placed file can never quietly substitute for the real checkpoint.
_seeded_ckpt = pathlib.Path("/content") / {Path(output_path).name!r}
if not _ckpt_file.is_file() and _seeded_ckpt.is_file():
    if _seeded_ckpt.stat().st_size == _expected_bytes:
        shutil.copy(_seeded_ckpt, _ckpt_file)
        print("Adopted pre-staged checkpoint from", _seeded_ckpt)
    else:
        print(
            "Ignoring pre-staged", _seeded_ckpt, "- it is",
            _seeded_ckpt.stat().st_size, "bytes, expected", _expected_bytes,
        )

for _attempt in range(1, 13):
    _have = _ckpt_file.stat().st_size if _ckpt_file.is_file() else 0
    if _have >= _expected_bytes:
        break
    _headers = dict(_auth)
    _headers["Range"] = "bytes=" + str(_have) + "-"
    try:
        with urllib.request.urlopen(
            urllib.request.Request(_ckpt_url, headers=_headers), timeout=120
        ) as _resp:
            _resuming = _resp.status == 206
            if not _resuming:
                _have = 0
            with open(_ckpt_file, "ab" if _resuming else "wb") as _fh:
                while True:
                    _chunk = _resp.read(1 << 20)
                    if not _chunk:
                        break
                    _fh.write(_chunk)
                    _have += len(_chunk)
    except Exception as exc:
        print("checkpoint attempt", _attempt, "interrupted:", type(exc).__name__, exc)
    print("checkpoint", _ckpt_file.stat().st_size, "/", _expected_bytes, "bytes")

_final_bytes = _ckpt_file.stat().st_size if _ckpt_file.is_file() else 0
if _final_bytes != _expected_bytes:
    raise RuntimeError(
        "The bootstrap checkpoint is incomplete: got "
        + str(_final_bytes) + " of " + str(_expected_bytes) + " bytes. "
        "Running on a truncated checkpoint would silently answer a different "
        "question, so this run stops here."
    )
if _drive_cache is not None:
    _cache_target = _drive_cache / {Path(output_path).name!r}
    if not _cache_target.is_file() or _cache_target.stat().st_size != _expected_bytes:
        try:
            shutil.copy(_ckpt_file, _cache_target)
            print("Cached checkpoint on Drive for the next session.")
        except Exception as exc:
            print("Could not cache the checkpoint:", exc)
print("Bootstrap checkpoint ready:", _ckpt_file, _final_bytes, "bytes")
"""

    source = f"""
# ===========================================================================
# COLAB PREAMBLE - makes this VM look like a Kaggle kernel.
#
# Read this once and the rest of the notebook needs no Colab-specific reading:
# every cell below is EXACTLY the cell that runs on Kaggle.  Only the machine
# changes, never the pipeline, so a Colab result stays comparable with the
# Kaggle runs it is measured against.
#
# Prerequisite (one manual step): upload your Kaggle API token to Colab's
# session storage via the Files pane, so it appears at
# {COLAB_UPLOADED_TOKEN_PATH}.  Nothing else needs configuring.
# ===========================================================================
import os
import pathlib
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

# (1) Fail before spending ten minutes downloading 3.5 GB onto a CPU runtime.
#     On a CPU runtime nvidia-smi is not merely unhappy, it is ABSENT, so this
#     has to survive FileNotFoundError as well as a non-zero exit - otherwise
#     the clear "pick a T4" message is replaced by a bare FileNotFoundError.
try:
    _gpu_probe = subprocess.run(["nvidia-smi"], capture_output=True).returncode
except FileNotFoundError:
    _gpu_probe = 127
if _gpu_probe != 0:
    raise RuntimeError(
        "No GPU is attached. Choose Runtime > Change runtime type > T4 GPU, "
        "then run this notebook again."
    )

# (2) Kaggle's two well-known directories.
#
#     /kaggle/input is kagglehub's MOUNT ROOT on Colab, not a plain directory:
#     it arrives as a READ-ONLY (and noexec) bind mount, and kagglehub mounts
#     Kaggle *models* underneath it instead of downloading them.  /kaggle and
#     /kaggle/working are ordinary writable directories, so a write test there
#     passes and hides the problem; staging into /kaggle/input then fails with
#     "OSError: [Errno 30] Read-only file system".
#
#     Colab runs as uid 0, so the mount is simply removed here, which turns the
#     path back into a normal directory this notebook owns.  That is only safe
#     because DISABLE_COLAB_CACHE is set below - without it kagglehub would try
#     to mount into the path we just unmounted and hang indefinitely.
COLAB_INPUT_ROOT = pathlib.Path("/kaggle/input")
COLAB_WORKING_ROOT = pathlib.Path("/kaggle/working")
if os.path.ismount(str(COLAB_INPUT_ROOT)):
    subprocess.run(["umount", str(COLAB_INPUT_ROOT)], check=True)
    if os.path.ismount(str(COLAB_INPUT_ROOT)):
        raise RuntimeError(
            "/kaggle/input is still a read-only mount after umount; the inputs "
            "cannot be staged where the notebook expects them."
        )
for _directory in (COLAB_INPUT_ROOT, COLAB_WORKING_ROOT):
    _directory.mkdir(parents=True, exist_ok=True)
os.chdir(COLAB_WORKING_ROOT)

# (3) Restore the NumPy <2 contract the pinned SAM 3.1 build requires.  Colab
#     ships NumPy 2.x; the notebook's own preflight refuses to run against it.
#     Installing it FIRST means the later pins resolve against a 1.x baseline.
subprocess.run(
    [sys.executable, "-m", "pip", "install", "--quiet", {COLAB_NUMPY_PIN!r}],
    check=True,
)

# (4) `kaggle_secrets` is a Kaggle-only module, and a later cell imports it at
#     module level.  This shim satisfies that import by reading Colab's own
#     secret store, so the import works whether or not a secret is ever needed.
_shim = COLAB_WORKING_ROOT / "kaggle_secrets.py"
_shim.write_text(
    "class UserSecretsClient:\\n"
    "    def get_secret(self, name):\\n"
    "        from google.colab import userdata\\n"
    "        return userdata.get(name)\\n",
    encoding="utf-8",
)
if str(COLAB_WORKING_ROOT) not in sys.path:
    sys.path.insert(0, str(COLAB_WORKING_ROOT))

# (5) Authenticate, then stage the inputs Kaggle would have mounted.
#     kagglehub reads ~/.kaggle/access_token, so an uploaded token file is all
#     that is needed - the secret value never has to be pasted into a cell.
_uploaded_token = pathlib.Path({COLAB_UPLOADED_TOKEN_PATH!r})
_kaggle_token_path = pathlib.Path({COLAB_TOKEN_DESTINATION!r})
if _uploaded_token.is_file() and not _kaggle_token_path.is_file():
    _kaggle_token_path.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy(_uploaded_token, _kaggle_token_path)
    os.chmod(_kaggle_token_path, 0o600)
if not _kaggle_token_path.is_file():
    raise RuntimeError(
        "No Kaggle credentials. Upload your API token to Colab session storage "
        "so it lands at " + {COLAB_UPLOADED_TOKEN_PATH!r} + ", then re-run."
    )
_kaggle_token = _kaggle_token_path.read_text().strip()

# Make kagglehub DOWNLOAD rather than MOUNT.  Inside Colab, kagglehub mounts
# Kaggle models under /kaggle/input via a FUSE-style cache; step (2) removed
# that mount so the inputs could be staged, and a mount attempt into an
# unmounted path hangs forever (observed: 18+ minutes on "Mounting files to
# /kaggle/input/sam3-1/pytorch/default/1..." with no error).  Datasets are
# unaffected - they always download - which is why the bundle worked and the
# model did not.  Downloading is also simply faster here: 61 MB/s measured.
os.environ["DISABLE_COLAB_CACHE"] = "1"

import kagglehub

print("Authenticated with Kaggle as:", kagglehub.whoami())

{drive_block}

def _cached(name, expected_bytes, fetch):
    # Return a local path for `name`, preferring the Drive cache.
    #
    # A cache entry is only trusted when its size matches, so a partial copy
    # left behind by a reclaimed session can never be mistaken for the real
    # file.  Anything freshly fetched is written back for the next session.
    if _drive_cache is not None:
        hit = _drive_cache / name
        if hit.is_file() and (expected_bytes is None or hit.stat().st_size == expected_bytes):
            print("cache hit:", name, hit.stat().st_size, "bytes")
            return hit
    fetched = fetch()
    if _drive_cache is not None and fetched is not None and pathlib.Path(fetched).is_file():
        try:
            shutil.copy(fetched, _drive_cache / name)
            print("cached for next session:", name)
        except Exception as exc:
            print("could not populate cache for", name, "-", exc)
    return fetched

# The frozen 20-image input bundle.  The next cell verifies its SHA-256, so a
# stale dataset version fails loudly instead of quietly reviewing old images.
_dataset_dir = pathlib.Path(kagglehub.dataset_download({dataset_id!r}))
_staged_inputs = COLAB_INPUT_ROOT / {dataset_id.split("/")[-1]!r}
_staged_inputs.mkdir(parents=True, exist_ok=True)
for _bundle in _dataset_dir.rglob("*" + {INPUT_ARCHIVE_FILENAME!r}):
    _target = _staged_inputs / {INPUT_ARCHIVE_FILENAME!r}
    if not _target.exists():
        _target.symlink_to(_bundle)

# The pinned official SAM 3.1 checkpoint (3.5 GB).  The notebook accepts at
# most one copy under /kaggle/input, so link exactly the one file.
def _fetch_sam3():
    _dir = pathlib.Path(kagglehub.model_download({SAM3_KAGGLE_MODEL_SOURCE!r}))
    for _found in _dir.rglob({SAM3_FILENAME!r}):
        return _found
    return None


_sam3_source = _cached({SAM3_FILENAME!r}, {SAM3_EXPECTED_SIZE}, _fetch_sam3)
if _sam3_source is None:
    raise RuntimeError("The SAM 3.1 checkpoint could not be resolved.")
_staged_sam3 = COLAB_INPUT_ROOT / "sam3-1"
_staged_sam3.mkdir(parents=True, exist_ok=True)
_target = _staged_sam3 / {SAM3_FILENAME!r}
if not _target.exists():
    _target.symlink_to(_sam3_source)

# Guard the two counts the next cells assert on, so a duplicate is reported
# here with context rather than as a bare count mismatch further down.
_bundles = [p for p in COLAB_INPUT_ROOT.rglob("*.bundle") if p.is_file()]
if len(_bundles) != 1:
    raise RuntimeError(
        "Expected exactly one .bundle under /kaggle/input; found "
        + str(len(_bundles)) + ": " + str(_bundles)
    )
_sam3_copies = [p for p in COLAB_INPUT_ROOT.rglob({SAM3_FILENAME!r}) if p.is_file()]
if len(_sam3_copies) != 1:
    raise RuntimeError(
        "Expected exactly one SAM 3.1 checkpoint under /kaggle/input; found "
        + str(len(_sam3_copies))
    )
{checkpoint_block}
print("Colab preamble complete; the Kaggle notebook body follows unchanged.")
"""
    return notebook_cell("code", source)


def build_notebook(
    runtime_bytes: bytes,
    runtime_hash: str,
    input_archive_hash: str,
    correction_manifest_bytes: bytes,
    correction_manifest_hash: str,
    sam31_smoke_only: bool = False,
    run_mode_arguments: list[str] | None = None,
    yoloe_text_checkpoint_glob: str | None = None,
    colab: bool = False,
    colab_checkpoint_source: str | None = None,
    colab_drive_cache: str | None = None,
) -> dict[str, Any]:
    RUN_MODE_ARGUMENTS = list(run_mode_arguments or [])
    YOLOE_TEXT_CHECKPOINT_GLOB = yoloe_text_checkpoint_glob or ""
    encoded_runtime = base64.b64encode(runtime_bytes).decode("ascii")
    encoded_correction_manifest = base64.b64encode(
        correction_manifest_bytes
    ).decode("ascii")
    cells = [
        notebook_cell(
            "markdown",
            """
# YOLOE-26X assisted mask review

This Kaggle-only notebook uses six audited files containing rectangle prompts
for seven inventory classes, generates tiled visual-prompt proposals for the
remaining fourteen images, asks SAM 3.1 to discover every fixed text concept
on all twenty images, refines existing seeds, and emits contact sheets for
pass/reject.  Everything stays in quarantine; there is no training or
promotion path in this notebook.
""",
        ),
        notebook_cell(
            "code",
            f"""
from pathlib import Path
import hashlib

if not Path("/kaggle/working").is_dir() or not Path("/kaggle/input").is_dir():
    raise RuntimeError("This workflow must run on Kaggle.")

matches = sorted(
    path for path in Path("/kaggle/input").rglob("*.bundle")
    if path.is_file()
)
if len(matches) != 1:
    raise RuntimeError(f"Attach exactly one assisted .bundle file; found {{len(matches)}}.")
INPUT_ARCHIVE = matches[0]
if INPUT_ARCHIVE.name != "assisted_label_inputs.bundle":
    raise RuntimeError(
        "The assisted input bundle must be named assisted_label_inputs.bundle."
    )
EMBEDDED_BUNDLE_MANIFEST = {{"input_archive_sha256": {input_archive_hash!r}}}
EXPECTED_INPUT_ARCHIVE_SHA256 = EMBEDDED_BUNDLE_MANIFEST["input_archive_sha256"]
actual_input_archive_sha256 = hashlib.sha256(INPUT_ARCHIVE.read_bytes()).hexdigest()
if actual_input_archive_sha256 != EXPECTED_INPUT_ARCHIVE_SHA256:
    raise RuntimeError(
        "Assisted input archive SHA-256 mismatch: "
        f"{{actual_input_archive_sha256}} != {{EXPECTED_INPUT_ARCHIVE_SHA256}}"
    )
print("Verified frozen assisted_label_inputs.bundle")
OUTPUT_ROOT = Path("/kaggle/working/assisted_review_quarantine")
RUNTIME_PATH = Path("/kaggle/working/assisted_label_review.py")
CORRECTION_MANIFEST_PATH = Path("/kaggle/working/review_correction_manifest.json")
# Keep the semantic thresholds in the notebook process as well as in the
# embedded runtime's CLI defaults.  The execution cell below passes these
# values explicitly, so defining them here prevents a generated notebook from
# depending on a symbol that only exists in the local bundle builder.
RUN_MODE_ARGUMENTS = {RUN_MODE_ARGUMENTS!r}
YOLOE_TEXT_CHECKPOINT_GLOB = {YOLOE_TEXT_CHECKPOINT_GLOB!r}
SAM31_SEMANTIC_THRESHOLDS = {SAM31_SEMANTIC_THRESHOLDS!r}
SAM31_RESCUE_THRESHOLDS = {SAM31_RESCUE_THRESHOLDS!r}
CORRECTION_GUIDED_THRESHOLDS = {CORRECTION_GUIDED_THRESHOLDS!r}
CORRECTION_GUIDED_TILED_SOURCE = {CORRECTION_GUIDED_TILED_SOURCE!r}
CORRECTION_GUIDED_TILED_TRIGGER_POLICY = {CORRECTION_GUIDED_TILED_TRIGGER_POLICY!r}
CORRECTION_GUIDED_TILED_THRESHOLDS = {CORRECTION_GUIDED_TILED_THRESHOLDS!r}
CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES = {CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES}
CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES = {CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES}
CORRECTION_GUIDED_TILED_ENABLED = {(not sam31_smoke_only)!r}
CORRECTION_GUIDED_TILED_COUNTS_USED_AS_GEOMETRY = False
AUDITED_VISUAL_RECOVERY_SOURCE = {AUDITED_VISUAL_RECOVERY_SOURCE!r}
AUDITED_VISUAL_RECOVERY_CLASS_IDS = {AUDITED_VISUAL_RECOVERY_CLASS_IDS!r}
AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY = {AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY!r}
AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT = {AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT}
AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT = {AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT}
AUDITED_VISUAL_RECOVERY_CONFIDENCE = {AUDITED_VISUAL_RECOVERY_CONFIDENCE}
AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES = {AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES}
AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES = {AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES}
AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS = {AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS!r}
AUDITED_VISUAL_RECOVERY_COUNTS_USED_AS_GEOMETRY = False
""",
        ),
        notebook_cell(
            "code",
            """
import subprocess
import sys

subprocess.run(
    [
        sys.executable,
        "-m",
        "pip",
        "install",
        "--disable-pip-version-check",
        "--no-cache-dir",
        "--quiet",
        "ultralytics==8.4.93",
        "huggingface_hub==0.36.0",
        "rapidocr-onnxruntime==1.4.4",
        "git+https://github.com/facebookresearch/sam3.git@46957e47805eaa273f4aa7bbbd25a88bca9108ce",
        "git+https://github.com/ultralytics/CLIP.git@0fa238b2ba553fe76dc158348d8e34625e3e2470",
    ],
    check=True,
)

# pip can replace NumPy while a notebook kernel is already alive.  Importing
# NumPy, Torch, Torchvision, OpenCV, Ultralytics, or SAM in that same process
# would then mix objects from two binary ABIs.  This short-lived interpreter is
# the authoritative dependency and CUDA check; every later model operation is
# kept behind the same fresh-process boundary.
dependency_preflight = r'''
import json
import cv2
import numpy as np
import torch
import torchvision
from ultralytics import YOLOE
from rapidocr_onnxruntime import RapidOCR
from sam3.model_builder import build_sam3_predictor

if int(np.__version__.split(".", 1)[0]) >= 2:
    raise RuntimeError(
        f"The pinned official SAM 3.1 dependency contract requires NumPy <2; got {np.__version__}."
    )
if not torch.cuda.is_available():
    raise RuntimeError("Select a Kaggle GPU accelerator before running masks.")

# Exercise one compiled Torchvision CUDA operator, not merely its Python
# import, so an incompatible Torch/Torchvision pair fails before a 3.5 GB
# checkpoint is downloaded.
probe = torch.ones((1, 4, 4), dtype=torch.bool, device="cuda")
torchvision.ops.masks_to_boxes(probe)
probe_boxes = torch.tensor([[0.0, 0.0, 3.0, 3.0]], device="cuda")
probe_scores = torch.tensor([1.0], device="cuda")
torchvision.ops.nms(probe_boxes, probe_scores, 0.5)
torch.cuda.synchronize()
print(json.dumps({
    "status": "fresh_process_dependency_cuda_preflight_passed",
    "numpy": np.__version__,
    "torch": torch.__version__,
    "torchvision": torchvision.__version__,
    "opencv": cv2.__version__,
    "rapidocr": type(RapidOCR()).__name__,
    "cuda_device": torch.cuda.get_device_name(0),
}))
'''
subprocess.run(
    [sys.executable, "-c", dependency_preflight],
    check=True,
)
""",
        ),
        notebook_cell(
            "code",
            f"""
import base64
import hashlib

runtime = base64.b64decode({encoded_runtime!r})
if hashlib.sha256(runtime).hexdigest() != {runtime_hash!r}:
    raise RuntimeError("Embedded assisted runtime hash mismatch.")
RUNTIME_PATH.write_bytes(runtime)
correction_manifest = base64.b64decode({encoded_correction_manifest!r})
if hashlib.sha256(correction_manifest).hexdigest() != {correction_manifest_hash!r}:
    raise RuntimeError("Embedded correction manifest hash mismatch.")
CORRECTION_MANIFEST_PATH.write_bytes(correction_manifest)
print("Verified assisted_label_review.py")
""",
        ),
        notebook_cell(
            "code",
            f"""
from huggingface_hub import hf_hub_download
from kaggle_secrets import UserSecretsClient
import shutil

# Prefer the pinned Kaggle model input.  This is the exact same official
# sam3.1_multiplex.pt checkpoint, but it is mounted read-only by Kaggle and
# therefore does not require a secret on this newly imported notebook kernel.
# HF_TOKEN remains a deliberately explicit fallback for accounts that do not
# have the model source attached.  In either path, the byte-size check runs
# before any proposal inference and the runtime records the final SHA-256.
sam3_path = Path("/kaggle/working") / {SAM3_FILENAME!r}
sam3_path_owned = False
model_matches = sorted(
    path
    for path in Path("/kaggle/input").rglob({SAM3_FILENAME!r})
    if path.is_file()
)
if len(model_matches) > 1:
    raise RuntimeError(
        "Expected at most one attached SAM 3.1 model input; found "
        f"{{len(model_matches)}} copies."
    )
try:
    if model_matches:
        sam3_path = model_matches[0]
        checkpoint_source = "kaggle_model_input:{SAM3_KAGGLE_MODEL_SOURCE}"
    else:
        hf_token = UserSecretsClient().get_secret("HF_TOKEN")
        if not hf_token:
            raise RuntimeError(
                "Attach the pinned Kaggle SAM 3.1 model input or provide HF_TOKEN "
                "for the gated official checkpoint."
            )
        sam3_path = Path(
            hf_hub_download(
                repo_id={SAM3_REPO_ID!r},
                filename={SAM3_FILENAME!r},
                revision={SAM3_REVISION!r},
                local_dir="/kaggle/working",
                token=hf_token,
            )
        )
        sam3_path_owned = True
        checkpoint_source = "huggingface:{SAM3_REPO_ID}@{SAM3_REVISION}"
    if sam3_path.stat().st_size != {SAM3_EXPECTED_SIZE}:
        raise RuntimeError(
            "Official SAM 3.1 checkpoint size mismatch: "
            f"{{sam3_path.stat().st_size}} bytes."
        )
except BaseException:
    # The mounted model input is read-only and must never be deleted.  Only a
    # private HF fallback copy belongs to this notebook session.
    if sam3_path_owned:
        sam3_path.unlink(missing_ok=True)
    shutil.rmtree("/kaggle/working/assisted_label_inputs", ignore_errors=True)
    shutil.rmtree("/kaggle/working/sam31_smoke_inputs", ignore_errors=True)
    shutil.rmtree("/kaggle/working/sam31_smoke_sessions", ignore_errors=True)
    shutil.rmtree(OUTPUT_ROOT / "sam31_sessions", ignore_errors=True)
    raise

def cleanup_sam3_checkpoint():
    if sam3_path_owned:
        sam3_path.unlink(missing_ok=True)

print(
    "Verified pinned official SAM 3.1 checkpoint "
    f"({{checkpoint_source}}; {{sam3_path.stat().st_size}} bytes)"
)
""",
        ),
        notebook_cell(
            "code",
            f"""
import os
import shutil

# Download/instantiate YOLOE in a disposable interpreter.  The notebook kernel
# deliberately never imports the post-pip NumPy/Torch computer-vision stack.
yoloe_download_script = r'''
from pathlib import Path
from ultralytics import YOLOE

model = YOLOE("yoloe-26x-seg.pt")
del model
if not Path("/kaggle/working/yoloe-26x-seg.pt").is_file():
    raise RuntimeError("YOLOE-26X-seg checkpoint download did not produce the expected file.")
'''
try:
    runtime_environment = os.environ.copy()
    # SAM 3.1 repeatedly allocates large, differently shaped feature tensors
    # across the twenty phone photos.  Expandable CUDA segments prevent those
    # changing shapes from fragmenting a 16 GB Kaggle T4.  This must be set on
    # the fresh runtime process before it imports Torch.
    runtime_environment["PYTORCH_CUDA_ALLOC_CONF"] = "expandable_segments:True"
    runtime_environment["PYTORCH_ALLOC_CONF"] = "expandable_segments:True"
    subprocess.run(
        [sys.executable, "-c", yoloe_download_script],
        cwd="/kaggle/working",
        check=True,
    )
    # Which checkpoint answers the TEXT prompts.
    #
    # V54 loaded a fine-tuned checkpoint and then never consulted it: the text
    # lane is a last-resort fallback that is off by default, so
    # text_only_proposal_count was 0 on all twenty images and every proposal
    # came from SAM 3.1 and the stock image-prompt lane.  Pairing a fine-tuned
    # checkpoint with --text-prompt-primary is what took cup instances from 233
    # to 445 in V55, so the two settings belong together and both now come from
    # builder flags rather than a hand-edit of this notebook.
    #
    # Fail loudly when a glob is configured but matches nothing.  Silently
    # falling back to stock weights would produce a plausible-looking run that
    # answers a different question than the one being asked.
    YOLOE_MODEL_PATH = "/kaggle/working/yoloe-26x-seg.pt"
    if YOLOE_TEXT_CHECKPOINT_GLOB:
        import glob as _glob

        _matches = sorted(_glob.glob(YOLOE_TEXT_CHECKPOINT_GLOB, recursive=True))
        if not _matches:
            raise RuntimeError(
                "No checkpoint matched the configured text-prompt glob "
                f"{{YOLOE_TEXT_CHECKPOINT_GLOB!r}}. Attach the dataset holding it, "
                "or rebuild the bundle without --text-prompt-checkpoint-glob."
            )
        YOLOE_MODEL_PATH = _matches[0]
    print("text-prompt checkpoint:", YOLOE_MODEL_PATH)

    subprocess.run(
        [
            sys.executable,
            str(RUNTIME_PATH),
            "--input-bundle",
            str(INPUT_ARCHIVE),
            "--expected-input-archive-sha256",
            EXPECTED_INPUT_ARCHIVE_SHA256,
            "--correction-manifest",
            str(CORRECTION_MANIFEST_PATH),
            "--expected-correction-manifest-sha256",
            {correction_manifest_hash!r},
            "--output-root",
            str(OUTPUT_ROOT),
            "--yoloe-model",
            YOLOE_MODEL_PATH,
            "--sam3-model",
            str(sam3_path),
            "--device",
            "0",
            # Run-mode switches.  These reached V55 and V56 only by hand-editing
            # the generated notebook JSON, which meant the run that produced a
            # result could not be reproduced from a single command and the
            # bundle manifest did not record how it had actually been run.
            # They are emitted from the builder now, so the flags, the manifest
            # and the notebook can no longer disagree.
            *RUN_MODE_ARGUMENTS,
            "--sam31-semantic-thresholds",
            *[str(value) for value in SAM31_SEMANTIC_THRESHOLDS],
            "--sam31-rescue-thresholds",
            *[str(value) for value in SAM31_RESCUE_THRESHOLDS],
            "--sam31-rescue-trigger-max-primary-instances",
            str({SAM31_RESCUE_TRIGGER_MAX_PRIMARY_INSTANCES}),
            "--sam31-rescue-max-raw-instances",
            str({SAM31_RESCUE_MAX_RAW_INSTANCES}),
            "--sam31-rescue-max-post-nms-instances",
            str({SAM31_RESCUE_MAX_POST_NMS_INSTANCES}),
            "--correction-guided-thresholds",
            *[str(value) for value in CORRECTION_GUIDED_THRESHOLDS],
            "--correction-guided-max-raw-instances",
            str({SAM31_RESCUE_MAX_RAW_INSTANCES}),
            "--correction-guided-max-post-nms-instances",
            str({SAM31_RESCUE_MAX_POST_NMS_INSTANCES}),
            "--correction-guided-tiled-source",
            CORRECTION_GUIDED_TILED_SOURCE,
            "--correction-guided-tiled-thresholds",
            *[str(value) for value in CORRECTION_GUIDED_TILED_THRESHOLDS],
            "--correction-guided-tiled-max-raw-instances",
            str(CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES),
            "--correction-guided-tiled-max-post-nms-instances",
            str(CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES),
            "--audited-visual-recovery-confidence",
            str(AUDITED_VISUAL_RECOVERY_CONFIDENCE),
            "--audited-visual-recovery-minimum-reference-support",
            str(AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT),
            "--audited-visual-recovery-max-raw-instances",
            str(AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES),
            "--audited-visual-recovery-max-post-nms-instances",
            str(AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES),
        ],
        env=runtime_environment,
        check=True,
    )
except BaseException:
    # A failed run must not leave multi-GB checkpoints, decoded private inputs,
    # or per-instance SAM session frames in the saved Kaggle version.
    cleanup_sam3_checkpoint()
    Path("/kaggle/working/yoloe-26x-seg.pt").unlink(missing_ok=True)
    shutil.rmtree("/kaggle/working/assisted_label_inputs", ignore_errors=True)
    shutil.rmtree(OUTPUT_ROOT / "sam31_sessions", ignore_errors=True)
    CORRECTION_MANIFEST_PATH.unlink(missing_ok=True)
    raise
""",
        ),
        notebook_cell(
            "code",
            """
import importlib.util
import json
import shutil
from pathlib import Path

run_manifest = json.loads((OUTPUT_ROOT / "run_manifest.json").read_text(encoding="utf-8"))
polygon_audit = json.loads((OUTPUT_ROOT / "polygon_audit_manifest.json").read_text(encoding="utf-8"))
final_sheets = sorted((OUTPUT_ROOT / "final_contact_sheets").glob("*.jpg"))
polygon_files = sorted((OUTPUT_ROOT / "sam3_refined_polygons").glob("*.txt"))
if run_manifest.get("status") != "awaiting_twenty_image_pass_reject":
    raise RuntimeError("Assisted run is not ready for pass/reject review.")
if len(final_sheets) != 20:
    raise RuntimeError(f"Expected 20 final contact sheets; found {len(final_sheets)}.")
if len(polygon_files) != 20:
    raise RuntimeError(f"Expected 20 polygon files; found {len(polygon_files)}.")
if polygon_audit.get("all_images_passed") is not True:
    raise RuntimeError("The strict polygon audit did not pass all 20 images.")
if polygon_audit.get("audited_image_count") != 20 or len(polygon_audit.get("images", [])) != 20:
    raise RuntimeError("The polygon audit does not contain exactly 20 image rows.")
if polygon_audit.get("total_instance_count") != polygon_audit.get("total_emitted_row_count"):
    raise RuntimeError("The total emitted polygon rows differ from the proposed instance count.")
for row in polygon_audit["images"]:
    if row.get("passed") is not True:
        raise RuntimeError(f"Polygon audit failed for {row.get('image_name')}.")
    if int(row.get("instance_count", 0)) <= 0:
        raise RuntimeError(f"Inventory image has no review proposals: {row.get('image_name')}.")
    if row.get("instance_count") != row.get("emitted_row_count"):
        raise RuntimeError(f"Polygon row count differs from instance count for {row.get('image_name')}.")
if run_manifest.get("class_names") != [
    "kraft paper bowl",
    "black soya sauce cup",
    "red teriyaki sauce cup",
    "white wayo dip cup",
    "orange chili mayo cup",
    "wooden chopstick tip",
    "black and white soya sauce packet",
]:
    raise RuntimeError("The review artifact does not use the fixed seven-class order.")
if any(row.get("polygon_audit_passed") is not True for row in run_manifest.get("images", [])):
    raise RuntimeError("Run manifest contains an image that did not pass polygon audit.")
if any(row.get("instance_count") != row.get("emitted_polygon_row_count") for row in run_manifest.get("images", [])):
    raise RuntimeError("Run manifest polygon counts are internally inconsistent.")
if any(row.get("ocr_status") != "available" for row in run_manifest.get("images", [])):
    raise RuntimeError("OCR bowl-sticker evidence is unavailable for one or more images.")
if run_manifest.get("sam3_status") not in {"available", "available_with_instance_fallbacks"}:
    raise RuntimeError("SAM 3 refinement was incomplete; pass/reject review cannot start.")
semantic_summary = run_manifest.get("sam31_semantic_discovery_summary", {})
if run_manifest.get("inference_parameters", {}).get("sam31_semantic_discovery_enabled") is not True:
    raise RuntimeError("SAM 3.1 semantic discovery is not enabled in the review artifact.")
if (
    semantic_summary.get("enabled") is not True
    or semantic_summary.get("image_count") != 20
    or semantic_summary.get("prompt_attempt_count") != 140
    or semantic_summary.get("prompt_success_count") != 140
    or semantic_summary.get("failed_prompt_count") != 0
    or int(semantic_summary.get("total_proposal_count", 0)) <= 0
    or len(semantic_summary.get("images", [])) != 20
):
    raise RuntimeError("SAM 3.1 semantic discovery did not complete the 20 x 7 prompt gate.")
for semantic_row in semantic_summary["images"]:
    if (
        semantic_row.get("status") != "success"
        or semantic_row.get("prompt_count") != 7
        or semantic_row.get("successful_prompt_count") != 7
        or semantic_row.get("failed_prompt_count") != 0
    ):
        raise RuntimeError(
            f"SAM 3.1 semantic prompt gate failed for {semantic_row.get('image_name')}."
        )
rescue_summary = run_manifest.get("sam31_bounded_rescue_summary", {})
if (
    rescue_summary.get("enabled") is not True
    or rescue_summary.get("image_count") != 20
    or len(rescue_summary.get("images", [])) != 20
):
    raise RuntimeError("The bounded SAM 3.1 rescue summary is incomplete.")
rescue_parameters = run_manifest.get("inference_parameters", {})
if rescue_parameters.get("sam31_bounded_rescue_enabled") is not True:
    raise RuntimeError("The bounded SAM 3.1 rescue lane is not explicitly enabled.")
if rescue_parameters.get("sam31_rescue_prompts") != [
    "brown kraft paper food container with a label",
    "black lidded sauce cup",
    "red lidded sauce cup",
    "white lidded sauce cup",
    "orange lidded sauce cup",
    "visible end of a wooden chopstick",
    "black and white soy sauce sachet",
]:
    raise RuntimeError("The bounded SAM rescue prompt bank is not fixed.")
for rescue_row in rescue_summary["images"]:
    if rescue_row.get("status") in {"accepted", "rejected", "failed"}:
        if rescue_row.get("image_name") in run_manifest.get("visual_reference_images", []):
            raise RuntimeError("SAM rescue must never run on audited references.")
    if int(rescue_row.get("raw_instance_count", 0)) > int(
        rescue_parameters.get("sam31_rescue_max_raw_instances", 0)
    ):
        raise RuntimeError("A bounded rescue row exceeded its raw instance limit.")
    if int(rescue_row.get("proposal_count", 0)) > int(
        rescue_parameters.get("sam31_rescue_max_post_nms_instances", 0)
    ):
        raise RuntimeError("A bounded rescue row exceeded its post-NMS limit.")
    if rescue_row.get("status") in {"failed", "rejected"}:
        raise RuntimeError(
            f"Bounded SAM 3.1 rescue failed or was rejected for "
            f"{rescue_row.get('image_name')}."
        )
inference_parameters = run_manifest.get("inference_parameters", {})
if inference_parameters.get("text_fallback_enabled") is not False:
    raise RuntimeError("The packaged review workflow must keep text fallback disabled.")
text_fallback_summary = run_manifest.get("text_prompt_fallback_summary", {})
if (
    text_fallback_summary.get("triggered_image_count") != 0
    or text_fallback_summary.get("text_proposal_count_before_union") != 0
    or text_fallback_summary.get("text_only_union_proposal_count") != 0
    or text_fallback_summary.get("dual_supported_union_proposal_count") != 0
    or text_fallback_summary.get("target_images") != []
):
    raise RuntimeError("The packaged visual-prompt review contains text-fallback proposals.")
if any(
    row.get("text_prompt_fallback_ran") is not False
    or int(row.get("text_prompt_instance_count", 0)) != 0
    or int(row.get("text_only_proposal_count", 0)) != 0
    or int(row.get("dual_supported_proposal_count", 0)) != 0
    for row in run_manifest.get("images", [])
):
    raise RuntimeError("A packaged review image contains unexpected text-fallback provenance.")
if run_manifest.get("training_authorized") is not False:
    raise RuntimeError("Assisted proposals unexpectedly authorized training.")
if run_manifest.get("release_gate", {}).get("passed") is not False:
    raise RuntimeError("The 95 percent production gate must remain closed.")
correction_summary = run_manifest.get("correction_guided_summary", {})
# Drive the shipped runtime helper so the notebook packaging gate cannot drift
# from the unit-tested soft-usefulness contract (V41/V42/V44 package blockers).
import importlib.util
_runtime_path = Path("/kaggle/working/assisted_label_review.py")
_spec = importlib.util.spec_from_file_location(
    "assisted_label_review_runtime",
    _runtime_path,
)
if _spec is None or _spec.loader is None:
    raise RuntimeError(f"Could not load assisted runtime from {_runtime_path}.")
_alr = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_alr)
_correction_package = _alr.validate_correction_guided_notebook_package_gates(
    correction_summary
)
if _correction_package.get("usefulness_incomplete"):
    print(
        "SOFT usefulness incomplete (does not block archive download): "
        f"failed_images={_correction_package.get('usefulness_failed_image_count')}"
    )
correction_tiled_summary = run_manifest.get(
    "correction_guided_tiled_recovery_summary",
    {},
)
if (
    correction_tiled_summary.get("enabled") is not True
    or correction_tiled_summary.get("image_count") != 20
    or correction_tiled_summary.get("failed_image_count") != 0
    or correction_tiled_summary.get("successful_prompt_count")
    != correction_tiled_summary.get("prompt_attempt_count")
    or correction_tiled_summary.get("count_targets_used_as_geometry") is not False
    or len(correction_tiled_summary.get("images", [])) != 20
):
    raise RuntimeError("The targeted tiled correction recovery lane is incomplete.")
if any(
    row.get("status") in {"failed", "rejected"}
    for row in correction_tiled_summary["images"]
):
    raise RuntimeError("A targeted tiled correction recovery pass failed.")
audited_visual_summary = run_manifest.get("audited_visual_recovery_summary", {})
if (
    audited_visual_summary.get("enabled") is not True
    or audited_visual_summary.get("source") != AUDITED_VISUAL_RECOVERY_SOURCE
    or audited_visual_summary.get("image_count") != 20
    or audited_visual_summary.get("target_image_count") != 14
    or audited_visual_summary.get("failed_image_count") != 0
    or audited_visual_summary.get("successful_inference_call_count")
    != audited_visual_summary.get("inference_call_count")
    or audited_visual_summary.get("count_targets_used_as_geometry") is not False
    or len(audited_visual_summary.get("images", [])) != 20
):
    raise RuntimeError("The audited YOLOE visual recovery lane is incomplete.")
if any(
    row.get("status") == "failed"
    for row in audited_visual_summary["images"]
):
    raise RuntimeError("An audited YOLOE visual recovery pass failed.")
reference_plans = audited_visual_summary.get("reference_plans")
if not isinstance(reference_plans, dict):
    raise RuntimeError("Audited visual recovery reference crops are missing.")
for class_id in AUDITED_VISUAL_RECOVERY_CLASS_IDS:
    class_plans = reference_plans.get(str(class_id))
    if not isinstance(class_plans, list) or len(class_plans) != AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT:
        raise RuntimeError("Audited visual recovery does not retain three crops per class.")
    for plan in class_plans:
        if not isinstance(plan, dict) or plan.get("class_id") != class_id:
            raise RuntimeError("Audited visual recovery crop metadata is invalid.")
        crop_path = Path(str(plan.get("reference_crop", "")))
        if not crop_path.is_file():
            raise RuntimeError("Audited visual recovery crop is missing from the review artifact.")
for recovery_row in audited_visual_summary["images"]:
    if recovery_row.get("count_targets_used_as_geometry") is not False:
        raise RuntimeError("Human counts were used as audited visual recovery geometry.")
    if recovery_row.get("triggered") is True:
        selected_ids = recovery_row.get("selected_class_ids")
        if (
            not isinstance(selected_ids, list)
            or not selected_ids
            or any(class_id not in AUDITED_VISUAL_RECOVERY_CLASS_IDS for class_id in selected_ids)
            or recovery_row.get("minimum_reference_support")
            != AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT
        ):
            raise RuntimeError("Triggered audited visual recovery evidence is invalid.")
if run_manifest.get("inference_parameters", {}).get(
    "audited_visual_recovery_minimum_reference_support"
) != AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT:
    raise RuntimeError("Audited visual recovery reference consensus drifted.")
if run_manifest.get("inference_parameters", {}).get(
    "audited_visual_recovery_counts_used_as_geometry"
) is not False:
    raise RuntimeError("Human counts were used as audited visual recovery geometry.")

archive = shutil.make_archive(
    "/kaggle/working/yoloe26x_sam31_semantic_assisted_review_quarantine",
    "zip",
    root_dir=OUTPUT_ROOT,
)
# The review archive already contains every durable proposal, polygon, contact
# sheet, audit row, and model hash.  Remove only the reproducibly downloadable
# multi-GB checkpoints and disposable SAM frames after the archive is closed so
# the saved Kaggle version stays small enough to download and inspect locally.
cleanup_sam3_checkpoint()
Path("/kaggle/working/yoloe-26x-seg.pt").unlink(missing_ok=True)
CORRECTION_MANIFEST_PATH.unlink(missing_ok=True)
shutil.rmtree("/kaggle/working/assisted_label_inputs", ignore_errors=True)
shutil.rmtree(OUTPUT_ROOT / "sam31_sessions", ignore_errors=True)
print(json.dumps({
    "status": run_manifest["status"],
    "contact_sheet_count": len(final_sheets),
    "training_authorized": False,
    "release_gate_passed": False,
    "download_archive": archive,
}, indent=2))
""",
        ),
    ]
    if sam31_smoke_only:
        # Keep the smoke version deliberately small: it downloads and loads the
        # exact pinned SAM 3.1 checkpoint, applies one audited box to one frozen
        # reference image, and proves that the returned polygon is a real SAM
        # mask.  YOLOE proposal generation is omitted, so a broken compatibility
        # fix fails in minutes instead of consuming another full 20-image run.
        cells = cells[:5] + [
            notebook_cell(
                "code",
                """
import os
import shutil

smoke_input_root = Path("/kaggle/working/sam31_smoke_inputs")
smoke_session_root = Path("/kaggle/working/sam31_smoke_sessions")
smoke_result_path = Path("/kaggle/working/sam31_instance_smoke.json")

# The entire real-mask test, including importing the embedded runtime and SAM,
# executes after pip in a fresh interpreter.  Paths/hashes cross the boundary
# as plain environment strings, so the long-lived notebook kernel never owns a
# NumPy, Torch, Torchvision, Ultralytics, or SAM object from the replaced ABI.
smoke_script = r'''
import gc
import importlib.util
import json
import os
from pathlib import Path

runtime_path = Path(os.environ["ASSISTED_RUNTIME_PATH"])
input_archive = Path(os.environ["ASSISTED_INPUT_ARCHIVE"])
input_archive_sha256 = os.environ["ASSISTED_INPUT_SHA256"]
sam3_checkpoint = Path(os.environ["SAM31_CHECKPOINT"])
smoke_input_root = Path(os.environ["SAM31_SMOKE_INPUT_ROOT"])
smoke_session_root = Path(os.environ["SAM31_SMOKE_SESSION_ROOT"])
smoke_result_path = Path(os.environ["SAM31_SMOKE_RESULT"])

spec = importlib.util.spec_from_file_location("assisted_label_review", runtime_path)
if spec is None or spec.loader is None:
    raise RuntimeError("Could not import the embedded assisted runtime for smoke testing.")
assisted = importlib.util.module_from_spec(spec)
spec.loader.exec_module(assisted)

input_manifest = assisted.extract_input_bundle(
    input_archive,
    smoke_input_root,
    input_archive_sha256,
)
reference_annotation = assisted.read_reference_annotation(
    smoke_input_root
    / "reference_annotations"
    / input_manifest["reference_annotation_names"][0]
)
if not reference_annotation["instances"]:
    raise RuntimeError("The SAM 3.1 smoke reference has no audited box prompt.")

# The largest audited object is the least ambiguous one-prompt smoke case.  It
# tests exact box-to-mask interactivity without treating an automatic label as
# ground truth and without authorizing training.
seed = max(
    reference_annotation["instances"],
    key=lambda row: (
        (row["bbox_xyxy"][2] - row["bbox_xyxy"][0])
        * (row["bbox_xyxy"][3] - row["bbox_xyxy"][1])
    ),
)
oriented = assisted.materialize_oriented_images(
    smoke_input_root / "images",
    smoke_input_root / "oriented_images",
    [reference_annotation["image_name"]],
)[reference_annotation["image_name"]]

from sam3.model_builder import build_sam3_predictor

predictor = None
adapter = None
try:
    predictor = build_sam3_predictor(
        checkpoint_path=str(sam3_checkpoint),
        version="sam3.1",
        use_fa3=False,
        use_rope_real=False,
        async_loading_frames=False,
    )
    adapter = assisted.Sam31ImageAdapter(predictor, smoke_session_root)
    refined = assisted.sam3_refine_image(adapter, oriented, [seed], "0")
    row = refined[0]
    if row.get("sam3_refinement_status") != "success" or len(row.get("polygon", [])) < 3:
        raise RuntimeError("Pinned SAM 3.1 did not return a real polygon for the audited smoke box.")
    if float(row.get("sam3_prompt_match_iou") or 0.0) < assisted.SAM3_MIN_PROMPT_MATCH_IOU:
        raise RuntimeError("Pinned SAM 3.1 smoke mask did not geometrically match its audited box.")
    if int(row.get("sam3_mask_nonzero_pixel_count") or 0) <= 0:
        raise RuntimeError("Pinned SAM 3.1 smoke output did not contain a non-empty binary mask.")

    smoke_result = {
        "schema_version": 1,
        "status": "passed_real_sam31_instance_mask",
        "image_name": reference_annotation["image_name"],
        "class_id": int(row["class_id"]),
        "class_name": row["class_name"],
        "prompt_bbox_xyxy": seed["bbox_xyxy"],
        "polygon_point_count": len(row["polygon"]),
        "prompt_match_iou": row["sam3_prompt_match_iou"],
        "prompt_method": row["sam3_prompt_method"],
        "cache_compatibility": row.get("sam3_cache_compatibility"),
        "mask_shape": row.get("sam3_mask_shape"),
        "mask_dtype": row.get("sam3_mask_dtype"),
        "mask_nonzero_pixel_count": row.get("sam3_mask_nonzero_pixel_count"),
        "output_instance_count": row.get("sam3_output_instance_count"),
        "real_sam31_mask": True,
        "training_authorized": False,
        "promotion_authorized": False,
        "release_gate_passed": False,
    }
    assisted.write_json(smoke_result_path, smoke_result)
    print(json.dumps(smoke_result, indent=2))
finally:
    # CUDA allocations disappear when this child exits; explicit release keeps
    # the successful path tidy while the parent owns filesystem cleanup.
    del adapter
    del predictor
    gc.collect()
'''

smoke_environment = os.environ.copy()
smoke_environment.update({
    "ASSISTED_RUNTIME_PATH": str(RUNTIME_PATH),
    "ASSISTED_INPUT_ARCHIVE": str(INPUT_ARCHIVE),
    "ASSISTED_INPUT_SHA256": EXPECTED_INPUT_ARCHIVE_SHA256,
    "SAM31_CHECKPOINT": str(sam3_path),
    "SAM31_SMOKE_INPUT_ROOT": str(smoke_input_root),
    "SAM31_SMOKE_SESSION_ROOT": str(smoke_session_root),
    "SAM31_SMOKE_RESULT": str(smoke_result_path),
})
try:
    subprocess.run(
        [sys.executable, "-c", smoke_script],
        env=smoke_environment,
        check=True,
    )
finally:
    # Always remove the 3.5 GB checkpoint, decoded private input, and session
    # frames, including when the child cannot start or exits with an exception.
    cleanup_sam3_checkpoint()
    shutil.rmtree(smoke_input_root, ignore_errors=True)
    shutil.rmtree(smoke_session_root, ignore_errors=True)

if not smoke_result_path.is_file():
    raise RuntimeError("The fresh-process SAM 3.1 smoke did not write its result artifact.")
print(smoke_result_path.read_text(encoding="utf-8"))
""",
            )
        ]
    if colab:
        # Prepend, never rewrite.  Keeping the Kaggle cells untouched is what
        # makes a Colab run comparable with the Kaggle runs it is measured
        # against; the delta is exactly this one cell.
        cells = [
            colab_preamble_cell(
                DEFAULT_DATASET_ID, colab_checkpoint_source, colab_drive_cache
            )
        ] + cells
    return {
        "cells": cells,
        "metadata": {
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python", "version": "3"},
            "kaggle": {"accelerator": "gpu", "internet": True},
        },
        "nbformat": 4,
        "nbformat_minor": 5,
    }


def kernel_metadata(config: BundleConfig) -> dict[str, Any]:
    return {
        "id": config.kernel_id,
        "title": config.kernel_title,
        "code_file": NOTEBOOK_FILENAME,
        "language": "python",
        "kernel_type": "notebook",
        "is_private": True,
        "enable_gpu": True,
        # PIN THE ACCELERATOR, do not just ask for "a GPU".
        #
        # `enable_gpu` alone lets Kaggle hand out whichever card is free, and on
        # 2026-07-27 it handed out a P100.  The pinned SAM 3.1 / Torch build in
        # this contract has no compiled kernels for that card, so the dependency
        # preflight died immediately with
        #   CUDA error: no kernel image is available for execution on the device
        # after warning "Flash Attention ... requires a GPU with Ampere (8.0)".
        # That is the same P100 failure this project banned back at V32.
        #
        # Kaggle's own metadata calls the T4 shape "NvidiaTeslaT4" — it is what
        # the successful V46 / V48 / V50 kernels report when their metadata is
        # pulled back down.  Recording it here means a rebuilt bundle carries the
        # requirement instead of relying on whoever pushes it to remember.
        "machine_shape": "NvidiaTeslaT4",
        "enable_internet": True,
        "dataset_sources": [config.dataset_id],
        "competition_sources": [],
        # Attaching the training kernel is what makes a fine-tuned
        # checkpoint reachable at /kaggle/input/**/artifacts/best.pt.
        "kernel_sources": list(config.kernel_sources),
        "model_sources": [SAM3_KAGGLE_MODEL_SOURCE],
    }


def dataset_metadata(config: BundleConfig) -> dict[str, Any]:
    """Describe the upload while recording that it must stay private.

    Kaggle's create command is private by default; ``isPrivate`` is retained as
    a machine-readable intent marker and the README deliberately never uses the
    public ``-u`` switch.
    """
    return {
        "title": config.dataset_title,
        "id": config.dataset_id,
        "licenses": [{"name": "other"}],
        "isPrivate": True,
        "description": (
            "Private, review-only inputs for the fixed YOLOE-26X assisted-label batch. "
            "Contains 20 frozen fridge images and six immutable audited references."
        ),
        "resources": [
            {
                "path": INPUT_ARCHIVE_FILENAME,
                "description": (
                    "Frozen 20-image review input with six immutable audited references."
                ),
            }
        ],
    }


def notebook_code(notebook: dict[str, Any]) -> str:
    return "\n".join(
        "".join(cell.get("source", []))
        for cell in notebook["cells"]
        if cell.get("cell_type") == "code"
    )


def verify_bundle(
    output_dir: Path,
    dataset_output_dir: Path,
    dataset_id: str,
    sam31_smoke_only: bool = False,
) -> None:
    actual = {path.name for path in output_dir.iterdir() if path.is_file()}
    if actual != EXPECTED_OUTPUT_FILES:
        raise ValueError(f"Assisted bundle differs from allow-list: {sorted(actual)}")
    dataset_actual = {
        path.name for path in dataset_output_dir.iterdir() if path.is_file()
    }
    if dataset_actual != EXPECTED_DATASET_FILES:
        raise ValueError(
            f"Assisted dataset upload differs from allow-list: {sorted(dataset_actual)}"
        )
    notebook = json.loads((output_dir / NOTEBOOK_FILENAME).read_text(encoding="utf-8"))
    code = notebook_code(notebook)
    for marker in FORBIDDEN_CODE_MARKERS:
        if marker.casefold() in code.casefold():
            raise ValueError(f"Notebook contains forbidden marker: {marker}")
    for required in ("assisted_label_review.py", INPUT_ARCHIVE_FILENAME, "/kaggle/working/assisted_review_quarantine"):
        if required not in code:
            raise ValueError(f"Notebook is missing required marker: {required}")
    if not sam31_smoke_only:
        for required in (
            "sam31_semantic_discovery_enabled",
            "sam31_semantic_discovery_summary",
            "prompt_attempt_count",
            "yoloe26x_sam31_semantic_assisted_review_quarantine",
        ):
            if required not in code:
                raise ValueError(
                    f"Notebook is missing SAM 3.1 semantic gate marker: {required}"
                )
    for required in (
        "CORRECTION_GUIDED_TILED_SOURCE",
        CORRECTION_GUIDED_TILED_SOURCE,
        "CORRECTION_GUIDED_TILED_TRIGGER_POLICY",
        CORRECTION_GUIDED_TILED_TRIGGER_POLICY,
        "CORRECTION_GUIDED_TILED_THRESHOLDS",
        "CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES",
        "CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES",
        "CORRECTION_GUIDED_TILED_ENABLED",
        "CORRECTION_GUIDED_TILED_COUNTS_USED_AS_GEOMETRY",
        "AUDITED_VISUAL_RECOVERY_SOURCE",
        "AUDITED_VISUAL_RECOVERY_CLASS_IDS",
        "AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY",
        "AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT",
        "AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT",
        "AUDITED_VISUAL_RECOVERY_CONFIDENCE",
        "AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES",
        "AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES",
        "AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS",
        "AUDITED_VISUAL_RECOVERY_COUNTS_USED_AS_GEOMETRY",
    ):
        if required not in code:
            raise ValueError(
                f"Notebook is missing V42 correction-guided tiled marker: {required}"
            )
    if not sam31_smoke_only:
        for required in (
            '"--correction-guided-tiled-source"',
            '"--correction-guided-tiled-max-raw-instances"',
            '"--correction-guided-tiled-max-post-nms-instances"',
            '"--audited-visual-recovery-confidence"',
            '"--audited-visual-recovery-minimum-reference-support"',
            '"--audited-visual-recovery-max-raw-instances"',
            '"--audited-visual-recovery-max-post-nms-instances"',
        ):
            if required not in code:
                raise ValueError(
                    f"Notebook is missing V42 correction-guided tiled CLI marker: {required}"
                )
    manifest = json.loads((output_dir / "bundle_manifest.json").read_text(encoding="utf-8"))
    if manifest["training_authorized"] is not False or manifest["promotion_authorized"] is not False:
        raise ValueError("Assisted bundle must remain fail-closed.")
    if manifest.get("text_prompt_fallback_enabled") is not False:
        raise ValueError("Assisted bundle must keep text fallback disabled by default.")
    if (
        not sam31_smoke_only
        and (
            manifest.get("sam31_semantic_discovery_enabled") is not True
            or manifest.get("sam31_semantic_prompts") != FIXED_CLASS_NAMES
            or manifest.get("sam31_semantic_thresholds")
            != SAM31_SEMANTIC_THRESHOLDS
        )
    ):
        raise ValueError("Assisted bundle has an invalid SAM 3.1 semantic contract.")
    expected_tiled_enabled = not sam31_smoke_only
    if (
        manifest.get("correction_guided_tiled_recovery_enabled")
        is not expected_tiled_enabled
        or manifest.get("correction_guided_tiled_source")
        != CORRECTION_GUIDED_TILED_SOURCE
        or manifest.get("correction_guided_tiled_trigger_policy")
        != CORRECTION_GUIDED_TILED_TRIGGER_POLICY
        or manifest.get("correction_guided_tiled_thresholds")
        != CORRECTION_GUIDED_TILED_THRESHOLDS
        or manifest.get("correction_guided_tiled_max_raw_instances")
        != CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES
        or manifest.get("correction_guided_tiled_max_post_nms_instances")
        != CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES
        or manifest.get("correction_guided_tiled_counts_used_as_geometry")
        is not False
        or manifest.get("correction_guided_tiled_target_only") is not True
    ):
        raise ValueError("Assisted bundle has an invalid V42 tiled recovery contract.")
    if (
        manifest.get("audited_visual_recovery_enabled")
        is not expected_tiled_enabled
        or manifest.get("audited_visual_recovery_source")
        != AUDITED_VISUAL_RECOVERY_SOURCE
        or manifest.get("audited_visual_recovery_class_ids")
        != AUDITED_VISUAL_RECOVERY_CLASS_IDS
        or manifest.get("audited_visual_recovery_trigger_policy")
        != AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY
        or manifest.get("audited_visual_recovery_reference_count_per_class")
        != AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT
        or manifest.get("audited_visual_recovery_minimum_reference_support")
        != AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT
        or manifest.get("audited_visual_recovery_confidence")
        != AUDITED_VISUAL_RECOVERY_CONFIDENCE
        or manifest.get("audited_visual_recovery_max_raw_instances")
        != AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES
        or manifest.get("audited_visual_recovery_max_post_nms_instances")
        != AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES
        or manifest.get("audited_visual_recovery_target_tile_settings")
        != {str(key): value for key, value in AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS.items()}
        or manifest.get("audited_visual_recovery_counts_used_as_geometry")
        is not False
        or manifest.get("audited_visual_recovery_target_only") is not True
    ):
        raise ValueError(
            "Assisted bundle has an invalid V44 audited visual recovery contract."
        )
    if manifest["release_gate"]["passed"] is not False:
        raise ValueError("Assisted bundle cannot pre-pass the release gate.")
    archive_hash = sha256_file(dataset_output_dir / INPUT_ARCHIVE_FILENAME)
    if manifest.get("input_archive_sha256") != archive_hash:
        raise ValueError("Bundle manifest input archive hash differs from the private dataset bundle.")
    embedded_hash_marker = (
        f'EMBEDDED_BUNDLE_MANIFEST = {{"input_archive_sha256": {archive_hash!r}}}'
    )
    if embedded_hash_marker not in code or 'EMBEDDED_BUNDLE_MANIFEST["input_archive_sha256"]' not in code:
        raise ValueError("Notebook does not verify the bundle manifest input archive hash.")
    if sam31_smoke_only:
        if "extract_input_bundle(" not in code or "EXPECTED_INPUT_ARCHIVE_SHA256" not in code:
            raise ValueError(
                "Smoke notebook does not pass the verified archive hash to its extractor."
            )
        if "sam31_instance_smoke.json" not in code:
            raise ValueError("Smoke notebook is missing its real-mask diagnostic artifact.")
    elif '"--expected-input-archive-sha256"' not in code:
        raise ValueError("Notebook does not pass the verified archive hash to the runtime.")
    kernel = json.loads((output_dir / "kernel-metadata.json").read_text(encoding="utf-8"))
    if kernel.get("dataset_sources") != [dataset_id]:
        raise ValueError("Kernel must attach exactly the assisted private input dataset.")
    dataset = json.loads(
        (dataset_output_dir / "dataset-metadata.json").read_text(encoding="utf-8")
    )
    if dataset.get("id") != dataset_id or dataset.get("isPrivate") is not True:
        raise ValueError("Assisted input dataset must retain explicit private intent.")


def build_bundle(config: BundleConfig) -> dict[str, Any]:
    validate_owner_slug(config.kernel_id, "kernel")
    validate_owner_slug(config.dataset_id, "dataset")
    if not RUNTIME_PATH.is_file():
        raise FileNotFoundError(f"Assisted Kaggle runtime was not found: {RUNTIME_PATH}")
    if config.correction_manifest is None or not config.correction_manifest.is_file():
        raise FileNotFoundError(
            "A completed fail-closed correction manifest is required."
        )
    images, annotations = batch_files(config.batch_root)
    verify_known_backup(config.batch_root, annotations)
    prepare_output_dir(config)

    input_manifest = make_input_manifest(images, annotations)
    archive_path = config.dataset_output_dir / INPUT_ARCHIVE_FILENAME
    write_input_archive(archive_path, images, annotations, input_manifest)
    input_archive_hash = sha256_file(archive_path)
    write_json(config.dataset_output_dir / "dataset-metadata.json", dataset_metadata(config))
    runtime_bytes = RUNTIME_PATH.read_bytes()
    runtime_hash = sha256_bytes(runtime_bytes)
    correction_manifest_bytes = config.correction_manifest.read_bytes()
    correction_manifest_hash = sha256_bytes(correction_manifest_bytes)
    # Turn the run-mode switches into the exact argv the notebook will pass to
    # the runtime, and keep that list in the manifest so a downloaded result can
    # always be traced back to the mode that produced it.
    run_mode_arguments: list[str] = []
    if config.text_prompt_primary:
        run_mode_arguments.append("--text-prompt-primary")
    if config.visual_prompt_model:
        run_mode_arguments += ["--visual-prompt-model", str(config.visual_prompt_model)]
    if config.raw_proposal_dump:
        run_mode_arguments += ["--raw-proposal-dump", str(config.raw_proposal_dump)]
    if config.proposal_confidence is not None:
        # The floor decides what is PROPOSED, so it sits above the replay seam
        # and cannot be tuned locally.  Measured on the shipped export, wooden
        # chopstick tips occupy a 0.01-0.07 confidence band while the runtime
        # default floor is 0.05, which cuts most of them off - and the
        # validator only needs that class DETECTED, not counted.
        run_mode_arguments += ["--confidence", str(config.proposal_confidence)]
    if config.text_prompts:
        # Prompts live ABOVE the replay seam, so a variant cannot be scored
        # locally - it needs its own proposal pass.  Emitting the words from a
        # build flag keeps that pass reproducible from one command and puts the
        # exact wording in the manifest beside its result.
        for prompt in config.text_prompts:
            run_mode_arguments += ["--text-prompt", str(prompt)]
    if config.image_shard:
        # Sharding is what makes a free/preemptible runtime survivable: a
        # reclaimed session costs one shard (~13 min) instead of the whole
        # ~90-minute pass.  It belongs in run_mode_arguments so the shard that
        # produced a result is recorded in the manifest, not hand-edited in.
        run_mode_arguments += ["--image-shard", str(config.image_shard)]

    notebook = build_notebook(
        runtime_bytes,
        runtime_hash,
        input_archive_hash,
        correction_manifest_bytes,
        correction_manifest_hash,
        sam31_smoke_only=config.sam31_smoke_only,
        run_mode_arguments=run_mode_arguments,
        yoloe_text_checkpoint_glob=config.yoloe_text_checkpoint_glob,
        colab=config.colab,
        colab_checkpoint_source=config.colab_checkpoint_source,
        colab_drive_cache=config.colab_drive_cache,
    )
    write_json(config.output_dir / NOTEBOOK_FILENAME, notebook)
    write_json(config.output_dir / "kernel-metadata.json", kernel_metadata(config))

    manifest = {
        "schema_version": 1,
        "workflow": (
            "sam31_exact_instance_smoke_only"
            if config.sam31_smoke_only
            else "yoloe26x_visual_prompt_tiled_plus_sam31_semantic_discovery_bounded_rescue_review_only"
        ),
        "workflow_revision": (
            "sam31_exact_instance_smoke_only_v1"
            if config.sam31_smoke_only
            else "v44_dense_exemplar_hires_visual_recovery_fail_closed_review"
        ),
        "diagnostic_only": config.sam31_smoke_only,
        "fixed_image_count": 20,
        "reference_count": 6,
        "target_count": 14,
        "class_names": FIXED_CLASS_NAMES,
        "reference_annotation_sha256": input_manifest["reference_annotation_sha256"],
        "raw_label_quarantine": RAW_LABEL_QUARANTINE,
        "text_prompt_fallback_enabled": False,
        "sam31_semantic_discovery_enabled": not config.sam31_smoke_only,
        "sam31_semantic_prompts": FIXED_CLASS_NAMES,
        "sam31_semantic_thresholds": SAM31_SEMANTIC_THRESHOLDS,
        "sam31_bounded_rescue_enabled": not config.sam31_smoke_only,
        "sam31_rescue_prompts": SAM31_RESCUE_PROMPTS,
        "sam31_rescue_thresholds": SAM31_RESCUE_THRESHOLDS,
        "sam31_rescue_trigger_max_primary_instances": (
            SAM31_RESCUE_TRIGGER_MAX_PRIMARY_INSTANCES
        ),
        "sam31_rescue_max_raw_instances": SAM31_RESCUE_MAX_RAW_INSTANCES,
        "sam31_rescue_max_post_nms_instances": SAM31_RESCUE_MAX_POST_NMS_INSTANCES,
        "correction_manifest_sha256": correction_manifest_hash,
        "correction_guided_enabled": not config.sam31_smoke_only,
        "correction_guided_image_count": 20,
        "correction_guided_target_image_count": 14,
        "correction_guided_reference_diagnostic_count": 6,
        "correction_guided_prompts": CORRECTION_GUIDED_PROMPTS,
        "correction_guided_thresholds": CORRECTION_GUIDED_THRESHOLDS,
        "correction_counts_used_as_geometry": False,
        # This targeted retry is allowed only when the correction audit says a
        # class must exist but the full-image correction pass returned no
        # candidate.  Bounds are serialized beside the source name so the
        # saved Kaggle version is reviewable and reproducible.
        "correction_guided_tiled_recovery_enabled": not config.sam31_smoke_only,
        "correction_guided_tiled_source": CORRECTION_GUIDED_TILED_SOURCE,
        "correction_guided_tiled_trigger_policy": (
            CORRECTION_GUIDED_TILED_TRIGGER_POLICY
        ),
        "correction_guided_tiled_thresholds": CORRECTION_GUIDED_TILED_THRESHOLDS,
        "correction_guided_tiled_max_raw_instances": (
            CORRECTION_GUIDED_TILED_MAX_RAW_INSTANCES
        ),
        "correction_guided_tiled_max_post_nms_instances": (
            CORRECTION_GUIDED_TILED_MAX_POST_NMS_INSTANCES
        ),
        "correction_guided_tiled_counts_used_as_geometry": False,
        "correction_guided_tiled_target_only": True,
        "audited_visual_recovery_enabled": not config.sam31_smoke_only,
        "audited_visual_recovery_source": AUDITED_VISUAL_RECOVERY_SOURCE,
        "audited_visual_recovery_class_ids": AUDITED_VISUAL_RECOVERY_CLASS_IDS,
        "audited_visual_recovery_class_names": [
            FIXED_CLASS_NAMES[class_id]
            for class_id in AUDITED_VISUAL_RECOVERY_CLASS_IDS
        ],
        "audited_visual_recovery_trigger_policy": (
            AUDITED_VISUAL_RECOVERY_TRIGGER_POLICY
        ),
        "audited_visual_recovery_reference_count_per_class": (
            AUDITED_VISUAL_RECOVERY_REFERENCE_COUNT
        ),
        "audited_visual_recovery_minimum_reference_support": (
            AUDITED_VISUAL_RECOVERY_MINIMUM_REFERENCE_SUPPORT
        ),
        "audited_visual_recovery_confidence": AUDITED_VISUAL_RECOVERY_CONFIDENCE,
        "audited_visual_recovery_max_raw_instances": (
            AUDITED_VISUAL_RECOVERY_MAX_RAW_INSTANCES
        ),
        "audited_visual_recovery_max_post_nms_instances": (
            AUDITED_VISUAL_RECOVERY_MAX_POST_NMS_INSTANCES
        ),
        "audited_visual_recovery_target_tile_settings": {
            str(key): value
            for key, value in AUDITED_VISUAL_RECOVERY_TARGET_TILE_SETTINGS.items()
        },
        "audited_visual_recovery_counts_used_as_geometry": False,
        "audited_visual_recovery_target_only": True,
        # Exactly what the notebook will hand the runtime. Kept in the manifest
        # so a downloaded result always states the mode that produced it.
        "run_mode_arguments": run_mode_arguments,
        "yoloe_text_checkpoint_glob": config.yoloe_text_checkpoint_glob,
        # Which platform this notebook was built for. A Colab bundle differs
        # from the Kaggle one by exactly one prepended preamble cell, and
        # recording that here keeps a downloaded result self-describing.
        "execution_platform": "colab" if config.colab else "kaggle",
        "colab_checkpoint_source": config.colab_checkpoint_source,
        "colab_drive_cache": config.colab_drive_cache,
        "runtime_sha256": runtime_hash,
        "input_archive_sha256": input_archive_hash,
        "input_dataset": {
            "id": config.dataset_id,
            "visibility": "private",
            "upload_directory": str(config.dataset_output_dir),
        },
        "training_authorized": False,
        "promotion_authorized": False,
        "human_action": "pass_or_reject_each_of_twenty_final_contact_sheets",
        "release_gate": {
            "metric": "assertion_pass_rate",
            "minimum_assertion_pass_rate": 0.95,
            "current_assertion_pass_rate": None,
            "passed": False,
        },
    }
    write_json(config.output_dir / "bundle_manifest.json", manifest)
    write_json(
        config.output_dir / "review_decision_manifest.json",
        {
            "schema_version": 1,
            "status": "awaiting_twenty_image_pass_reject",
            "class_names": FIXED_CLASS_NAMES,
            "reference_annotation_sha256": input_manifest["reference_annotation_sha256"],
            "images": [
                {
                    "image_name": image_name,
                    "decision": "pending",
                    "reviewer": None,
                    "notes": "",
                }
                for image_name in input_manifest["image_names"]
            ],
            "approved_image_count": 0,
            "rejected_image_count": 0,
            "training_authorized": False,
            "promotion_authorized": False,
            "release_gate": manifest["release_gate"],
        },
    )
    verify_bundle(
        config.output_dir,
        config.dataset_output_dir,
        config.dataset_id,
        sam31_smoke_only=config.sam31_smoke_only,
    )
    return manifest


def parse_args() -> argparse.Namespace:
    repo_root = Path(__file__).resolve().parents[3]
    parser = argparse.ArgumentParser(description="Build the Kaggle-only assisted 20-image mask review bundle.")
    default_correction_manifest = resolve_default_correction_manifest(repo_root)
    parser.add_argument("--batch-root", type=Path, default=repo_root / "training" / "sam_annotation_batch")
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path(__file__).with_name("kaggle_assisted_label_bundle"),
    )
    parser.add_argument(
        "--dataset-output-dir",
        type=Path,
        default=Path(__file__).with_name("kaggle_assisted_label_inputs_dataset"),
    )
    parser.add_argument("--kernel-id", default="mib348/sushi-yoloe26x-assisted-label-review")
    parser.add_argument("--kernel-title", default="Sushi YOLOE26X Assisted Label Review")
    parser.add_argument("--dataset-id", default=DEFAULT_DATASET_ID)
    parser.add_argument("--dataset-title", default=DEFAULT_DATASET_TITLE)
    parser.add_argument("--clean", action="store_true")
    parser.add_argument(
        "--text-prompt-primary",
        action="store_true",
        help=(
            "Send every target image through the YOLOE text lane instead of "
            "using text only as a fallback for weak visual-prompt images. This "
            "is what V55 ran; it took cup instances from 233 to 445."
        ),
    )
    parser.add_argument(
        "--visual-prompt-model",
        default=None,
        help=(
            "Checkpoint used for IMAGE prompts, when it must differ from the "
            "text-lane checkpoint. A fine-tuned checkpoint has no SAVPE head, "
            "so image prompting has to fall back to stock weights."
        ),
    )
    parser.add_argument(
        "--kernel-source",
        action="append",
        default=[],
        dest="kernel_sources",
        help=(
            "Kaggle kernel whose OUTPUT is mounted under /kaggle/input, repeatable. "
            "Required to reach a checkpoint produced by a training kernel, e.g. "
            "mib348/v53-bootstrap-train for artifacts/best.pt."
        ),
    )
    parser.add_argument(
        "--text-prompt-checkpoint-glob",
        default=None,
        help=(
            "Glob under /kaggle/input for the fine-tuned checkpoint that should "
            "answer TEXT prompts, e.g. /kaggle/input/**/artifacts/best.pt. Pair "
            "it with --text-prompt-primary; alone, a fine-tuned checkpoint is "
            "loaded and never consulted, which is the V54 failure. Omit for stock."
        ),
    )
    parser.add_argument(
        "--raw-proposal-dump",
        default=None,
        help=(
            "Kaggle path where the notebook writes the union of proposals "
            "BEFORE any filter runs. This is what makes filter work locally "
            "replayable instead of costing a 90-minute GPU run per threshold."
        ),
    )
    parser.add_argument(
        "--sam31-smoke-only",
        action="store_true",
        help="Build a short one-audited-box SAM 3.1 diagnostic notebook without YOLOE proposal generation.",
    )
    parser.add_argument(
        "--proposal-confidence",
        type=float,
        default=None,
        help=(
            "Confidence floor for the proposal lanes (runtime default 0.05). "
            "Lives ABOVE the replay seam, so it needs its own pass. Measured: "
            "chopstick tips sit at 0.01-0.07, so 0.05 cuts most of them, and "
            "the detector validator only requires that class to be DETECTED."
        ),
    )
    parser.add_argument(
        "--text-prompt",
        action="append",
        default=[],
        help=(
            "One text-lane prompt, repeated exactly seven times in FIXED class "
            "order. Only the words handed to the text encoder change; class "
            "identity and order are untouched. Prompts live above the replay "
            "seam, so a variant needs its own proposal pass - this makes that "
            "pass reproducible from one command."
        ),
    )
    parser.add_argument(
        "--image-shard",
        default=None,
        help=(
            "Run only shard K of N images, as K/N. Every shard still loads all "
            "six audited references for calibration; only the TARGET images are "
            "split. With 20 images, 1/7 is roughly 13 minutes instead of 90, so "
            "a preempted free runtime costs one shard rather than the whole pass."
        ),
    )
    parser.add_argument(
        "--colab-drive-cache",
        default=None,
        help=(
            "Folder under Google Drive's MyDrive used to cache the big "
            "read-only inputs (SAM 3.1, the bootstrap checkpoint). Free Colab "
            "reclaims sessions and destroys the VM disk; Drive outlives it, so "
            "a fresh session becomes a copy instead of a multi-GB download."
        ),
    )
    parser.add_argument(
        "--colab",
        action="store_true",
        help=(
            "Prepend a preamble cell that makes a Google Colab VM look like a "
            "Kaggle kernel: creates /kaggle/input and /kaggle/working, pulls the "
            "input bundle and SAM 3.1 with kagglehub, shims kaggle_secrets, and "
            "pins NumPy <2. Every other cell stays byte-identical to the Kaggle "
            "notebook, which is what keeps a Colab run comparable with V55/V56."
        ),
    )
    parser.add_argument(
        "--colab-checkpoint-source",
        default=None,
        help=(
            "Bootstrap checkpoint to download on Colab, as "
            "'owner/kernel:path/inside/output' - Colab cannot mount a Kaggle "
            "kernel output. State the FULL path: Kaggle's flat file listing "
            "hides directory prefixes, so 'artifacts/best.pt' 404s while "
            "'yoloe26x_bootstrap/artifacts/best.pt' returns the file."
        ),
    )
    parser.add_argument(
        "--correction-manifest",
        type=Path,
        default=default_correction_manifest,
        help=(
            "Latest fail-closed human correction audit embedded hash-bound in the notebook. "
            "If a newer review export exists, its review_corrections_current manifest must "
            "be rebuilt before packaging."
        ),
    )
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    repo_root = Path(__file__).resolve().parents[3]
    manifest = build_bundle(
        BundleConfig(
            repo_root=repo_root,
            batch_root=args.batch_root,
            output_dir=args.output_dir,
            dataset_output_dir=args.dataset_output_dir,
            kernel_id=args.kernel_id,
            kernel_title=args.kernel_title,
            dataset_id=args.dataset_id,
            dataset_title=args.dataset_title,
            clean=args.clean,
            sam31_smoke_only=args.sam31_smoke_only,
            correction_manifest=args.correction_manifest,
            text_prompt_primary=args.text_prompt_primary,
            visual_prompt_model=args.visual_prompt_model,
            raw_proposal_dump=args.raw_proposal_dump,
            yoloe_text_checkpoint_glob=args.text_prompt_checkpoint_glob,
            kernel_sources=args.kernel_sources,
            colab=args.colab,
            colab_checkpoint_source=args.colab_checkpoint_source,
            colab_drive_cache=args.colab_drive_cache,
            image_shard=args.image_shard,
            text_prompts=args.text_prompt,
            proposal_confidence=args.proposal_confidence,
        )
    )
    print(json.dumps(manifest, indent=2))


if __name__ == "__main__":
    main()
