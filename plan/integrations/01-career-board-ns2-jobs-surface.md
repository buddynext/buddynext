# NS-2 — Native Jobs Surface (Career Board, in Pro)

> Sub-plan of `01-career-board.md` (BM-1 + NS-1 done). Builds the BN-native `/jobs/`
> hub + profile tab. **This also builds the reusable mechanism every future Pro
> surface (courses, events, directory) uses to own a BN route** — so the Free seams
> here are a one-time foundational investment, not jobs-specific.

**Goal:** A top-level `/jobs/` BuddyNext route + a profile "Jobs" tab, rendered with
BN-native components consuming Career Board's data/`wcb/v1`. Career Board ships data
only; BN owns all markup/CSS/JS. No 2nd-party screens, no foreign assets (AssetIsolation
already allowlists `BUDDYNEXTPRO_PLUGIN_URL`).

**Architecture:** Free's `PageRouter` hardcodes 5 hubs with no external-registration
seam. Add 4 small, well-named seams to Free (Free exposes seams; Pro consumes), then
Pro registers the jobs hub, templates, JS store, assets, and profile tab entirely on
its own side. Reference packages: `includes/Media/`, `includes/Messages/` + `templates/messages/`.

**Tier:** Pro. **Placement (locked):** `/jobs/` hub + profile tab. **Feed cards:** kept.

---

## Free seams (Task 1) — the reusable foundation

| Seam | File | Lets Pro… |
|---|---|---|
| `buddynext_hub_template` filter | `PageRouter::resolve_hub_template()` | map an unknown hub (`jobs`) → a template relative path |
| `buddynext_hub_context` filter | `PageRouter::build_hub_context()` | supply the context array for its hub |
| `buddynext_template_paths` filter | `TemplateLoader::locate()` | add `buddynext-pro/templates/` to the search path |
| `buddynext_enqueue_hub_assets` action | `PageRouter::enqueue_hub_assets()` (switch default) | enqueue its hub's bundle + inject `wcb/v1` config |

Pro self-handles (existing WP hooks, no new seam): rewrite rules `/jobs/` + `/jobs/(\d+)/`,
`query_vars`/`add_rewrite_tag` for `bn_jobs_id`, a Pro flush sentinel, REST consumption,
profile tab. Jobs is public → no login-gate seam needed. Title falls back to `ucfirst('jobs')`.

---

## Tasks (TDD; each verified before the next)

### Task 1 — Free hub-extension seams · Free
**Files:** `includes/Core/PageRouter.php`, `includes/Core/TemplateLoader.php`; tests `tests/Core/HubSeamsTest.php`.
- `resolve_hub_template()`: when the core switch yields null, `return apply_filters( 'buddynext_hub_template', null, $hub )`.
- `build_hub_context()`: before returning, `$context = apply_filters( 'buddynext_hub_context', $context, $hub )`.
- `TemplateLoader::locate()`: build the search roots through `apply_filters( 'buddynext_template_paths', $roots )` so Pro can append its templates dir.
- `enqueue_hub_assets()`: at the end (after the switch), `do_action( 'buddynext_enqueue_hub_assets', $hub, $context )`.
- Tests: each filter/action is reached and its value is honoured (register a fake `zzz` hub via the filters, assert template + context resolve and the action fires).
- Bump `ROUTER_VERSION` (rewrite-rule set unchanged, but the dispatch contract changed — keep deploy-flush behaviour predictable). **No version string bump** (pre-release); ROUTER_VERSION is an internal rewrite sentinel, not a plugin version.

### Task 2 — Pro jobs hub registration · Pro
**Files:** `includes/Jobs/JobsHub.php` (route + seams wiring), registered in `Core\Plugin::wire_extensions()`.
- `init` (priority 11): `add_rewrite_rule( '^jobs/?$', 'index.php?bn_hub=jobs', 'top' )` + `'^jobs/([0-9]+)/?$' → bn_hub=jobs&bn_jobs_id=$matches[1]`.
- `query_vars` filter: add `bn_jobs_id`; `init`: `add_rewrite_tag( '%bn_jobs_id%', '([0-9]+)' )`.
- Pro flush sentinel option (`buddynextpro_router_version`) → flush once on change.
- Hook the 4 Free seams: `buddynext_hub_template` (jobs → `jobs/single.php` if `bn_jobs_id` else `jobs/list.php`), `buddynext_hub_context` (jobs → JobsData payload), `buddynext_template_paths` (append `BUDDYNEXTPRO_PLUGIN_DIR . 'templates'`), `buddynext_enqueue_hub_assets` (jobs → JobsAssets::enqueue).
- Verify: visiting `/jobs/` resolves a Pro template (even an empty stub) wrapped in theme chrome, 200 not 404.

### Task 3 — JobsClient + JobsData · Pro
**Files:** `includes/Jobs/JobsClient.php`, `includes/Jobs/JobsData.php`; tests `tests/Jobs/JobsDataTest.php`.
- `JobsClient::available()` → `function_exists( 'wcb_run' )`.
- `JobsData::list( array $args )` → job rows from the `wcb_job` CPT + registered meta (title, company, location, type, salary, posted, permalink, bookmark state). Server-render source.
- `JobsData::get( int $id )` → single job payload (+ employer, apply state).
- `JobsData::for_profile( int $user_id )` → employer's postings + (if owner) candidate's applications.
- Tests: seed `wcb_job` posts (demo data exists), assert list/get/for_profile shapes; assert empty/guarded when `wcb_run` absent.

### Task 4 — BN-native templates · Pro
**Files:** `templates/jobs/list.php`, `templates/jobs/single.php`, `templates/parts/job-card.php`.
- BN tokens (OKLCH), `buddynext_icon()` for icons (no emoji), Interactivity wrapper `data-wp-interactive="buddynextpro/jobs"`, responsive incl. 390px. Mirrors `templates/messages/native.php` shell conventions.

### Task 5 — Jobs Interactivity store · Pro
**Files:** `assets/js/jobs/store.js`.
- `import { store, getContext } from '@wordpress/interactivity'`; reads injected `{ wcbRest, nonce, userId }`; actions: filter/search (re-query `wcb/v1/jobs`), apply (`POST /jobs/{id}/apply`), bookmark (`POST /jobs/{id}/bookmark`). `X-WP-Nonce` header.

### Task 6 — JobsAssets · Pro
**Files:** `includes/Jobs/JobsAssets.php`.
- Gate: `PageRouter::is_bn_route() && 'jobs' === get_query_var('bn_hub')` (or profile tab context). Enqueue `bn-jobs` css + `@buddynextpro/jobs` module; inject `{ wcbRest: rest_url('wcb/v1'), nonce, userId }`. Registered from `JobsHub` on the `buddynext_enqueue_hub_assets` action.

### Task 7 — Profile "Jobs" tab · Pro
**Files:** `includes/Jobs/JobsProfileTab.php`, `templates/jobs/profile-panel.php`.
- `add_filter( 'buddynext_part_profile_tab_bar_args', … )` to append a `jobs` tab (label, count via JobsData::for_profile, icon `briefcase`). Render the panel (employer postings / candidate applications). Verify the tab shows for users with jobs/applications.

### Task 8 — Browser verification · Pro
- Playwright walk: `/jobs/` list, `/jobs/{id}` single, apply + bookmark, profile Jobs tab — desktop + 390px, light + dark. Confirm AssetIsolation strips Career Board's own JS/CSS on `/jobs/` (only BN/Pro assets load), zero console errors. Mark NS-2 done.

---

## Open NS-2 decisions (lock if they come up)
- **Server-render source:** read the `wcb_job` CPT + meta directly (the partner's data) for the list/single SSR; the JS store uses `wcb/v1` for write actions (apply/bookmark). (Default — mirrors MessagesData consuming the partner in-process.) Alternative: in-process `rest_do_request('/wcb/v1/jobs')`.
- **Filters in v1:** keyword + type + location, or keyword only for June? (Surface is "coming in Pro" anyway — build can be incremental.)
