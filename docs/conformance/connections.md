# Conformance Dossier — Connections (Social Graph)

**Feature:** Connections (mutual request → accept/decline/withdraw)
**Repo:** free
**Spec ref:** `docs/specs/features/01-social-graph.md` (Locked) + journey `docs/journeys/social-graph.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md` (block/visibility)
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/
**Verdict:** usable-leave-as-is

---

## Core happy path

A logged-in member visits another member's profile, sends a connection request, the recipient
is notified, accepts (or declines) from the profile / directory / notification, and either party
can later withdraw or disconnect. Spec intent: send / accept / decline / withdraw + pending
inbox + mutual count + degree badge.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| "Connect" button on profile hero | ui | wired | `templates/parts/profile-hero.php:422` (`data-wp-on--click="actions.connect"`, hidden bound to `context.showConnect`) |
| Context seeded for the hero | ui | wired | `templates/profile/view.php:319-329` (`profileUserId`, `showConnect`, `isConnected`, `connectionPending`, `connectionReceived`, `restNonce`) inside `data-wp-interactive="buddynext/profile"` at `view.php:343` |
| `connect` store action → POST /connect | store | wired | `assets/js/profile/store.js:1092-1109` (`fetch(.../users/{id}/connect, POST, X-WP-Nonce)`) |
| Accept / Decline / Withdraw / Disconnect actions | store | wired | `assets/js/profile/store.js:1111-1171` (accept POST `/connect/accept`, decline POST `/connect/decline`, withdraw + disconnect DELETE `/connect`) |
| Route registration | rest | wired | `includes/REST/Router.php:66` (`( new ConnectionController() )->register_routes()`) |
| REST routes (POST/DELETE connect, accept, decline, GET me/connections, me/connection-requests) | rest | wired | `includes/SocialGraph/ConnectionController.php:32-89`; auth gate `require_auth` at `:219-229` |
| Block guard before connect | rest | wired | `ConnectionController.php:101-107` (`blocks->is_blocking_either`) — matches spec "can't be connected if blocked" |
| Service lifecycle pending→accepted/declined/withdrawn | service | wired | `includes/SocialGraph/ConnectionService.php` send `:47`, accept `:124`, decline `:179`, withdraw `:230`, remove `:282`; duplicate-pair guard `:68-87`; fires `buddynext_connection_*` actions |
| Recipient notified on request / accept | service | wired | `includes/Notifications/NotificationListener.php:36-37,155,187`; copy `NotificationMessageService.php:119-138` |
| Mutual count + degree badge | service | wired | `ConnectionService.php:510-537` (`mutual_connections`, `connection_degree`); rendered `profile-hero.php:202-221,288-303` |
| DB writes | db | wired | `wp_bn_connections` insert/update/delete in `ConnectionService.php`; cache group `buddynext_connections` flushed on write `:580-582` |
| Accepted connections tab | ui | wired | `templates/profile/connections.php:49-77` (joins `bn_connections` status=accepted, paginated 12); remove via `assets/js/connections/store.js:7-29` (DELETE /connect) |
| Directory card 5-state control (incl. accept/decline) | ui | wired | `templates/parts/member-card.php:262-314` (`actions.toggleConnection`, `acceptConnection`, `declineConnection`) |

## First break

none — journey complete. Every link from the profile-hero "Connect" button through the REST
controller, service, and DB is bound; accept/decline are reachable from the profile hero,
directory cards, and the request notification.

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Spec line "Pending inbox: received + sent requests" has no single aggregated inbox screen. Received requests surface via notification + profile-hero Accept/Decline + directory cards; sent requests surface as "Pending/Requested" on profiles/cards. REST exists (`/me/connection-requests`, service `pending_sent`/`pending_received`) but no one page lists both. Core accept/decline journey is still completable, so this is a convenience surface, not a break. | low | confirmed-in-code | `ConnectionController.php:80-88` exposes only received (`get_connection_requests` → `pending_received`); `templates/profile/connections.php` lists accepted only; no template consumes a combined sent+received list |
| Journey doc curl uses `POST /connect` to withdraw; controller withdraw/disconnect path is `DELETE /connect` (`ConnectionController.php:42-46,170-188`). Web UI correctly uses DELETE (`store.js:1114,1160`). The doc's POST-toggle withdraw examples will instead hit `send_request` and return `request_already_exists`. Doc-vs-code mismatch only; live web journey unaffected. | low | confirmed-in-code | `docs/journeys/social-graph.md:99-113` vs `ConnectionController.php:42-46`; store DELETE at `store.js:1114` |

## Minimal refactor plan

None required for usability. The core journey (send → notify → accept/decline → withdraw/disconnect)
is fully wired across ui/store/rest/service/db and reachable from the live entry URL. The two items
above are low-severity (a missing convenience aggregation surface and a doc-only HTTP-verb mismatch);
neither blocks a member from completing the journey. Leave working code as-is.

## Notes for the live walk

- As a logged-in member, go to `/members/<other-member>/`: expect a "Connect" button in the hero
  action cluster (`profile-hero.php:407-458`). Shown for non-owners even on empty test accounts.
- Accept/Decline appear in the hero only when `connection_received` is true (an inbound pending row
  exists); seed a pending row from another account first, then reload as the recipient.
- The recipient also gets a `bn.connection_requested` notification linking to the requester's
  profile, where Accept/Decline render.
