# Conformance: Connections (Social Graph)

**Feature:** Connections (mutual request → accept) — Free
**Repo:** buddynext (free)
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked) + journey `docs/journeys/social-graph.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

## Verdict

**usable-leave-as-is** (with one minor optional polish noted).

The core connection journey — send request, get notified, accept/decline, view connections, withdraw, disconnect — is fully wired end-to-end on BOTH the web journey (Interactivity API UI → REST → service → DB) and the app/REST journey. No journey break found.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Profile "Connect" button (other member's profile) | ui | wired | `templates/parts/profile-hero.php:423-426` (`data-wp-on--click="actions.connect"`) |
| `connect` store action → POST /connect | store | wired | `assets/js/profile/store.js:1092-1107` |
| Route + auth + block guard for send | rest | wired | `includes/SocialGraph/ConnectionController.php:35-48,97-121` |
| send_request inserts pending row, fires hook | service/db | wired | `includes/SocialGraph/ConnectionService.php:47-126` (insert into `bn_connections`, `do_action('buddynext_connection_requested')`) |
| Recipient notified of request | service | wired | `includes/Notifications/NotificationListener.php:36,155-172` (`bn.connection_requested`) |
| Notification links recipient to requester profile | service | wired | `includes/Notifications/NotificationMessageService.php:683-688` (`PageRouter::profile_url($actor_id)`) |
| Accept / Decline buttons on requester's profile | ui | wired | `templates/parts/profile-hero.php:435-446` (Accept/Decline, shown when `connectionReceived`) |
| accept/decline store actions → REST | store | wired | `assets/js/profile/store.js:1126-1155` |
| accept/decline routes | rest | wired | `ConnectionController.php:50-68,129-159` |
| status transition pending→accepted / →declined + hooks | service/db | wired | `ConnectionService.php:135-232` |
| Withdraw pending ("Pending" button) → DELETE /connect | ui/store/rest | wired | hero:429-433; `store.js:1111-1124`; `ConnectionController.php:170-188`; `ConnectionService.php:241-281` |
| Disconnect accepted ("Connected" button) → DELETE /connect | ui/store/rest/service | wired | hero:447-452; `store.js:1157-1170`; controller withdraw falls back to `remove_connection` (`ConnectionController.php:177-180`; `ConnectionService.php:293-328`) |
| View accepted connections (profile tab) | ui/db | wired | `templates/profile/connections.php:44-259`; route `includes/Core/PageRouter.php:832`; count in hero stats `view.php:276-281` |
| Connect/Accept/Decline on member cards (directory, followers, following) | ui/store/rest | wired | partial `templates/partials/connection-button.php`; store `buddynext/connection-button` in `assets/js/social/follow-store.js:194-291`; included by `followers.php:223`, `following.php:149` |
| Module enqueue on profile route | infra | wired | `PageRouter.php:660` (`profile` bundle on people-hub+user_id); `PageRouter.php:605` (`@buddynext/social-buttons` every hub); map `AssetService.php:316,331,333` |
| Routes registered at runtime | infra | wired | `includes/REST/Router.php:66` (`new ConnectionController()->register_routes()`) |
| Pending inbox (received + sent) as a single list view | ui | api-only | REST `GET /me/connection-requests` (`ConnectionController.php:80-88,207-212`) + `GET /me/connections` (`:70-78`) exist; no web template binds to either; web surfaces pending only per-profile + via notifications |
| Connection degree + mutual count in directory | ui/service | wired | `MemberDirectoryController.php:214,270`; degree/mutual in hero `view.php:80,84` |

## First break

none — journey complete. Every action a member needs (send, accept, decline, withdraw, disconnect, view) is reachable in the web UI and wired through to the DB and app/REST clients.

## UX gaps

1. **No consolidated "connection requests" inbox in the web UI** — severity: low, confidence: confirmed-in-code.
   Evidence: `templates/profile/connections.php` lists accepted connections only; no template binds `GET /me/connection-requests` (`ConnectionController.php:207`). Incoming requests remain actionable: the `bn.connection_requested` notification links to the requester's profile (`NotificationMessageService.php:683-688`) where the hero renders Accept/Decline (`profile-hero.php:435-446`). The list endpoint is fully usable for app/REST clients. This is a convenience-list gap, not a journey break — do not rewrite working code to add it.

No other real gaps. Privacy gate (`PrivacyService::can_connect`), self-connect guard, block guard, duplicate-request guard, and full cache invalidation are present in the service/controller.

## Minimal refactor plan

EMPTY — usable-leave-as-is. An optional pending-inbox list (gap 1) could later be a thin read-only view bound to the existing `GET /me/connection-requests` + `GET /me/connections` endpoints; it is not required for the journey to be complete and must not trigger changes to the working send/accept/decline/withdraw/disconnect paths.

## Live-walk URL

http://buddynext-dev.local/members/varundubey/

Walk note: seed at least one other member with a pending request to/from `varundubey` before walking — on an empty account the hero shows only the "Connect" CTA and the connections tab shows the empty state, which can read as "missing" but is built and wired.
