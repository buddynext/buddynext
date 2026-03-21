# BuddyNext — Membership / Access Gating

**Status:** Locked
**Last updated:** 2026-03-19

---

## No Payment Processing

BuddyNext does not process payments. No Stripe SDK. No WooCommerce dependency. No subscription management.

Access gating is handled entirely through the Roles + Permissions system (`17-roles-permissions.md`). Any payment platform grants access by calling the BuddyNext webhook.

---

## How Gating Works

Spaces and content have an optional `required_ability` field. When a user tries to join or access:

1. `buddynext_can( $user_id, 'buddynext-spaces/join-gated', ['space_id' => $id] )` is called
2. Checks `bn_user_abilities` for a valid non-expired grant
3. If no grant → shows paywall UI with a configurable CTA (admin sets the CTA URL)

The CTA URL points to wherever the site handles payment: WooCommerce checkout, MemberPress, Lemon Squeezy, Stripe payment link, anything.

---

## Paywall UI

- Blurred/locked space preview with CTA button
- Admin configures: button label, button URL, description text
- Per-space CTA override (different CTA per space)
- No payment UI inside BuddyNext — just a button pointing out

---

## Granting Access

External system processes payment → calls BuddyNext webhook:

```
POST buddynext/v1/webhook/access
{
  "user_id": 123,
  "action": "grant_ability",
  "ability": "buddynext-spaces/join-gated",
  "expires_at": "2027-01-01T00:00:00Z",
  "source": "woocommerce"
}
```

User instantly gains access. No page reload needed — Interactivity API updates the UI.

---

## Works With Any System

| Payment platform | Integration method |
|-----------------|-------------------|
| WooCommerce | WooCommerce webhook on order complete → BuddyNext webhook |
| MemberPress | MemberPress webhook → BuddyNext webhook |
| Lemon Squeezy | Lemon Squeezy webhook → BuddyNext webhook |
| Stripe (direct) | Stripe webhook → BuddyNext webhook |
| Zapier / Make | Automation triggers BuddyNext webhook on any event |
| Admin manual | Admin panel: grant ability to user directly |
| WBGamification | Points milestone → bridge grants ability |

---

## Pro Addons

BuddyNext Pro ships first-party webhook bridge plugins for common platforms:
- WooCommerce bridge
- MemberPress bridge
- Paid Memberships Pro bridge
- Restrict Content Pro bridge

Each bridge is a thin adapter (~30 lines) that maps platform events to BuddyNext webhook calls. Community can build their own for any platform.

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Roles + Permissions | `bn_user_abilities` + `buddynext_user_can` filter |
| Spaces | `required_ability` field on `bn_spaces` |
| Webhook API | `buddynext/v1/webhook/access` — full spec in `17-roles-permissions.md` |
| Admin Settings | Paywall CTA config, webhook secret key |
| WBGamification | Points milestones can trigger ability grants |

---

## Gaps / Open Questions

- None — fully locked
