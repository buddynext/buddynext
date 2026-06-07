# Conformance: Custom Reactions (Pro)

**Feature:** Custom Reactions (admin-defined reaction emoji set extending Free's default six)
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/08-reactions-comments.md` (Reactions section — "Admin-configurable emoji set") + journey `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/custom-reactions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Journey chain

Owner configures a custom reaction in admin → it merges into Free's reaction-type list via filter → member sees it in the front-end picker → member selects it → it persists via REST.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Admin page registered under BuddyNext menu (slug `buddynextpro-custom-reactions`) + AdminHub Settings → Reactions tab | ui | wired | `buddynext-pro/includes/Admin/CustomReactionsAdmin.php:68-104` |
| 2 | Add-reaction form (slug/label/color, HTML5 `pattern` validation) posts to `admin-post.php` | ui | wired | `CustomReactionsAdmin.php:232-280` |
| 3 | `handle_add()` enforces `manage_options` + nonce, validates, persists | service | wired | `CustomReactionsAdmin.php:294-327`; `CustomReactionsService::add_reaction()` `includes/Reactions/CustomReactionsService.php:73-113` |
| 4 | Stored in WP option `buddynextpro_custom_reactions` (not a table — matches journey) | db | wired | `CustomReactionsService.php:38,110` |
| 5 | Pro hooks `buddynext_reaction_types`; `extend_reaction_types()` merges + caps at 20 | service | wired | `buddynext-pro/includes/Core/Plugin.php:141-147`; `CustomReactionsService.php:156-187` |
| 6 | Free `ReactionService::reaction_types()` applies that filter | service | wired | `buddynext/includes/Reactions/ReactionService.php:188-202` |
| 7 | Front-end picker iterates `reaction_types()`, renders a button per slug (incl. custom) with `data-reaction-type` | ui | wired | `buddynext/templates/parts/post-actions.php:131-195` |
| 8 | Custom slug has no SVG → `buddynext_reaction_meta` supplies label/color/glyph; template renders colored text glyph fallback so the button is never blank | ui | wired | `CustomReactionsService::reaction_meta()` `CustomReactionsService.php:212-237`; template fallback `post-actions.php:169-194` |
| 9 | `actions.setReaction` reads `data-reaction-type`, POSTs `{object_type, object_id, emoji:<slug>}` to `/reactions/toggle` with nonce | store | wired | `buddynext/assets/js/feed/store.js:761-784` |
| 10 | REST `POST /buddynext/v1/reactions/toggle` (auth-gated), `emoji` sanitized via `sanitize_key`, no whitelist restriction | rest | wired | `buddynext/includes/Reactions/ReactionController.php:31-57,117-136` |
| 11 | `ReactionService::toggle()` stores any sanitized slug into `bn_reactions` (custom slug accepted, no canonical-six gate) | db | wired | `buddynext/includes/Reactions/ReactionService.php:43-56,250-272` |

## First break

none — journey complete. Both the admin-config web journey and the member front-end react journey are fully wired across ui → store → rest → service → db, and the REST/app path accepts custom slugs without a whitelist gate.

## UX gaps

No usability breaks. Two non-blocking observations (both `needs-live-verification`, neither stops the journey):

1. **Journey-doc REST drift (doc, not code).** The journey (`custom-reactions.md:39-45,86-91`) describes `GET /buddynext/v1/reactions` returning a slug list including `celebrate`. The actual `/reactions` route is `get_count` and requires `object_type`+`object_id` (`ReactionController.php:59-79`); there is no slug-list endpoint. The web picker does not depend on this — it reads `reaction_types()` server-side in the template — so the journey still completes. The doc's verification step #5 would need rewriting, but the feature works. Severity: low.

2. **Custom-slug glyph is a text initial, not an emoji.** Custom reactions ship no SVG, so the picker shows a one-character colored glyph derived from the label (`post-actions.php:169-194`). This is intended per the service docblock and is visible/clickable; it is a visual-fidelity note, not a break. Confirm on a live light+dark walk that the glyph color has adequate contrast against the picker background. Severity: low.

## Minimal refactor plan

None — feature is usable as-is. (Optional doc-only fix: update journey step #5 in `buddynext-pro/docs/journeys/custom-reactions.md` to reflect that there is no slug-list REST endpoint; the picker resolves types server-side.)

## Notes on grounding-rule risks checked

- Isolation mu-plugin: the template degrades gracefully if `ReactionService` is stripped (`post-actions.php:131-135`), so front-end isolation would fall back to the built-in six rather than fatal — not a custom-reactions break.
- App/REST client: `/reactions/toggle` accepts arbitrary sanitized slugs, so an app client can react with a custom slug identically to the web client.
