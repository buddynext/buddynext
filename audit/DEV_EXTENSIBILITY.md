# Developer-Extensibility Audit — BuddyNext (free core)

**Generated:** 2026-06-09
**Lens:** "Tool, not SaaS." Every completed component reviewed for whether a third-party
developer can *mold* it without forking — lifecycle hooks, REST filterability, template
overrides, service swappability, and concrete blocking gaps.

> **Method note.** This audit was seeded by five parallel exploration agents, then **every
> claim acted on was re-verified against the code.** Agent reports contained contradictions
> and false positives (e.g. one claimed `buddynext_post_deleted` does not exist — it fires at
> `PostService.php:375`). Findings below are tagged **[VERIFIED]** (confirmed at code level,
> with `file:line`) or **[UNVERIFIED]** (agent lead, not yet code-checked — do **not** act on
> these until verified). No code is changed on the strength of an unverified lead.

---

## 0. Fixed in this pass — the nav extensibility contract (shipped)

The single highest-value finding was not a missing feature but a **broken, advertised contract
plus dead consumer code** — exactly the "dead/duplicate code" class to catch before adding anything.

**What was wrong [VERIFIED]:**

- `buddynext_nav_items` was hooked by **both** `JetonomyBridge` (Discussions link) and
  `WPMediaVerseBridge` (Media link) — but the filter **fired in zero places**. Both bridge nav
  links were silently dead.
- The admin nav UI (`NavManager.php:685`) advertised `buddynext_main_nav_items` and
  `buddynext_profile_tabs` to developers — **both fire in zero places** (phantom filters).
- The canonical `docs/specs/HOOKS.md` documented `buddynext_nav_items` as the real seam, with a
  raw-SVG `icon` contract — wrong on both counts.
- Even after repointing, the bridge items would still have failed: the rail skips any item
  missing `show => true`, and renders `icon` via `buddynext_icon($slug)` (a Lucide **slug**, not
  the inline SVG the bridges supplied).

**The real seam [VERIFIED]:** `buddynext_rail_items` (`templates/shell/rail.php:126`) — the live
primary-nav surface. Item shape: `key, label, url, icon` (slug), `show` (bool), `badge` (int,
optional), `active` (bool, optional).

**Fix (consolidation, no new seam):**
- `JetonomyBridge` + `WPMediaVerseBridge`: repointed to `buddynext_rail_items`; added
  `show => true`; replaced inline-SVG `icon` with slugs (`list`, `image`); docblocks made accurate.
- `rail.php`: now honours an explicit `active` flag so injected non-hub links *can* highlight
  (previously active-state was hub-keyed only — the documented behaviour was itself incomplete).
- `NavManager.php`, `docs/specs/HOOKS.md`, `docs/MASTER_DEVELOPMENT_PLAN.md`: phantom filter names
  replaced with the real ones (`buddynext_rail_items`, `buddynext_part_profile_tab_bar_args`,
  `buddynext_space_tabs`, `buddynext_context_nav`).

**Verified end-to-end:** browser snapshot of `/activity/` rail now shows **Media → /explore-media/**
and **Discussions → /community/**, each with a resolved icon. Zero remaining references to the
dead/phantom filter names across code and docs.

> The nav surfaces are intentionally layered: `buddynext_rail_items` (desktop left rail),
> `buddynext_context_nav` (Level-2 sub-nav), `buddynext_space_tabs` (space tabs),
> `buddynext_part_profile_tab_bar_args` (profile tabs), `buddynext_nav_tabs` (admin-only config
> registry in `NavManager`). The mobile bottom bar (`partials/nav.php`) is a fixed 5-item pattern
> by design and is **not** filterable.

---

## 1. Verified hook asymmetries (consistency bugs — cheap, in-place fixes)

| Gap | Evidence | Severity |
|---|---|---|
| `BlockService::mute()` / `unmute()` fire **no action**, while `block`/`unblock`/`restrict`/`unrestrict` all do | `BlockService.php:124`, `:156` vs `:76`, `:110`, `:214`, `:247` | **MED** — gamification/analytics blind to mutes |
| `PrivacyService::set_preference()` fires **no action** at all | `PrivacyService.php` (no `do_action`) | **MED** — privacy changes unobservable |
| Post **update** fires no action (`created`/`deleted` do) | `PostService.php:198`, `:375`; no `buddynext_post_updated` | **MED** — bridges can't react to edits |
| Comment **before-create** has no filter (after-lifecycle is complete: created/updated/deleted all fire) | `CommentService.php:103`, `:228`, `:284` | **MED** — can't validate/transform before save |

These are pattern-completions, not new concepts — add the missing `do_action`/`apply_filters`
to match the seams already present beside them.

---

## 2. Verified-but-mitigated / systemic (real, lower urgency)

| Finding | Evidence | Note |
|---|---|---|
| `ROLE_MAP` + Abilities `CATALOG` are private consts, not filterable | `PermissionService.php:38`, `Abilities.php:24` | **Mitigated** by layer-4 `buddynext_user_can` (`PermissionService.php:121`) — devs aren't fully blocked |
| No global REST response-envelope filter | controllers return `new WP_REST_Response($data)` directly | **Systemic** — one shared fix, not 15 scattered ones. Weigh against the bloat test before adding |
| No `buddynext_register_services` filter to rebind container services | `Container.php` `bind()` reachable but no documented seam | **MED** — `Container::instance()->bind()` works at `plugins_loaded:10`; fragile/undocumented |
| Write-path **before-create** filters absent across posts/comments/reactions/polls/shares | services fire after-actions only | Recurring theme — judge per case; a single convention (`buddynext_{type}_pre_save`) would cover most |

**Confirmed already-extensible (no action needed):** `FeatureRegistry` (`buddynext_features`,
`FeatureRegistry.php:239`), template override system (`buddynext_get_template` → child/parent/plugin
cascade), `buddynext_space_tabs`, `buddynext_context_nav`, `buddynext_part_profile_tab_bar_args`,
`buddynext_profile_extra_data`, `buddynext_member_card_meta_html`, the 20+ bridge lifecycle actions
consumed by Gamification/Jetonomy/WPMediaVerse bridges.

---

## 3. UNVERIFIED agent leads — verify before acting

Plausible but raised by agents that were wrong elsewhere. **Do not implement on these alone.**

- Comments rendered JS/DOM-only with no overridable template.
- Member directory query/sort sealed — no `buddynext_directory_query_args` filter.
- No REST unblock/unmute endpoints (block/mute exist, reverse may not).
- Moderation report-reason enum hardcoded (`ModerationService` REASONS const) — no filter.
- Space-type enum (`open/private/secret`) hardcoded — no filter.
- Social-provider list (Google/Facebook) not filter-extensible despite `buddynext_auth_social_providers` seam.
- Hashtag extraction regex hardcoded; search result post-filter is all-or-nothing.
- Bridge pattern undocumented; no `buddynext_register_bridge` self-registration hook.

---

## 4. Remediation log — what shipped (2026-06-09)

Each gap was first traced + adversarially double-verified by the `dev-extensibility-spec`
workflow (run `w0jnuwdol`, 12 gaps × trace+verify) before any edit. Every change was then
lint-clean and **runtime/browser-verified** (a listener fired, a filter mutated/rejected, a
registered provider flowed end-to-end). Verification corrected several agent leads — block/mute
REST endpoints already exist; the bridge self-registration hook (`buddynext_load_bridges`) already
exists; `buddynext_post_deleted` already exists — those were dropped, not built.

| # | Gap | Seam(s) added | Verified |
|---|---|---|---|
| Nav | dead `buddynext_nav_items` + phantom filters | repointed bridges → `buddynext_rail_items`; rail honours `active` | browser ✓ |
| P1 | mute/unmute silent | `buddynext_mute`, `buddynext_unmute` | runtime ✓ |
| P2 | privacy change silent | `buddynext_privacy_preference_changed` (wired through the live ProfileController edit flow) | runtime ✓ |
| P3 | post update silent | `buddynext_post_updated` | runtime ✓ |
| P4 | no before-save validation | `buddynext_post_before_save`, `buddynext_comment_before_save`, `buddynext_share_before_save`, `buddynext_poll_vote_before_save` (transform + WP_Error reject) | runtime ✓ |
| P5 | directory query/sort sealed | `buddynext_member_directory_query_args`, `_order_by`, `_items` | runtime ✓ |
| P6 | no per-item search filter | `buddynext_search_item` (enrich-only; suppression stays at query level) | runtime ✓ |
| P7 | hashtag regex hardcoded | `buddynext_hashtag_pattern` | runtime ✓ (unicode) |
| P8 | report reasons hardcoded | `buddynext_report_reasons` (memoised accessor; REASONS stays private; no REST-enum coupling) | runtime ✓ |
| P9 | comment markup JS-only | `buddynext.comment` + `buddynext.commentNode` (wp.hooks); dead `author_meta_html` wired + `wp_kses_post`-hardened | browser ✓ |
| P10 | social providers half-wired | `buddynext_oauth_providers` — every OAuth flow now reads the filtered map | runtime ✓ end-to-end |
| P12 | platform seams | `buddynext_role_map`, `buddynext_abilities`, `buddynext_rest_init`, `buddynext_rest_routes_registered`, `buddynext_register_services`, `buddynext_services_registered` | runtime ✓ |

### Deliberate exclusions (recorded, not punted)

- **Reactions before-save filter** — NOT added. Reactions already expose `buddynext_reaction_types`
  (gate allowed emoji) + the `buddynext_reaction_added` action. A reject-capable before-save filter
  would require threading `WP_Error` through the high-traffic `toggle()` (void) → controller path; a
  reject that those swallow would be a silent failure. Fails the bloat test for marginal gain. The
  existing seams cover the need.
- **Search per-item suppression (null-drop)** — the per-item filter mutates only; dropping items
  would desync `total`/cursor (itself half-cooked). True exclusion belongs in
  `buddynext_member_directory_query_args` / `buddynext_search_query_args`.

## 5. P11 — space-type registry — **DONE**

New `includes/Spaces/SpaceTypeRegistry.php`: each type declares `visibility` (public|private|secret),
`join` (direct|request|invite), `label`, `tone`; all behaviour (listed? hidden from non-members?
content member-gated?) is **derived** from `visibility`. Register a custom type via the
`buddynext_space_types` filter — the three core types are always guaranteed to exist.

Atomic migration of every hardcoded `open/private/secret` check (no partial state — privacy-critical):
- `SpaceService` — `type_label`, create/update validation, list **and** search secret-exclusion (→ `unlisted_keys()`).
- `SpaceController` — list filter, validation, get-space gate, member-roster gate, join logic (invite/request).
- `PageRouter` + `PostController` — secret-space post gates → `is_hidden_from_non_members()`.
- Templates — `home` (404 gate + feed gate), `directory` (own SQL exclusion + visibility chip + join button), `settings`/`members`/`moderation` (tone+label), `space-card` (join button), `create-space-modal` (type `<option>`s now registry-driven), admin `Spaces` badge.

Consolidation win: the badge tone had **drifted** across 6 templates (open was `info` in some, `success`
in others; admin used a third set). All now read one canonical tone from the registry.

Verified: built-in semantics byte-for-byte preserved (`secret` = only unlisted key, matching the old
`type != 'secret'`); a custom `vip` (secret-like) / `team` (private-like) type derives all rules and
flows into the SQL exclusion; directory + private-space home render clean in the browser; create form
offers registry types. The secret→404 path is unchanged boolean logic over `is_hidden_from_non_members()`
(admin bypass intact), runtime-verified true for `secret`.

Paused under this audit: the access-control/anti-spam/usability plan (RegistrationGuard wiring,
Community Visibility presets, setup usability). `RegistrationGuard` (`includes/Auth/RegistrationGuard.php`)
remains standalone and unwired — safe.
