# Conformance — Comments (free)

**Spec ref:** `docs/specs/features/08-reactions-comments.md` (Locked)
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Feature

Threaded comments on feed posts: open thread, post a comment, reply, edit own,
soft-delete, react, report, moderator-pin. Shared `bn_comments` engine over
`object_type`/`object_id`. This audit traces the core web journey on `post`
objects (the activity feed). App/REST clients are served by the same routes.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Activity page enqueues feed bundle (store.js + bn-feed.css) | ui | wired | `includes/Core/PageRouter.php:630` (`$assets->enqueue('feed')`); module map `includes/Core/AssetService.php:315` |
| Post card renders with Interactivity context (postId, restUrl, reactNonce, commentCount) | ui | wired | `templates/partials/post-card.php:318-356` |
| "Comment" toggle button bound to store action | ui | wired | `templates/parts/post-actions.php:199-218` (`data-wp-on--click="actions.openComments"`) |
| openComments toggles region + fetches thread | store | wired | `assets/js/feed/store.js:935-946` → `bnLoadComments` `:560-626` |
| GET /comments?object_type=post&object_id= | rest | wired | `includes/Comments/CommentController.php:66-93`, `217-304`; route reg `includes/REST/Router.php:81` |
| List service builds N-deep tree + pinned prepend | service | wired | `includes/Comments/CommentService.php:380-540` |
| Comments read from bn_comments | db | wired | `includes/Comments/CommentService.php:569-590` |
| Thread rendered as DOM nodes (author, time, edited mark, deleted placeholder) | store | wired | `assets/js/feed/store.js:41-107` (`buildCommentNode`) |
| Comment form textarea + submit bound | ui | wired | `templates/parts/post-comment-form.php:82-99` (`data-wp-on--click="actions.submitComment"`) |
| submitComment POSTs and appends new node | store | wired | `assets/js/feed/store.js:947-1012` |
| POST /comments → create + count bump + hooks | rest/service | wired | `CommentController.php:170-209`; `CommentService.php:49-131` |
| Reply (per-node form) POSTs with parent_id | store | wired | `store.js:457-516`; controller `parent_id` arg `CommentController.php:59-63` |
| Edit own comment (inline form, PUT, "edited" mark) | service | wired | `store.js:282-345`; `CommentController.php:312-349`; `CommentService.php:189-231` |
| Soft delete (placeholder, count -1) | service | wired | `store.js:350-376`; `CommentService.php:240-287` |
| React on comment (picker → POST /reactions) | rest | wired | `store.js:126-265` (object_type='comment') |
| Moderator pin/unpin | service | wired | `store.js:385+`; `CommentController.php:377-414`; `CommentService.php:314-350` |
| Suspended-user gate before write | service | wired | `CommentService.php:56-64` |

## First break

none — journey complete. A logged-in member can open a post's thread, read
existing comments, post a comment, reply, edit, delete, react, and (as
moderator) pin — every link is bound from template control → store action →
REST route → service → `bn_comments`.

## UX gaps

These are spec/contract divergences, not journey breaks. The happy path on
public activity posts works regardless.

1. **Threading depth exceeds spec (medium, confirmed-in-code).**
   Spec line 25: "two levels max, no deeper nesting." Code permits 5 levels:
   `CommentService::MAX_REPLY_DEPTH = 5` (`CommentService.php:359`) and
   `COMMENT_MAX_DEPTH` in `store.js` (`:20`). The thread still renders and is
   usable; it just nests deeper than the locked spec describes.

2. **Privacy inheritance not enforced in the comment layer
   (high, needs-live-verification).** Spec lines 37-38: "Inherits parent
   object's privacy. Can't comment on content you can't see." `list_comments`
   permission is `__return_true` (`CommentController.php:69`) and `create`
   checks only auth + suspension (`CommentService.php:49-64`) — neither
   resolves the parent post's `privacy`. The list query is keyed on
   `object_type`/`object_id` only (`CommentService.php:569-581`). For public
   feed posts (the audited journey) this is correct. Open question: is a
   non-public post's card ever served to a viewer who can't see it? If the
   feed/post-detail layer already gates the card (postId never reaches a
   disallowed viewer), there is no real leak. Needs a live walk: post a
   `connections`-only post, add a comment, load as a non-connection, watch the
   `/comments` request.

3. **`viewer_reaction` read by JS but not supplied by PHP enrichment
   (low, confirmed-in-code).** `store.js:157` reads `comment.viewer_reaction`
   (falls back to `'like'`); the controller supplies `viewer_liked` but never
   `viewer_reaction` (`CommentController.php:258-289`). Cosmetic — a
   previously-reacted comment defaults its highlighted glyph to the like icon
   rather than the actual emoji chosen. Does not block the journey.

## Minimal refactor plan

None for the journey itself — it is usable end-to-end. Gaps above are spec
alignment items, not journey wiring, and are recorded for a separate
spec-vs-behaviour reconciliation pass.
