# Conformance — Comments (free)

**Feature:** Comments
**Spec ref:** `docs/specs/features/08-reactions-comments.md` (Locked, 2026-03-19)
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `features/17-roles-permissions.md`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Journey chain

Core happy path: signed-in member opens a post's comments, reads the thread, posts a comment, replies, edits/deletes own, reacts, reports another's, and a moderator pins one.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Comment button on post card | ui | wired | `templates/parts/post-actions.php:199-218` (`data-wp-on--click="actions.openComments"`) |
| Toggle comments region | store | wired | `assets/js/feed/store.js:935-946` (`openComments`); bound region `templates/partials/post-card.php:514-520` + `state.commentsHidden` `store.js:711-713` |
| Fetch thread | store→rest | wired | `store.js:560-598` (`bnLoadComments` GETs `/comments?object_type=post&object_id=`) → `includes/Comments/CommentController.php:66-93,217-304` |
| List service (tree, pin, restrict gate, cache) | service→db | wired | `includes/Comments/CommentService.php:380-540,554-602` (bn_comments) |
| Render thread nodes | store | wired | `store.js:41-534` (`buildCommentNode`, safe DOM) |
| Comment form (input + send) | ui | wired | `templates/parts/post-comment-form.php:82-99` (`data-wp-on--click="actions.submitComment"`) |
| Submit comment | store→rest→service→db | wired | `store.js:947-1018` POST `/comments` → `CommentController.php:170-209` → `CommentService::create` `CommentService.php:49-131` |
| Reply (parent_id) | store→rest | wired | `store.js:490-518` POST `/comments` with `parent_id` |
| Edit own (edited marker) | store→rest→service | wired | `store.js:282-345` PUT `/comments/{id}` → `CommentController.php:312-349` → `CommentService::update` sets `is_edited` `CommentService.php:189-231` |
| Soft delete (placeholder) | store→rest→service | wired | `store.js:350-377` DELETE `/comments/{id}` → `CommentService::delete` (is_deleted=1, blank content) `CommentService.php:240-287`; placeholder render `store.js:97-99,367-368` |
| React on comment | store→rest | wired | `store.js:225-264` POST `/reactions/toggle` (`object_type:'comment'`) → `includes/Reactions/ReactionController.php:31-37,117-124` |
| Report comment | store→rest | wired | `store.js:418-452` POST `/reports` (`object_type:'comment'`) → `includes/Moderation/ModerationController.php:57` |
| Moderator pin/unpin | store→rest→service | wired | `store.js:382-414` POST/DELETE `/comments/{id}/pin` → `CommentController.php:132-161,377-414` (`require_moderator`) → `CommentService::pin/unpin` `CommentService.php:314-350`; pinned prepended `CommentService.php:508-537` |

---

## First break

none — journey complete.

Every spec capability (threading, rich text via `wp_kses_post`, @mentions/emoji as content, edit marker, soft delete, react, report, moderator pin, page pagination, privacy via restrict gate) has a UI control bound to a store action that reaches a live REST endpoint and service/DB write. Guests correctly get a read-only thread (form suppressed at `post-comment-form.php:46`; list endpoint public at `CommentController.php:69`). App/REST clients are equally served — the same routes back the web UI.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Threading depth diverges from spec: spec says "two levels max, no deeper nesting" but code renders up to 5 levels with Discord-style fold-back beyond the cap. Over-delivery (more reply depth than promised), not a journey break — replies stay readable and attached. | low | confirmed-in-code | spec line 25; `CommentService.php:359` (`MAX_REPLY_DEPTH = 5`), fold-back `CommentService.php:462-501`; JS mirror `store.js:24` |
| Comment-create does not independently re-verify parent post visibility ("can't comment on content you can't see"). The restrict gate runs only in `list()`. Likely fine since the feed only renders cards the viewer can see, but create trusts the client. | low | needs-live-verification | `CommentService.php:49-131` (create has no parent-privacy check); restrict gate only in `list()` `CommentService.php:391-417` |

Neither gap stops the journey; both are below the bar for a wiring/refactor action.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## Notes for the live walk

- Seed a post with several comments + one nested reply chain before judging; empty accounts hide the whole region.
- Walk as: (1) post author, (2) a different member, (3) admin/moderator — to exercise edit/delete (own only), report (non-owner only), pin (moderator only). These permission branches are computed server-side in `CommentController::list_comments` enrich (`CommentController.php:258-289`).
- Confirm "(edited)" marker, "[deleted]" placeholder, reaction heart toggle, and "Pinned" badge in light + dark.
