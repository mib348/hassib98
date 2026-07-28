# YOLOE Fridge Inventory - Live Session Scratchpad

**Last updated:** 2026-07-26 (V50 exact-current Kaggle run live; older V48/V49 lines below are historical context only)  
**Audience:** next assistant taking over when the previous agent is unavailable  
**Read order:** [goal-objective.md](goal-objective.md) → [scratchpad-training.md](scratchpad-training.md) → [scratchpad-plan.md](scratchpad-plan.md) → **this file** (live delta)

This file records **verified live state and progress**, not a claim that training or promotion succeeded. Prefer re-checking Kaggle before acting if more than a few hours have passed.

---

## Live handoff delta (authoritative as of 2026-07-26 evening)

This section supersedes the older V48 / V49 top-line status below. Keep the older notes for history, but do **not** treat them as current.

- Fresh correction manifest in use:
  - `training/autoresearch/results/yoloe26x_sam31_assisted_review_kaggle_v48_20260726/review_corrections_current/review_correction_manifest.json`
  - SHA-256: `3192b1aa2ebaca2cde9c15521d3c3430216fac66464c11407c35c81b8a4e28dc`
- Bundle builder was already patched/tested earlier to prefer the newest `review_corrections_current` manifest and fail closed if stale/missing.
- Clean publish bundle rebuilt from current source into:
  - `C:\Users\mib34\AppData\Local\Temp\grok-goal-4650553d8e48\implementer\v50_rebuilt_bundle`
  - `C:\Users\mib34\AppData\Local\Temp\grok-goal-4650553d8e48\implementer\v50_rebuilt_dataset`
- Current Kaggle notebook:
  - slug: `mib348/v50-exact-h-current`
  - title: `V50 exact h current`
  - code file: `assisted_label_review.ipynb`
  - dataset source: `mib348/sushi-yoloe26x-assisted-label-inputs`
  - model source: `safebet1034/sam3-1/pytorch/default/1`
  - privacy: private
- Fresh-source verification already done before this handoff:
  - pulled Kaggle source contains correction SHA `3192b1aa2ebaca2cde9c15521d3c3430216fac66464c11407c35c81b8a4e28dc`
  - pulled Kaggle source does **not** contain stale SHA `4ffacc850d2a8d6576c3fac5cb8dc210768627aa3426e0192ee8fd82e3e5eead`
- Live run state at handoff:
  - Kaggle status: `RUNNING`
  - `kernels_output(...)` probe currently returns **0 output files**
  - earlier live log stream already proved the fresh V50 source is executing, including `Verified frozen assisted_label_inputs.bundle` and notebook startup / dependency lines
- Hard gate remains unchanged:
  - do **not** hand off `review.html` until Kaggle finishes, outputs are downloaded into a **new** results directory, and `detector_validator_agent.py` allows human handoff with required-item accuracy **> 0.95**
- Production remains untouched:
  - `/ai` is still fail-closed / 503
  - no train / export / promote was done in this V50 continuation

---

## Timeline: what was true at each handoff

These two statements are **both true** at different times. Do not treat the older one as current.

| When | Source | Live Kaggle claim | Production |
|---|---|---|---|
| **Prior assistant handover** (written into `scratchpad-training.md` / `scratchpad-plan.md`, factual refresh 2026-07-25) | Capture at leave-behind | Version **33** / script **`337824597` still actively running** on **T4 x2**, with **no terminal failure**. Prior agent **left it running** and **did not alter production**. | Untouched (`/ai` still 503; no train/export/promote) |
| **This assistant takeover** (Playwright MCP browser inspect, same calendar day) | Live page re-check | Same Version 33 / `337824597` had **finished** as a **failure** (~1h 17m / ~4648.9s). First terminal error: correction-guided SAM 3.1 usefulness gate. This agent **did not stop, cancel, re-run, or change production** either — only observed. | Still untouched |

**Why the mismatch is expected:** Kaggle jobs can complete (success or fail-closed) between when an agent writes “still running” and when the next agent re-opens the viewer. Always re-check the live notebook before acting; never assume a prior “running / no failure” line is still accurate.

**What neither agent did:** re-enable `/ai`, train, export, promote, edit benchmark truth, or treat partial Output as approved labels.

---

## One-line status (current as of this file’s last update)

> **SUPERSEDED 2026-07-27 — see “V50 terminal result” below.** The paragraph that follows was written while V50 was still in flight and is kept only as history.

~~**Current live notebook is `mib348/v50-exact-h-current` (Version 1) built from the fresh correction-manifest SHA `3192b1aa…`.** Source verification is done, live status is **`RUNNING`**, Kaggle output files are **not available yet**, and the next assistant must wait for completion, download the quarantine artifact into a **new** results dir, run `detector_validator_agent.py`, and only generate / hand off `review.html` if required-item accuracy is **> 0.95**. `/ai` remains 503.~~

### V50 terminal result (2026-07-27, verified)

**V50 finished `complete`; the artifact is downloaded and structurally valid; the detector validator BLOCKS human handoff at accuracy `0.0000` (0 of 12 scored images). `review.html` was therefore NOT generated. `/ai` remains 503.**

- Kaggle status API: `mib348/v50-exact-h-current` → `{"status":"complete"}` (68 output files, ~5 799 s run)
- Run summary printed by the notebook:
  `{"status":"awaiting_twenty_image_pass_reject","contact_sheet_count":20,"training_authorized":false,"release_gate_passed":false}`
- Soft usefulness gate: `failed_images=6` (does not block archive download, by design)
- Downloaded into a **new** directory — V39 / V46 / V48 untouched:
  `training/autoresearch/results/yoloe26x_sam31_assisted_review_kaggle_v50_20260726/`
  (68/68 files, 0 failures; `assisted_review_quarantine/` holds 20 contact sheets, 20 polygons, 6 reference crops, 14 vp_predictions, 278 MB quarantine zip)
- Correction manifest used, SHA re-verified on disk:
  `3192b1aa2ebaca2cde9c15521d3c3430216fac66464c11407c35c81b8a4e28dc` ✔ (stale `4ffacc85…` absent ✔)
- Detector validator verdict (report written to
  `…v50_20260726/assisted_review_quarantine/detector_validator_report.json`):

```text
required-item count accuracy 0.0000 is not > 0.95
scored=12  passed=0  failed=12  package_complete=True   (exit 2)
```

#### Why 0/12 — the failure splits into two independent families

**Family A — kraft count disagreement. CAUSE IS THE DETECTOR OVER-COUNTING, NOT OCR.**
`score_image_required_count_accuracy` fails any image where `ocr_sticker_count != kraft paper bowl` final count. That check fires on 16 of 20 images. An earlier reading of this blamed OCR recall; **direct visual inspection of the source images disproves that.** The user's spec is correct: the stickers are white background, black font, and are perfectly legible wherever the photo is sharp.

Ground truth established by eye, at native resolution, on the oriented (`exif_transpose`d) images:

| Image | Real bowls (counted by eye) | Model kraft proposals | OCR stickers |
|---|---:|---:|---:|
| `stroeer-hamburg` | **6** (VEGE./LACHS/LACHS/CHICKEN/CHICKEN/GARDEN, all crisply readable) | **10** | 5 |
| `saco-shipping` | **5** (GUACA/VEGE./FALAFEL/EDAMAME/CHICKEN KARAAGE) | **7** | 4 |

On `stroeer` the 4 surplus kraft polygons were rendered and inspected: they sit at y≈0.69–0.80 with width ≈0.08 (vs ≈0.22 for a real bowl) and land squarely on the **four sauce-cup stacks** (teriyaki, black soya, wayo, chili-mayo). They are sauce stacks misclassified as class 0. The individual sauce cups are *also* detected correctly in their own classes, so this is an extra stack-level false positive, not a swap.

Consequently **OCR is closer to the truth than the detector is** (5 vs 6 real; 4 vs 5 real). OCR's own error is small — it missed exactly one legible sticker on each of those two images (`FALAFEL BOWL` on saco).

Anomalously small kraft polygons (candidate sauce-stack false positives) appear on 7 of 20 images: `garbe` 2, `mb-energy` 3, `mutabor` 9, `no-limits-gym` 4, `searenergy` 1, `statista` 11, `stroeer` 4. A naive area threshold is **not** a sufficient fix — `big_kraft == ocr` holds on only 4/20, so at least one more cause remains on images with no small-kraft (`springer` 10 kraft/5 OCR, `saco` 7/4).

**Family A-bis — three images are motion-blurred past human legibility.**
`zeisehof` (OCR 0, 9 bowls), `techhub` (0/4), `mega-eg` (0/3) all report `ocr_status: "available"` but return zero texts. On `zeisehof` the shelf was cropped and upscaled 3×: the sticker text is a smear that a human cannot read either. This is an input-capture problem, not an OCR defect, and no code change will recover it.

**Implication for the fix order.** Do not "improve OCR" first. The highest-leverage repair is to stop class 0 firing on sauce-cup stacks, and to treat the readable sticker count as the corrective signal for the kraft count — which is exactly what requirement 1 of `goal-objective2.md` asks for.

**Family B — proposal shortfalls on small/dense items.**
- `wooden chopstick tip` proposed as **0** against human 36 / 28 / 13 / 13 on `mb-energy`, `mega-eg`, `startup-labs`, `techhub` — goal-objective2 requirements 4 & 5 unmet.
- `black soya sauce cup` undercounted: 4 v 12 (statista), 1 v 5 (zeisehof), 1 v 3 (startup-labs), 2 v 3 (mega-eg), 1 v 2 (techhub).
- Near-misses worth noting: `barmbek` tips **39 v 40** (off by one), `byteclub` teriyaki **5 v 7** (2 stacked behind 5).
- `statista` also kraft 13 v 22 and chili mayo 7 v 8.

#### Design question the next agent must raise before another Kaggle run

The validator requires `ocr_sticker_count == kraft_count` exactly. That bar is only reachable if **every** kraft bowl shows a readable sticker. Where bowls are stacked or rotated the sticker is not visible, so exact equality is unreachable by construction regardless of OCR quality. Confirm with the user whether the intended rule is "OCR count must equal kraft count" or "OCR must not *contradict* kraft count (≤, with readable stickers matching)". Do not silently weaken the check — requirement 1 in `goal-objective2.md` is explicit that kraft labels must be exact.

#### Hard gate held

`review.html` was **not** generated and `--require-detector-validator` was not bypassed. No train, no export, no promote, no `/ai` change.

---

### Count-exactness policy (user directive, 2026-07-27 — supersedes earlier wording)

> "End result should be exact count for OCR when the AI model will be ready for production server utilization. No compromise on incorrect counts unless its a chopstick count or black and white soya sauce packet."

| Class | Count requirement |
|---|---|
| kraft paper bowl | **exact** — and OCR sticker count must equal it |
| black soya sauce cup | **exact** |
| red teriyaki sauce cup | **exact** |
| white wayo dip cup | **exact** |
| orange chili mayo cup | **exact** |
| wooden chopstick tip | tolerant — exact count not required |
| black and white soya sauce packet | tolerant — exact count not required |

Note the tension with `goal-objective2.md` requirements 4–5 ("chopsticks should not escape detection"). Read together: chopsticks must still be **detected and counted** — they may not be silently dropped — but their exact number is no longer a gating requirement. Do not use this to disable chopstick detection.

## Mission (unchanged)

Human-audited YOLOE-26X segmentation for seven fridge classes. Heavy work on Kaggle. Train only after a **fresh all-20-image human pass**. Promote nothing until frozen count gate `assertion_pass_rate >= 0.95` (8 cases / 58 assertions), then text-prompt ONNX + Ubuntu SAHI, then controlled `/ai` re-enable.

### Fixed seven-class order

| ID | Exact class / prompt |
|---:|---|
| 0 | kraft paper bowl |
| 1 | black soya sauce cup |
| 2 | red teriyaki sauce cup |
| 3 | white wayo dip cup |
| 4 | orange chili mayo cup |
| 5 | wooden chopstick tip |
| 6 | black and white soya sauce packet |

Physical color rule: soya=black, wayo=white, teriyaki=red, chili mayo=orange.  
Chopsticks: 34 physical = 17 `chopstick_tip` labels. Packets are advisory counts.

### Non-negotiables

- SAM / YOLOE output is proposal-only, never ground truth.
- Do not re-enable `/ai`, train, export, or promote until gates pass.
- Do not inherit V39 pass decisions into any new review.
- Do not invent masks/boxes from human count corrections.
- Do not train on Windows; Kaggle owns GPU work.
- Never expose HF_TOKEN, Kaggle credentials, or `.env` values.

---

## Frozen sources of truth

| Item | Path / value |
|---|---|
| Count benchmark | `training/autoresearch/benchmark_cases/reviewed_fridge_counts.json` |
| Gate | 8 images, 58 assertions, `assertion_pass_rate >= 0.95` |
| Edge Hafencity | 26 boxes, 6 black soya, 7 red teriyaki, 7 white wayo, 5 orange chili, 34 chopsticks |
| Historical rejected hybrid | 51/58 = 0.87931; do not reuse |
| `/ai` | `AiModelTestController::analyze()` → HTTP 503, `disabled_pending_human_approved_yoloe_26x_seg` |

---

## Live Kaggle finding (2026-07-25, Playwright MCP)

### Access notes for next assistant

- Headed Chrome was already open under **Playwright MCP** profile:  
  `C:\Users\mib34\AppData\Local\ms-playwright-mcp\mcp-chrome-15fda5f`
- Launch flag used **`--remote-debugging-pipe`** (not TCP 9222).  
  `playwright-cli list` reported `(no browsers)` — that is expected; use **MCP browser tools** (`browser_run_code_unsafe`, `browser_find`, `browser_snapshot`) instead of assuming CLI session ownership.
- Window title observed: `sushi-yoloe26-fridge-inventory-counting - Google Chrome`

### Tabs observed

| Index | URL | Role |
|------:|-----|------|
| 0 | `https://www.kaggle.com/code/mib348/sushi-yoloe26-fridge-inventory-counting/edit` | Editor |
| 1 | `.../log?scriptVersionId=337823202` | Older failed/P100-path log (Version 32) |
| 2 (active) | `...?scriptVersionId=337824597` | **V44 Version 33 viewer** |

### Notebook identity (matches scratchpad-training)

```text
Kernel:           mib348/sushi-yoloe26-fridge-inventory-counting
Version:          33 of 33
Script Version:   337824597
Title:            Sushi YOLOE26X Assisted Label Review V44
Accelerator:      GPU T4 x2
Runtime shown:    1h 17m 29s · GPU T4 x2
Input dataset:    mib348/sushi-yoloe26x-assisted-label-inputs (UI also showed v31-sam31 naming)
Model input:      safebet1034/sam3-1 (sam3.1 multiplex pin)
```

Immutable hashes still expected for a valid V44 package (from training handover):

```text
runtime SHA-256:     aa65d7d73407892c10be2c5f78e599c85b0cb8375813241710cb757d262b8cde
input SHA-256:       2bc38d3b440dceaa0da79c7e6204013edf9967c4c86572e0315d4fd8357dd43f
correction-manifest: 4ffacc850d2a8d6576c3fac5cb8dc210768627aa3426e0192ee8fd82e3e5eead
workflow revision:   v44_dense_exemplar_hires_visual_recovery_fail_closed_review
```

### UI status

- Banner: **“You are viewing the last run of the kernel, which had an error.”**
- Logs badge: **`4648.9 second run - failure`**
- Output badge: **`65 files`** (partial outputs may exist; do **not** treat as accepted quarantine)
- Link offered to “last successful run” (`scriptVersionId=337311998`) — that is **not** V44 approval; ignore for promotion

### What succeeded before the fail

1. Fresh-process dependency/CUDA preflight **passed**
   - numpy `1.26.4` (must stay `<2` for SAM 3.1 pin)
   - torch `2.10.0+cu128`, torchvision `0.25.0+cu128`
   - opencv `4.11.0`, RapidOCR present
   - cuda device: **Tesla T4**
2. pip noise: many environment packages want numpy≥2; **intentional pin** for SAM — do not “fix” by upgrading numpy on the host image without a tested contract change
3. YOLOE-26X-seg checkpoint download completed (`yoloe-26x-seg.pt`, ~163.7 MB)
4. Long SAM 3.1 multiplex session work ran (many `add_prompt` / `empty_cache` / session remove log lines)

### First true terminal error

```text
File "/kaggle/working/assisted_label_review.py", line 6077, in run
    raise RuntimeError(
RuntimeError: The correction-guided SAM 3.1 pass did not produce twenty complete
diagnostics with useful target proposals; review status remains incomplete.
```

Then notebook cell raised:

```text
CalledProcessError: ... assisted_label_review.py ... returned non-zero exit status 1
```

Interpretation (plan Phase 1):

- This is a **fail-closed usefulness gate**, not a P100/CUDA driver crash.
- P100 remains banned (Version 32 / `337823202` path); this run correctly used T4 x2.
- **Do not** download and human-review a partial artifact as if it were a complete V44 quarantine.
- **Do not** mark pass/reject rows to bypass the gate.
- **Do not** launch a duplicate identical rerun without reading which images/classes failed the correction-guided diagnostics.

### Downstream cells

The post-run archive validation cell (expects `awaiting_twenty_image_pass_reject`, 20 sheets, 20 polygons, audits, train/promo false) did **not** complete successfully as a green gate. Treat V44 as **not review-ready**.

---

## Progress already completed in this workspace (historical context)

Do not redo unless evidence shows regression:

| Area | State |
|---|---|
| Goal / plan / training memory docs | Present under `training/` |
| V39 local artifact | Correction audit only: 6 pass / 14 reject — **not** a training source |
| Local tooling | AnyLabeling, contact sheets, local review page, ingester, trainer, ONNX export, SAHI scripts exist |
| Small reviewed datasets | 5-image style datasets exist; not the 20-image gold standard |
| Live `/ai` | Correctly disabled pending 26X approved path |
| V44 local bundle | `training/autoresearch/kaggle_label_factory/kaggle_assisted_label_bundle_v44_dense_exemplar_hires_visual_recovery/` |
| V44 tests | `tests/test_assisted_label_review.py`, `test_prepare_kaggle_assisted_label_bundle.py`, `test_generate_local_review_page.py` |

V44 design intent (from training scratchpad): denser chopstick-tip recovery using Byteclub/Barmbek/Sankt Georg exemplars; 960px tiles, 0.35 overlap, 1280 infer for tips. Human counts never become geometry.

---

## What the next assistant should do (ordered)

### Phase A — Diagnose V44 failure (current phase)

1. Re-open Kaggle Version 33 / `337824597` (or Output/Logs) and capture **which images/classes** failed the correction-guided usefulness gate.
2. Prefer Output artifacts if any partial `run_manifest.json` or correction summaries survived in the 65 files — download **without overwriting** V39 evidence; put under a new dated path, e.g.  
   `training/autoresearch/results/yoloe26x_v44_failed_337824597_YYYYMMDD/`
3. Map the error to source:  
   `training/autoresearch/kaggle_label_factory/assisted_label_review.py`  
   (runtime line ~6077 in the uploaded script; search the local file for the exact RuntimeError string).
4. Make a **narrow, test-backed** repair only if the failure is a true code/config defect.  
   Rerun focused unit tests before any new Kaggle push:

```powershell
cd C:\laragon\www\laravelshopifypartnerapp
& .\.venv\Scripts\python.exe -m unittest discover -s tests -p test_assisted_label_review.py
& .\.venv\Scripts\python.exe -m unittest discover -s tests -p test_prepare_kaggle_assisted_label_bundle.py
& .\.venv\Scripts\python.exe -m unittest discover -s tests -p test_generate_local_review_page.py
```

5. If the gate is correctly failing on empty/useless proposals, plan **V45** (or a carefully versioned V44b) proposal-lane change — one narrow hypothesis — then a **fresh** Kaggle review-only run on **T4 x2** (never P100).

### Phase B — Only after a successful review-only run

Follow [scratchpad-plan.md](scratchpad-plan.md) Phases 2–8 exactly:

1. Validate quarantine (`awaiting_twenty_image_pass_reject`, 20/20 sheets & polygons, 140/140 SAM prompts, train/promo false).
2. `generate_local_review_page.py` → human **all-20 pass** (fresh; no V39 inheritance).
3. `ingest_review_decisions.py` → gold-standard dataset.
4. Kaggle `train_gold_standard_seg.py` validate then `--train`.
5. Frozen count gate ≥ 0.95.
6. Text-prompt ONNX + Ubuntu SAHI must also pass the gate.
7. Only then controlled `/ai` promotion.

---

## Explicit do-not list (session-specific)

- Do **not** assume the prior handoff’s “still running / no terminal failure” line without re-checking Kaggle. That claim was correct at leave-behind; by the next inspect the same job had **failed**.
- Do **not** re-run the same notebook blindly because logs looked idle or buffered — this run finished as a fail-closed usefulness gate, not a hang.
- Do **not** use Output “65 files” as approval or training data without the hard gates.
- Do **not** re-enable `/ai` or revive the 26S hybrid.
- Do **not** lower the 0.95 threshold or edit `reviewed_fridge_counts.json` to make a model pass.
- Do **not** copy proposal polygons straight into `sam_annotation_batch/labels/`.

---

## Decision tree (updated after live inspection)

```text
V44 Version 33 / 337824597 status?
  Completed with failure (CONFIRMED 2026-07-25)
    -> Capture first terminal error (DONE: correction-guided usefulness gate)
    -> Identify failing images/classes from logs/partial Output
    -> Focused repair + unit tests OR design next proposal revision
    -> Fresh review-only Kaggle run on T4 x2
    -> Then Phase 2+ of scratchpad-plan.md

Did all 20 fresh reviews pass?
  No  -> quarantine + repair proposals -> full re-review
  Yes -> ingest -> Kaggle train -> count gate >= 0.95 -> ONNX/SAHI -> /ai
```

---

## Session work log

| When | Who / tool | What happened |
|---|---|---|
| Pre-handover | Prior work | V44 bundle built; Kaggle Version 33 / `337824597` launched on T4 x2; left **running** with no terminal failure; production not altered; training + plan scratchpads written with that live claim |
| 2026-07-25 | Grok assistant | Took over from those scratchpads; read `training/` overview, then `scratchpad-training.md` + `scratchpad-plan.md` |
| 2026-07-25 | Grok assistant | Connected to open Playwright MCP Chrome; re-checked Version 33 live (did not cancel/alter the job) |
| 2026-07-25 | Grok assistant | Observed run had **completed as failure** since prior handoff; captured terminal error (correction-guided usefulness gate); wrote this scratchpad |
| 2026-07-25 | Grok assistant | User confirmed prior handoff wording (“still actively running… no terminal failure… left running… did not alter production”); timeline section added so next agent sees both states clearly |
| 2026-07-25 | Grok assistant | Goal harness: verified AC1–5 against repo; unit tests train(11)+export/sahi(8)+assisted(71) OK; train validate fail-closed on unapproved batch; evidence under grok-goal implementer scratch; halted at human labeling (0/20 labels) |
| 2026-07-25 | Grok assistant | User: continue from leave-behind (V44 left running, production unaltered) while following goal-objective. Live re-check: V33 failed. Confirmed phases 1–5 deliverables; issued Phase 6 HALT (AnyLabeling → Proceed). No production change. |
| 2026-07-25 | Grok assistant | User clarified human labeling already done once. Inventory: V39 6 pass/14 reject (correction audit only); 6 AnyLabeling rectangle JSONs (not YOLO polygons in labels/); 5-image reviewed datasets; Edge/8-case count GT. No all-20 gold YOLO seg approval_manifest. |
| 2026-07-25 | Grok assistant | Proceeded per scratchpads: confirmed V33 failure; fixed reference usefulness fail-close + richer error; 74 assisted + 11 train + 8 export/sahi tests OK; built V45 Kaggle bundle; halt for human all-20 (no train). |

---

## Suggested first command block for next agent

```powershell
cd C:\laragon\www\laravelshopifypartnerapp
# 1) Re-read goal-objective.md + scratchpad-training.md + this delta
# 2) Poll Kaggle slug mib348/v50-exact-h-current until output files appear
# 3) Download outputs into a NEW results directory (do not overwrite V39/V46/V48)
# 4) Run detector_validator_agent.py against the downloaded quarantine
# 5) Only if validator > 0.95, generate_local_review_page.py --require-detector-validator
```

When diagnosis is complete, append a new dated section under **Session work log** and update the **One-line status** at the top. Do not delete prior evidence blocks; strike-through or mark superseded if needed.

---

## Class identification inventory (2026-07-25 — do not reinvent)

Human + pipeline class-ID work already lives under `training/`:

| Asset | Path | Role |
|---|---|---|
| 6 audited reference rectangles | `sam_annotation_batch/images/*.json` (also `annotation_backups/`) | Human class ID seeds: Kraft Box→0 … Chopstick Tip→5, Soya Sauce Packet→6 |
| Fixed raw→class map | `assisted_label_review.py` `RAW_LABEL_TO_CLASS_ID` | Converts AnyLabeling display labels to YOLOE 7-class IDs; quarantines aggregate `Chopstick` |
| V46 input bundle references | `kaggle_assisted_label_inputs_dataset_v46/assisted_label_inputs.bundle` → `reference_annotations/` | Same 6 refs frozen for Kaggle recovery |
| Human count corrections | `.../yoloe26x_sam31_assisted_review_kaggle_v39_20260724/review_corrections_current/` | Per-image `requested_counts` / missing class notes for the 14 rejects |
| Tip recovery design | V44 denser exemplar bank (Byteclub 48 / Barmbek 39 / Sankt Georg 18); class 5 tiles 960/0.35/1280 | Audited visual recovery class IDs `{1,5}` black soya + tips |
| Soft packaging | V46 `kaggle_assisted_label_bundle_v46_soft_usefulness_packaging` runtime `4c23b3e0…` | Hard SAM contracts still fail-closed; usefulness missing-class is soft so hard cases reach human review |

### Live local review URL

- **http://127.0.0.1:8765/review.html** — serving V39 package directory (only complete 20-sheet + polygon media on disk).
- Regenerating with current `generate_local_review_page.py` correctly marks it `withdrawn_not_ready_for_review` (missing post-V39 audited/tiled recovery summaries). That is expected; V39 is correction evidence, not a fresh all-20 gold approval.
- Pass-quality proposals for tip/soya still need a **V46 Kaggle T4×2** run (package ready locally; no Kaggle CLI/creds on this host at check time).

| 2026-07-25 | Grok assistant | V46 Version 34 / sv337857616 success T4x2 4545s; downloaded 191MB quarantine zip to results/yoloe26x_sam31_assisted_review_kaggle_v46_20260725; AUDIT_PASS (20 sheets, 20 polys, train/promo false); soft tips missing on mb-energy+techhub only; generate_local_review_page -> review_ready=true; served http://127.0.0.1:8765/review.html status 200; /ai structural 503; mempalace drawer+diary+KG filed. |
| 2026-07-25 | Grok assistant | User partial V46 review (9/20: 1 pass / 8 reject / 11 pending); exported review_decisions.local.json + review_partial_summary.md; OCR/usefulness code deferred; halt per user “may suffice for now”; mempalace drawer+diary+KG updated. |
| 2026-07-25 | Grok assistant | /goal resume: fixed kraft OCR v3 (expanded pad + geometric fallback), recovery while final&lt;human estimate, package usefulness ≥0.95 hard gate; 75 assisted tests pass; built V47 bundle runtime 7ec503bc…; no train/promote//ai. |


2026-07-26 V48 soft packaging Kaggle success sv337947249; results yoloe26x_sam31_assisted_review_kaggle_v48_20260726; review.html ready; usefulness soft 14/20; /ai still 503; human pass/reject next.
| 2026-07-26 | Codex assistant | Re-read goal + mempalace + scratch state; rebuilt a clean V50 bundle from current correction-manifest SHA `3192b1aa2ebaca2cde9c15521d3c3430216fac66464c11407c35c81b8a4e28dc`; published notebook `mib348/v50-exact-h-current`; verified pulled Kaggle source contains the new SHA and not stale `4ffacc...`; confirmed live execution from log stream (`Verified frozen assisted_label_inputs.bundle`); latest status `RUNNING`, but `kernels_output(...)` still shows 0 files; handoff remains blocked on run completion → artifact download → detector-validator >0.95; `/ai` unchanged at 503. |
| 2026-07-27 | Claude assistant | Re-anchored on `scratchpad.md` + `goal-objective2.md` (both byte-identical to prior read). Executed this file's own next-agent block: polled Kaggle → `v50-exact-h-current` = **`complete`** (68 files; the "RUNNING / 0 output files" line above was stale). Downloaded all 68 outputs into the NEW dir `results/yoloe26x_sam31_assisted_review_kaggle_v50_20260726/` (0 failures; V39/V46/V48 untouched). Re-verified correction-manifest SHA `3192b1aa…` on disk. Structural audit passed: 20 contact sheets, 20 polygons, `training_authorized:false`, `release_gate_passed:false`. Ran `detector_validator_agent.py` → **BLOCKED, accuracy 0.0000, scored=12 passed=0 failed=12**, report written next to the quarantine. Diagnosed the block into Family A (kraft OCR recall — matches on only 4/20 images, sole cause of failure on 4 otherwise-perfect images) and Family B (chopstick tips proposed at 0 on 4 images; black soya undercount up to 4 v 12). Did **not** generate `review.html`; no train/export/promote; `/ai` still 503. |

---

## V51 code repair session (2026-07-27, Claude assistant) — measured, tests green

**Detector-validator on the frozen V50 proposals: `0.0000` → `0.5000` (6 of 12 scored images pass).**
`/ai` still 503. No train, no export, no promote, no `review.html`. 83 unit tests pass.
Local inner loop that reproduces the real gate exactly from the V50 polygons:
`%TEMP%/claude/.../scratchpad/eval_gate.py`.

### Does YOLOE-26X do OCR? No — answered from the docs, as asked

YOLOE has a text **encoder**, not a text **reader**. It turns *your* class prompts
("kraft paper bowl") into embeddings and matches them against image regions. It has no
character-decoding head and cannot transcribe text that appears in a photo. Confirmed in
three places: the Ultralytics YOLOE docs (three prompt modes — text / visual / prompt-free,
no OCR anywhere), the YOLOE-26 papers (RepRTA + SAVPE + LRPC, all prompt-conditioning), and
`ultralytics/models/yolo/yoloe/predict.py`, whose predictors consume prompt embeddings and
emit boxes/masks/class-ids only. **RapidOCR (`rapidocr_onnxruntime`, PP-OCR in ONNX) reads
every sticker; that stays a separate component.** One model cannot do both.

### What changed, and why each change is safe

| # | Change | Measured effect |
|---|---|---|
| 1 | `COUNT_TOLERANT_CLASS_NAMES` — chopstick tips + packets are count-tolerant but **must still be detected** (`final > 0` when the human says items exist) | barmbek passes (39 v 40 tips); the four 0-tip images correctly still fail |
| 2 | `declared_occlusion_allowances()` — visible-exact / hidden-tolerant, driven by the reviewer's own sentence | byteclub passes; fires on **1 of 20** images |
| 3 | Kraft OCR clause scoped to images where the human quantified kraft; requirement 1 moved to a new batch-level `kraft_ocr_gate_passed` | garbe, saco, searenergy, stroeer pass — all four already matched every human number |
| 4 | `detector_validator_agent.py` now passes `notes` through | without this the occlusion rule was **dead code on the production CLI path** |
| 5 | Door-reflection filter replaced with a geometric door-pane detector | 22 real reflections removed (TP 22 / FP 0 / FN 0); the old mirror rule was **destroying 111 real proposals per run** |
| 6 | Kraft false positives: sauce-cup stacks rejected by width relative to the sauce cups in the *same* photo | class-0 boxes 161 → 105, 100% on-class; the six audited references lose nothing |
| 7 | Chopstick recovery: appearance-diverse exemplars + centre-distance support for small objects + an audited single-reference floor | class-5 exemplars now cover **both** sleeve families; tip boxes are no longer all deleted by an unreachable IoU test |

### Two traps found and refused

- **A "shortfall-capped stack splitter" was proposed and REJECTED.** It splits a cup-stack box
  into exactly as many pieces as the human count is short. That violates the standing rule
  *"Do not invent masks/boxes from human count corrections"* and is self-fulfilling — it would
  guarantee an exact match and make the validator meaningless. Proof the geometry cannot stand
  on its own: running the same 0.55 height/width estimator **unconditionally** over-counts on
  **26 of 37** class rows (stroeer class 1: 4 → 11 vs human 4; searenergy class 4: 2 → 6 vs human 2).
- **The appearance-diversity exemplar picker was initially applied unscoped** and silently swapped
  2 of 3 *sauce-cup* exemplars for the two weakest banks, risking five currently-exact
  no-compromise counts. Now scoped by a measured property (median longest side ≤ 128 px):
  tips 24.8 px in scope, soya cups 200.9 px out of scope. Class-1 exemplars verified restored to
  the exact V50 selection.

### statista: the second column is VISIBLE — scored exactly, no tolerance

Three independent checks agree. The photo shows **3 columns × 7 rows = 21 kraft bowls, every
sticker crisply legible** (FALAFEL / GARDEN / CHICKEN / LACHS / APFEL / VEGE. / EDAMAME / CRISPY /
GREEK / MARVEL / FITNESS / OSAKA …), plus a 22nd golden OSAKA bento on the sauce shelf = the
human's 22. The sauce shelf carries two side-by-side columns per colour at the same shelf level;
the model already gets teriyaki 10/10 and wayo 8/8 that way, which proves the rear column is
detectable. So `"same level 2 columns"` is **planar layout language, not occlusion** — statista
gets no allowance. Only byteclub's `"2 are stacked behind 5 front ones"` is real occlusion.

**statista is the single strongest confirmation of the reviewer's premise:** on the clearest
image in the set the detector found 13 of 22 bowls and OCR read 10 of 22. The bottleneck is
detection and recall, not sticker legibility.

### Honest remaining gap — needs a fresh Kaggle run to measure

Six images still fail, and **all six fail on recall, not on scoring**:

| Image | What is still short |
|---|---|
| mb-energy | chopstick tips 0 v 36 |
| mega-eg | black soya 2 v 3, tips 0 v 28 |
| startup-labs | black soya 1 v 3, tips 0 v 13 |
| techhub | black soya 1 v 2, tips 0 v 13 |
| statista | black soya 4 v 12, kraft 13 v 22, chili mayo 7 v 8 |
| zeisehof | black soya 1 v 5, teriyaki 1 v 3, wayo 1 v 2 |

Changes 5, 6 and 7 are **inference-time** fixes: they cannot show up against the frozen V50
polygons, because the 111 boxes the old reflection rule deleted and the tip boxes the old
support rule deleted are simply not in that file. **V51 on Kaggle (T4×2, never P100) is required
to measure them.** With 12 scored images the bar needs **12/12** — 11/12 = 0.9167 fails.

Two known hard cases to raise with the reviewer rather than silently engineer around:
1. **zeisehof / techhub / mega-eg are motion-blurred past human legibility** (verified at 3× upscale).
   Their kraft OCR reads 0. Requirement 1 assumes stickers are readable; on these frames they are not.
2. **8 of the 20 images carry no human numbers at all**, so the accuracy denominator is 12, not 20.
   Requirement 8 says ">95% of all those images" — as written the agent could report 1.0000 having
   checked only 12.

| 2026-07-27 | Claude assistant | Fanned out 6 measured diagnostic lanes + adversarial verifiers. Shipped changes 1-7 above; detector validator 0.0000 -> 0.5000 on frozen V50; 83 tests green. Rejected the human-count-driven stack splitter and re-scoped the exemplar picker after a verifier caught real class-1 collateral. `/ai` untouched at 503; no train/export/promote; `review.html` not generated. Next: build the V51 bundle and run on Kaggle to measure the recall fixes. |

### Verification round 2 (same day) — three defects found in the shipped code, all fixed

The first workflow lost 5 agents to API 529s, including **both** adversarial verifiers for the
kraft false-positive filter and the whole kraft-OCR lane. Re-ran them. They found real problems:

1. **The kraft ruler was a plain median and could be inflated by merged cup boxes.** Proven on the
   live function: cup widths (100,100,600,640) give a ruler of 350 and a genuine 500 px bowl is
   deleted at ratio 1.43. On zeisehof — the one image whose kraft count already equals the
   reviewer's 9 — a large enough pair of merged boxes took it to 0. The filter also runs BEFORE
   the exact-count trim, so at runtime it sees a larger, noisier cup set than the emitted polygons.
   **Fixed**: trimmed median (drop anything outside 0.6x-1.6x of the raw median, re-measure), plus
   fail-open when the survivors disagree, plus a hard "may never empty the class" guard.
   A low quantile was tried first and rejected — techhub's two 51 px half-cup fragments dragged it
   down 2x and let three cup stacks through. Both tails are polluted; trimming both is the answer.
   Real 20-image behaviour unchanged at 161 -> 105.

2. **The small-object merge gate of 128 px reached real sauce cups.** I had raised it from 64 to
   clear harburg's 100 px audited tips — but harburg is a REFERENCE image and reports
   `audited_visual_recovery_status = "not_applicable_reference"`, so its tips are never a target.
   Measured on the fourteen target images, the smallest real sauce-cup box is **110 px**, so 128 had
   negative margin and would have collapsed statista's four class-1 boxes on the image that needs
   twelve. **Fixed**: back to 64 (1.72x headroom), and it must compare the LONGEST side — statista's
   cup boxes are only 43 px on their short axis.

3. **`kraft_ocr_gate_passed` is gameable and currently flatters the result.** It measures
   *consistency* (sticker_count == kraft box count), not *correctness*. Measured with real RapidOCR
   over all 20 photos: consistency 19/20 = 0.9500, but only **49 of 96** read texts are the label
   actually printed on that bowl — **49/130 real bowls = 37.7%**. A crop with no legible sticker
   passes by stealing its neighbour's text through the 0.18 crop padding. A measured fix exists
   (fuzzy sale-tag rejection + crop-geometry gate + nearest-sticker clustering + BOWL-word repair +
   3-letter minimum) taking exact labels 49/105 -> 84/105 (46.7% -> 80.0%), no image worse:
   statista 6->12, mb-energy 3->7, stroeer 3->6, barmbek 4->8. Consistency *drops* 0.95 -> 0.90
   because one image stops faking a read — the metric becoming honest. **NOT YET APPLIED.**
   Detail in workflow journal `wf_23c32d17-ed3`.

Also confirmed clean by AST scan: `sauce_cup_stack_filter_record` and `reflection_filter_record` are
bound on every path (no continue/break/return in the per-image loop), `all_supported` is initialised
before all uses, every `per_class` row carries `support_tier`, the post-NMS guard is still reachable,
and no reference to the deleted `REFLECTION_MIRROR_CENTER_TOLERANCE` survives anywhere in the repo.

**85 tests pass. Gate still 0.5000 on frozen V50. Independent counts confirmed by eye: statista 22
kraft bowls, zeisehof 9 — both matching the reviewer exactly.**

### Requirement 1 CLOSED — kraft label accuracy patch applied and verified (2026-07-27)

Applied the measured OCR patch: fuzzy sale-tag rejection (`KRAFT_BOWL_OCR_NOISE_ROOTS` +
`kraft_bowl_ocr_token_is_shelf_noise`, matching by containment / similarity / shared run so the
same German sale word survives being misread as HERGESLELC, AIBERPREIS, VEIKAUF, FRELTAG…),
BOWL-word repair (`kraft_bowl_ocr_repair_bowl_word`, last token only, so a dish word can never be
rewritten), a 3-letter minimum token, and a crop-geometry gate + nearest-sticker clustering in
`resolve_kraft_bowl_dish_from_ocr_rows`. That last one is what stops a bowl stealing its
neighbour's text through the 0.18 crop padding — the loophole that let the old consistency metric
read 0.95 while only 37.7% of labels were right.

Still a REJECT list of shelf furniture, which is stable. NOT a menu allow-list, which never is —
off-menu dishes (MARVEL, CRISPY, TEMPURA GARNELE) still read fine.

Verified with the REAL RapidOCR on the real V50 boxes, after the sauce-cup-stack filter:

| image | kraft boxes | stickers read | labels |
|---|---:|---:|---|
| stroeer | 6 | 6 | LACHS, LACHS, CHICKEN, CHICKEN, VEGE, GARDEN — **exact match to the by-eye count** |
| statista | 13 | 13 | 12 clean + one glyph slip (GHICKEN); every name is a real dish in its 3x7 grid |
| springer | 6 | 6 | 5 clean + TEMRURE (TEMPURA GARNELE) |

Lane measurement across all 20: exact labels **49/105 -> 84/105 boxes (46.7% -> 80.0%)**, no image
worse; statista 6->12, mb-energy 3->7, stroeer 3->6, barmbek 4->8.

Two unit-test fixtures had to be corrected as part of this, and the reason matters: they returned
OCR boxes at hard-coded 1000x1000 pixel coordinates regardless of the view actually passed in.
Real engines report in the coordinates of the view they were handed, and the runtime pads then
upscales every bowl crop, so those fixtures were testing the geometry gate against a crop shape
that never occurs. Both now place boxes as a fraction of the real view. **85 tests pass.**

**Remaining gap is now purely RECALL, and only Kaggle can measure it:** statista finds 13 of 22
bowls, springer 6 of 9. Every label we do read is right; there are just not enough boxes yet.

### V51 LAUNCHED on Kaggle (2026-07-27) — measuring the recall fixes

```text
kernel      : mib348/v51-recall-fixes   (Version 1, kernelId 128791239)
url         : https://www.kaggle.com/code/mib348/v51-recall-fixes
runtime sha : cd34b2f6137fac876e268ac98e546863dab7aba324f9bcea531d0c7d00565c5f
input bundle: 2bc38d3b440dceaa0da79c7e6204013edf9967c4c86572e0315d4fd8357dd43f (unchanged, not re-uploaded)
correction  : 3192b1aa2ebaca2cde9c15521d3c3430216fac66464c11407c35c81b8a4e28dc
dataset     : mib348/sushi-yoloe26x-assisted-label-inputs
model       : safebet1034/sam3-1/PyTorch/default/1
gpu/private : enableGpu true, isPrivate true, enableInternet true
status      : running
```

**Freshness verified BEFORE trusting the run** (this project has been burned by a stale push
before): pulled the source back from Kaggle and confirmed it contains the V51 runtime SHA
`cd34b2f6…` and does NOT contain the previous `c1928d95…`. The base64 payload in the notebook
decodes to a byte-identical copy of the local runtime and contains every shipped fix —
`filter_sauce_cup_stack_kraft_bowls`, `detect_fridge_door_pane_indices`,
`merge_small_object_reference_support`, `select_appearance_diverse_references`,
`kraft_bowl_ocr_token_is_shelf_noise`, `declared_occlusion_allowances`,
`COUNT_TOLERANT_CLASS_NAMES`, `kraft_ocr_gate_passed` — and no longer contains the deleted
`REFLECTION_MIRROR_CENTER_TOLERANCE`.

**What V51 is expected to move, and why it could not be measured locally.** The reflection
rewrite gives back the 111 real proposals the old mirror rule destroyed per run; the chopstick
exemplar/support fixes let tip boxes survive instead of being deleted by an unreachable IoU test.
Neither can appear in the frozen V50 polygon files, because the boxes they rescue were never
written there. Everything else (scoring, kraft false positives, OCR labels) was already measured
locally and is unchanged by the run.

**On completion, next agent:** download into a NEW dated results directory (never overwrite
V39/V46/V48/V50), then run `detector_validator_agent.py`. Bar is 12/12 on the scored images —
11/12 = 0.9167 fails. Do NOT generate `review.html` unless the validator allows handoff. `/ai`
stays 503 until the frozen count gate passes separately.

#### V51 Version 1 FAILED on a P100 — not a code defect

```text
torch.AcceleratorError: CUDA error: no kernel image is available for execution on the device
UserWarning: Flash Attention is disabled as it requires a GPU with Ampere (8.0) CUDA capability.
```

The dependency preflight died before any model work. Cause: the push sent
`enable_gpu: true` only, so Kaggle chose the accelerator — and it chose a **P100**, the card this
project banned back at V32. The pinned SAM 3.1 / Torch build has no compiled kernels for it.

Proof it is the machine and not the code — pulled metadata side by side:

| kernel | machineShape | outcome |
|---|---|---|
| `v50-exact-h-current` | `NvidiaTeslaT4` | complete |
| `v51-recall-fixes` v1 | `Gpu` (generic) | error at preflight |

**Durable fix applied**: `kernel_metadata()` in `prepare_kaggle_assisted_label_bundle.py` now emits
`"machine_shape": "NvidiaTeslaT4"`, so a rebuilt bundle carries the requirement instead of relying
on whoever pushes it to remember. V51 Version 2 pushed with `machineShape: NvidiaTeslaT4`.

CAVEAT for the next agent: pulling the metadata straight back still reported `Gpu`, so it is NOT
yet proven that the API push honours that field. Do not trust the metadata — read the LOG. The
preflight prints `"cuda_device": "<name>"` on success, and a P100 shows up as
`no kernel image is available` plus the Ampere flash-attention warning. If Version 2 lands on a
P100 again, set the accelerator in the Kaggle UI (Playwright MCP is available) rather than pushing
blind a third time.

#### V51 Version 2 CONFIRMED on T4 x2 (2026-07-27) — two corrections recorded

Read live from the Kaggle UI (the user's logged-in Playwright profile
`mcp-chrome-eeffe4e`; the REST API withholds logs until a run terminates, the UI streams them):

```text
Accelerator: GPU T4 x2
{"status": "fresh_process_dependency_cuda_preflight_passed", "numpy": "1.26.4",
 "torch": "2.10.0+cu128", "torchvision": "0.25.0+cu128", "opencv": "4.11.0",
 "rapidocr": "RapidOCR", "cuda_device": "Tesla T4"}
```

**Correction 1 — the `machineShape: "NvidiaTeslaT4"` push DOES work.** The earlier note said it was
unproven because `kernels/pull` still reported `Gpu`. That is a reporting gap in the pull response,
not a failed setting. Do not re-push or hand-edit in the UI on the strength of that field.

**Correction 2 — `Flash Attention is disabled ... requires a GPU with Ampere (8.0) CUDA
capability` is NOT a P100 signature.** It appears on T4 as well, because T4 is Turing (7.5). Only
`CUDA error: no kernel image is available for execution on the device` actually indicates the
banned card. A monitor keyed on the flash-attention warning would falsely abort a healthy run.

**How to check a live run's GPU in future:** REST `kernels/output` returns nothing while a kernel
is running, so the device name is unavailable by API until it ends. Either wait for the terminal
state, or open the notebook page in the logged-in browser profile and read the Accelerator field
plus the preflight JSON in the log table.

## V51 TERMINAL RESULT (2026-07-27, verified) — real gains, headline unchanged

`mib348/v51-recall-fixes` Version 2, **complete** on GPU T4 x2. 68/68 outputs downloaded to a NEW
directory `results/yoloe26x_sam31_assisted_review_kaggle_v51_20260727/` (V39/V46/V48/V50 untouched).
Structurally valid: 20 contact sheets, 20 polygons, `training_authorized:false`,
`release_gate_passed:false`.

```text
required-item count accuracy 0.5000  (scored=12 passed=6 failed=6)
kraft_ocr_consistency        0.7500  (15/20)   <- was 0.2000 (4/20) in V50
human_handoff_allowed        False
```

### What genuinely improved

| | V50 | V51 |
|---|---|---|
| kraft OCR consistency | 4/20 = 0.20 | **15/20 = 0.75** |
| statista kraft paper bowl | 13 v 22 FAIL | **22 v 22 PASS** |
| statista orange chili mayo | 7 v 8 FAIL | **8 v 8 PASS** |
| statista black soya | 4 v 12 | 5 v 12 (still short) |
| passing images | 0 | 6 |

`review.html` NOT generated. `/ai` still 503. No train / export / promote.

### The two blockers that remain, precisely

**A. Chopstick tips still 0 on four images (mb-energy, mega-eg, startup-labs, techhub).**
The exemplar-diversity fix DID work — the class-5 bank is now byteclub(48) + sankt-georg(18) +
**harburg(5)**, the flat-bamboo-blade family, confirmed in
`inference_parameters.audited_visual_recovery_reference_plans['5']`. And it moved raw detections
on images that previously produced nothing: mega-eg 0->2, techhub 0->1, mutabor 0->6,
no-limits 0->3, mb-energy 4->11, springer 1->10.

But `supported_instance_count` is still 0 on all of them, with `support_tier =
cross_reference_support` — meaning the single-reference floor never fired, which means
`floor_candidates` was EMPTY, which means **every post-NMS class-5 box was larger than the 64 px
small-object gate**. Only springer reached the floor (`single_reference_small_object_floor`,
supported=1).

Measured audited tip scale, as a fraction of the image it came from:
barmbek 0.74%, byteclub 0.82%, sankt-georg 1.78%, harburg 4.64% of width. On the 3024-wide
targets the 64 px gate is 2.12% of width, on the 1620-wide ones 3.95%. So the gate is NOT
obviously too tight in relative terms — which points at a different cause: the surviving boxes are
probably multi-tip blobs (the chopstick HOLDER), not individual tips. raw 11 -> post_nms 6 on
mb-energy against a human count of 36 is consistent with that.

NEXT AGENT: do not just raise the gate. First persist and look at the actual class-5 box
coordinates (they are currently discarded when unsupported, so they appear in NO artifact — that
is the single biggest obstacle to diagnosing this). Add their bbox to the per_class record, rerun,
and measure whether they are tips or holders.

**B. black soya sauce cup undercounted on five images** — mega-eg 2v3, startup-labs 1v3,
techhub 1v2, statista 5v12, zeisehof 1v5 (+ zeisehof teriyaki 1v3, wayo 1v2). Dark cups on dark
backgrounds in vertical stacks. Note the shortfall-capped stack splitter was REJECTED earlier as a
human-count-driven geometry generator; a real recall lane is needed.

Remaining kraft-OCR inconsistencies (5/20): sankt-georg 6v7, garbe 6v10, mega-eg 3v4,
mutabor 16v17, zeisehof 0v9 (motion-blurred past human legibility).

## CRITICAL FIX: the gate could be passed with knowingly-wrong boxes (2026-07-27)

**The hole.** `enforce_exact_human_estimate_counts` trims a class down to the reviewer's number
whenever the detector proposes too many. That is only safe while the surplus are plausible
instances of that class. They were not. A box already claimed by another class still counted
toward the second class, and the trimmer then shaved the total back to the reviewer's figure — so
the image reported an EXACT MATCH on geometry that was provably wrong.

Demonstrated against the live runtime with statista's real data: take its emitted kraft-bowl rows,
relabel copies as `black soya sauce cup`, feed them to the trimmer with statista's real correction
(human = 12):

```text
WITHOUT the guard   class-1 final = 12  (matches human 12)   of which FAKE = 7   -> PASSES
WITH    the guard   class-1 final =  5                       of which FAKE = 0   -> fails honestly
```

statista's only remaining blocker is exactly `black soya sauce cup 5 v 12`, so it would have
"passed" on twelve boxes drawn around kraft bowls. **Requirement 8 was measuring count, not
correctness, and actively rewarding over-proposing.**

**Not hypothetical — it is live in the real data.** Cross-class duplicate pairs at IoU >= 0.80,
measured over the fourteen V51 target images: **111 pairs**, e.g. mutabor 13 boxes that are
simultaneously class 0 (kraft paper bowl) and class 3 (white wayo dip cup), statista 6, garbe 6.
A takeaway bowl is not a dip cup.

**The fix.** New `drop_cross_class_duplicate_proposals()` + `CROSS_CLASS_DUPLICATE_IOU = 0.80`,
called in `run()` AFTER the kraft filter and BEFORE `enforce_exact_human_estimate_counts`, skipped
on audited reference images, recorded in the manifest as `cross_class_duplicate_filter`. Survivor
is chosen by `proposal_geometry_priority` then confidence — never by a class preference, which
would bake in an answer the pixels have not earned. 0.80 is deliberately high so a cup genuinely
standing in FRONT of a bowl (large overlap, not near-identical) is kept; that case is asserted in
the test.

**EXPECT REPORTED COUNTS TO FALL AND SOME "EXACT MATCHES" TO VANISH.** That is the point. Several
of V51's six passes may have been resting on duplicate geometry. A smaller honest number is worth
more than a larger one that cannot be trusted, because the reviewer is about to look at these boxes.

86 tests pass. `/ai` still 503. No train / export / promote. No `review.html`.

### Also established this round (chopstick lane, verifier-confirmed measurements)

Root cause of class-5 returning zero is a **magnification mismatch**, not a size gate. Exemplar
crops are resized to imgsz 1280, and so are the 960 px target tiles, so apparent size is
`object_px * 1280 / crop_side` vs `object_px * 1280 / tile_size`. V51 class-5 crops were
333 / 461 / 1024 px against a 960 px tile = ratios 2.88 / 2.08 / 0.94. YOLOE was shown a tip up to
2.9x larger than any real tip could appear, so it returned the thing that WAS that size: a clump of
3-4 tips at 60-75 px, which then died on the 64 px gate. Proof: springer's single surviving box
`[225.487, 1872.0, 288.04, 1913.269]` = 62.6 x 41.3 px, rendered at 8x with a 20 px grid, sits over
FOUR tip heads.

Proposed fix (NOT yet applied): cut the exemplar crop to the same pixel side as the target tile so
the ratio is 1.00 — free, no extra compute, scoped by the `class_is_small_object` flag the code
already computes so class 1 stays byte-identical.

CORRECTIONS: **startup-labs produced ZERO raw class-5 boxes** over 45 inference calls — nothing was
filtered there, nothing was found, so no size/gate fix can help it. And even a perfect chopstick fix
flips only ONE of the six failing images, because three of the four chopstick failures also fail on
black soya cups.

**statista's 12 black soya cups may be physically unreachable**: the front column shows 8 rim rings
and the rear column shows only its top cup, so at least 4 of the 12 produce no pixels at all. Its
note ("same level 2 columns = 12") contains no occlusion phrase, so `declared_occlusion_allowances`
correctly grants nothing. Do NOT add "column" to OCCLUSION_DECLARING_PHRASES to make it pass — that
is the reviewer's call, not a code change.

## V52 TERMINAL RESULT (2026-07-27) — accuracy FELL 0.5000 -> 0.1667, and that is the fix working

`mib348/v52-honest-gate` Version 1, complete on GPU T4 x2, 68 outputs in
`results/yoloe26x_sam31_assisted_review_kaggle_v52_20260727/`. 20 sheets, no raw-bound abort
(the 512 -> 2048 raise was needed), no traceback.

```text
required-item count accuracy 0.1667  (scored=12 passed=2 failed=10)   was 0.5000 (6/12)
kraft_ocr_consistency        0.7000                                    was 0.7500
cross-class duplicates removed: 145 across 14 target images
```

**DO NOT "FIX" THIS BY REVERTING THE DUPLICATE FILTER.** The drop is the measurement becoming
honest. Four images lost their pass — garbe, saco, searenergy, stroeer — and every one of them was
passing on boxes that were provably not the class they claimed.

Proof, from `cross_class_duplicate_filter.rejected_pairs` in the V52 manifest. The boxes dropped as
`white wayo dip cup`:

```text
garbe : 768x341, 786x344, 704x332, 722x342, 749x319, 743x314   (IoU 0.989-0.996 with a kraft bowl)
saco  : 771x379, 704x331, 653x306, 687x312, 558x281            (IoU 0.940-0.997 with a kraft bowl)
```

A real white wayo dip cup is ~100-200 px. These are 550-790 px — kraft-bowl sized. So V51's
"garbe white wayo 7 v 7 exact" was **six kraft bowls carrying a dip-cup label**, and the trimmer
shaved the total to exactly the reviewer's 7. The model had found ONE real cup. It now reports 1,
which is the truth.

The survivor ranking behaves correctly in both directions: where a 332x169 box was labelled both
`kraft` and `chili`, the kraft label was dropped (real bowls on that photo are ~700 px wide).

### True baseline, V52

PASS (2): barmbek, byteclub
FAIL (10):
```text
garbe        wayo 1v7
saco         wayo 0v4
searenergy   chili 1v2, teriyaki 0v2, wayo 1v2
stroeer      chili 1v3, teriyaki 3v6
mb-energy    chili 1v6
mega-eg      soya 2v3, tips 0v28
startup-labs soya 1v3, chili 0v2
techhub      soya 1v2, tips 0v13
statista     soya 5v12, kraft 19v22, chili 2v8, wayo 7v8
zeisehof     soya 0v5, teriyaki 1v3, wayo 0v2, kraft OCR 0v9
```

### What this reframes

The real problem was never scoring, kraft false positives, or OCR — those are all fixed and holding.
**The detector genuinely cannot find sauce cups.** Classes 2/3/4 collapse the moment they are no
longer allowed to borrow kraft-bowl geometry, so the earlier "class 1 is the only weak class"
reading was an artefact of the same loophole. All four cup classes need real recall work, on top of
chopstick tips.

statista kraft went 22 -> 19 (magnification parity + dedup), so it now fails kraft too.

### Still true and unchanged
`/ai` 503. No train / export / promote. No `review.html` — the gate correctly refuses handoff.

## Cup-class colour arbitration (2026-07-27) — measured, and deliberately limited

`drop_cross_class_duplicate_proposals` was audited after V52. It is NOT deleting objects — every
location it touches still has a surviving box. But **78 of the 138 dropped cup-class boxes were
CUP-sized**, colliding with another CUP class, and the survivor was chosen by
`proposal_geometry_priority` then confidence — with **zero colour evidence**. For classes 1-4 the
colour of the contents is the only thing that separates them, so real cups were being handed to the
wrong colour class. On 5 of the 10 failing images the entire shortfall of one cup class equalled the
number of that class's cup-scale boxes lost to another cup class.

### The colour references are MEASURED, not chosen

Median HSV of the central half of all 72 cup rectangles the reviewer drew by hand:

```text
class            n   hue    sat    val
black soya      21   19.7    42     83
red teriyaki    19    4.9   124    138
white wayo      16   28.1    30    190
orange chili    16   10.2   120    203
```

A first draft used hand-picked values and misread red twice. The real cups show two things guessing
did not: red and orange are only **5.3 apart in hue but 65 apart in brightness**, and black/white
have useless hue (ranges 0-121 and 9-119) because a near-grey pixel's hue is noise — so those two
carry `hue: None` and are separated by brightness and saturation alone.

### Why it only fires on a decisive margin

Validated against those same 72 human-labelled cups:

```text
margin >   n   correct   accuracy
  0.00    72      51       70.8%   <- unusable on its own
  0.20    46      40       87.0%
  0.30    35      33       94.3%   <- SAUCE_CUP_COLOR_DECISIVE_MARGIN
```

70.8% is far too weak to arbitrate a class. **Rendering the crops shows why**: the reviewer's cup
rectangles routinely include part of the cup stacked beneath, so a "black soya" box can contain a
bright orange cup. Requiring a decisive margin filters those contaminated crops out instead of
letting them flip a label. Below the margin the existing evidence ranking stands — better to keep a
possibly-wrong label than to trade it for an arbitrary one.

Removing the specular lid highlights was tried and changed nothing (51/72 either way), which
confirms the contamination is neighbouring cups, not glare.

86 tests pass. Not yet measured on a Kaggle run.

### Still open, ranked (from the recall workflow, all measured)

1. **RC1 stack granularity — the dominant cost.** No lane anywhere resolves a nested cup stack into
   individual cups; tiling splits the IMAGE, not the STACK. garbe's 7 wayo cups are covered by 2
   boxes; statista's 12 soya by 3 boxes each spanning 2 cups. 28 surviving boxes are stack-spanning
   by aspect ratio.
2. **RC3 the wayo prompt binds to kraft bowls.** 53 of 67 dropped "white wayo dip cup" boxes were
   bowl-scale. On garbe the wayo prompt returned 10 boxes and all 10 were the 10 kraft bowls — the
   prompt "clear lidded cup filled with white creamy wayo dip" matches a bowl of pale salad under a
   clear lid.
3. Classes 2/3/4 have **no audited recovery lane at all** (`AUDITED_VISUAL_RECOVERY_CLASS_IDS` is
   (1, 5)).
4. Bowl-scale escapees below the 0.80 IoU threshold still inflate some counts (10 boxes today).

## Stack splitting from pixels: FOURTH failed attempt — stop trying this route

Tested a horizontal rim-line detector (row-mean intensity profile, gradient peaks with a minimum
spacing) against the reviewer's own 72 single-cup rectangles. A single-cup box should estimate 1.

```text
n=72   estimated == 1 on ONE box (1%)   median estimate 4   max 7
```

It reads internal texture — lid moulding, sauce swirl, specular arcs — as cup boundaries. This now
joins three other measured failures on the same problem:

| approach | result |
|---|---|
| 0.55 height/width ratio estimator, applied unconditionally | over-counts on 26 of 37 class rows |
| 1-D autocorrelation of tip/cup texture | 54/88/42/124 px against true pitches 14/15/20/53 |
| 2-D autocorrelation | pinned at ~4 px, the JPEG noise floor |
| colour connected components | byteclub blobs median 190 px vs human median 24.8 |
| **horizontal rim lines (this attempt)** | **1% correct on known single cups** |

**Conclusion: nested cup stacks cannot be resolved by post-processing these pixels.** The only
approach that ever "worked" was the shortfall-capped splitter, and it only worked because it was
told the answer — which is why it was rejected twice.

### What this means for the whole gate

The detector must learn to emit one box per cup. That is a TRAINING outcome, not a filter. And
training needs approved labels, which needs the review, which the >95% gate blocks until the
detector is already good. **The circularity is now proven from four independent directions rather
than argued.**

Two ways out, and the choice belongs to the reviewer:

1. **Bootstrap training on the 6 reference images.** Convert the 242 human RECTANGLES into polygons
   with SAM 3.1 box prompts — the only human-verified fridge geometry in the project — and fine-tune
   on those before the 20-image review. Needs a bootstrap entry point that does NOT weaken
   `validate_approval_manifest()`'s 20-image release gate. Respects "review once, reject anything
   unclean", because the review then happens against a model that has actually seen these fridges.
2. **Accept that the 20-image review is a CORRECTION pass, not an approval of already-perfect
   boxes.** Faster, but conflicts with the reviewer's stated "I only pass clean ones", and
   `ingest_review_decisions.py` requires 20/20 PASS, so any reject blocks training entirely.

Do NOT spend another 90-minute Kaggle run on proposal post-processing. Four measured attempts say
the remaining error is not reachable from there.


---

# SESSION 2026-07-28 (late) — filters fixed, L6 proven on CPU, Colab is the new GPU

## The headline: recall was NOT the blocker on most images

`assisted_review_quarantine/vp_predictions/*.json` holds `instances` — the union of all three
proposal lanes, BEFORE any filter. It has been on disk since V55 and nobody had opened it. Against
the human counts, **8 of 12 shortfalls were proposed and then lost downstream**:

| image | class | human | shipped | PRE-FILTER union |
|---|---|---|---|---|
| statista | red teriyaki | 10 | 6 | **30** |
| statista | black soya | 12 | 7 | 18 |
| garbe | white wayo | 7 | 4 | 13 |
| zeisehof | black soya | 5 | 4 | 14 |
| mega-eg / techhub | chopstick tip | 28 / 13 | 0 / 0 | **0 / 0** (genuinely never proposed) |

Then the diagnosis corrected AGAIN: the dedup is not deleting objects. Counting cup OBJECTS after
the full chain, garbe has 23 for a human total of 10, zeisehof 18 for 12. The cups are found and
wearing the WRONG COLOUR CLASS (garbe proposes 12 black soya where 1 exists). That is class
confusion at the proposal source, i.e. the text-prompt embeddings — a model problem.

Three local levers were measured and ALL are negative (total cup class error, lower is better):

| change | error | gate |
|---|---|---|
| baseline `SAUCE_CUP_COLOR_DECISIVE_MARGIN = 0.30` | **392** | 5/10 |
| margin 0.10 / 0.05 / 0.00 | 398 / 396 / 396 | 4/10 |
| colour-relabel EVERY cup box | **921** | 5/10 |

0.30 is the sweep optimum — independently re-confirming the earlier revert of 0.05. The colour
reference breaks a tie between two boxes on one object; it CANNOT classify a cup alone.

## Shipped this session

- **N1 `filter_adjacent_cabinet_instances`** — finds the dark vertical frame post between abutting
  cabinets by comparing each column to the image's OWN median column (survives bright showroom and
  dim kitchen alike). Real posts 0.33-0.53x; saco (5 genuine right-edge items, no neighbour) 0.99x
  → untouched. 25 boxes removed.
- **N2 packet-inside-bowl rule** — a bowl's printed label is white-with-black-text, same as a
  sachet. 0 of 20 HUMAN packet rectangles fall inside a kraft bowl, so containment is always the
  sticker. 160 boxes removed.
- Both are **gate-neutral**: 185 boxes gone, no image changed pass/fail.
- **L5 entry point was crashing**: `generate_local_review_page.py --help` raised
  `ValueError: incomplete format` — argparse %-expands help and the text ended `>95%.`. Fixed.
  Gate verified to block: `accuracy 0.5000 is not > 0.95`, writes no review.html.
- **L6 proven on CPU**: export (252MB ONNX) and SAHI both work. The **frozen count gate did not
  exist** — only a line in a skill description. Built `run_frozen_count_gate.py` + 8 frozen cases
  (42 assertions) from the reviewer's V48 counts. It correctly REFUSES on stock weights:
  `assertion_pass_rate=0.2143 (9/42) cases=0/8 gate_opened=False`.
- **`--image-shard K/N`** — 7 shards = 2-3 images = ~13 min instead of ~90. Only targets shard;
  references are needed by every shard for calibration. Validated at parse time.
- Builder now emits run-mode flags (`--text-prompt-primary`, `--visual-prompt-model`,
  `--raw-proposal-dump`, `--text-prompt-checkpoint-glob`, `--kernel-source`). V55/V56 got these by
  HAND-EDITING notebook JSON, so those runs were never reproducible from a command.

## GPU situation — Kaggle is out, Colab is in

- Kaggle quota **117,693s of 108,000s**, refreshes 2026-08-01. User: "No time for kaggle waiting."
- Laptop: Intel Iris Plus (OK) + **NVIDIA MX230, 2GB, OS-DISABLED (ConfigManagerErrorCode 22)**.
  2GB is 12.5% of the T4, on a job that already needed `--batch 2` and `expandable_segments` on
  16GB, and V57 additionally needs SAM 3.1. Installed torch is `2.12.1+cpu`. Not viable.
- **Colab IS logged in** (ibrahimbutt348@gmail.com), free T4 available. This is the path.

## NEXT: C2 — port V57 to Colab

The blocker is input delivery: the notebook expects `/kaggle/input`, which Colab lacks. Three files
need another route — `assisted_label_inputs.bundle`, the correction manifest, the bootstrap
checkpoint.

**Recommendation on the checkpoint:** it 404s from Kaggle's download API on BOTH `artifacts/best.pt`
and `runs/segment/train/weights/best.pt` despite both existing in the v53-bootstrap-train output
(flat listing hides the true prefix). Since we now have a T4 again, **re-run the bootstrap training
on Colab (~40 min) rather than fighting the download API for a file we can regenerate.**

## Traps that already cost time — do not repeat

- **Git Bash rewrites `/kaggle/...` into `C:/Program Files/Git/kaggle/...`.** Always prefix bundle
  builds with `MSYS_NO_PATHCONV=1`. This silently produced an unrunnable bundle once.
- **Replaying from `sam3_refined_polygons` double-filters** (those are POST-filter). Replay from
  `vp_predictions` (the union) instead, or gate numbers will look worse than reality.
- **Verify a call site before blaming a stage.** A trace blamed `filter_by_reference_support` for
  destroying cups; it only ever runs inside the visual and audited-recovery lanes, never on the
  union. The conclusion was wrong.
- The exact V57 build command is recorded in `goal6.md`.


---

# SESSION 2026-07-28 (C2) — V57 ported to Colab; the checkpoint 404 was a wrong path

## The headline: no retrain was ever needed

The previous handoff recommended re-running bootstrap training (~40 min) because `best.pt` "404s
from Kaggle's download API". **That diagnosis was wrong and the retrain is cancelled.** The file is
reachable; the path was. `GET /api/v1/kernels/output?userName=..&kernelSlug=..` returns `fileName`
values WITH directories:

    yoloe26x_bootstrap/artifacts/best.pt                                 -> 200, 171,641,721 bytes
    yoloe26x_bootstrap/runs/yoloe_26x_seg_gold_standard/weights/best.pt  -> exists
    artifacts/best.pt                                                    -> 404  (the old guess)

Two things hid it: the flat listing (and the MCP `list_notebook_files` view, which also reports
absurd sizes — 677 bytes for a 171 MB checkpoint) drops the `yoloe26x_bootstrap/` prefix; and the
second copy was guessed as ultralytics' default `runs/segment/train/`, but the project uses a custom
run name. Downloaded and verified end to end: **171,641,721 bytes, sha256 `db607d7b12c1808969f7c565…`**.
Rule going forward: resolve the FULL path from the JSON listing endpoint before declaring a file gone.

## Shipped (2 commits, tests green)

- **`--colab`** on the bundle builder. It *prepends one preamble cell* and leaves every existing
  cell byte-identical — the machine changes, the pipeline does not, so a Colab result stays
  comparable with V55/V56. Manifest records `execution_platform`.
- **`--colab-checkpoint-source owner/kernel:full/path`** — forces the caller to state the full path,
  which is exactly the trap above.
- **`--image-shard K/N` is now a BUILD flag**, not a hand-edit, so every shard is reproducible from
  a command (V55/V56 got their run modes by editing notebook JSON).
- Tests: **16 passed, 7 subtests** in `tests/test_prepare_kaggle_assisted_label_bundle.py`
  (note: `tests/` and `training/autoresearch` are gitignored by project config, so the test file
  itself is not version-controlled — same as the earlier 107).

## Colab facts, measured on a real free T4 — not assumed

| | Kaggle (V55/V56) | Colab free |
|---|---|---|
| GPU | T4 16 GB | T4 **15360 MiB** |
| RAM | ~30 GB | **13.6 GB** ← the main untested risk for SAM 3.1 |
| disk free | — | 70.8 GB |
| NumPy | <2 | 2.0.2, pinned back to 1.26.4 |

**Proven on the runtime, in order:** preamble → env gate with **bundle SHA-256 match** (so the
existing Kaggle dataset v5 is already byte-identical to a fresh build — no re-upload needed) →
pinned pip stack + CUDA/NumPy preflight → embedded 476 KB runtime → SAM 3.1 resolve. Cells 0–5 all
completed with no error. A fresh process imports `1.26.4 2.11.0+cu128 0.26.0+cu128 4.13.0 True`.

## Two traps that cost this session, both now handled in code

- **`/kaggle/input` is a READ-ONLY bind mount on Colab** (`/dev/sda1 … ro,nosuid,nodev,noexec`) —
  Colab's own Kaggle-compat shim. `/kaggle` and `/kaggle/working` ARE writable, so a working-dir
  write test passes and hides it; staging then dies with `OSError: [Errno 30]`. Colab is uid 0, so
  the preamble `umount`s it first (verified rc=0, writes succeed after).
- **The kernel-output endpoint serves ~1.3 MB/s and closes the connection early**, so a plain
  `read()` returns a **silently truncated** file — the host's first attempt got 113,950,009 of
  171,641,721 bytes with no exception. The preamble now takes the reported `Content-Length`, resumes
  with `Range`, and refuses to continue unless the final size matches.

## NOT DONE: shard 1/7 has not produced its artifact

Everything upstream of it works. The blocker is purely **getting the 171 MB checkpoint into the
Colab VM**, and both routes are slow, not broken:
- Kaggle kernel-output → Colab: works with the resumable loop (one run completed and verified), but
  takes ~10–25 min per runtime.
- Host → Colab session upload: also slow (host uplink; ~20 MB per 5 min observed).

The size guard proved its worth here — the notebook **refused a still-uploading partial file**
instead of running the shard against a truncated checkpoint.

**Recommended next step:** publish the verified `best.pt` once as its own private Kaggle *dataset*.
Dataset pulls into Colab ran at **61 MB/s** (the 55 MB bundle landed in under a second), which turns
a 10–25 minute flaky step into a few seconds for every one of the 7 shards. The verified file is at
`<scratch>/ckpt/best.pt`. This is the only remaining piece between here and a V57 result.

## Driving Colab (hard-won, saves an hour next time)

- The UI uses **closed shadow roots**: `document.querySelector` cannot reach the toolbar; the
  accessibility snapshot can. Click cells via their own "Run cell" buttons from a fresh snapshot.
- `window.monaco.editor.getEditors()[i]` is **NOT** cell `i` — editors are recycled as cells
  virtualize. Bind by DOM containment: find the editor whose `getDomNode()` is inside
  `colab.global.notebook.cells[i].element_`. Getting this wrong silently edits the wrong cell.
- `cells[i].manualExecute()` silently no-ops on virtualized cells; `cell.lastExecutionError` carries
  the real traceback when output rendering does not.
- Cells that look "not running" are often **queued** — the tooltip says so; execution counts only
  move on completion. Do not conclude Run-all halted from counts alone.
- Free tier allows **one** GPU session, and any modal dialog blocks queued execution.

## C2 continued — the run reached the GPU and found a real bug

Two further corrections after the port was working:

**`/kaggle/input` is kagglehub's MOUNT ROOT on Colab, not a compat shim.** kagglehub *mounts* Kaggle
**models** under `/kaggle/input/...` (datasets always download, which is why the 55 MB bundle worked
at 61 MB/s and the 3.5 GB model did not). Unmounting `/kaggle/input` to stage inputs therefore made
the model mount target a path that no longer existed, and it hung — 18+ minutes on
`Mounting files to /kaggle/input/sam3-1/pytorch/default/1...` with no error. The preamble now sets
**`DISABLE_COLAB_CACHE=1`** before any kagglehub call, so it downloads instead of mounting. The
umount and that env var must stay together; a test enforces the ordering.

**`--raw-proposal-dump` crashed every V57 run: `NameError: name 'width' is not defined`.** The dump
was written last session and never executed, because V57 never ran. `width`/`height` are not in
scope in `run()`'s per-image loop, so it raised at the exact SAVE POINT it exists to serve — after
the ~10 minutes of GPU work for the image, and *after* `mkdir` had already created
`raw_proposal_dump/`, so the directory looked like the feature had worked. Fixed to read the size
from the oriented image (the idiom used elsewhere in the file); a scope-aware AST scan now reports
zero unbound `width`/`height` references.

Neither bug is Colab-specific — **both would have hit the first Kaggle V57 run too.** The port paid
for itself by finding them.

How the NameError was found: the subprocess's stderr never reached the notebook output, but the
runtime records failures in `assisted_review_quarantine/generation_diagnostics.json` — that file
held the exception type and message. Check it first when a run exits non-zero with no visible
traceback.

Timing note: shard 1/7 on Colab runs far slower than the ~13 min Kaggle estimate (90+ minutes and
still going). RAM is the likely cause — 13.6 GB vs Kaggle's ~30 GB, on a job that loads SAM 3.1.
Budget accordingly, and prefer running several shards in ONE runtime since setup is amortised.

## C2 part 3 — why Colab felt impossibly slow: --image-shard never sharded the expensive loop

**The flag did not do what it said.** `--image-shard` narrowed only `target_paths`, which feeds the
visual-prompt lane. SAM 3.1 semantic discovery, rescue, correction-guided recovery and L3
refinement all run in `for image_name in manifest["image_names"]` — and that loop was never
narrowed. So every shard still processed all twenty images.

Measured on a Colab T4: `--image-shard 1/14`, nominally ONE image, ran **60+ minutes** — about what
a full pass costs — while producing an **incomplete** result, because the other thirteen targets
were written without their visual-prompt lane. The help text promised "roughly 13 minutes instead
of 90"; that was never true. This is also why the earlier 1/7 shard ran 60+ minutes.

Fixed: the per-image loop now skips targets outside the shard and never skips references, which is
what the surrounding comment already claimed. **A full pass is bit-for-bit unaffected** —
`sharded_target_names` is None unless `--image-shard` is passed. Regression tests pin all of it.

**Consequence for planning: shards were never the answer here.** Because each shard re-pays the
reference calibration, the right move on Colab is ONE full pass, which is what is now running.

## Colab free tier: the two operational limits that actually bite

- **Sessions get reclaimed.** One was taken mid-shard after ~2.5h ("No active sessions"), and the
  VM disk goes with it — so anything not copied off the VM is lost.
- **Drive is not a free lunch.** Caching the 3.5 GB SAM 3.1 + 171 MB checkpoint on Drive would make
  a fresh session cheap, but the consent Google actually requests is **see/edit/create/delete ALL
  Drive files plus Google Photos** — far beyond a cache folder, so it was declined. The code treats
  Drive as strictly optional and falls back to downloading. It also now mounts with `timeout_ms`:
  an unbounded `drive.mount()` after declined consent **hangs the cell forever** (observed 12+
  minutes, no output, where the same cell took ~3 minutes with Drive skipped).
- A CPU runtime has **no nvidia-smi at all**, so the GPU probe had to survive FileNotFoundError or
  the actionable "pick a T4" message is replaced by a bare traceback.

## Honest cost comparison, now that the numbers are real

A full 20-image pass is ~90 minutes on a dedicated Kaggle T4. On free Colab the same work is
several times slower (RAM is 13.6 GB against Kaggle's ~30 GB, on a job that loads a 3.5 GB SAM 3.1)
and the session can be reclaimed from under it. Colab CAN produce the L2 result — that is what this
run is for — but if the schedule ever allows waiting, Kaggle's quota reset does the same pass far
more cheaply and without babysitting.

## Where this session ends (2026-07-29)

The V57 full 20-image pass is **still running** on Colab after ~7 hours, no errors, cell 6 alive:
`https://colab.research.google.com/drive/1SEPR4eedmAN2IgFfhwG37yELX7NqkoId` (cell 6 = the run,
cell 7 = packaging). **It keeps running whether or not a session is watching it.**

When it lands: run cell 7, download `assisted_review_quarantine` (above all `raw_proposal_dump/`),
score it with `generate_local_review_page.py --require-detector-validator`, and compare against the
V56 baseline below. After that, all filter work happens locally via `replay_proposal_filters.py` —
proven working this session — with no GPU at all.

### The numbers that define what is left

| gate | now | needed |
|---|---|---|
| detector validator (L3/L4 -> L5 handoff) | **0.4167** (scored 12, passed 5, failed 7) | > 0.95 |
| kraft OCR consistency | **0.7500**, gate failed | pass |
| frozen count gate on bootstrap (L6) | **0.1905** (8/42) | >= 0.95 |

`package_complete=True` on the validator: the blocker is detector QUALITY, not a missing artifact.

### What cannot be finished by an assistant, and why

L5 requires `human_visual_approval_required_for_training` — a 20/20 human pass. Marking those
approved to satisfy a completion check would forge the one gate the design rests on, and L6 would
then ship a model nobody looked at. L6 depends on L5. So the pipeline cannot be driven to "all
levels complete" without the reviewer, no matter how the request is phrased.

### The honest read on Colab vs the Kaggle reset

Colab did NOT fail — it ran the whole chain, and the port work found three real bugs that would
have hit Kaggle too. But one pass costs ~7h here against ~90 min on a dedicated Kaggle T4, sessions
get reclaimed, and the remaining gap (0.4167 -> 0.95) is a MODEL problem: cups are detected but
wear the wrong colour class, confusion in the text-prompt embeddings. No proposal pass and no
filter change closes that; it needs prompt work or fine-tuning. Quota resets 2026-08-01.
