# BuddyNext — Roles, Permissions + Abilities API

**Status:** Locked
**Last updated:** 2026-03-19

---

## Design Principle

BuddyNext never processes payments. It stores access state (role, credits, granted abilities) and gates against it. Any external system — WooCommerce, MemberPress, Lemon Squeezy, Stripe, Zapier, Make, custom code — can update a user's access via webhook. BuddyNext doesn't care how the access was earned.

```
External system (payment / automation / admin)
    → POST buddynext/v1/webhook/access
        → updates bn_user_abilities / bn_user_credits / community role
            → buddynext_can() checks stored state
```

---

## Single Check Function

```
buddynext_can( $user_id, $capability, $context = [] )
```

Every gate in BuddyNext — REST permission callbacks, block render conditions, template guards — calls this one function. Nothing checks permissions any other way.

---

## Four-Layer Model

```
Layer 1 — WordPress caps            site-wide, stored on WP user
Layer 2 — Community role            admin / moderator / member
Layer 3 — Granted abilities         per-user ability grants, optional expiry
Layer 4 — Developer filter          buddynext_user_can — full override
```

Space role (owner / moderator / member) is context checked inside layer 1/2 when `space_id` is in `$context`.

---

## Community Roles

Stored in `wp_usermeta` key `bn_community_role`. No table needed.

| Role | What they can do |
|------|-----------------|
| `admin` | Everything — settings, moderation, all spaces |
| `moderator` | Moderate content and users site-wide. No settings. |
| `member` | Post, react, comment, follow, connect, create/join spaces |

Role can be set via webhook, admin panel, or developer code.

---

## Granted Abilities

Per-user, per-ability grants stored in `bn_user_abilities`. Optional expiry date.

Examples:
- User paid for Pro → grant `buddynext-spaces/join-gated` until subscription end date
- User completed a course → Zapier grants `buddynext-spaces/join:space_id=42` permanently
- Admin manually grants moderator capability to a specific user for a specific space

Abilities are additive on top of the base role. Revoking an ability removes the grant row.

---

## Credits

Numeric balance per user stored in `bn_user_credits`. Optional.

Use cases:
- Post in a premium space costs N credits
- Create a space costs N credits
- WBGamification points can optionally map to credits (bridge hook)
- External system tops up credits via webhook

Credits are consumed via `buddynext_spend_credits( $user_id, $amount, $reason )`. BuddyNext never charges for credits — it only stores and deducts them.

---

## Webhook API

Any external system can update a user's access. No payment processing in BuddyNext.

**Endpoint:** `POST buddynext/v1/webhook/access`

**Actions:**

| action | Effect |
|--------|--------|
| `set_role` | Sets community role (admin/moderator/member) |
| `grant_ability` | Grants a specific ability, optional expiry |
| `revoke_ability` | Removes an ability grant |
| `add_credits` | Adds credits to balance |
| `set_credits` | Sets balance to exact amount |
| `deduct_credits` | Deducts credits (floors at 0) |

**Payload:**
```
{
  "user_id": 123,           // or "user_email": "user@example.com"
  "action": "grant_ability",
  "ability": "buddynext-spaces/join-gated",
  "expires_at": "2027-01-01T00:00:00Z",   // optional
  "source": "woocommerce",                 // for audit log
  "meta": {}                               // any extra data for logging
}
```

**Security:** Every webhook call must include an `X-BuddyNext-Signature` HMAC header (SHA-256, signed with a secret key set in admin settings). Invalid signatures rejected with 401.

**Zapier / Make support:** The webhook endpoint accepts standard POST JSON — works natively as a Zapier webhook action or Make HTTP module with no custom app needed.

**Webhook log:** Every call logged to `bn_webhook_log` — timestamp, source, action, user, success/fail. Accessible to admin for debugging.

---

## WordPress Abilities API (WP 6.9+)

BuddyNext registers all its capabilities via `wp_register_ability()` at boot. This is mandatory from day 1 — not optional, not deferred.

Benefits:
- Capabilities are discoverable by any WordPress tooling
- Third-party plugins check BuddyNext abilities via standard `wp_can()` — no BuddyNext-specific API needed
- Documentation generators can auto-list all BuddyNext capabilities
- Consistent with WordPress platform direction

All `buddynext-*` namespaced abilities registered at `plugins_loaded:15`.

Addons register their own abilities in their own namespaces:
```
mvs-media.*            WPMediaVerse
jetonomy-discussions.* Jetonomy
wb-gam-points.*        WBGamification
wp-cb-jobs.*           Career Board
```

---

## Capability Catalog

### Profile
| Capability | Default |
|-----------|---------|
| `buddynext-profile/edit-own` | member+ |
| `buddynext-profile/edit-any` | admin |
| `buddynext-profile/view` | public (privacy model applies) |

### Feed
| Capability | Default |
|-----------|---------|
| `buddynext-feed/create-post` | member+ |
| `buddynext-feed/delete-own-post` | member+ |
| `buddynext-feed/delete-any-post` | moderator+ |
| `buddynext-feed/pin-post` | moderator+ |
| `buddynext-feed/schedule-post` | member+ |

### Spaces
| Capability | Default |
|-----------|---------|
| `buddynext-spaces/create` | member+ (configurable: admin-only) |
| `buddynext-spaces/join` | member+ |
| `buddynext-spaces/join-gated` | requires granted ability |
| `buddynext-spaces/post` | space member+ |
| `buddynext-spaces/moderate` | space moderator+ |
| `buddynext-spaces/manage-settings` | space owner+ |
| `buddynext-spaces/delete` | space owner, admin |

### Connections
| Capability | Default |
|-----------|---------|
| `buddynext-connections/follow` | member+ |
| `buddynext-connections/connect` | member+ |

### Moderation
| Capability | Default |
|-----------|---------|
| `buddynext-moderation/report` | member+ |
| `buddynext-moderation/review-queue` | moderator+ |
| `buddynext-moderation/issue-strike` | moderator+ |
| `buddynext-moderation/suspend-user` | admin |

---

## Developer Filter

```
apply_filters( 'buddynext_user_can', $can, $user_id, $capability, $context )
```

Full override. Any plugin can modify any permission decision.

**MemberPress example (hooks into granted ability check):**
```
// MemberPress doesn't need special BuddyNext integration —
// it can just update the ability via webhook on subscription events
// OR hook the filter directly:
add_filter( 'buddynext_user_can', function( $can, $user_id, $cap, $context ) {
    if ( $cap === 'buddynext-spaces/join-gated' ) {
        return MeprUser::has_active_subscription( $user_id );
    }
    return $can;
}, 10, 4 );
```

**Custom role example:**
```
add_filter( 'buddynext_user_can', function( $can, $user_id, $cap, $context ) {
    if ( $cap === 'buddynext-spaces/moderate' ) {
        if ( get_user_meta( $user_id, 'my_ambassador_role', true ) ) {
            return true;
        }
    }
    return $can;
}, 10, 4 );
```

---

## Data Stored

`wp_usermeta` key `bn_community_role` — site-wide community role
`bn_user_abilities` — granted abilities per user (ability, expires_at, source)
`bn_user_credits` — credit balance per user (balance, updated_at)
`bn_webhook_log` — webhook call log (source, action, user_id, payload, status, created_at)

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Spaces | `buddynext-spaces/join-gated` checked on join; credits spent on create (if configured) |
| Activity Feed | `buddynext-feed/create-post` checked before post |
| Moderation | `buddynext-moderation/*` capabilities gate queue access |
| WBGamification | Points can optionally top up `bn_user_credits` via bridge hook |
| Admin Settings | Webhook secret key management, webhook log viewer |
| Zapier / Make | Webhook endpoint works as standard HTTP POST action |
| Any payment plugin | Grant access via webhook — zero BuddyNext code changes needed |

---

## Gaps / Open Questions

- None — fully locked
