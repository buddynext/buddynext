# BuddyNext Free - Role Matrix

**Generated:** 2026-05-20 | **Version:** 0.2.0

Source files: `includes/Core/PermissionService.php` (ROLE_MAP, ROLE_HIERARCHY) and `includes/Core/Abilities.php` (CATALOG).

---

## Community Role Hierarchy

BuddyNext defines four community roles with numeric weights used in comparisons:

| Role | Weight | Description |
|------|--------|-------------|
| `owner` | 4 | Space/community owner. Has all rights within their scope. |
| `admin` | 3 | Site-level community administrator. Can suspend users and edit any profile. |
| `moderator` | 2 | Can moderate content, issue strikes, manage space settings. |
| `member` | 1 | Standard community member. Can post, follow, connect, report. |

> **Note:** These roles are BuddyNext community roles, separate from WordPress user roles. WordPress `manage_options` holders pass every capability check at Layer 1 regardless of their community role.

---

## 4-Layer Permission Model

All BuddyNext permission checks flow through `PermissionService::can($user_id, $capability, $context)`:

1. **WP site admin** - `current_user_can('manage_options')` → always granted
2. **Community role** - check `ROLE_MAP[$capability]` minimum role against user's community role
3. **Explicit ability grant** - `bn_ability_{slug}` user_meta entry with optional expiry (`0` = never)
4. **Developer filter** - `buddynext_user_can` filter can override in either direction

Space-scoped capabilities (`buddynext-moderate-space`, `buddynext-manage-space`) bypass the generic role-map and query `bn_space_members` directly.

---

## Capability × Role Matrix

`min_role` = minimum community role required by default (from `ROLE_MAP`).
`null` = no role-based default; must be explicitly granted via the `bn_ability_{slug}` user_meta entry or the filter.

| Capability | Min Role | Owner | Admin | Moderator | Member | Notes |
|------------|----------|:-----:|:-----:|:---------:|:------:|-------|
| `buddynext-profile/edit-own` | `member` | yes | yes | yes | yes | Edit own profile fields |
| `buddynext-profile/edit-any` | `admin` | yes | yes | no | no | Edit any user's profile |
| `buddynext-profile/view` | `null` | yes | yes | yes | yes | Public by default (no role gate) |
| `buddynext-feed/create-post` | `member` | yes | yes | yes | yes | Create a feed post |
| `buddynext-feed/delete-own-post` | `member` | yes | yes | yes | yes | Delete own post |
| `buddynext-feed/delete-any-post` | `moderator` | yes | yes | yes | no | Moderator can delete any post |
| `buddynext-feed/pin-post` | `moderator` | yes | yes | yes | no | Pin a post to top of feed/space |
| `buddynext-feed/schedule-post` | `member` | yes | yes | yes | yes | Schedule post for future publish |
| `buddynext-spaces/create` | `member` | yes | yes | yes | yes | Create a new space |
| `buddynext-spaces/join` | `member` | yes | yes | yes | yes | Join a public space |
| `buddynext-spaces/join-gated` | `null` | - | - | - | - | Requires explicit grant (for paid/restricted spaces) |
| `buddynext-spaces/post` | `member` | yes | yes | yes | yes | Post in a space |
| `buddynext-spaces/moderate` | `moderator` | yes | yes | yes | no | Moderate content in spaces |
| `buddynext-spaces/manage-settings` | `moderator` | yes | yes | yes | no | Change space settings |
| `buddynext-spaces/delete` | `moderator` | yes | yes | yes | no | Delete a space |
| `buddynext-connections/follow` | `member` | yes | yes | yes | yes | Follow another user |
| `buddynext-connections/connect` | `member` | yes | yes | yes | yes | Send a connection request |
| `buddynext-moderation/report` | `member` | yes | yes | yes | yes | Report content or users |
| `buddynext-moderation/review-queue` | `moderator` | yes | yes | yes | no | View and act on report queue |
| `buddynext-moderation/issue-strike` | `moderator` | yes | yes | yes | no | Issue a strike against a user |
| `buddynext-moderation/suspend-user` | `admin` | yes | yes | no | no | Suspend a user account |
| `buddynext-moderate-space` | `null` | - | - | - | - | Space-scoped: resolved from bn_space_members role |
| `buddynext-manage-space` | `null` | - | - | - | - | Space-scoped: resolved from bn_space_members role |

---

## WordPress Capability Gates (admin context only)

These are standard WordPress capabilities used for admin page access. They are NOT BuddyNext community capabilities.

| Capability | Used In | Purpose |
|------------|---------|---------|
| `manage_options` | All admin pages, elevated REST actions | Site administrator gate |
| `edit_users` | Members admin page (`admin_members`) | Access to member management |
| `read` | Subscriber-level checks in a few REST handlers | Minimum WP access |

---

## Space-Level Roles

Spaces have their own role within `bn_space_members.role`. These are checked separately:

| Space Role | Can Moderate Space | Can Manage Space Settings | Can Ban Members |
|------------|:-----------------:|:------------------------:|:---------------:|
| `owner` | yes | yes | yes |
| `admin` | yes | yes | yes |
| `moderator` | yes | yes | no |
| `member` | no | no | no |
| `banned` | no | no | no |

---

## Explicit Grants — `bn_ability_{slug}` user_meta

Each ability grant is a single `wp_usermeta` row. The `meta_key` is
`bn_ability_` + the sanitised ability slug (e.g. `bn_ability_buddynext_spaces_join_gated`).
The `meta_value` is an integer unix timestamp — `0` means "never expires",
otherwise the timestamp at which the grant lapses. Use
`PermissionService::ability_meta_key( $slug )` to build the key.

```php
update_user_meta(
    $user_id,
    PermissionService::ability_meta_key( 'buddynext-spaces/join-gated' ),
    0 // never expires
);
```

Use cases:
- Grant `buddynext-spaces/join-gated` for paid members
- Temporarily elevate a member to `buddynext-feed/pin-post` without changing their role
- Time-limited moderation access (`buddynext-moderation/review-queue` with expiry)

---

## Developer Override

The `buddynext_user_can` filter fires at Layer 4 and can grant or deny any capability:

```php
add_filter('buddynext_user_can', function($result, $user_id, $capability) {
    // Custom grant logic
    return $result;
}, 10, 3);
```
