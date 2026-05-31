# Conformance: Custom Reactions (Pro)

**Feature:** Custom Reactions (admin-configurable custom reaction emoji set, Pro)
**Repo:** buddynext-pro (extends buddynext Free via filter)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/08-reactions-comments.md` (Reactions section — "Admin-configurable emoji set") + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/custom-reactions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Journey chain

Admin configures a custom reaction, it merges into Free's reaction set, and a member can pick it on a feed post and have it persist.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin page registered under BuddyNext menu, `manage_options` | ui | wired | `buddynext-pro/includes/Admin/CustomReactionsAdmin.php:94-104` (submenu, `manage_options`) + `:73-81` (AdminHub tab) |
| Add-reaction form renders (table + add form + cap copy) | ui | wired | `CustomReactionsAdmin.php:130-153` (render_content), `:161-179` (list/empty state) |
| Form POST handled with nonce + capability + validation | rest | wired | `CustomReactionsAdmin.php:294-327` (handle_add: `current_user_can`, `check_admin_referer`, calls service) |
| Validate slug/label/color, dedupe, persist to option | service | wired | `buddynext-pro/includes/Reactions/CustomReactionsService.php:73-113` (add_reaction), `:245-305` (validators) |
| Custom reactions stored in `buddynextpro_custom_reactions` option | db | wired | `CustomReactionsService.php:38,110` (`update_option`, autoload false) |
| Filter merges custom slugs into Free reaction types (cap 20) | service | wired | `CustomReactionsService.php:156-187` (extend_reaction_types) hooked at `buddynext-pro/includes/Core/Plugin.php:141-144` |
| Free resolves label/glyph/color for custom slugs | service | wired | `CustomReactionsService.php:212-237` (reaction_meta) self-registered at `:163`; consumed at `buddynext/templates/parts/post-actions.php:159` |
| Free picker renders all registered slugs (defaults + custom) | ui | wired | `buddynext/templates/parts/post-actions.php:131-195` — iterates `ReactionService::reaction_types()`, emits a `data-reaction-type` button per slug |
| `ReactionService::reaction_types()` applies the filter | service | wired | `buddynext/includes/Reactions/ReactionService.php:188-201` (`apply_filters( 'buddynext_reaction_types', … )`) |
| Member click bound to store action | store | wired | `post-actions.php:185` (`data-wp-on--click="actions.setReaction"`); store `buddynext/post-card` at `buddynext/assets/js/feed/store.js:628,761-784` |
| Store POSTs slug to REST toggle with nonce | store→rest | wired | `store.js:773-777` (POST `/reactions/toggle`, body `emoji: newType`, `X-WP-Nonce: reactNonce`); context provides `restUrl`/`reactNonce` at `buddynext/templates/partials/post-card.php:320-347` |
| REST toggle accepts arbitrary slug (auth required) | rest | wired | `buddynext/includes/Reactions/ReactionController.php:31-57` (route, `require_auth`, `emoji` sanitize_key, no enum), `:117-124` (toggle) |
| Service persists any non-empty slug to `bn_reactions` | service→db | wired | `buddynext/includes/Reactions/ReactionService.php:250-281` (toggle → react/replace, INSERT/UPDATE; no whitelist rejection) |

## First break

None — journey complete. Admin add/remove and the member front-end pick both trace cleanly from UI control → bound store action → Free REST endpoint → `bn_reactions` table. The custom slug is never enum-rejected at the route or service layer, so a custom reaction picked in the UI persists exactly like a built-in one. The merge filter, the meta filter, the admin page, and the front-end picker are all registered at boot (`Plugin.php:141-147`).

## UX gaps

- **Journey automation note guesses the wrong selector** — severity: low, confidence: confirmed-in-code. The journey doc (`docs/journeys/custom-reactions.md:109`) proposes `[data-reaction="celebrate"]` as the front-end picker selector. The actual rendered attribute is `data-reaction-type="celebrate"` on `button.bn-post-card__emoji-btn` (`post-actions.php:182-186`). Doc/automation-note nit, not a runtime break — the picker works.
- **Member step + custom-glyph render needs a live eyeball on a seeded post** — severity: low, confidence: needs-live-verification. Path is fully wired, but per the project's "seed data before calling a gap" rule, confirm the count increment and the colored text-glyph fallback (custom slugs ship no SVG → tinted glyph at `post-actions.php:169-194`) on a real seeded post in light + dark mode.

## Minimal refactor plan

Empty — feature is usable as-is. (Optional, non-blocking, doc-only: align the journey doc's selector note from `[data-reaction]` to `[data-reaction-type]`.)

---

### Notes on cross-cutting contracts
- **REST/front-end contract:** the front-end reaches the same `buddynext/v1/reactions/toggle` endpoint a REST/app client would, via the Interactivity store with nonce auth — both journeys served.
- **Scale:** `bn_reactions` enforces one reaction per user+object (spec "One reaction per user per object"); counts are cached.
- **Visibility/roles:** admin handlers gate on `manage_options`; the toggle route gates on `require_auth`.
- **Isolation mu-plugin caveat:** if Pro is stripped on a front-end route, `post-actions.php:131-135` degrades to the six built-in slugs (custom slugs simply absent) — the picker never fatals. Not a break in normal operation.
