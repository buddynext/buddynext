# Integration #1 — Career Board (Jobs)

**Status:** 🔒 LOCKED 2026-06-14 (one integration at a time; this is the per-integration locked plan, not the general concept). BM-1 cleared to execute.
**Tier:** Pro (jobs = a business app). **Plugin:** WP Career Board (free + pro, live v1.0.2).

**Locked decisions:**
1. **June 20 ship:** BM-1 (bridge → Pro + guard/signature fixes) ships now. Native jobs surface (NS-*) declared "Jobs — coming in Pro."
2. **Native surface placement:** top-level `/jobs/` section **+** profile tab (employer postings / candidate applications).
3. **Feed cards:** job postings keep appearing as activity-feed cards.
4. **Tier-aware:** light up Career Board **Pro** features when present (same model as the 3 core bridges).
**Law:** first-party → addon ships data/logic only; BuddyNext owns the entire jobs presentation centrally on BN URLs. (`WORKFLOW.yaml` integration_law · `native-integration-program.md`.)

---

## A. Verified API surface (sourced from the installed plugin, 2026-06-14)

**Guard (the real bug):** the bridge checks `wcb_get_job()` + `WCB_Career_Board` — **neither exists anywhere** in free or pro. Real always-present bootstrap: **`wcb_run()`** (also `wcb_rest()`, `wcb_page_url()`). Correct guard = `function_exists( 'wcb_run' )`.

**Events — REAL signatures (grepped from `wp-career-board` source) vs. what the bridge assumed:**
| Hook | REAL `do_action` signature | Bridge assumed | Status |
|---|---|---|---|
| `wcb_job_created` | `($job_id, $request)` — 2 args, 2nd is `WP_REST_Request` | `($job_id, array $job_data, int $user_id)` | 🔴 BUG — fix handler |
| `wcb_job_expired` | `($job_id)` | `($job_id)` | ✅ matches |
| `wcb_application_submitted` | `($app_id, $job_id, $candidate_id)` (candidate 0 = guest) | same | ✅ matches |
| `wcb_application_status_changed` | `($app_id, $old, $new)` — 3 args, NO candidate | `(…, int $candidate_id)` — 4 | 🔴 BUG — resolve candidate |
| `wcb_application_withdrawn` | `($app_id, $job_id, $candidate_id)` — 3 args, NO employer | `(…, int $employer_id)` — 4 | 🔴 BUG — resolve employer |

**Data model (for resolving the missing IDs):**
- Application = a post; candidate stored as **`post_author`** AND meta **`_wcb_candidate_id`** (0 = guest). → candidate = `(int) get_post_meta( $app_id, '_wcb_candidate_id', true )`.
- Employer = the job post's **`post_author`** → `(int) get_post_field( 'post_author', $job_id )`.
- Job for search index → read `post_title` / `post_content` / `post_author` from `get_post( $job_id )` (ignore the `$request`).

> The existing Free test "passes" only because it feeds the bridge the *assumed* args — it encodes the bug. Rewrite the tests to fire the REAL signatures.

**REST (data source for the native surface):** namespace **`wcb/v1`** — `boards`, `boards/{id}/stages`, `credits/checkout|webhook`, `employers/{id}/credits`, `fields/groups`, plus resume/notification routes. Job-listing read routes to be enumerated in Task NS-1 below.

**CPTs:** `wcb_resume` confirmed; the job CPT is registered via constant (not a literal) — confirm exact slug in NS-1.

---

## B. Scope — two deliverables, both land in Pro

### B1. Bridge move + guard fix (small, ships first)
Move the event/notification/search wiring out of Free into Pro and correct the guard.

### B2. Native jobs surface — `includes/Jobs/` in Pro (the real value)
A BN-owned jobs hub/tab consuming `wcb/v1`, rendered with BN components on a BN URL. Career Board ships **data only**; BuddyNext owns markup/CSS/UX. Pattern = `includes/Media/` (Client → Domain → Renderer → Assets → Surface). No 2nd-party screens, no link-outs to Career Board's own pages.

---

## C. Build tasks (TDD; each verified before the next)

### Task BM-1 — Move bridge to Pro + fix guard · ✅ DONE 2026-06-14
> Shipped: bridge in `buddynext-pro/includes/Bridges/CareerBoardBridge.php` (guard `wcb_run`, all 3 signature bugs fixed), registered on the `buddynext_load_bridges` seam in Pro `Core\Plugin::wire_extensions()`; removed from Free `Core\Plugin` + bridge file deleted; `PostService::delete_by_link()` added so the bridge never touches `bn_posts` directly; test ported to `buddynext-pro/tests/Bridges/CareerBoardBridgeTest.php` (5 tests, real signatures, incl. guest-skip) — green. phpcs clean, php -l clean.

**Files:**
- Create: `buddynext-pro/includes/Bridges/CareerBoardBridge.php` (namespace `BuddyNextPro\Bridges`; logic identical; uses Free's `BuddyNext\Notifications\NotificationService` + `BuddyNext\Search\SearchService`).
- Modify guard: `if ( ! function_exists( 'wcb_run' ) ) { return; }` (drop the dead `wcb_get_job`/`WCB_Career_Board` checks).
- Register in Pro: in `BuddyNextPro\Core\Plugin` (boots `plugins_loaded:20`) `add_action( 'buddynext_load_bridges', fn() => ( new CareerBoardBridge() )->init() )` — fires at Free's `:25` seam.
- Remove from Free: `includes/Core/Plugin.php` line 41 (`use ... CareerBoardBridge;`) + line 355 (`( new CareerBoardBridge() )->init();`); delete `includes/Bridges/CareerBoardBridge.php`.
- Move test: `tests/Bridges/CareerBoardBridgeTest.php` → `buddynext-pro/tests/Bridges/CareerBoardBridgeTest.php` (namespace `BuddyNextPro\Tests\Bridges`; update `use`).
- Re-verify each hook's arg signature against the Career Board source; fix any handler that mismatches.

**Verify:** Free active alone → no Career Board references, boots clean. Pro active → bridge loads only when `wcb_run` exists; the moved `CareerBoardBridgeTest` passes (free CB plugin active).

### Task NS-1 — Map the native jobs data (no UI yet)
Enumerate `wcb/v1` job-listing read routes + the job CPT slug; define the `Jobs/JobsClient.php` read contract (list, single, employer, apply-state). Output: a short data-contract appended here. No rendering.

### Task NS-2..NS-n — Native jobs surface
`includes/Jobs/` (Pro): Client → domain (BN privacy/visibility gating) → renderer (BN-native cards/hub) → assets (BN JS talking to `wcb/v1`) → surface (BN route/tab). Browser-verified (incl. 390px), keep search indexing, zero foreign assets. Detailed steps locked after NS-1.

---

## D. Open decisions to LOCK (discuss before coding)

1. **Ship split for June 20:** land BM-1 (bridge move + guard fix) now; declare the native jobs surface (NS-*) as "Jobs — coming in Pro"? Or hold the whole integration until NS-* is native-complete?
2. **Jobs surface placement:** a top-level BN section (`/jobs/`), a profile tab, or a Space tab — or more than one?
3. **Free vs Pro of Career Board:** the bridge guards on `wcb_run` (present in both). Confirm we light up Career Board **Pro** features when present (tier-aware), same as the core bridges.
4. **Feed cards:** keep job postings appearing as feed cards (current `on_job_expired` implies a `job_post` card exists), or jobs-section-only?

**Nothing in Section C gets executed until D is locked.**
