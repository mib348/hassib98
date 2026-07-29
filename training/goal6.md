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
2. **Adjacent fridge counted through the door glass.** FIXED (2026-07-28) — see below.
3. **Kraft bowls double-outlined blue+purple** (bowl and packet on the same object). FIXED (2026-07-28) — see below.

### FIXED: adjacent cabinet is not a "pane" (was OPEN)

Resolved without a GPU, because both defects are FILTERS — they live below the seam, so
`replay_proposal_filters.py` verifies them against polygons already on disk.

**The signal.** Two cabinets standing side by side meet at their frame posts, forming a dark vertical
band. `locate_adjacent_cabinet_frame_post` finds that band in the image rather than inferring it from
how boxes happen to be spread out. Each column is compared against the image's **own** median column,
which is what makes one threshold hold across a bright showroom and a dim basement kitchen — an
absolute brightness cut would fire everywhere in one and nowhere in the other.

| image | darkest column in 0.75–0.97 | vs own median | boxes removed |
|---|---|---|---|
| mutabor | x=0.841 | 0.53x | 12 |
| no-limits | x=0.900 | 0.33x | 8 |
| zeisehof / techhub / stroeer / startup-labs | 0.786–0.910 | 0.48–0.53x | 1–2 each |
| **saco** (5 GENUINE right-edge items, no neighbour) | — | **0.99x → no post** | **0** |

saco is the control: a blanket "right-edge" rule would have destroyed it, and saco is a *passing*
image. Two refusals to act are built in — no post found → keep everything; a cut that would remove
more than 25% of the frame → keep everything, since a post that deep was mis-located.

### FIXED: kraft bowl double-outlined as a packet (was OPEN)

A bowl's printed dish label is a white sticker with black text; so is a soya sachet. 160 of 473 packet
boxes were the bowl's own label outlined a second time (up to 55% on one image).

The reviewer's own rectangles decide whether that configuration is ever real: **0 of 20 human packet
rectangles fall inside a kraft bowl.** Sachets are stocked beside the bowls, never on them — so
containment is always the sticker, not a near-miss needing arbitration. IoU-based dedup could never
have caught this: a small packet box *inside* a large bowl box has low IoU but ~1.0 containment.

**Both filters are gate-neutral: 185 boxes removed, no image changes pass/fail.** Verified with a
control run — an apparent 6/12→4/12 drop turned out to be the known artifact of replaying
already-filtered polygons (the control measured 4/12 too), not a regression from the new code.

### Historical: why the pane rule missed it

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

### CORRECTION (2026-07-28, later): recall is NOT the blocker on most images

The claim below — that the only thing left is recall, and that it needs a GPU — is **wrong**, and it
was wrong because nobody had looked at the pre-filter union. `assisted_review_quarantine/vp_predictions/*.json`
carries `instances`: the union of all three lanes, before any filter. It has been on disk since V55.

Comparing that union against the human counts, **8 of 12 shortfalls were proposed and then lost
downstream**:

| image | class | human | final | PRE-FILTER union | verdict |
|---|---|---|---|---|---|
| statista | red teriyaki | 10 | 6 | **30** | proposed, lost |
| statista | black soya | 12 | 7 | 18 | proposed, lost |
| statista | orange chili | 8 | 7 | 11 | proposed, lost |
| garbe | white wayo | 7 | 4 | 13 | proposed, lost |
| zeisehof | black soya | 5 | 4 | 14 | proposed, lost |
| techhub | black soya / chili | 2 / 2 | 1 / 1 | 6 / 5 | proposed, lost |
| startup-labs | black soya | 3 | 1 | 8 | proposed, lost |
| mega-eg, techhub, startup-labs | chopstick tip | 28/13/13 | 0/0/1 | **0/0/1** | genuinely never proposed |
| statista | kraft bowl | 22 | 19 | 11 | genuinely under-proposed |

Replaying the union through the real filter chain then locates the loss in
`drop_cross_class_duplicate_proposals`:

| image | class | pre-dedup | post-dedup | human |
|---|---|---|---|---|
| statista | black soya | 18 | 6 | 12 |
| statista | orange chili | 10 | 6 | 8 |
| garbe | white wayo | 13 | 5 | 7 |
| techhub | orange chili | 5 | 1 | 2 |
| **zeisehof** | — | — | — | **nothing short after the full chain** |

zeisehof has enough of every class after every filter, yet it failed V55 — so a second loss exists
between that point and the final polygons (SAM 3.1 refinement or arbitration), not yet isolated.

**Scope note, learned the hard way in this same investigation:** a first trace also ran
`filter_by_reference_support` over the union and appeared to show it annihilating cups (31→5, 18→0).
That was invalid — the function is applied *inside the visual lane* (line 3983) and to the audited
recovery lane (4177), never to the union; the text lane never passes through it. Verify the call site
before attributing a loss to a stage.

**Why no fix is applied here.** `drop_cross_class_duplicate_proposals` exists to close the trimmer
loophole, where duplicate boxes wearing a second class label produced fake exact matches. Loosening
it to recover cups could re-open that hole, which is worse than a failing image. The fix needs the
per-drop arbitration reasons examined case by case — real work, but **local work**, needing no GPU.

So the corrected blocker is: **chopstick-tip recall on 3 images genuinely needs the GPU; the sauce-cup
shortfalls on the rest do not.**

### The arbitration work, performed — and its negative result (2026-07-28)

The correction above pointed at `drop_cross_class_duplicate_proposals`. Doing the work changed the
diagnosis a second time. **The dedup is not deleting objects.** Counting cup OBJECTS after the full
chain, against the human cup total:

| image | cup objects after dedup | human cup total | per class (found/human) |
|---|---|---|---|
| garbe | 23 | 10 | black 12/1, teriyaki 3/2, wayo 5/7, chili 3/0 |
| zeisehof | 18 | 12 | black 8/5, teriyaki 5/3, wayo 3/2, chili 2/2 |
| statista | 31 | 38 | black 6/12, teriyaki 10/10, wayo 9/8, chili 6/8 |
| startup-labs | 10 | 8 | black 3/3, teriyaki 3/2, wayo 2/1, chili 2/2 |
| techhub | 7 | 6 | black 2/2, teriyaki 0/0, wayo 4/2, chili 1/2 |

Four of five have *more* cup objects than the reviewer counted. The cups are found. They are wearing
the **wrong colour class** — garbe proposes 12 black soya where 1 exists. This is class confusion at
the proposal source, not loss in arbitration.

Three local levers were then tested against the pre-filter union (total cup class error, summed
|found - human| over classes 1-4, and the gate):

| change | cup class error | gate |
|---|---|---|
| baseline, `SAUCE_CUP_COLOR_DECISIVE_MARGIN = 0.30` | **392** | 5/10 |
| margin 0.10 | 398 | 4/10 |
| margin 0.05 | 396 | 4/10 |
| margin 0.00 (colour always decides collisions) | 396 | 4/10 |
| colour-relabel EVERY cup box, not just collisions | **921** | 5/10 |

All negative. 0.30 is the best value on the sweep, which independently re-confirms the earlier revert
of 0.05. The measured colour reference is strong enough to break a tie between two boxes on one
object, and far too weak to classify a cup on its own — relabelling everything more than doubles the
error.

**Conclusion.** The local levers are exhausted. Cup colour confusion lives in the text-prompt
embeddings, so it is fixed by prompts or fine-tuning, both of which need the GPU. This is a negative
result, not an unattempted task: the arbitration was examined, three candidate fixes were measured,
and none of them is worth shipping.

### zeisehof isolated (2026-07-28) — the second loss is one class, and N1 may have fixed it

The correction above noted zeisehof is short of nothing after the full chain yet still failed V55,
and that a second loss existed somewhere downstream. Isolated:

| class | human | replay from union (current filters) | V55 shipped |
|---|---|---|---|
| kraft paper bowl | 9 | 9 | 9 |
| **black soya cup** | 5 | **8** | **4** |
| red teriyaki | 3 | 5 | 3 |
| white wayo | 2 | 3 | 2 |
| orange chili | 2 | 2 | 2 |

Exactly one class loses boxes. The likely mechanism is N1 itself: the adjacent-cabinet filter removes
2 boxes at zeisehof's frame post (x=0.786), and removing them BEFORE the cross-class dedup changes
which boxes win arbitration — so the soya cups that V55 lost now survive.

NOT claimed as a gate improvement. SAM 3.1 refinement (L3) runs between the replay point and the
shipped polygons, so the union replay cannot prove what the real pipeline will produce. V57 is the
measurement that settles it, and its raw dump will make this comparison exact rather than inferred.

### L6 CPU dry run — 3 of 4 links proven, the 4th does not exist (2026-07-28)

The whole L6 chain was exercised locally on CPU, with no GPU and nothing promoted. All artifacts went
to a scratch directory; `/ai` was not touched.

| link | result |
|---|---|
| `export_text_prompts.py --device cpu` | **works** — 252 MB ONNX, embeddings `.npy`, manifest with all 7 prompts |
| `sahi_inference.py --provider cpu` | **works** — `status: success`, 9 detections on statista, per-class counts emitted |
| frozen 8-case count gate | **was missing — now built and proven** (`run_frozen_count_gate.py`) |

The gate was written, wired to the reviewer's own counts (8 cases, 42 assertions from the V48
corrections), and executed against the exported ONNX:

    assertion_pass_rate=0.2143 (9/42)  cases=0/8  gate_opened=False

That is the correct result. The drill used STOCK weights, so a passing gate would have meant the
gate was broken. It refuses to open, exits non-zero, and prints per-assertion diagnostics
(`red teriyaki sauce cup: expected 10, got 1`) rather than a bare verdict.

One result carries past the drill: `wooden chopstick tip: expected 13, got 0` on techhub. The
chopstick recall failure measured in the label factory reproduces in the SHIPPED inference path,
confirming it is a model problem and not an artifact of the proposal lanes.
| `/ai` re-enable | correctly still gated |

Local dependency versions match the Kaggle pins exactly (ultralytics 8.4.93, onnx 1.22, onnxruntime
1.27, sahi 0.12.1), so a CPU export here is a faithful rehearsal of the release export.

**The finding that matters:** L6's final acceptance gate was never written. Every plan revision has
listed "frozen 8-case count gate (assertion_pass_rate >= 0.95)" as the last step before `/ai`, and
there is no code behind it. Discovering that after training the release model would have stalled the
ship at the final step; it is now a known, GPU-free task that can be built and tested against the
existing SAHI output format before quota returns.

Note: the drill used stock `yoloe-26x-seg.pt`, not the bootstrap checkpoint — two `best.pt` files
exist in the v53-bootstrap-train output but both `artifacts/best.pt` and
`runs/segment/train/weights/best.pt` return 404 from the download API, and the flat file listing hides
the true prefix. Architecture is identical, so the chain validation holds; only the weights differ.

### The exact V57 build command (recorded so it is reproducible)

The bundle lives in a scratch directory and is therefore disposable; this command is the durable
part. Run it from the repo root. `MSYS_NO_PATHCONV=1` is required under Git Bash, which otherwise
rewrites `/kaggle/working/...` into `C:/Program Files/Git/kaggle/...` and silently produces a bundle
that cannot run.

```
MSYS_NO_PATHCONV=1 .venv/Scripts/python.exe   training/autoresearch/kaggle_label_factory/prepare_kaggle_assisted_label_bundle.py   --output-dir <scratch>/v57_bundle --dataset-output-dir <scratch>/v57_dataset   --kernel-id mib348/v57-raw-dump --kernel-title "V57 raw dump"   --correction-manifest training/autoresearch/results/yoloe26x_sam31_assisted_review_kaggle_v48_20260726/review_corrections_current/review_correction_manifest.json   --text-prompt-primary   --kernel-source mib348/v53-bootstrap-train   --text-prompt-checkpoint-glob "/kaggle/input/**/artifacts/best.pt"   --visual-prompt-model /kaggle/working/yoloe-26x-seg.pt   --raw-proposal-dump /kaggle/working/assisted_review_quarantine/raw_proposal_dump   --clean
```

**Unresolved before the push:** the checkpoint glob above is unverified. Two `best.pt` files exist in
the `v53-bootstrap-train` output, but the download API returns 404 for both `artifacts/best.pt` and
`runs/segment/train/weights/best.pt`, and the flat file listing hides the true prefix. The notebook
now fails loudly rather than falling back to stock weights, so a wrong glob costs a push, not a
silently wrong result.

### Superseded: "the single blocker" (kept for the record)

Every layer that can be advanced without a GPU has been advanced. What remains is not unimplemented
code, an undecided design, or an unwritten test — it is **recall**: the detector does not yet find
enough real objects on 6 of the 10 scored images, and no filter, threshold or scoring change can
create a detection that was never made.

Recall improves only by running the model, which needs a T4. Quota is spent:

    used 117,693s of 108,000s allowed — refreshes 2026-08-01T00:00:00Z

So L2 (quality), and therefore L3/L4 (a passing result), L5 (the single review) and L6 (bake and
ship) are date-blocked, not work-blocked. **V57 is staged and is one push when quota returns**, with
the current runtime hash embedded and its run mode recorded in the manifest instead of hand-edited
into the notebook.

One item to confirm at push time: the bootstrap checkpoint must be reachable at
`/kaggle/input/**/artifacts/best.pt`. V57's `dataset_sources` currently lists only the inputs
dataset. The notebook now aborts with a clear message if the glob matches nothing, so this cannot
silently degrade a run into a stock-weights run — but the checkpoint dataset may need attaching.

### C2 — V57 ported to Google Colab (2026-07-28)

Kaggle's GPU quota is spent until 2026-08-01, so V57 runs on a free Colab T4. The port does **not**
rewrite the notebook. The builder gained `--colab`, which *prepends one preamble cell* and leaves
every existing cell byte-identical, so a Colab result stays directly comparable with V55/V56. The
manifest records `execution_platform`, and `--image-shard K/N` is now a builder flag rather than a
hand-edit, so each shard is reproducible from a command.

#### The bootstrap checkpoint was never unreachable — the listing hid the prefix

The previous session concluded `best.pt` "404s from Kaggle's download API" and recommended a
~40-minute retrain. That was wrong, and the retrain is **not needed**. `GET /api/v1/kernels/output`
returns `fileName` values *with* directories, and the real paths are:

    yoloe26x_bootstrap/artifacts/best.pt                                  <- 200, 171,641,721 bytes
    yoloe26x_bootstrap/runs/yoloe_26x_seg_gold_standard/weights/best.pt
    artifacts/best.pt                                                     <- 404 (the old guess)

Two mistakes compounded: the flat file listing (and the MCP `list_notebook_files` view, which also
reports nonsense sizes like 677 bytes for a 171 MB checkpoint) hides the `yoloe26x_bootstrap/`
prefix; and the second copy was guessed as ultralytics' default `runs/segment/train/`, when the
project uses a custom run name. **Always resolve the full path from the JSON listing endpoint before
concluding a file is missing.**

#### The measured Colab envelope, and the four things that actually differ

| | Kaggle (V55/V56) | Colab free |
|---|---|---|
| GPU | T4 16 GB | T4 15 GB (15360 MiB) |
| RAM | ~30 GB | **13.6 GB** |
| disk free | — | 70.8 GB |
| NumPy | <2 | 2.0.2 |

1. `/kaggle/input` and `/kaggle/working` do not exist — created (Colab is uid 0).
2. Inputs are not mounted — pulled with kagglehub inside the runtime.
3. `kaggle_secrets` does not exist, and a cell imports it at module level — shimmed onto Colab
   `userdata`.
4. The base image ships NumPy 2.x — pinned to 1.26.4. Verified on a real runtime: a fresh process
   then imports `1.26.4 2.11.0+cu128 0.26.0+cu128 4.13.0 True`, i.e. NumPy <2 with a working CUDA
   torch. pip warns that jax/cupy/rasterio/opencv-contrib want NumPy >=2; none is in this pipeline.

#### Trap: /kaggle/input is a read-only mount on Colab

Colab ships its own Kaggle-compatibility shim and bind-mounts `/dev/sda1` at `/kaggle/input`
**read-only and noexec**. `/kaggle` and `/kaggle/working` are ordinary writable directories, so a
`/kaggle/working` write test passes and hides the problem — staging inputs then dies with
`OSError: [Errno 30] Read-only file system`. Colab runs as uid 0, so the preamble simply
`umount`s it (verified rc=0, writes succeed afterwards) and recreates it as a normal directory.

#### Credential delivery

SAM 3.1 needs a credential on both routes — HF `facebook/sam3.1` is gated (401 `GatedRepo`) and the
unauthenticated Kaggle model download 404s. kagglehub resolves `~/.kaggle/access_token`, so the
token is delivered by **uploading that file** to Colab session storage; the secret value never has
to be pasted into a cell. The Kaggle API keeps working with GPU quota exhausted — only GPU
*sessions* are blocked, so dataset/model/kernel-output downloads are unaffected.

#### Reproducible build command (Colab, shard 1 of 7)

Run from the repo root. `MSYS_NO_PATHCONV=1` stops Git Bash rewriting `/kaggle/...`, but note it
also stops `/c/...` being translated, so pass **Windows-style** output paths or the bundle lands in
`C:\c\...`.

```
MSYS_NO_PATHCONV=1 .venv/Scripts/python.exe training/autoresearch/kaggle_label_factory/prepare_kaggle_assisted_label_bundle.py   --output-dir <scratch>/v57_colab_shard1 --dataset-output-dir <scratch>/v57_dataset   --kernel-id mib348/v57-raw-dump --kernel-title "V57 raw dump"   --correction-manifest training/autoresearch/results/yoloe26x_sam31_assisted_review_kaggle_v48_20260726/review_corrections_current/review_correction_manifest.json   --text-prompt-primary   --kernel-source mib348/v53-bootstrap-train   --text-prompt-checkpoint-glob "/kaggle/input/**/artifacts/best.pt"   --visual-prompt-model /kaggle/working/yoloe-26x-seg.pt   --raw-proposal-dump /kaggle/working/assisted_review_quarantine/raw_proposal_dump   --colab   --colab-checkpoint-source "mib348/v53-bootstrap-train:yoloe26x_bootstrap/artifacts/best.pt"   --image-shard 1/7   --clean
```

Driving Colab: the UI uses closed shadow roots, so `document.querySelector` cannot reach the
toolbar, but `window.monaco.editor.getEditors()[i].getModel().setValue(...)` and
`window.colab.global.notebook.cells[i].manualExecute()` work, and `cell.lastExecutionError` carries
the real traceback. Free tier allows **one** GPU session, and modal dialogs silently block queued
executions.

### L6 exercised end-to-end on the BOOTSTRAP checkpoint (2026-07-29)

The L6 chain had only ever been run against stock `yoloe-26x-seg.pt`. With the bootstrap checkpoint
now recovered locally (171,641,721 bytes, sha256 `db607d7b12c1808969f7c565…`), the whole chain was
run against **real fine-tuned weights** for the first time, on CPU. Nothing was promoted and `/ai`
was not touched.

| link | result |
|---|---|
| `export_text_prompts.py --device cpu` | **works** — ONNX + manifest in 38.4s, all 7 prompts bound |
| `run_frozen_count_gate.py --provider cpu` | **works** — 8 cases, 42 assertions, scored end to end |

    assertion_pass_rate=0.1905 (8/42)  cases=0/8  gate_opened=False

**The bootstrap checkpoint is not better than stock on the shipped path — it is slightly worse.**
Stock scored 0.2143 (9/42) in the earlier drill; the bootstrap checkpoint scores 0.1905 (8/42) on
the same 42 assertions. That is not a contradiction of Layer 1's purpose (it teaches "one cup is one
object" from coarse rectangles, and is non-promotable by construction), but it does settle a
question that was open: **shipping the bootstrap checkpoint is not an option, and no amount of L6
work changes that.** L6 completion requires the L5 release model.

Two failures worth carrying forward:

- `kraft paper bowl: expected 22, got 0` (statista) and `expected 9, got 0` (zeisehof) — the
  bootstrap model finds **no** kraft bowls on some images through the shipped path.
- `wooden chopstick tip: expected 13, got 0` reproduces on both startup-labs and techhub, which is
  the same chopstick recall failure already measured in the label factory and in the earlier stock
  drill. It is a model problem, not a proposal-lane artifact, and it survives export.

The gate did exactly what it exists for: it refused. The 0.95 bar was not touched, and
`reviewed_fridge_counts.json` was not edited.

Caution when reading gate exit codes from a shell: piping the command (`... | tail`) makes `$?` the
exit status of `tail`, so `EXIT=0` can appear next to `gate_opened=False`. Read the printed verdict
or the JSON report, not the piped exit code.

### L5 handoff gate verified, and the V56 baseline V57 has to beat (2026-07-29)

`generate_local_review_page.py --require-detector-validator` was run against the real V56
quarantine artifact. It refused, and — the part that matters — **it wrote no `review.html` at all**;
the output directory was left empty. The hard rule ("no review page until the detector validator
allows handoff") is enforced in code, not just in the plan.

    Detector validator BLOCKS human handoff: required-item count accuracy 0.4167 is not > 0.95
    (scored=12, passed=5, failed=7, package_complete=True,
     kraft_ocr_consistency=0.7500 over 20 images, kraft_ocr_gate_passed=False)

This is the number V57 has to move: **0.4167 → >0.95**, i.e. 5 of 12 scored images passing today
against 12 of 12 required. `package_complete=True` confirms the blocker is detector QUALITY, not a
missing artifact — which is the same conclusion the earlier arbitration work reached from the other
direction. A second, independent gate is also failing: `kraft_ocr_gate_passed=False` at 0.7500
consistency over the twenty images.

Two things follow. First, L5 is genuinely blocked by L2 quality and nothing else, so there is no L5
work to do until a better proposal pass exists. Second, the gap is large: this is not a case where
a marginally better pass tips it over. Note also that the earlier "4 of 10 detector images pass"
figure is superseded — the validator scores 12 images, not 10.

Nothing was promoted, no review page was produced, and the >0.95 bar was not touched.

### The local replay path is proven, and it is the real unlock (2026-07-29)

`replay_proposal_filters.py` was written last session and, like the raw dump and `--image-shard`,
had never been executed — because V57 had never run, so no dump existed to replay. Both of the
other two turned out to be broken. This one was smoke-tested against a schema-exact synthetic dump
built from the real twenty images, and **it works**:

    accuracy=0.0833 scored=12 passed=1 handoff=False

The numbers are meaningless as quality (the boxes are synthetic, one per class). What matters is
the plumbing: it read all twenty dump files, ran the filter stages, and scored **12 images** —
the same `scored=12` the detector validator reports on a real artifact, which confirms the replay
and the gate share a scoring path rather than approximating each other.

**Why this matters more than the V57 numbers themselves.** Once a raw dump exists, every downstream
question — thresholds, size bounds, arbitration rules, scoring policy — is answerable locally in
seconds with no GPU. That is what makes the remaining work tractable on any schedule, and it
survives a reclaimed Colab session, because the dump is a file and not a runtime.

It does NOT cover anything above the seam: prompts, checkpoints, tiling and confidence floors still
need a GPU pass. Since the measured blocker is cup colour confusion in the text-prompt embeddings —
a MODEL problem — the replay path cannot fix the 0.4167, but it can prove cheaply that no filter
change will either.

### The prepared next experiment: separate the PROMPT from the CLASS IDENTITY (2026-07-29)

The measured blocker is cup colour confusion in the text-prompt embeddings, and `goal6.md` already
records that this is fixed by prompts or fine-tuning, not by filters. This is the concrete
experiment, written down so it is one run rather than a fresh investigation.

**The mechanism to test.** The four cup prompts are

    "black soya sauce cup"   "red teriyaki sauce cup"   "white wayo dip cup"   "orange chili mayo cup"

They differ by a colour adjective plus a sauce name, and the sauce name is **not visually
observable** — it is at best printed on a small sticker. Three of the four words in each phrase are
therefore either shared ("sauce cup") or unreadable at this resolution, which leaves one adjective
carrying the entire discriminative burden. That is a plausible mechanism for garbe proposing 12
black soya cups where 1 exists.

**Candidate prompt sets** (class ORDER must not change — the ids are load-bearing):

| variant | cup prompts |
|---|---|
| A — lid made explicit | `sauce cup with a black lid`, `... red lid`, `... white lid`, `... orange lid` |
| B — drop the unobservable noun | `black cup`, `red cup`, `white cup`, `orange cup` |
| C — lid only | `black lid`, `red lid`, `white lid`, `orange lid` |

**The safety constraint, and why it is easy to satisfy.** `FIXED_CLASS_NAMES` is class IDENTITY —
correction manifests and per-class thresholds key on those strings, and
`FIXED_CLASS_NAMES.index(...)` is used for the packet and chopstick classes. It must NOT change.
The reviewer's frozen counts use a *different* vocabulary again (`sojasauce_cup`,
`teriyakisauce_cup`, ids 0-5), so the reviewer's file is already decoupled and does not need
touching either — which matters, because editing it is forbidden.

So the change is: add a separate prompt list used ONLY where the embedding is built
(`get_text_pe` / `set_classes` in `export_text_prompts.py`, and the text lane in the runtime),
defaulting to `FIXED_CLASS_NAMES` so existing behaviour is bit-identical unless a variant is
selected. Identity stays put; only the words handed to the text encoder move.

**How to evaluate cheaply.** Prompts live ABOVE the replay seam, so `replay_proposal_filters.py`
cannot score them — each variant needs its own proposal pass. But the frozen count gate does NOT:
`export_text_prompts.py` + `run_frozen_count_gate.py` score a variant end to end on CPU in about
two minutes (measured: 38.4s export, gate a few minutes). That is the fast screen. Run the full
label-factory pass only for a variant that moves the gate off 0.1905.

### The frozen count gate was scoring its own confidence floor (2026-07-29)

The gate shells out to `sahi_inference.py` and passed no thresholds, so every run used that script's
default confidence floor of **0.25**. On the bootstrap checkpoint that floor is destructive, not
conservative. Summed over the eight frozen cases:

| class | expected | detected @0.25 |
|---|---|---|
| black and white soya sauce packet | 36 | 47 |
| black soya sauce cup | 36 | 38 |
| **kraft paper bowl** | **31** | **0** |
| orange chili mayo cup | 25 | 30 |
| red teriyaki sauce cup | 29 | 38 |
| white wayo dip cup | 31 | 18 |
| **wooden chopstick tip** | **62** | **0** |

Two entire classes — 93 of 250 expected objects, 37% of the bar — returned **exactly zero**, while
every other class sat within ~30% of truth. Kraft bowls are the largest objects in frame, so zero of
31 is not a detector failing to see them.

Confirmed by re-running one image at two floors. zeisehof, human truth kraft 9 / soya 5 / teriyaki 3
/ wayo 2 / chili 2:

| floor | kraft | soya | teriyaki | wayo | chili | total |
|---|---|---|---|---|---|---|
| 0.25 | 0 | 0 | 1 | 1 | 0 | 9 |
| 0.20 | 1 | 1 | 2 | 1 | 1 | 17 |
| 0.15 | 1 | 2 | 5 | 1 | 5 | 31 |
| 0.10 | 2 | 6 | 5 | 1 | 5 | 46 |
| 0.05 | 4 | 14 | 15 | 3 | 6 | 89 |

The objects are decoded and then discarded. `--confidence` and `--class-thresholds` now pass
through the gate and **both are recorded in the report**, because a pass rate without its operating
point is not a measurement. The 0.95 bar is deliberately NOT exposed as a flag: tuning where the
model operates is legitimate, moving the bar is not.

**Methodological consequence.** The prompt screen was run at this floor, where two of seven classes
are identically zero for every variant — which strips the comparison of much of its discriminative
power. Any variant verdict measured at 0.25 is weaker evidence than it looks.

**Cheaper method for any future sweep.** `sahi_inference.py` emits every detection with its
confidence, so one run per image at floor 0.01 permits scoring *any* threshold offline in
milliseconds, instead of one full inference pass per candidate threshold.

### The prompt experiment: a clean negative, and the threshold ceiling that settles L6 (2026-07-29)

**Prompt variants, scored by the documented CPU screen** (bootstrap checkpoint, 8 frozen cases):

| variant | cup prompts | assertion_pass_rate |
|---|---|---|
| baseline | shipped wording | **0.1905** (8/42) |
| lid | `sauce cup with a black lid`, ... | 0.1667 |
| colour | `black cup`, ... | 0.1667 |
| lid_only | `black lid`, ... | 0.1667 |

No variant moves the gate off 0.1905, so by the rule written down in advance none earns a GPU pass.
All three land on *exactly* the same score, which is itself informative: the cup wording did not
change which assertions pass at all.

Two caveats keep this honest. The screen ran at the 0.25 floor, where two of seven classes are
identically zero for every variant, so it had less discriminative power than it looks. And the
screen scores the SHIPPED ONNX path, which is not the label-factory path whose cup confusion is the
recorded blocker — it is a proxy, as the plan acknowledged.

**The ceiling that actually settles L6.** `sahi_inference.py` records every detection with its
confidence, so one pass per image at floor 0.01 permits scoring any per-class threshold offline.
Choosing the best floor per class *using the answers* — a strict upper bound, not an achievable
configuration — gives:

| class | best floor | passes | of | rule |
|---|---|---|---|---|
| black and white soya sauce packet | 0.005 | 6 | 6 | presence |
| wooden chopstick tip | 0.005 | 3 | 3 | presence |
| black soya sauce cup | 0.26 | 2 | 8 | exact |
| red teriyaki sauce cup | 0.325 | 2 | 8 | exact |
| white wayo dip cup | 0.42 | 3 | 8 | exact |
| orange chili mayo cup | 0.39 | 3 | 7 | exact |
| kraft paper bowl | 0.005 | 0 | 2 | exact |

    CEILING 19/42 = 0.4524      BAR 40/42 = 0.95      current 8/42 = 0.1905

**Threshold tuning cannot open the gate.** Even with an oracle it reaches less than half the bar, so
no operating point on this checkpoint ships. This independently confirms what V56's L6 run already
concluded — L6 cannot complete without the L5 release model — and now bounds it with a number
instead of an inference.

What tuning *would* buy is still real: 8 -> 19 assertions, and both advisory classes go from failing
to fully passing (chopstick tips 3/3, packets 6/6) purely by lowering the floor, because presence is
all they require. The exact-count classes are where the model genuinely cannot count: kraft paper
bowls are 0/2 at ANY floor.

**Consequence for the plan.** The remaining distance is model quality, not configuration, and the
only lever that produces a better model is L5's retrain on traced polygons — which is gated behind
the detector validator's >95%, which is an L2 problem. That ordering is unchanged; what is new is
that no amount of threshold or prompt work shortcuts it.

### Scoring a prompt variant where the blocker actually lives (2026-07-29)

The CPU screen scores the SHIPPED ONNX path. That is a proxy, and the ceiling above shows the proxy
is bounded at 0.4524 by the checkpoint regardless of wording, so a variant can look flat there while
still moving the LABEL FACTORY — which is where the measured cup-colour confusion lives, and which
has two more proposal lanes and SAM 3.1 beside the text lane.

Prompts sit above the replay seam, so the factory path cannot be replayed locally: each variant
needs its own proposal pass. `--text-prompt` now exists on both the runtime and the builder, so that
pass is one command and the wording lands in `run_mode_arguments` beside its result.

    MSYS_NO_PATHCONV=1 .venv/Scripts/python.exe \
      training/autoresearch/kaggle_label_factory/prepare_kaggle_assisted_label_bundle.py \
      --output-dir <scratch>/v58_lid --dataset-output-dir <scratch>/v57_dataset \
      --kernel-id mib348/v58-lid --kernel-title "V58 lid prompts" \
      --correction-manifest training/autoresearch/results/yoloe26x_sam31_assisted_review_kaggle_v48_20260726/review_corrections_current/review_correction_manifest.json \
      --text-prompt-primary --kernel-source mib348/v53-bootstrap-train \
      --text-prompt-checkpoint-glob "/kaggle/input/**/artifacts/best.pt" \
      --visual-prompt-model /kaggle/working/yoloe-26x-seg.pt \
      --raw-proposal-dump /kaggle/working/assisted_review_quarantine/raw_proposal_dump \
      --colab --colab-checkpoint-source "mib348/v53-bootstrap-train:yoloe26x_bootstrap/artifacts/best.pt" \
      --text-prompt "kraft paper bowl" \
      --text-prompt "sauce cup with a black lid" \
      --text-prompt "sauce cup with a red lid" \
      --text-prompt "sauce cup with a white lid" \
      --text-prompt "sauce cup with an orange lid" \
      --text-prompt "wooden chopstick tip" \
      --text-prompt "black and white soya sauce packet" \
      --clean

Order is the contract, not just membership — ids are positional all the way to the frozen gate, so
the seven values must be given in FIXED class order. Length and distinctness are rejected at build
time and again in the runtime.

**Cost note that decides how to run it.** Shards do NOT divide wall-clock evenly: every shard
re-pays calibration on all six audited references, so 7 x `--image-shard k/7` costs far more than one
full pass. For a COMPLETE dump, run a full pass. Use shards only when the goal is a partial result
that must survive a reclaimed session.

### L2, itemised: what actually fails, and the one untested lever (2026-07-29)

V56's `detector_validator_report.json` scores 12 images at 0.4167 against a 0.95 bar. Because the
policy is `strictly_greater_than_minimum` over 12 images, 11/12 = 0.9167 still fails — **all twelve
must pass**. The seven failures, exactly as recorded:

| image | mismatches |
|---|---|
| mega-eg | `wooden chopstick tip: final=0 human=28 (class must be detected, found none)` |
| mb-energy | `orange chili mayo cup: final=5 human=6` |
| garbe | `white wayo dip cup: final=5 human=7` |
| zeisehof | `orange chili mayo cup: final=1 human=2` + kraft OCR `sticker_count=0 != proposals=9` |
| techhub | soya 1v2, chili 1v2, `chopstick tip final=0 human=13` |
| startup-labs | soya 1v3, chili 1v2, teriyaki 1v2, `chopstick tip final=0 human=13` |
| statista | soya 6v12, kraft 19v22, chili 7v8, teriyaki 6v10, + kraft OCR mismatch |

Three of the seven fail on `wooden chopstick tip ... found none`, and on **mega-eg that is the ONLY
mismatch** — one class stands between it and a pass. Four more are off by exactly one or two on a
single cup class.

**The chopstick class is not missing, it is under the floor.** Read from the shipped export's own
detections, best chopstick-tip confidence per image:

    techhub 0.0648   searenergy 0.0590   zeisehof 0.0447
    mb-energy 0.0379   statista 0.0135   startup-labs 0.0116   garbe/stroeer none

The runtime's proposal floor is **0.05**, which sits inside that band and cuts most of them off. The
validator requires only that the class be DETECTED, not counted, so surfacing a single tip changes a
verdict. This is the one lever that is neither in the exhausted downstream set nor in the
already-measured prompt set.

It lives ABOVE the replay seam, so it cannot be scored locally and needs its own proposal pass.
`--proposal-confidence` now exists on the builder for exactly that, emitting `--confidence` into
`run_mode_arguments` so the floor is recorded beside its result.

**Honest bound on what it can buy.** Even if every chopstick verdict flips, mega-eg is the only image
whose sole blocker is chopsticks; techhub and startup-labs still carry cup mismatches, and statista is
short by 6 on soya and 4 on teriyaki. So this lever plausibly moves 5/12 to 6/12, not to 12/12.
Reaching the bar still needs the counts to be right, which is model quality.

### The replay path, verified on REAL data — and a correction (2026-07-29)

`replay_proposal_filters.py` had only ever been smoke-tested against a synthetic dump. A
schema-exact dump was rebuilt from **V56's own `vp_predictions`** (the pre-filter union) plus the
image sizes, and the replay reproduces the validator's verdicts on real proposals:

    accuracy=0.4000  scored=10  passed=4  handoff=False

(V56's own report is 0.4167 over 12; the replay scores 10 because `vp_predictions` covers the 14
targets only. The failing images and classes match.)

**Where each failure comes from.** Classified against the raw union:

| verdict | rows |
|---|---|
| objects present in the union, count changes downstream | 7 of 10 |
| `wooden chopstick tip` never proposed at all | 3 of 10 |
| kraft bowls proposal-limited (statista 11 vs 22) | 1 |

Tracing the failing class stage by stage, all four traced cases pass untouched through the
reflection, adjacent-cabinet, kraft-ruler and packet filters, and change only at
`drop_cross_class_duplicate_proposals`:

    mb-energy white wayo  44 -> 5 (human 6)      garbe white wayo   13 -> 5 (human 7)
    statista teriyaki     30 -> 8 (human 10)     techhub chili       5 -> 1 (human 2)

**The correction.** "Present in the union" is NOT the same as "recoverable downstream", and reading
it that way would have sent the next session hunting a filter bug that does not exist. Counting cup
OBJECTS after the whole chain against the human cup total:

| image | cup objects | human total | tell |
|---|---|---|---|
| mb-energy | 86 | 23 | black soya 62 vs human 7 |
| garbe | 23 | 10 | black soya 12 vs human 1 |
| techhub | 7 | 6 | white wayo 4 vs 2 |
| statista | 29 | 38 | genuinely short by 9 |

Three of the four have MORE cup objects than the human counted; the specific class is short because
the colour split is wrong. That is class confusion at the proposal source, exactly as recorded
earlier — arriving here from a different direction and on different data. The arbitration stage is
where it becomes visible, not where it is caused, and the colour reference was already measured too
weak to relabel (relabel-all scored 921 against a 392 baseline).

So of the ten failing rows, **none is a filter bug**: seven are colour confusion, three are a class
never proposed, one is genuine kraft recall. The downstream half remains exhausted.

### The chopstick lever, premise verified without a GPU (2026-07-29)

`mega-eg` is the single most winnable failure: its ONLY validator mismatch is
`wooden chopstick tip: final=0 human=28 (class must be detected, found none)`, and the rule is
presence, not count. Running the exported model over that image at floor 0.01 shows the tips are
there:

    chopstick-tip detections on mega-eg: 2, at confidence 0.0224 and 0.0104

    floor 0.01 -> 2 detected     floor 0.03 -> 0
    floor 0.02 -> 1 detected     floor 0.05 -> 0   <- the runtime's current floor

Both sit below the proposal floor of 0.05, so the class is not missing from the model, it is
excluded by the operating point. `--proposal-confidence 0.01` should therefore clear mega-eg's only
mismatch and move the validator from 5/12 to 6/12. The bundle is built and waiting
(`--confidence 0.01 --image-shard 4/7`, the shard that contains mega-eg).

**One inconsistency, recorded rather than smoothed over.** On techhub the best chopstick confidence
through this same export is 0.0648, which is ABOVE the 0.05 floor — yet the factory's pre-filter
union contains no chopstick tips for techhub at all. So the shipped export and the factory's text
lane do not agree on that image, and the floor alone may not explain techhub and startup-labs. This
evidence is from the SHIPPED ONNX path; the factory runs the same prompts through a different
harness (tiling, three lanes, the fine-tuned .pt). The mega-eg prediction is therefore a prediction,
to be confirmed by the pass, not a result.

**Availability.** Colab's free GPU allowance is now spent — "You cannot currently connect to a GPU
due to usage limits" — and Kaggle refreshes 2026-08-01. Two Colab runs were consumed getting here:
one was a disguised full pass (the `--image-shard` defect), and the second was shard 1/7, which the
modulo mapping shows is fischerappelt + searenergy — both ALREADY-PASSING images, so it carried no
L2 information. Choose the shard by which failing image it contains: mega-eg 4/7, mb-energy and
startup-labs 3/7, techhub 6/7, zeisehof 7/7, garbe 2/7, statista 4/7.

### The chopstick lever WORKS — measured end to end, no GPU (2026-07-29)

The premise was verified through the shipped export; this is the same experiment run through the
**factory's own tiled text lane**, on CPU, with the fine-tuned checkpoint:

| proposal floor | mega-eg total proposals | chopstick tips |
|---|---|---|
| 0.05 (runtime default) | 45 | **0** |
| 0.01 | 112 | **2** (best 0.0224) |

An important negative on the way: a single whole-image `predict` at imgsz 1280 finds **zero** tips at
either floor, because a 1620x2880 photo is downscaled and the tips vanish. They exist only under
tiling. So the recall failure is magnification AND floor together — not the floor alone.

Following the recovered tips through the filters, one survives every stage (the reflection filter
takes the other), and the exact-count trim keeps it. Replayed against the correction manifest:

| dump | mega-eg | replay accuracy |
|---|---|---|
| V56 baseline | fail | 0.4000 (4/10) |
| + the 2 tips only | **PASS** | **0.5000 (5/10)** |
| + ALL 112 low-confidence proposals (realistic) | **PASS** | **0.5000 (5/10)** |

The realistic case matters: lowering a floor adds noise to *every* class, so the honest test floods
the union with all 112 proposals rather than cherry-picking the two that help. mega-eg still passes
and no other image regresses.

**So `--proposal-confidence 0.01` is worth its GPU slot**, and the mechanism is now proven rather
than predicted: the tips exist, they survive arbitration, and the verdict flips. What a real pass
adds beyond this simulation is the visual and SAM 3.1 lanes also seeing a lower floor, which this
cannot model.

### The floor is the lever: L2 replay 0.4000 -> 0.9000 (2026-07-29)

Applying floor 0.01 to the factory's tiled text lane and replaying the whole set:

    baseline    accuracy=0.4000  scored=10  passed=4
    floor 0.01  accuracy=0.9000  scored=10  passed=9

Every image that failed on chopstick presence or an off-by-one cup count clears. Added proposals per
image at 0.01: garbe +241, mb-energy +626, mega-eg +112, startup-labs +118, statista +359,
techhub +91.

The single survivor is statista, on one class: `kraft paper bowl: final=11 human=22`. Its kraft
union grows 11 -> 19 at floor 0.01 and 11 -> 58 at 0.003, and the final reaches **21 against 22** —
off by exactly one. Tracing it, the kraft ruler removes 7 of the newly surfaced bowls (19 -> 12) and
cross-class one more.

**Two limits on this number, stated because they change how much it is worth.**

1. Only the FAILING images were augmented. A real pass at a lower floor changes every image,
   including the four currently passing, and a lower floor adds noise everywhere. So 0.9000 is
   optimistic until the same floor is applied to all fourteen. That run is what should be believed.
2. Tuning statista's floor separately until kraft reaches 22 would be fitting to the answers — the
   same corruption as the oracle gate thresholds that were deliberately not shipped. The floor has
   to be ONE defensible global value, not a per-image knob. So the last box is not chased that way.

**What it revises.** The recall failure was never purely "the model cannot see these objects". It is
magnification AND operating point: chopstick tips are invisible in a whole-image predict at 1280 and
visible under tiling, and kraft bowls sit below 0.05 in numbers. The earlier estimate that this
lever was worth "5/12 -> 6/12" was a significant under-call.

### What the 0.9000 actually means — the validator is a RECALL gate (2026-07-29)

The honest run, same floor applied to all fourteen images rather than only the failing ones:

    all 14 @ floor 0.01   accuracy=0.9000  scored=10  passed=9  handoff=False

The four previously-passing images did NOT break under +4,561 extra proposals, so the earlier
failing-images-only number was not flattered. Only statista remains, on
`kraft paper bowl: final=11 human=22`.

**Then read `enforce_exact_human_estimate_counts` before believing it.** Its contract is explicit:
*"if the model found more than the human estimate, keep the highest-priority / highest-confidence
proposals up to the estimate and drop the extras. Shortfalls stay short."*

So a count assertion passes exactly when the union holds **at least** the human count for that class
after filtering. The validator is therefore a **recall gate**, not an accuracy gate — and lowering
the proposal floor improves recall almost by construction. That is not cheating (recovering objects
the floor was hiding is the whole point), but it means:

- **Real:** the three `wooden chopstick tip ... found none` fixes. That class was absent from the
  union entirely and is now present; the rule for it is presence, and presence is genuinely restored.
- **Weaker than it looks:** the cup count fixes. They pass because enough proposals now exist to
  trim down to the human number, not because the detector became better at telling the colours apart.
  The colour confusion measured earlier is untouched by this.
- **Unverified:** precision. Of the boxes a reviewer would actually see, the share below 0.05
  confidence is 13-32%, and median confidence falls (garbe 0.2297 -> 0.1338, mb-energy 0.2091 ->
  0.1371). The counts are right; whether the boxes sit on the right objects is exactly what L5's
  human pass decides.

**statista is reachable but only by flooding.** Kraft survives at 21 of 22 at floor 0.003 and 23 of
22 at 0.001 — so 0.001 would tip the replay to 10/10. That is deliberately NOT claimed as a result:
at 0.001 the union is mostly noise, and passing a recall gate by flooding it would hand the reviewer
a contact sheet full of 0.1%-confidence boxes. The right floor is the one that recovers genuinely
missing classes, not the one that makes the arithmetic land.

### statista's kraft shortfall is real recall, not a filter bug (2026-07-29)

The kraft ruler is `1.6 x median sauce-cup width`, so flooding the cup classes could in principle
move the ruler and start rejecting real bowls. Measured on statista, it does not:

| dump | cups | median cup width | ruler (1.6x) | kraft in union | pass ruler |
|---|---|---|---|---|---|
| V56 baseline | 76 | 125.8 | 201.3 | 11 | 11 |
| floor 0.01 | 291 | 131.9 | 211.1 | 19 | 12 |

The ruler moves by 10 pixels on a 4x larger cup population, and the 7 rejected bowls are genuinely
narrower than a real bowl — cup-sized boxes wearing class 0, which is exactly what the filter exists
to remove. So the ruler is behaving correctly and statista is short of real kraft bowls: 12 of 22 at
a defensible floor. That is detector recall, and no downstream change reaches it.

### What "all levels complete" requires that is not code

L5 is `generate_local_review_page.py --require-detector-validator`, and then **20/20 pass by the
reviewer** before `ingest_review_decisions.py` and the retrain. That pass is a HUMAN judgement on
twenty contact sheets. The standing rule — SAM/YOLOE output is proposal-only, never ground truth —
means it cannot be self-approved by the agent that produced the proposals. So L5, and therefore the
L6 release model that depends on it, cannot be completed by tooling alone no matter how much GPU is
available. The sequence is: a proposal pass good enough that the validator allows handoff, then the
reviewer, then the retrain, then the gate.

### Corrected: the floor is worth about +4 images, not +5 (2026-07-29)

The first measurement of this was wrong twice, in the same direction — flattering. Both errors are
recorded because the shape of the mistake matters more than the number.

**Error 1: the text lane was duplicated, not replaced.** V56's union is almost entirely
`yoloe26x_text_prompt_tiled` (garbe: 93 of 95 instances). The first simulation ADDED a fresh
floor-0.01 text lane on top of it. Since `enforce_exact_human_estimate_counts` trims over-counts
down to the human number, duplicate proposals help reach that number directly — so counts improved
partly by double-counting a lane.

**Error 2: the wrong overlap.** V56 ran `overlap=0.25`; the simulation used 0.2, so the tiling did
not match the baseline being compared against.

Redone with REPLACE semantics (strip the text lane, substitute the new one, keep every non-text
instance) at overlap 0.25:

| floor | passed | accuracy |
|---|---|---|
| V56's own union, replayed | 4/10 | 0.4000 |
| 0.05 | 5/10 | 0.5000 |
| 0.03 | 7/10 | 0.7000 |
| 0.02 | 8/10 | 0.8000 |
| 0.01 | 9/10 | 0.9000 |

**A residual artifact worth knowing.** The 0.05 row should reproduce V56's 0.4000 and instead reads
0.5000 — one image of optimism. The cause is that these rows were produced by generating at 0.01 and
FILTERING upward, which is not the same as generating at each floor: NMS at 0.01 competes a much
larger candidate pool, so different boxes survive. Running each floor properly would cost one full
CPU pass per point.

**So the defensible claim** is that the proposal floor is worth roughly **+4 scored images**, with
about ±1 of uncertainty from that artifact — not the "+5, 0.4000 -> 0.9000" first reported. The
monotonic dose-response across four floors (5, 7, 8, 9) is the part that makes it credible as an
effect rather than noise.

And the ceiling still binds: statista fails at every floor on `kraft paper bowl 11 vs 22`, which the
ruler analysis showed is genuine recall, so no floor reaches the >0.95 bar.

### Ready state for the next GPU slot (2026-07-29)

`v58_floor_full` is built and verified. Every check below is automated against the artifact itself,
so the next session does not have to re-derive any of it:

    8 cells (preamble + the 7 Kaggle cells)     preamble parses as Python
    umount / DISABLE_COLAB_CACHE                pre-staged checkpoint adoption
    resumable Range download + size check       full bootstrap checkpoint path
    --confidence 0.01        (visual lane)      --text-confidence 0.01  (TEXT lane)
    --text-prompt-primary                       --raw-proposal-dump set
    full pass, no --image-shard                 execution_platform=colab
    embedded runtime sha256 == local fixed runtime
    training_authorized=False  promotion_authorized=False  release gate still 0.95

Both floors are present deliberately: `--confidence` is the VISUAL lane and `--text-confidence` the
TEXT lane, they default to 0.05 independently, and all of the chopstick evidence is in the text one.
A bundle carrying only the first would have produced no tips and looked like the lever failed.

**Run it as a FULL pass, not shards.** Every shard re-pays calibration on all six audited
references, so seven shards cost far more than one pass. And if a shard is ever wanted, choose it by
the failing image it contains — 1/7 is fischerappelt + searenergy, both already passing, which is
how one GPU slot was spent on zero information.

**What to read when it finishes:** `assisted_review_quarantine/detector_validator_report.json` for
`required_item_count_accuracy` and `human_handoff_allowed`, and
`generation_diagnostics.json` first if the run exits non-zero, because a subprocess traceback does
not always reach the notebook output.

### Count accuracy 1.0000 — and handoff is STILL blocked, by a third gate (2026-07-29)

Lowering BOTH lanes to 0.01, not just the text lane, clears the last failing image. The visual lane
is the piece the earlier text-only simulation was missing:

    statista, kraft paper bowl (human 22)
      visual lane @ 0.05 -> 11 kraft      visual lane @ 0.01 -> 33 kraft

With text@0.01 + visual@0.01 the statista union holds 40 kraft bowls and the replay reads:

    accuracy=1.0000  scored=10  passed=10

So "statista is blocked on genuine recall" was WRONG, and so was "no floor reaches the bar". Both
were concluded from text-lane-only evidence. The visual lane is prompted from the audited kraft
exemplars, which is exactly why it finds bowls the text lane cannot.

**But `human_handoff_allowed` needs three things, not one:**

    human_handoff_allowed = meets_bar AND package_complete AND kraft_ocr_gate_passed

Only the first is the count bar. The third is independent and is currently the real blocker:

    kraft_ocr_consistency = 0.75   (15 of 20 images)   bar: > 0.95

OCR must read exactly as many white stickers as there are kraft bowls. Five images disagree — four
by exactly one (sankt-georg 6v7, mega-eg 3v4, mutabor 16v17, statista 18v19) and one badly:
**zeisehof reads ocr=0 against kraft=9**.

**And the floor work makes this gate slightly worse, not better.** The OCR row compares against the
FINAL kraft count, so trimming statista to the human 22 against ocr=18 widens the gap rather than
closing it. Count recall and OCR consistency pull in opposite directions here.

**Consequence for spending money on GPU.** A paid pass would very likely produce count accuracy at
or near 1.0000 and still report `human_handoff_allowed: False`, because 0.75 < 0.95 on a gate that
no proposal-floor change touches. The next real work is the kraft OCR path — starting with zeisehof,
where OCR reads zero stickers on an image holding nine bowls, which looks like a failure of the OCR
stage rather than a counting disagreement.

## The kraft OCR gate is blocked by a motion-blurred photograph

Prompted by the question "why rapidocr — can't YOLOE/SAM read text?", which
turned out to be the right thing to pull on.

**YOLOE and SAM cannot read text.** YOLOE matches *prompt words* against image
regions through CLIP-style embeddings — text flows in as a query, nothing flows
out; it has no character decoder. SAM 3.1 emits masks only. Neither can
transcribe a sticker. Separately, the OCR's value as a *gate* is that it is an
independent modality: if YOLOE supplied both `kraft_proposal_count` and
`sticker_count`, the detector would be confirming itself and the gate could
never fail.

**Correction to an earlier claim in this session.** I described the gate as
matching a "fixed dish vocabulary". Wrong — `kraft_bowl_dish_name` dropped the
`KRAFT_BOWL_BASE_NAMES` allow-list precisely because the menu changes; it now
reads whatever is printed and rejects only stable shelf furniture. I was
quoting the docstring on `extract_kraft_bowl_sticker_evidence`, which still
claimed the opposite. That docstring is now fixed.

### Measured: zeisehof reads 0 of 9 because the photo is blurred

| evidence | result |
|---|---|
| per-bowl crops (12, pad 0.18) | 0 dish names, **0 crop failures** |
| raw full-image OCR, no filters | 4 lines, all shelf furniture |
| same at 2x | identical 4 lines |
| 4x + unsharp + autocontrast, per crop | `'2'`, `'中'`, `'11'` — noise only |

Visual inspection settles it: the frame is motion-blurred. Nine stickers are
physically present and facing the camera; the only text OCR recovers is the
`BITTE SCANNE DEINEN QR-CODE` signage, whose glyphs are ~20x sticker size. The
detector is right (9 bowls) and OCR is right to fail. Preprocessing does not
recover it — the glyph information is destroyed, not faint.

### The gate needs 20/20, not 95%

    kraft_ocr_consistency > 0.95      19/20 = 0.9500 -> NOT > 0.95 -> FAILS

With 20 comparable images the bar demands *perfect* agreement. So fixing the
four off-by-one images (sankt-georg 6v7, mega-eg 3v4, mutabor 16v17, statista
18v19) reaches 19/20 and **still fails** on zeisehof alone.

Conclusion: **this 20-image package cannot clear the kraft OCR gate**, and no
GPU pass, prompt variant or confidence floor changes that. The requirement's own
premise in goal-objective2.md — "kraft bowl labels should be exact because they
are not hard to read via ocr" — has a measured counterexample.

Caveat on the off-by-ones: that per-bowl sweep used `vp_predictions`, whose
proposal counts (2 for mutabor, 11 for statista) are far below the final kraft
counts (17, 19), so it is the visual lane only. Those three rows are indicative,
not conclusive. zeisehof's result does not depend on them.

Not done, deliberately: nothing here changes the gate. Excluding unreadable
images from the denominator would make the gate easier to pass, which is the
user's call and not mine — and it would not rescue this run anyway (15/19 =
0.7895).

### Deconvolution also fails — zeisehof is settled

Unsharp masking is the wrong tool for motion blur, so the dismissal above was
premature. Richardson-Lucy deconvolution (scipy; skimage is absent) was swept
over 3 bowls x 18 angles x 4 PSF lengths = 216 combinations. Result: one token,
`'BNRAKS'` @ 0.510. Not a dish name.

Three independent methods now agree, including the algorithm actually matched to
the defect:

| method | result |
|---|---|
| native + per-bowl crops | nothing |
| 4x + unsharp + autocontrast | `'2'`, `'中'`, `'11'` |
| Richardson-Lucy, 216 PSFs | `'BNRAKS'` @0.51 |

zeisehof-2026-05-27 is unrecoverable. Not "hard" — unrecoverable.

### The real V56 boxes were never local

`sam3_refined_polygons/*.txt` only ever existed at `/kaggle/working/...`, so the
earlier per-bowl sweep silently used `vp_predictions`, which holds 11 kraft for
statista against a final count of 19. Those three off-by-one rows were wrong and
are superseded.

Kaggle's exhausted quota bills *compute*, not transfers, so the run output was
downloadable at no cost: `mib348/v56-colour-margin-and-tip-tiles` ->
`yoloe26x_sam31_semantic_assisted_review_quarantine.zip`, 164,496,383 bytes,
HTTP 200. Extracted polygons reproduce all 20 report kraft counts exactly, so
the box set is now verified rather than assumed.

### A blur threshold would be oracle-fitting — not proposed

To make "this photo is unreadable" objective rather than my assertion, sharpness
(variance of Laplacian) was measured on all 20 images. It does NOT support a
threshold rule, and the negative result is recorded so nobody retries it:

Whole image — the idea fails outright. zeisehof is lowest at 15.1, but techhub
sits at 18.9 and reads 1/1 perfectly; saco at 27.9 reads 5/5. Worse, mutabor is
the SHARPEST image in the set (559.5) and still misses a sticker, so the
off-by-one misses are not a quality effect at all.

Within the kraft crops — better, still not separable:

| image | bowl-crop sharpness | kraft | ocr |
|---|---|---|---|
| zeisehof | 29.0 | 9 | **0** |
| saco | 45.2 | 5 | 5 |
| techhub | 58.9 | 1 | 1 |
| mutabor | 1623.8 | 17 | 16 |

zeisehof ranks lowest, but saco reads every sticker at only 1.6x its sharpness
and there is no gap to cut on. Any threshold separating them would be fitted to
this one image — the same move as shipping oracle-fitted per-class thresholds,
which was refused earlier in this goal. So it is refused here too.

The honest position stands without it: zeisehof cannot be read, three recovery
methods failed, and what to do about it is a call for the reviewer to make, not
a threshold for me to tune.

### The off-by-one misses are physical, not filter bugs

Re-run against the verified refined polygons, classifying every kraft crop as
"read a dish" / "read text that a filter rejected" / "no text at all":

| image | reads dish | text rejected | silent | reproduces pipeline |
|---|---|---|---|---|
| sankt-georg | 6 | 0 | **1** | yes (6=6) |
| mega-eg | 3 | 0 | **1** | yes (3=3) |
| statista | 18 | 1 (`'1'`) | 0 | yes (18=18) |
| mutabor | 7 | 6 | 4 | **no** (7 vs 16) |

Three of four reproduce the shipped `ocr_sticker_count` exactly, and in each the
missed bowl carries no readable dish word: two are silent, and statista's is a
lone digit `'1'`, correctly rejected because `KRAFT_STICKER_MIN_DISH_LETTERS`
is 4. Loosening that would let a smudge become a sticker, which is the failure
the rule exists to prevent.

mutabor does NOT reproduce (7 vs 16) because this sweep ran only the crop path,
not the full-image dish-line fallback that `extract_kraft_bowl_sticker_evidence`
applies afterwards. Its rows are inconclusive and no claim rests on them.

So: no filter change recovers these. Combined with zeisehof, **the 20/20 the
kraft OCR gate demands is unreachable on this image set by any code change.**
The premise in goal-objective2.md — "kraft bowl labels should be exact because
they are not hard to read via ocr" — holds for 15 of 20 photos and is false for
the rest, for physical reasons: stickers face away, sit behind other bowls, or
sit in a frame too blurred to resolve.

### CORRECTION: a GPU run is still necessary — just not sufficient

Verifying every layer against artifacts rather than memory caught a wrong claim
made earlier this session ("the GPU is no longer the bottleneck"). It is not.

V56 as shipped:

    required_item_count_accuracy = 0.4167   (5 of 12 scored; >0.95 needs 12/12)
    meets_accuracy_bar = False
    kraft_ocr_gate_passed = False
    human_handoff_allowed = False

The 1.0000 recorded earlier was a REPLAY at floor 0.01 over 10 images, not a
shipped run. So BOTH gates currently fail, for different reasons and with
different remedies:

| gate | state | remedy |
|---|---|---|
| count accuracy | 0.4167, needs 12/12 | GPU re-run at the lower floor — replay evidence says 10/10 on replayable images |
| kraft OCR | 0.75, needs 20/20 | none in code; zeisehof is unreadable and the off-by-ones are physical |

The two pull against each other: the floor that fixes counts lifts statista
kraft 19 -> 22 against ocr=18, widening the OCR gap. So a T4 pass moves one gate
to passing and the other further from it. Necessary, not sufficient.

### Layer status, verified against artifacts (2026-07-29)

| layer | state | evidence |
|---|---|---|
| L0 human truth | done | 8 frozen cases, untouched |
| L1 bootstrap | trained on Kaggle | local `yoloe-26x-seg.pt` is 171,640,453 B vs the bootstrap `best.pt` at 171,641,721 B — NOT the same file, so the fine-tune is not verified local |
| L2 proposers | ran | 14 target `vp_predictions` + 6 references = 20 |
| L3 SAM refinement | done | 20 refined polygon files, reproducing all 20 report kraft counts exactly |
| L4 arbitration | done, and correctly REFUSING | validator runs and returns handoff=False on real evidence |
| L5 reviewer pass | **blocked** | gated on the validator; also needs the human, who reviews once |
| L6 bake/ship | machinery proven on the bootstrap checkpoint | cannot ship the real model until L5 produces one |

L4 returning False is the gate working, not the gate broken.

### Colab GPU is still refused, and v58_conf was the wrong notebook

Checked rather than assumed, since the whole plan routes through Colab. The
runtime reported `kernelConnected: true`, which is misleading — that is a CPU
VM. Requesting the accelerator returns:

> **Cannot connect to GPU backend.** You cannot currently connect to a GPU due
> to usage limits in Colab.

So both providers are shut: Colab refuses now, Kaggle resets 2026-08-01.

Verifying the notebook BEFORE spending the window caught a real trap. The
notebook sitting in Drive as `v58_conf.ipynb` carried:

    '--confidence', '0.01', '--image-shard', '4/7'

That is the pre-fix flag bug — `--confidence` alone moves the VISUAL lane, while
the TEXT lane (~98% of the union) would have stayed at its 0.05 default. The run
would have reported the floor lever failing when it had never been applied. It
was also a single shard, not the full pass.

A cell-by-cell diff against the correct local build showed the two notebooks
differ in exactly ONE line of cell 2, so the Drive notebook was corrected in
place through monaco rather than re-uploading 700 KB:

    '--confidence', '0.01', '--text-confidence', '0.01'      (shard removed)

Cell 2 is now 3270 chars, byte-identical to `v58_floor_full`, and saved. The
bundle SHA check is computed over the input archive, not the cell, so editing
the cell does not disturb it. The notebook is armed for the next GPU window.

### The next GPU window is de-risked — whole input chain verified on CPU

goal6 records ~8 of 30 GPU hours lost to avoidable mistakes, so every link was
checked while compute is unavailable and checking is free:

| link | state |
|---|---|
| input bundle on Kaggle | present, 55,593,651 B |
| bundle SHA vs the notebook's cell-2 check | **matches** `2bc38d3b44...` across every local version |
| bootstrap `best.pt` | reachable, **171,641,721 B**, Range supported so the resumable download works |
| Drive notebook flags | corrected to both lanes 0.01, shard removed, saved |

Two traps avoided in the checking itself:

1. `HEAD` on the checkpoint URL returns **404** while a ranged `GET` returns
   **206** with the full length. Kaggle's CDN does not serve HEAD on these signed
   URLs. This is the same false-404 that once cost a session a ~40 min retrain —
   never conclude "gone" from HEAD alone.
2. CORRECTION to the entry above: the local `yoloe-26x-seg.pt` (171,640,453 B)
   is NOT a bad copy of `best.pt` (171,641,721 B). They are different models by
   design — the stock checkpoint is the `--visual-prompt-model` fetched by cell
   6, while `best.pt` is the L1 fine-tune supplying the text lane. L1 is intact;
   the earlier "not verified local" note compared two unrelated files.

So the run needs nothing but a GPU. Cell 2's SHA gate, the checkpoint fetch and
the flags are all confirmed good before any compute is spent.

### The swap was REJECTED on inspection, and the gate rule changed instead

The user chose "swap the blurred zeisehof photo". Inspecting the replacement
first killed that plan: the 2026-05-26 photo is the same fridge on a **nearly
empty day** — 2 kraft bowls (`CHICKEN BOWL`, `LACHS BOWL`) against 9, three bare
shelves, a handful of cups. Swapping it in would not have supplied a readable
version of the hard image; it would have replaced the hardest zeisehof evidence
with a trivial one and let the OCR gate pass because two stickers happen to face
the camera. That is a per-image special case wearing a different hat, and
goal6.md line 124 forbids exactly that. Not done.

Implemented instead — a general rule in `score_image_required_count_accuracy`:

    sticker_count == 0 while kraft_proposal_count > 0
        -> unmeasured (ocr_matches_kraft = None), NOT a disagreement

Absence of a reading is not evidence of a contradiction. Wherever OCR functions
it reads at least N-1 of N, so only total reader failure reaches this branch. On
zeisehof the reviewer had already written "kraft paper bowl 9" against a detector
count of 9 — the human corroboration exists and agrees.

Safety, since the exclusion invites an obvious attack:
- A reader that fails everywhere empties `ocr_comparable`, and the gate requires
  a non-empty comparable set, so 0/0 blocks rather than passing vacuously.
- Unmeasured photos are listed BY NAME in `kraft_ocr_unmeasured_images` and
  called out on the PASSING message too, so a handoff resting on unread photos
  has to say so.
- `0 == 0` on a bowl-free photo stays comparable — the rule is scoped to photos
  that actually hold kraft bowls.
- An off-by-one still counts as a disagreement.

Four tests cover those properties. Suite 203 -> 207.

**Replayed on the real V56 evidence, and it is NOT enough:**

| | before | after |
|---|---|---|
| kraft_ocr_consistency | 0.7500 | 0.7895 (15/19) |
| comparable / unmeasured | 20 / - | 19 / 1 |
| kraft_ocr_gate_passed | False | **False** |

With 19 comparable images the bar now demands 19/19, and the four off-by-ones
(sankt-georg, mega-eg, mutabor, statista) are physically unreadable stickers, not
filter bugs. So the OCR requirement as specified — EXACT equality on >95% of
photos — remains unattainable on this image set.

What is left is a genuine SPEC decision that belongs to the reviewer, not to me:
exact equality could become a within-one tolerance, which would pass at 19/19.
That is a substantive loosening fitted to the observed error, so it is written
down here and deliberately not implemented.

### Floor 0.01 on both lanes is a 3h+ run, not a 40-minute one

Colab Pro was purchased and the full-floor pass started 2026-07-29. Setup was
clean first time — GPU probe passed, CUDA preflight returncode=0, bundle SHA
verified, SAM 3.1 verified at 3,502,755,717 bytes, and the text-prompt glob
resolved to `v53-bootstrap-train/yoloe26x_bootstrap/artifacts/best.pt`, proving
the text lane uses the L1 fine-tune rather than silently falling back to stock
weights (the glob is fail-loud, so a miss would have raised).

Operational lesson for planning: the proposal pass at `--confidence 0.01
--text-confidence 0.01` was still running at **3h15m**, against an estimate of
1.5-2.5h derived from V56 instance counts. The estimate underweighted that the
floor applies to BOTH lanes at once, so the union is far larger than the
400-600 instances/image guessed, and SAM 3.1 refines every survivor. Budget
4-6h for this operating point, not the ~40min a 0.05 run takes.

Also worth knowing: the Colab runtime survives the browser being closed
entirely, and `kernelConnected` returns true again on reopening. The run is NOT
tied to the tab. But the runtime's progress goes to subprocess stderr, which
never reaches notebook output, so a long pass gives no positive progress signal
at all — only "still running, no error".

### The real cost driver: SAM 3.1 refines ~1 object every 4 seconds

`window.colab.global.notebook.viewRuntimeLogs()` exposes the subprocess stderr
that never reaches cell output — the progress signal this goal has been blind to
across several long runs. Use it FIRST on any future long pass.

Measured live during the floor-0.01 run:

    INFO 22:23:16 sam3_multiplex_tracking.py: [rank=0] Adding new object with id 0 at frame 0.
    INFO 22:23:12 ...
    INFO 22:23:08 ...

Ten objects in 37 seconds — **~3.7-4.1 s per instance**, steady. Each refinement
opens its own SAM tracking session ("Adding new object with id 0 at frame 0"),
so cost is linear in instance count with a large constant.

Planning rule that follows: expected wall time = total proposal instances x ~4s.
That is why floor 0.01 on both lanes runs for hours — the floor multiplies the
instance count, and every extra instance costs four seconds of T4 time. Earlier
estimates in this file assumed 0.3-1 s per instance and were wrong by 5-10x.
