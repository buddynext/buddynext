# Conformance — Comments (free)

**Spec ref:** `docs/specs/features/08-reactions-comments.md` (Locked, 2026-03-19)
**Cross-cutting:** REST-FRONTEND-CONTRACT.md, SCALE-CONTRACT.md, 17-roles-permissions.md (visibility)
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The Comments journey on feed posts is wired end-to-end: a real UI control
(the Comment action button) opens a thread region, lazily fetches the thread
over REST, renders threaded comments with reply/edit/delete/react/report/pin
controls, and a bound submit button posts new comments. Backend service,
controller, notification fan-out, scale caching, and visibility (restrict)
gating are all present. No journey-stopping break found.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | "Comment" button on post card | ui | wired | `templates/parts/post-actions.php:146` (`data-wp-on--click="actions.openComments"`) |
| 2 | `openComments` toggles + loads thread | store | wired | `assets/js/feed/store.js:923-934` → `bnLoadComments` |
| 3 | GET /comments?object_type=post&object_id=… | rest | wired | `store.js:585`; `includes/Comments/CommentController.php:217-304` (public read) |
| 4 | List service (paginated tree, restrict gate, pinned prepend) | service | wired | `includes/Comments/CommentService.php:342-502` |
| 5 | Read thread from `bn_comments` (gen-keyed cache) | db | wired | `CommentService.php:516-564` |
| 6 | Render comment nodes (safe DOM, depth cap, deleted placeholder) | ui | wired | `store.js:41-533` (`buildCommentNode`) |
| 7 | Comment textarea + submit button | ui | wired | `templates/parts/post-comment-form.php:82-99` (`data-comment-input`, `actions.submitComment`) |
| 8 | `submitComment` POSTs new comment | store | wired | `store.js:935-1007` |
| 9 | POST /comments (auth) → create | rest | wired | `CommentController.php:170-209` |
| 10 | Insert + count bump + hooks | service/db | wired | `CommentService.php:48-120` (insert, `bn_posts.comment_count++`, `buddynext_comment_created`) |
| 11 | Notify post owner of comment | service | wired | `includes/Notifications/NotificationListener.php:41,273` (`on_comment_created`) |
| 12 | Reply (parent_id) | ui→service | wired | `store.js:496-499`; `CommentService.php:48` (parent_id), tree attach `CommentService.php:424-463` |
| 13 | Edit own comment ("edited" marker) | ui→service | wired | `store.js:318` (PUT); `CommentService.php:151-193` (`is_edited=1`) |
| 14 | Soft delete ("deleted" placeholder) | ui→service | wired | `store.js:362-372`; `CommentService.php:202-249` (`is_deleted=1`, blank content); anonymize on list `CommentController.php:246-286` |
| 15 | React on comment | ui→service | wired | `store.js:126-271` (`object_type:'comment'`); enrich like fields `CommentController.php:258-289` |
| 16 | Report to moderation | ui→service | wired | `store.js:417-451` (`object_type:'comment'` report) |
| 17 | Moderator pin/unpin | ui→rest | wired | `store.js:385-414`; `CommentController.php:132-161` (`require_moderator`); `CommentService.php:276-312` |
| 18 | @mention / emoji typeahead in comment box | ui | wired | `store.js:2108-2123` (`enhanceCommentForms` → `attachMentionHashtagTypeahead`), MutationObserver `store.js:2128+` for injected reply forms |

---

## First break

none — journey complete.

---

## UX gaps

None that stop the journey. Notes for the human walk (not refactor items):

- **Two-level nesting (spec line 25) vs. 5-deep cap (code).** The spec says
  "two levels max, no deeper nesting"; both `CommentService::MAX_REPLY_DEPTH = 5`
  (`CommentService.php:321`) and `COMMENT_MAX_DEPTH = 5` (`store.js:24`) allow
  five. This is a deeper-is-more-permissive divergence, not a usability break —
  threads still render and flatten gracefully at the cap. Severity: low,
  confidence: confirmed-in-code. Decide spec-vs-code source of truth; do not
  rewrite working threading for this.
- **Viral-thread pagination.** `CommentService::list()` loads the full
  descendant set in one query (`CommentService.php:402-412`, acknowledged in
  the inline comment). Fine for normal community sizes per SCALE-CONTRACT;
  >1000-comment threads are an explicitly deferred separate sprint. Severity:
  low, confidence: confirmed-in-code, needs-live-verification at scale.

---

## Minimal refactor plan

Empty — feature is usable as built. No code changes proposed.
