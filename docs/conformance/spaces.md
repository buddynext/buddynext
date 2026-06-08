# Conformance — Spaces (Communities)

**Feature:** Spaces (Communities) — repo: free
**Spec ref:** `docs/specs/features/03-spaces.md` (Locked)
**Journey / mockups:** `docs/v2 Plans/v2/spaces-directory.html`, `docs/v2 Plans/v2/space-home.html`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/spaces

---

## Verdict: usable-leave-as-is

> **Resolution (2026-06-08).** The space Reports tab actions are now wired. The report list is wrapped in a nested `data-wp-interactive="buddynext/moderation"` root (restNonce/restUrl context) with per-card `data-wp-context` (reportId/userId/spaceId), and the `moderation` module is now enqueued on the space-moderation sub-route (`PageRouter`). Also fixed a pre-existing bug in the moderation store: `dismissReport` called `PUT /reports/{id}` (404) instead of `POST /reports/{id}/dismiss` — this affected the top-level queue too. Verified live: Dismiss on a space report set `bn_reports.status=dismissed, resolved_by=1`. `templates/spaces/moderation.php`, `includes/Core/PageRouter.php`, `assets/js/moderation/store.js`.

The core happy-path — discover a space → join / request → land on space home → post to the
space feed → manage members → approve/decline join requests — is **fully wired end to end**
(template control → `buddynext/spaces` Interactivity store action → REST endpoint → service → DB).
The directory is the live entry route and every CTA on it reaches a real store action.

One **non-core moderator sub-journey is broken**: the *content report* action buttons on the
space Moderation page (Dismiss / Warn / Remove content / Remove from space / View) are bound to
actions that do not exist in the store that governs the page. They render against real
`bn_reports` data but click does nothing. This does not block the primary member or owner
journey, hence minor-polish rather than broken-journey.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/spaces` resolves to directory; `enqueue('spaces')` loads store + CSS | rest/service | wired | `includes/Core/PageRouter.php:865`, `:668`; `includes/Core/AssetService.php:373` |
| Directory lists spaces (search/category/type/sort, pagination, secret hidden) | db | wired | `templates/spaces/directory.php:62-142`, `:64` (`type != 'secret'`) |
| Directory is interactive (`data-wp-interactive="buddynext/spaces"`) | ui | wired | `templates/spaces/directory.php:390` |
| Join (open) button → `actions.joinSpace` → `POST /spaces/{id}/join` | ui→store→rest | wired | `directory.php:710`; `assets/js/spaces/store.js:387-425`; `SpaceController.php:706-779` |
| Request to join (private/secret) → `actions.requestJoin` → same endpoint | ui→store→rest | wired | `directory.php:720`; `store.js:431-457`; `SpaceController.php:752-768` |
| Leave / cancel request → `actions.leaveSpace` / `cancelJoinRequest` | ui→store→rest | wired | `directory.php:688`,`:699`; `store.js:523`,`:580`; `SpaceController.php:787-804` |
| Create space → `actions.openCreate`/`submitCreate` → `POST /spaces` | ui→store→rest | wired | `directory.php:377`,`:751`; `store.js:1485-1573`; `SpaceController.php:439-512` |
| Space home (`/spaces/{slug}`) renders, gates private feed for non-members | ui/db | wired | `PageRouter.php:862`; `templates/spaces/home.php:598`,`:660-698` |
| Member feed composer → `actions.submitPost` → `POST /posts` w/ `space_id` | ui→store→rest | wired | `templates/parts/space-feed-panel.php:98`; `store.js:704-752` |
| Owner/mod manage members (roster, remove, role, ban, transfer) | rest/service | wired | `SpaceController.php:1147-1285`; `templates/parts/space-members-panel.php` |
| Approve / decline join request (Pending tab) → `members/{uid}/approve|decline` | ui→store→rest | wired | `templates/spaces/moderation.php:566`,`:575`; `store.js:259-322` (`moderateJoinRequest`); `SpaceController.php:142-161` |
| Handle a reported post (Reports tab — DEFAULT tab) → `actions.dismissReport` etc. | ui→store | **broken** | `templates/spaces/moderation.php:455-493`; actions only in `assets/js/moderation/store.js`, not in `buddynext/spaces`; module not enqueued (`AssetService.php:373-378`, only `@buddynext/spaces`) |
| Settings (general/privacy/members/moderation/notifications/integrations/danger) | ui/rest | wired | `templates/spaces/settings.php`; panels under `templates/parts/space-settings-panel-*.php`; `SpaceController.php:320-353` (permissions) |

---

## First break

Reports tab on the space Moderation page (`templates/spaces/moderation.php:455-493`). The report-action
buttons declare `data-wp-on--click="actions.viewReportedPost | dismissReport | warnMember | removeContent | removeFromSpace"`,
but:

1. The enclosing interactive container is `data-wp-interactive="buddynext/spaces"` (`moderation.php:246`) — the
   Interactivity runtime resolves `actions.*` against the **spaces** store, which defines none of these
   (it defines only `approveJoinRequest`/`declineJoinRequest` for the Pending tab).
2. The five actions exist only in `assets/js/moderation/store.js` (`@buddynext/moderation`), and the spaces
   hub enqueue (`AssetService.php:373` → `wp_enqueue_script_module('@buddynext/spaces')`) does **not** load that module.

Both reasons independently make the buttons inert. The Reports tab is the **default** moderation tab
(`moderation.php:53-57`) and reads real pending rows from `bn_reports` (`moderation.php:103-120`), so a
moderator with a live report sees clickable-looking buttons that silently do nothing.

The membership-moderation path (Pending tab → approve/decline) is unaffected and works.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Space Reports tab action buttons (dismiss/warn/remove content/remove from space/view) are inert: actions undefined in the `buddynext/spaces` store that owns the page, and the `@buddynext/moderation` module is not enqueued on the spaces hub. | high | confirmed-in-code | `templates/spaces/moderation.php:246` (namespace) vs `:455-493` (buttons); `assets/js/spaces/store.js` (no such actions); `assets/js/moderation/store.js` (definitions); `includes/Core/AssetService.php:373-378` (enqueue) |

---

## Minimal refactor plan

Reuse the existing `assets/js/moderation/store.js` actions — do not re-implement. Two equivalent
minimal fixes; pick one:

1. **Wrap the Reports section in its own namespace + enqueue the module.**
   - In `templates/spaces/moderation.php`, wrap the reports list (around `:362`–end of reports branch)
     in an inner element with `data-wp-interactive="buddynext/moderation"` so the report buttons resolve
     against the moderation store, leaving the Pending/log tabs under `buddynext/spaces`.
   - In `includes/Core/PageRouter.php` (spaces hub asset case near `:668`) or in the moderation template
     itself, additionally `wp_enqueue_script_module('@buddynext/moderation')` when the moderation
     sub-template is the active tab, so the store is present.

2. **Or** port the five report actions (`viewReportedPost`, `dismissReport`, `warnMember`,
   `removeContent`, `removeFromSpace`) into the `buddynext/spaces` store from `moderation/store.js`
   (delegating to the same REST endpoints) — only if the spaces hub should not load a second module.

Option 1 is preferred (no logic duplication; reuses the canonical moderation store).
