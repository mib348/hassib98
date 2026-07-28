# Goal 6: Layered fine-tuning to a production-capable fridge counter

**Status:** active. Supersedes the zero-shot proposal-tuning approach that produced V39–V52.

---

## Why this plan exists

Every count measured up to V52 came from a **stock `yoloe-26x-seg.pt` that has never been
fine-tuned**. `training/sam_annotation_batch/labels/` is empty; no `.pt` or `.onnx` exists anywhere
in the repo. The honest V52 baseline is **2 of 12 scored images exact** (`accuracy 0.1667`).

The dominant failure is not prompt wording. Sauce cups are sold in **nested vertical stacks**, the
reviewer counts every cup, and a model that has never seen these fridges returns **one box for a
stack of six**. Four independent attempts to recover that granularity in post-processing were
measured and all four failed:

| approach | measured result |
|---|---|
| 0.55 height/width ratio estimator | over-counts on 26 of 37 class rows |
| 1-D autocorrelation of cup/tip texture | 54/88/42/124 px vs true pitch 14/15/20/53 |
| 2-D autocorrelation | pinned at ~4 px, the JPEG noise floor |
| colour connected components | blobs median 190 px vs human median 24.8 |
| horizontal rim-line detection | **1% correct** on 72 known single cups |

The only method that ever "worked" was a splitter told the human's answer, which is self-fulfilling
and was rejected twice. **Instance separation has to be learned, not filtered.**

### The circularity, and how it breaks

Training needs approved labels → labels need the reviewer's pass → the >95% gate blocks the review
until the detector is already good → the detector only gets good by training.

It breaks at the one asset already in hand: **242 rectangles the reviewer drew by hand across 6
photos**, unused until now because the trainer wants polygons. A rectangle written as its four
corners is a valid 9-field polygon row, so those become training labels with no GPU and no new
annotation work.

---

## Core principle: the layers are in the LABEL FACTORY, not in the shipped model

SAM 3.1 and image prompts cannot run in the Ubuntu ONNX runtime, and stacking them at inference
would be slow and fragile. **Production is one ONNX file with the seven text prompts baked in.**
Every layer below exists to raise *label quality*, because label quality is what makes the final
model generalise.

| tool | building labels | production `/ai` |
|---|---|---|
| YOLOE text prompts | yes | **yes — the only one** |
| YOLOE image (visual) prompts | yes, recovery lane | no |
| SAM 3.1 | yes, box → traced mask | no |
| the 6 human reference photos | yes, anchor + exemplars | no |

---

## The layers

### Layer 0 — human truth (done)
6 photos, 242 rectangles, 6 distinct capture sites. The only human-verified geometry in the
project. Everything downstream is anchored here and nothing may contradict it.

### Layer 1 — bootstrap fine-tune  *(sequential, must be first)*
Train on the 242 rectangles-as-polygons. Coarse masks, but they teach the single thing the stock
model lacks: **one sauce cup is one object**. No other layer can supply that.

- `training/build_bootstrap_dataset.py` → 230 valid polygons (12 aggregate `Chopstick` boxes
  quarantined, 0 degenerate), all classes present.
- `train_gold_standard_seg.py --bootstrap` — a path that is **mutually exclusive** with the release
  path *by manifest content*, so the 20-image bar cannot be dodged and coarse geometry cannot leak
  into a release run. `MIN_APPROVED_IMAGES = 20` is untouched.
- Output: a non-promotable `best.pt`.

### Layer 2 — three proposers, in PARALLEL, over the 20 images
Now that Layer 1 exists, the lanes fail differently instead of failing identically:

| proposer | strength | weakness |
|---|---|---|
| text prompts (fine-tuned) | all 7 classes, full coverage | weakest on tiny / occluded items |
| image prompts from the 6 refs | best on chopstick tips and repeated small items | needs magnification parity with the target tile |
| SAM 3.1 semantic | finds objects even when no class matches | assigns no class |

Parallelism is the point: agreement across independent lanes is evidence; disagreement flags an
image for attention rather than silently averaging.

### Layer 3 — SAM 3.1 as mask refiner  *(sequential, after Layer 2)*
Every surviving box becomes a traced polygon. This is where Layer 1's coarse rectangles are
replaced by real outlines.

### Layer 4 — arbitration  *(sequential)*
One object, one label. Already implemented and holding:
- `drop_cross_class_duplicate_proposals` + colour arbitration for cup-vs-cup collisions
  (measured 94.3% correct above a 0.30 margin, and deliberately inert below it)
- `filter_fridge_door_reflection_instances` — geometric door-pane detection, 22 TP / 0 FP
- `filter_sauce_cup_stack_kraft_bowls` — trimmed-median ruler, never empties the class
- `detector_validator_agent` — the >95% bar

### Layer 5 — ONE reviewer pass, then the real training
`generate_local_review_page.py --require-detector-validator`, handed over only when the validator
allows handoff. 20/20 pass → `ingest_review_decisions.py` → retrain on 20 **traced** polygons.
Same architecture as Layer 1, far better geometry.

### Layer 6 — bake and ship
`get_text_pe` → `set_classes` → `export(format="onnx", imgsz=1280)` → `sahi_inference.py` →
frozen 8-case count gate (`assertion_pass_rate >= 0.95`) → controlled `/ai` re-enable.

---

## Generalising to "any similar image"

Six photos of six locations will **not** cover the estate. They teach *what a cup is*; they do not
cover every lighting condition, camera distance and fridge layout.

`app.sushi.catering/train-images.zip` becomes usable exactly here — **not** by the reviewer checking
thousands of images, but because a Layer-5 model can auto-label them and surface only its
low-confidence disagreements. That is the active-learning loop the original plan described, and it
cannot start before a checkpoint exists. Running it earlier changes nothing.

---

## Non-negotiables carried forward

- Never use the reviewer's counts to create, size or split geometry. Rejected twice already.
- No dish-name allow-lists, no per-image special cases, no absolute pixel constants (the corpus is
  both 1620×2880 and 3024×4032 — thresholds must be relative).
- Do not lower the 0.95 bar or edit `reviewed_fridge_counts.json`.
- SAM / YOLOE output is proposal-only, never ground truth.
- Training is Kaggle-only, T4 or better, never P100.
- Do not overwrite V39/V46/V48/V50/V51/V52 results directories.
- No `review.html` until the validator allows handoff.
- The reviewer reviews **once**. Any image not clean gets rejected, and one reject blocks training,
  so the proposals must be clean before they are shown.

## Kaggle operational notes

- Pin `machineShape: "NvidiaTeslaT4"`. Without it Kaggle assigned a P100 and V51 v1 died at the
  dependency preflight.
- `kernels/pull` reports `machineShape: Gpu` even when T4 is set — do not trust it. Read the
  Accelerator field in the logged-in browser, or the preflight's `"cuda_device"`.
- The flash-attention "requires Ampere (8.0)" warning appears on T4 too. It is **not** a P100
  signal; only `no kernel image is available` is.
- `kernels/output` returns nothing while a kernel runs; logs publish at terminal state only.
- Do not leave `--batch -1` for this dataset: auto-batch overcommitted the T4 at imgsz 1280 with
  72–73 objects per image and training died in the loss assigner with
  `CUDA error: unknown error`. Pin an explicit small batch.
- **A fine-tuned checkpoint cannot drive the image-prompt lane.** `YOLOEPESegTrainer` is a linear
  probe: it freezes the backbone, trains the prompt-embedding head, and the checkpoint it writes has
  no **SAVPE** module. Handing it to `YOLOEVPSegPredictor` dies with
  `AttributeError: 'YOLOESegment26' object has no attribute 'savpe'` — exactly how V54 v1 failed.
  Give each lane the weights it can use: `--yoloe-model` = fine-tuned (text prompts, where the
  learning lives), `--visual-prompt-model` = stock `yoloe-26x-seg.pt` (the only place SAVPE still
  exists). The flag defaults to `--yoloe-model`, so un-fine-tuned runs are unaffected.
- Attach a checkpoint produced by another notebook via `kernelDataSources`; it lands under
  `/kaggle/input/notebooks/<owner>/<slug>/...`. Fail loudly if it is missing rather than silently
  falling back to stock weights — otherwise a run looks fine-tuned and is not.

## Progress

- [x] **L0** 6 photos / 242 rectangles inventoried
- [x] **L1a** rectangles → 230 valid polygons + bootstrap dataset
- [x] **L1b** mutually-exclusive `--bootstrap` trainer path (12 trainer tests pass)
- [x] **L1c** bootstrap `best.pt` trained on Kaggle T4 (`mib348/v53-bootstrap-train` v2, complete,
      no exceptions). Validation on the 1-image val split: Box P 0.181 / R 0.375 / mAP50 0.213,
      Mask mAP50 0.124. Treat as a bootstrap signal, not a result — mask mAP is near-zero *by
      construction* because Layer 1 trains on rectangles and is scored on tracing outlines.
- [x] **L1c-v2 RETRAIN, full fine-tune.** The first bootstrap used `YOLOEPESegTrainer`, which is a
      LINEAR PROBE: read from the Ultralytics source, it deletes `model.model[-1].savpe` and
      re-enables gradients on exactly three tensors (`cv3[0][2]`, `cv3[1][2]`, `cv3[2][2]` — the
      final class-prediction convs). Backbone, neck, box-regression head and mask head stay frozen,
      and no argument can change it. Instance separation lives entirely in the frozen parts, so that
      recipe could never fix the cup-stack defect. **Measured proof:** the linear-probe checkpoint
      moved cup instances by **+3** against a shortfall of ~147 and changed **zero** images
      pass/fail (V52 0.1667 == V54 0.1667). Passing no `trainer=` lets YOLOE load its task_map
      default `YOLOESegTrainer`, which trains the whole network.

      | metric (same data, same 1-image val split) | linear probe | full fine-tune |
      |---|---|---|
      | Box mAP50 | 0.213 | **0.546** |
      | Box recall | 0.375 | **0.613** |
      | Mask mAP50 | 0.124 | **0.421** |

      Recall is the relevant axis — it is literally "how many of the real objects were found".
      Checkpoint metadata now records `trainable_scope` so a reader can tell the two apart.
- [x] **L2 THE FINE-TUNED LANE WAS NEVER BEING CONSULTED.** Two Kaggle runs (~3h) were spent on a
      checkpoint the pipeline never asked for a proposal. `text_only_proposal_count` was **0 on all
      twenty images** in V54: the weights loaded, SHA-verified, and were ignored. All 583 proposals
      came from SAM 3.1 (505+128+6) and the stock-weight image-prompt lane (166). The text lane was
      built as a last-resort fallback — off by default, and even when on, only offered to images
      where the visual lane returned <= 2 boxes. Correct caution for a stock checkpoint; wrong once
      the checkpoint has seen this inventory. `--text-prompt-primary` runs it on every target image.

      **Result (V55, `mib348/v55-text-primary`):**

      | | V52 stock | V55 text-primary |
      |---|---|---|
      | gate accuracy | 0.1667 (2/12) | **0.5000 (6/12)** |
      | kraft OCR consistency | 0.70 | **0.75** |
      | cup instances (human 147) | 233 | **445** |
      | `text_only` proposals | 0 | **1713** |

      Gained: mb-energy, saco, searenergy, stroeer. Counts landed ON the human numbers, not near
      them — mb-energy 23=23, stroeer 16=16, searenergy 8=8. zeisehof went 3 -> 11 against a human
      12: that is the cup-stack separation five post-processing attempts could never produce.

      **Two guards had to learn about the new mode, and both were good guards.** `build_run_manifest`
      and the notebook provenance audit each refused to emit text-prompt records unless a text mode
      was explicitly declared. Widened to accept `text_prompt_primary`, and INVERTED in that mode:
      if it is set and no image carries text provenance, the run now FAILS. The exact bug that cost
      two runs is now a hard error instead of a clean-looking artifact built entirely by SAM 3.1.
- [ ] **L2b** close the remaining 6 — *in progress*

      | image | remaining gap |
      |---|---|
      | garbe | wayo 4 v 7 |
      | mega-eg | chopstick tips 0 v 28 |
      | startup-labs | black soya 1 v 3 |
      | statista | soya 7v12, kraft 19v22, chili 7v8, teriyaki 6v10, kraft OCR 18v19 |
      | techhub | soya 1v2, chili 1v2, tips 0 v 13 |
      | zeisehof | soya 4 v 5, kraft OCR 0 v 9 |

      Chopstick tips are still 0 on mega-eg and techhub — the text lane did not rescue class 5.
      Note also that unscored images gained a lot (fischerappelt 17->52, mutabor 26->91,
      no-limits 10->57): watch for over-detection when the sheets are reviewed.
- [ ] **L3/L4** refine + arbitrate, then run the detector validator
- [ ] **L5** single reviewer pass → retrain on traced polygons
- [ ] **L6** ONNX export → SAHI → frozen gate → `/ai`


---

## STATE AT GPU QUOTA EXHAUSTION (2026-07-28)

`Maximum weekly GPU quota of 30.00 hours reached.` No further Kaggle runs until it resets.

### Honest scoreboard

Best run is **V55** (`mib348/v55-text-primary`): gate **0.5000 (6/12)**, kraft OCR 0.75.
V56 regressed to 5/12 and both of its changes were reverted.

**But 6/12 overstates the detector.** Breaking down the passes:

| image | reference? | classes scored | text proposals | geometry from |
|---|---|---:|---:|---|
| barmbek | YES | 1 | 0 | the reviewer's own 72 rectangles |
| byteclub | YES | 1 | 0 | the reviewer's own 73 rectangles |
| mb-energy | no | 5 | 234 | detector |
| saco | no | 3 | 103 | detector |
| searenergy | no | 4 | 78 | detector |
| stroeer | no | 4 | 116 | detector |

barmbek and byteclub pass because human labels match human counts, on ONE class each.
**The real detector record is 4 of 10.**

### What the reviewer found by eye that no metric caught

Looking at two contact sheets exposed defects six Kaggle runs of metrics missed, because they
live in the count-tolerant classes that nothing was checking:

1. **Packet class outlines furniture.** 44 boxes on stroeer, 59 on mutabor — location signs,
   QR-code signs, shelf rails, the metal tray, reflective panels, the temperature display.
   stroeer was reported PASS. FIXED: `filter_implausible_packet_proposals` (size 3.94-15.41% of
   width, aspect 0.38-2.13, both from the reviewer's own 20 packet rectangles) removes 251 of 493
   (no-limits 75->18, mutabor 59->28, statista 20->4).
2. **Adjacent fridge counted through the door glass.** OPEN — see below.
3. **Kraft bowls double-outlined blue+purple** (bowl and packet on the same object). OPEN.

### OPEN DEFECT: adjacent cabinet is not a "pane"

`detect_fridge_door_pane_indices` was built for a MIRRORED column separated from the shelf by the
dark door frame. mutabor and no-limits show something different: a neighbouring cabinet that ABUTS,
with boxes running continuously to the frame edge. Measured, per rule:

| image | group <=20% | corridor gap >=0.12 | no x-overlap | detail <0.90 |
|---|---|---|---|---|
| mutabor | pass | **0.044 FAIL** | **FAIL** | 0.446 pass |
| no-limits | pass | **0.063 FAIL** | **FAIL** | 0.990 **FAIL** |

The corridor test is what blocks both, and the detail test additionally fails on no-limits because a
directly-visible neighbouring fridge is SHARP, not veiled by glass. 13 boxes on each image sit in the
far-right 18% of the frame and none are caught. A fix needs a different signal — locating the
cabinet's own frame boundary in the image rather than inferring it from the box distribution.
NOT attempted without a GPU to verify; guessing here is what made V56 cost four hours.

### The seam that should have existed from the start

`--raw-proposal-dump DIR` now persists the proposal union at the exact line where GPU work ends
(`assisted_label_review.py`, immediately before the reflection filter), and
`training/replay_proposal_filters.py` replays every downstream stage locally using the same shipped
functions. Cost profile of a run:

| stage | cost | needs GPU |
|---|---|---|
| deps + checkpoint | ~60 s | no |
| proposal generation | **~4.5 min x 20 images** | **yes** |
| reflection / kraft / packet / arbitration | ms | no |
| trim + validator + sheets | s | no |

Almost every change made in this session lives BELOW the seam and never needed a GPU run to
evaluate. The next run must be launched with `--raw-proposal-dump`; after that, filter and threshold
work is seconds instead of hours.

### GPU spent on avoidable mistakes (~8 of 30 hours)

| run | cost | what went wrong |
|---|---|---|
| V54 v1+v2 | ~3 h | ran a checkpoint the pipeline never consulted; `text_only_proposal_count = 0` was already visible in a downloaded manifest |
| V56 | ~4 h | 480 px tiles: regressed 6/12 -> 5/12, and the tile count was mis-estimated 6.5x |
| failed starts | ~1 h | P100 (no `machineShape`), two provenance guards, one wrong slug |

### Next run, when quota returns

One run, launched with `--text-prompt-primary --visual-prompt-model <stock> --raw-proposal-dump`,
carrying: 960 px tiles (reverted), colour margin 0.30 (reverted), the packet plausibility filter
(new). Then all remaining filter work is local.


### Verified while GPU-blocked (2026-07-28)

**Packet filter causes no regression.** Applied to V55 geometry: 250 packet boxes removed, gate
stays 0.5000 (6/12), no passing image lost. Pure label-quality gain — 250 boxes of signage, shelf
rails and temperature displays that would otherwise have become training labels.

**Replay harness works end to end.** `training/replay_proposal_filters.py` was exercised against a
synthetic dump rebuilt from V55 polygons and completed in seconds. It reported 4/12 rather than
6/12, and that discrepancy is the point: the synthetic dump holds POST-filter boxes, so replaying
runs them through the filters twice (stroeer visibly loses black soya 4 -> 2 to a second
cross-class pass). That confirms the dump must be taken at the seam, before any filter, which is
where `--raw-proposal-dump` writes it. The tool is validated; it just needs real input.

**Why no local path to 12/12 exists.** Every filter only ever REMOVES boxes, and all six remaining
failures are SHORTFALLS — garbe needs 3 more wayo, statista 5 more soya and 3 more kraft, techhub
needs a second soya and a second chili, mega-eg and techhub need any chopstick tip at all. Nothing
downstream can create a detection that was never made. This is measured, not assumed.

### Layer status, precisely

| layer | state |
|---|---|
| L0, L1a, L1b, L1c | complete |
| L2 | partial — 4 of 10 detector images pass; 6 fail on shortfalls |
| L3 (SAM 3.1 refinement) | EXECUTES every run and produces refined polygons; not a missing stage |
| L4 (arbitration) | EXECUTES every run; validator runs; blocked by L2 quality, not unimplemented |
| L5 | correctly gate-blocked at 0.5000; generator and gate both smoke-tested |
| L6 | requires L5 |

L3 and L4 are not "unrun" — they run inside every Kaggle pass and their records are in the V55
manifest. What is missing is a PASSING result from them, which is an L2 detector-quality problem.
