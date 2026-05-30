# Conformance: Custom Reactions (Pro)

**Feature:** Custom Reactions (admin-defined reaction emoji set)
**Repo:** buddynext-pro (extends buddynext Free via filter)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/08-reactions-comments.md` ("Admin-configurable emoji set") + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/custom-reactions.md`
**Date:** 2026-05-31

## Verdict: partial-needs-wiring

The admin authoring half is fully usable and runtime-wired. The member-facing
half (a custom reaction appearing in, and being selectable from, the front-end
web reaction picker) is not wired: the picker template hardcodes the six
built-in reactions and never consumes `buddynext_reaction_types`. App/REST
clients can still POST a custom slug directly, so this is an **api-only** gap
specific to the web journey — not a total break.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Admin opens Custom Reactions (Pro) page | ui | wired | `buddynext-pro/includes/Admin/CustomReactionsAdmin.php:33` (PAGE_SLUG `buddynextpro-custom-reactions`), registered in `includes/Core/Plugin.php:145` |
| Admin submits Add Reaction form (slug/label/color) | rest | wired | `CustomReactionsAdmin.php:70` `admin_post_buddynextpro_add_custom_reaction` → `handle_add()` (nonce + manage_options) |
| Service validates + persists to option | service | wired | `buddynext-pro/includes/Reactions/CustomReactionsService.php:73` `add_reaction()` (slug/label/color validation, dup check, `update_option`) |
| Stored in `buddynextpro_custom_reactions` option | db | wired | `CustomReactionsService.php:38,110` (option key + `update_option`) |
| Pro merges into Free reaction-type list | service | wired | `CustomReactionsService::extend_reaction_types()` :156; hooked `Plugin.php:142` on `buddynext_reaction_types`; cap=20 |
| Free exposes merged list via filter | service | wired | `buddynext/includes/Reactions/ReactionService.php:201` `apply_filters('buddynext_reaction_types', REACTION_TYPES)` |
| Member opens web reaction picker | ui | broken | `buddynext/templates/parts/post-actions.php:115-133` hardcodes `$reaction_labels` (6 defaults), never calls `ReactionService::reaction_types()` / the filter |
| Member selects custom reaction in picker | store | missing | `assets/js/feed/store.js:761` `setReaction` reads `data-reaction-type` from the rendered buttons — custom slug is never rendered, so no button exists to click |
| REST stores the chosen reaction | rest/service | api-only | `ReactionController.php:49-54` accepts any `sanitize_key` emoji; `ReactionService::react()/toggle()` (`:43,:250`) do NOT validate against `reaction_types()`, so a custom slug IS storable by a direct REST call |
| Admin removes custom reaction | rest/service | wired | `CustomReactionsAdmin.php:71` `handle_remove()` → `CustomReactionsService::remove_reaction()` :123 |

## First break

`buddynext/templates/parts/post-actions.php:115-133` — the web reaction picker
hardcodes the six built-in reactions and never consumes the
`buddynext_reaction_types` filter, so a custom reaction defined by Pro never
appears as a clickable picker button. The entire Pro merge has no effect on the
web member journey. (The journey doc itself flags this at lines 47-51 / 108-109:
"frontend selector TBD".)

## UX gaps

1. **[critical / confirmed-in-code]** Custom reactions never reach the web
   picker. `post-actions.php:115-133` iterates a static 6-entry array instead of
   `ReactionService::reaction_types()`. A Pro-defined `celebrate` is stored,
   merged into the filter, and accepted by REST, but no member can select it
   from a browser. Evidence: `templates/parts/post-actions.php:115-133`;
   `buddynext-pro/docs/journeys/custom-reactions.md:47-51,108-109`.

2. **[high / confirmed-in-code]** No glyph for custom slugs. The picker renders
   icons via `buddynext_emoji($slug)` / `IconService::render_emoji()`
   (`buddynext/includes/Core/IconService.php:92`), which returns an empty string
   when `assets/emoji/<slug>.svg` is absent. Custom slugs ship no bundled SVG, so
   even if the picker iterated the filter, the button would render blank. The
   stored `label` and `color` from the option are discarded — the filter merge
   passes slug strings only (`CustomReactionsService::extend_reaction_types():172`).

3. **[low / confirmed-in-code]** No Free REST endpoint returns the reaction-type
   list. The journey step 5 expects `GET /buddynext/v1/reactions` to return the
   slug list including `celebrate`, but `ReactionController.php:59-79` `/reactions`
   returns a per-object count, not the type list. App/REST clients cannot
   discover the available custom reactions; they must know the slug out-of-band.

## Minimal refactor plan

1. In `buddynext/templates/parts/post-actions.php`, replace the static
   `$reaction_labels` array (lines 115-122) with a loop over
   `\BuddyNext\Reactions\ReactionService::reaction_types()`, deriving each
   button's label from the stored custom-reaction `label` (fall back to the slug)
   so Pro slugs render as picker buttons. Keep the existing built-in labels for
   the six defaults.
2. Make the glyph resilient: when `assets/emoji/<slug>.svg` is missing, render a
   text/colored-token fallback (using the option's `color`/`label`) instead of an
   empty span — wire `IconService::render_emoji()` to a fallback, or have the
   picker emit a colored chip for custom slugs. Pass label/color through the
   filter (or have the template read the option) since the current filter is
   slug-only.
3. (App/REST parity, optional for web) Add a read endpoint or extend
   `GET /buddynext/v1/reactions` to return the merged `reaction_types()` list so
   REST clients can discover custom slugs, satisfying journey step 5.

No backend or admin rewrite is needed — `CustomReactionsService`,
`CustomReactionsAdmin`, the `buddynext_reaction_types` filter wiring, and the
REST toggle path are all built and working. The fix is confined to the Free
picker template (+ icon fallback).

## Live-walk URL

http://buddynext-dev.local/activity (admin authoring at
`wp-admin/admin.php?page=buddynextpro-custom-reactions`)
