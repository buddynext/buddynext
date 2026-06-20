# BuddyNext — Deep UX Re-Audit Brief (per-screen, presentation-grade)

**Why this exists:** the first 1.0 flow-audit (task metadata #1–21) was *structural* — "is the flow wired? is the REST there?" It produced ~7 fixes. The dev team logs ~1000 cards for the same surface. The gap is the entire **UI / UX / consistency / per-state / per-role** layer, walked in a browser. This pass closes that gap. **Do NOT accept "it's wired" as "it's right."** Be generous logging cards — one per real issue, not one per feature.

## The method — apply to EVERY screen

For each screen, walk it in the browser (Playwright MCP, not scripts) across the full matrix:
- **Ends:** frontend **and** backend (wp-admin) where the feature has both.
- **Themes:** light **and** dark (toggle via the host theme's real toggle — [[reference_bn_dark_mode_trigger]]).
- **Roles:** guest · member · the relevant privileged role (space owner/mod · site admin/owner).
- **States:** empty (zero data) · loading/skeleton · error · populated · **overflow** (long names, many items, long content) · edge (suspended/blocked/private).
- **Viewports:** 1440 · 768 · **390** (member-facing only; admin = desktop/iPad per [[feedback_admin_screens_desktop_ipad]]).

Screenshot each meaningful state (route to `~/Documents/work-artifacts/screenshots/YYYY-MM/`, not the webroot). For each screen capture findings across **all** these lenses:
1. **Data flow** — does the rendered data match the source of truth? stale counts, wrong author, missing fields, N+1.
2. **REST/API usage** — which endpoint backs it; is the response shape complete (no client-side scraping); are errors handled; is it app-consumable.
3. **UI consistency** — design tokens (no raw hex/px), spacing scale, component vocabulary (button ladder, card, input, badge, empty-state primitive), icon set (Lucide, no emoji), copy/microcopy tone. Compare the SAME component across surfaces — drift is a card.
4. **End-user UX vs the bar** — judge by what a person expects from **Facebook / X / LinkedIn**, not by what we built ([[feedback_design_for_user_expectation]], [[feedback_ux_over_functionality]]). Affordances, labels ("React" vs "Like"), inline noise (e.g. Follow on every feed row), discoverability.
5. **A11y / responsive / dark** — focus rings, contrast, tap targets ≥40px, RTL logical props, dark token adaptation, reduced-motion.
6. **Empty/error/edge correctness** — is every state designed, or does something blank/break.

## Card logging (Basecamp)
- Project **47683682**, Bugs column **9990191646**, via the official `basecamp` CLI (NOT the MCP) — [[reference_basecamp_project]]. Filed via `basecamp card "<title>" "<body>" --project 47683682 --column 9990191646` (explicit flags; the `$VAR`/`-q` form mis-parses).
- Title = `[surface] concise issue`. Body = expected vs actual + file:line if known + screenshot ref + severity (MICRO/MEDIUM/MAJOR).
- **Fix-vs-card rule** (user, 2026-06-20): a CLEAR micro/medium issue → fix it yourself as senior dev (clean, DRY, no dup, enterprise; judge for 10k site owners). Only MAJOR / needs-a-decision → card. Dedup against the 7 cards already filed (payments ×3, app-REST parity, notifications scope, UX enhancements, white-label scope) and against the already-committed fixes (see [[project_bn_1_0_beta1_readiness]]).

## Screen inventory (walk every one; expand per sub-screen/state)
Front-of-house: signup/login/verify/onboarding wizard · profile (view/edit/hero/tabs: Posts/Scheduled/Replies/Media/Likes/Network/Discussions/Achievements/Portfolio) · activity feed (composer all tools, For-you/Following/Spaces/Network, post types, reaction picker, comment thread, share, save, hashtag pages) · member directory · spaces (directory, hub Feed/Members/Moderation/About/Discussions, settings 8 tabs, create) · messages (rail, thread, compose, group) · notifications (full page + header bell dropdown + prefs) · search (results page + cmd+K overlay) · gamification (Achievements tab + leaderboard) · privacy/block lists · account/security settings · bookmarks · PWA.
Pro front-of-house: membership pricing/checkout/my-membership · Portfolio integration panels (jobs/business/courses) · AI smart-reply island · push opt-in panel.
Backend (wp-admin): every BuddyNext + BuddyNext Pro settings/dashboard/list/editor/wizard screen — Setup wizard, Settings (all groups), Members admin, Moderation queue, Email/Broadcast/Drip builders, Analytics dashboards (Overview/Cohorts/Funnel/Profile-views), Membership/Tiers, Stripe, Push, White-label, AI, integrations.

## Setup notes
- `?autologin=1` (admin) or `?autologin=<login>` (member) — [[reference_local_mail_catcher]] for emails (Mailpit localhost:10010).
- **Seed missing data via the model's API/models, NEVER raw SQL** — [[feedback_seed_via_api_not_sql]] (orphan rows skip indexing/activity/counts). Demo members exist (ids 2–8, real avatars).
- Watch for the isolation mu-plugin hiding Pro on front-end routes — [[reference_isolation_mu_plugin]].
- V2 prototype is the design source of truth where templates diverge — [[reference_v2_ux_prototype]].

## Output of this pass
A large, deduped card backlog (target: the real density, not 7) + the clear micro/medium issues fixed in-place + a per-screen screenshot set that doubles as the raw material for the docs-screenshot phase (B) and marketing (C).
