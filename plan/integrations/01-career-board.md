# Integration #1 — Career Board (Jobs + Resumes)

**Status:** 🔒 Model locked 2026-06-14. **Tier:** Pro (application-layer integration).
**Plugin:** WP Career Board (free + pro, live). Standalone — keeps ALL its own pages.

---

## The model — BuddyNext adds a community layer ON TOP of the standalone plugin

BuddyNext does **not** replace, rebuild, or take over Career Board's UI. Career Board
keeps its own `/jobs/` directory, single-job pages, resume pages, dashboards, and all
core features — **untouched**. BuddyNext's integration is a thin **community layer**
that drives engagement around that plugin:

1. **Profile** — a member's BuddyNext profile shows the **jobs they've posted** and the
   **resumes they've posted**, each linking out to Career Board's own page.
2. **Activity** — when a member **posts a new job (or resume)**, BuddyNext publishes an
   **activity** to the feed → engagement.
3. **Space (possible/later)** — surface jobs in a space context if a community wants it.
4. **Notifications** — Career Board application events become BuddyNext notifications.

### Hard rules (apply to EVERY application-layer integration)
- **Never take over the partner's screens** or routes. No top-level BN section that
  replaces their directory. No making their CPT headless.
- **Never touch a plugin's core feature** unless explicitly asked.
- Integration touchpoints are **profile + activity (+ possible space)** only.
- Link **out to the partner's own pages** — they own that UX.

> **The one exception (don't generalise from it):** WPMediaVerse messaging was a
> deliberate *takeover* (BN-native messages) so members never see two message screens —
> the "integrated BN model" for a **core** community primitive. Application-layer
> business apps (Career Board, Learnomy, Eventonomy, Listora, WPConnectPress) get the
> community-layer treatment above, **not** a takeover. See `WORKFLOW.yaml` integration_law.

---

## Status

| Touchpoint | What | Status |
|---|---|---|
| **Bridge — notifications** | `wcb_application_submitted/status_changed/withdrawn` → BN notifications (employer/candidate). Guards on `WCB_VERSION`. | ✅ DONE (Pro, committed) |
| **Bridge — search** | `wcb_job_created` → index job in `bn_search_index` (jobs findable in BN search). | ✅ DONE (Pro, committed) |
| **Activity on post** | `wcb_job_created` / resume-created → publish a BN activity (feed card) for engagement. `wcb_job_expired` already removes its card via `PostService::delete_by_link`. | ⏳ TODO |
| **Profile — jobs** | Profile section/tab listing the member's posted jobs (link to Career Board). Uses `buddynext_part_profile_tab_bar_args`. | ⏳ TODO |
| **Profile — resumes** | Same for the member's posted resumes (`wcb_resume`). | ⏳ TODO |
| **Space** | Jobs in a space context. | 🔮 Possible/later |

**Reverted (the mess, 2026-06-14):** a top-level `/jobs/` BN route, BN-native job
list/single templates, a JS store, and a filter that made Career Board headless
(suppressed its archive). All deleted; Free's hub-extension seams reverted; Career
Board's `/jobs/` restored. That was a takeover — wrong for an application-layer integration.

---

## Verified Career Board API (still valid reference)

**Guard:** `defined( 'WCB_VERSION' )` — the only reliable runtime signal. (`wcb_run()`
exists only in Career Board's test helper; `wcb_get_job`/`WCB_Career_Board` don't exist.)

**Events (real signatures, from `wp-career-board` source):**
| Hook | Signature | Use |
|---|---|---|
| `wcb_job_created` | `($job_id, $request)` — 2nd is `WP_REST_Request` | activity + search (read fields from the post) |
| `wcb_job_expired` | `($job_id)` | remove the activity card |
| `wcb_application_submitted` | `($app_id, $job_id, $candidate_id)` (0 = guest) | notify employer |
| `wcb_application_status_changed` | `($app_id, $old, $new)` | notify candidate (resolve via `_wcb_candidate_id` meta) |
| `wcb_application_withdrawn` | `($app_id, $job_id, $candidate_id)` | notify employer (resolve via job `post_author`) |

**Data:** job CPT `wcb_job`, resume CPT `wcb_resume`; both `post_author` = the member.
Job meta: `_wcb_company_name`, `_wcb_salary_min/max/currency`, `_wcb_remote`, `_wcb_deadline`.
Member's posted jobs/resumes = `WP_Query( post_type, author => member_id )`. Permalinks
are Career Board's own pages (link out, don't embed).

---

## Build tasks (TODO — light, no takeover)

### Task A — Activity on job/resume post · Pro (bridge)
Extend `CareerBoardBridge::on_job_created` to publish a BN activity (author = employer,
text = "posted a new job: {title}", link = job permalink, card type `job_post`) so it
appears in the feed. Add a resume listener for the same. Reuse `PostService` (no raw SQL).
Verify: posting a job creates a feed activity; expiry removes it.

### Task B — Profile jobs + resumes · Pro
Add a "Jobs" (and resumes) profile tab via `buddynext_part_profile_tab_bar_args`; render
a BN-native panel listing the member's posted jobs/resumes with links to Career Board.
Count badge from `WP_Query`. Verify on a profile with seeded jobs, desktop + 390px.

### Task C — Space (later) · Pro
Only if a community wants a space-scoped job board. Same light pattern.
