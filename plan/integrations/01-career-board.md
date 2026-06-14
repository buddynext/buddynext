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
| **Notifications** | **Deferred to Career Board — BN does NOT create them.** CB owns application/job notifications via its own in-app bell (`wcb_notifications` + `wcb/v1/notifications`, when CB Pro is active) + emails. Duplicating would double-notify. (Confirmed against CB's `audit/manifest.json`.) | ✅ DECIDED (defer) |
| **Activity on post** | `wcb_job_created` → BN feed activity + `bn_search_index`; `wcb_job_expired` removes it. `wcbp_resume_published` → "open to work" activity (public resumes only). Idempotent via `SuiteActivity`/`PostService::exists_by_link`. | ✅ DONE (Pro, committed) |
| **Search** | `wcb_job_created` → index job in `bn_search_index` (findable in BN search). | ✅ DONE |
| **Profile — jobs** | Portfolio panel: member's jobs → each job's CB page; "View all" → member's CB company page; owner-only "Manage" → employer dashboard. | ✅ DONE (Pro, committed) |
| **Profile — resumes** | Portfolio panel: member's PUBLIC resume(s) → each resume's own `/resume/{slug}` page (gated on `is_post_type_viewable`); owner-only "Manage" → candidate dashboard. | ✅ DONE (Pro, committed) |
| **Space** | Jobs in a space context. | 🔮 Possible/later |

> **Manifest-first lesson (2026-06-14):** always read the partner plugin's `audit/manifest.json` before integrating. It listed CB Pro's notification bell + REST feed (don't duplicate) and the `wcbp_resume_published` hook (resume activity) — both initially missed by ad-hoc code grep.

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

## The social layer (decided 2026-06-14)

Career Board wires into the **shared surfaces** from `00-social-layer-architecture.md` —
**no Jobs tab, no Resumes tab**, no takeover. All four signals approved:

| Signal | Surface | How |
|---|---|---|
| Activity on new job post | **Feed** | `wcb_job_created` → `SuiteActivity::publish()` ("posted a new job: {title}", links to the job). `wcb_job_expired` removes the card. |
| Jobs on profile | **Portfolio tab** | `buddynext_member_suite_panels` → a `cb_jobs` panel listing the member's posted jobs, linking out to Career Board. |
| Resume / "Open to work" | **Portfolio tab** | a `cb_resume` panel, **gated on the member's resume visibility** (sensitive — only when they've made it public / open-to-work). |
| Application notifications | **Notifications** | ✅ already in `CareerBoardBridge` (employer on apply, candidate on status). |

Optional add-on: a lightweight stats-strip tile ("3 jobs posted") via `buddynext_profile_extra_data`.

## Build tasks (TDD; build the shared infra first, then CB plugs in)

### Task A — Shared infra (build once) · Pro
Per `00-social-layer-architecture.md`: `includes/Suite/SuiteProfile.php` (Portfolio tab +
`buddynext_member_suite_panels` provider API + `templates/suite/portfolio.php`) and
`includes/Suite/SuiteActivity.php` (feed-activity helper over `PostService`). Tests: panels
aggregate + sort by priority; tab hidden when no panels; activity helper creates a card.
**First confirm** the profile-tab panel-render mechanism (see that doc's open note).

### Task B — Career Board provider · Pro
`includes/Integrations/CareerBoard/CareerBoardSocial.php`: register the `cb_jobs` panel
(member's `wcb_job` by author, link to Career Board) and the `cb_resume` panel gated on
resume visibility. Test: provider returns the member's jobs; resume panel hidden when not public.

### Task C — Activity on post · Pro
In the CB social module (or bridge), `wcb_job_created` → `SuiteActivity::publish()`; add the
resume-created listener. Verify: posting a job creates a feed activity; expiry removes it.

### Task D — Verify · Pro
Browser: a seeded member's profile shows ONE Portfolio tab with Jobs (+ Resume if public)
sections linking to Career Board; posting a job creates a feed activity. Desktop + 390px,
light + dark. Confirm Career Board's own pages are untouched.

### Later — Space
A space-scoped job board only if a community wants it. Same shared-surface pattern.
