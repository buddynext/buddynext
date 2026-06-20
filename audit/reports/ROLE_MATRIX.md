# BuddyNext Free - Role Matrix

**Generated:** 2026-06-07 | **Version:** 0.2.0

Source files: `includes/Core/PermissionService.php` (ROLE_MAP lines 38-63, ROLE_HIERARCHY lines 70-75) and `includes/Core/Abilities.php` (CATALOG lines 25-45). Mirrored in `audit/manifest.json` `capabilities[]` (21 abilities).

---

## Community Role Hierarchy

`PermissionService::ROLE_HIERARCHY` (PermissionService.php:70):

| Role | Weight | Description |
|------|--------|-------------|
| `owner` | 4 | Space/community owner. Highest authority within scope. |
| `admin` | 3 | Community administrator. Can suspend users and edit any profile. |
| `moderator` | 2 | Moderates content, issues strikes, manages space content. |
| `member` | 1 | Standard member. Can post, follow, connect, report. |

A user's site-wide community role is read from the `bn_community_role` user_meta (defaults to `member`; PermissionService.php:149). Inside a space, the role is read from `bn_space_members.role` for `status='active'` (PermissionService.php:257).

> These are BuddyNext community roles, distinct from WordPress user roles. Any holder of WordPress `manage_options` passes every check at Layer 1 (PermissionService.php:95) regardless of community role.

---

## 4-Layer Permission Model

All checks flow through `PermissionService::can($user_id, $capability, $context)` (PermissionService.php:85):

0. **Space-ban hard-deny** - for any `buddynext-spaces/*` capability with a `space_id` context, `is_space_banned()` short-circuits to deny (PermissionService.php:89; checks `bn_space_bans` and `bn_space_members.status='banned'`).
1. **WP site admin** - `has_cap('manage_options')` -> always granted (PermissionService.php:95).
2. **Community role** - `passes_role_check()` compares the user's role weight against `ROLE_MAP[$capability]` (PermissionService.php:132). Space-scoped capabilities resolve the role from `bn_space_members`.
3. **Explicit ability grant** - if the role check fails, `has_active_grant()` reads the `bn_ability_{slug}` user_meta entry; an int unix timestamp (`0` = never expires) gates validity (PermissionService.php:321).
4. **Developer filter** - `apply_filters('buddynext_user_can', $result, $user_id, $capability, $context)` (PermissionService.php:121) can override either direction.

Space-scoped capabilities `buddynext-moderate-space` and `buddynext-manage-space` bypass the generic role map and resolve directly via `can_moderate_space()` / `can_manage_space()` (PermissionService.php:97-102, :169, :192).

---

## Capability x Role Matrix

`Min Role` = `ROLE_MAP[$capability]`. `null` = no role default; must be granted via `bn_ability_{slug}` user_meta or the `buddynext_user_can` filter. "yes" means the role's weight meets or exceeds the minimum.

| Capability | Min Role | Owner | Admin | Moderator | Member | Registered | Notes |
|------------|----------|:-----:|:-----:|:---------:|:------:|------------|-------|
| `buddynext-profile/edit-own` | `member` | yes | yes | yes | yes | Abilities.php:25 | Edit own profile fields |
| `buddynext-profile/edit-any` | `admin` | yes | yes | no | no | Abilities.php:26 | Edit any user's profile |
| `buddynext-profile/view` | `null` | yes | yes | yes | yes | Abilities.php:27 | Public by default (no role gate) |
| `buddynext-feed/create-post` | `member` | yes | yes | yes | yes | Abilities.php:28 | Create a feed post |
| `buddynext-feed/delete-own-post` | `member` | yes | yes | yes | yes | Abilities.php:29 | Delete own post |
| `buddynext-feed/delete-any-post` | `moderator` | yes | yes | yes | no | Abilities.php:30 | Delete any post |
| `buddynext-feed/pin-post` | `moderator` | yes | yes | yes | no | Abilities.php:31 | Pin a post |
| `buddynext-feed/schedule-post` | `member` | yes | yes | yes | yes | Abilities.php:32 | Schedule a post for future publish |
| `buddynext-spaces/create` | `member` | yes | yes | yes | yes | Abilities.php:33 | Create a space |
| `buddynext-spaces/join` | `member` | yes | yes | yes | yes | Abilities.php:34 | Join a space (context: space_id) |
| `buddynext-spaces/join-gated` | `null` | - | - | - | - | Abilities.php:35 | Requires explicit grant (paid/restricted; context: space_id) |
| `buddynext-spaces/post` | `member` | yes | yes | yes | yes | Abilities.php:36 | Post in a space (context: space_id) |
| `buddynext-spaces/moderate` | `moderator` | yes | yes | yes | no | Abilities.php:37 | Moderate space content (context: space_id) |
| `buddynext-spaces/manage-settings` | `moderator` | yes | yes | yes | no | Abilities.php:38 | Change space settings (context: space_id) |
| `buddynext-spaces/delete` | `moderator` | yes | yes | yes | no | Abilities.php:39 | Delete a space |
| `buddynext-connections/follow` | `member` | yes | yes | yes | yes | Abilities.php:40 | Follow another user |
| `buddynext-connections/connect` | `member` | yes | yes | yes | yes | Abilities.php:41 | Send a connection request |
| `buddynext-moderation/report` | `member` | yes | yes | yes | yes | Abilities.php:42 | Report content or users |
| `buddynext-moderation/review-queue` | `moderator` | yes | yes | yes | no | Abilities.php:43 | View and act on the report queue |
| `buddynext-moderation/issue-strike` | `moderator` | yes | yes | yes | no | Abilities.php:44 | Issue a strike against a user |
| `buddynext-moderation/suspend-user` | `admin` | yes | yes | no | no | Abilities.php:45 | Suspend a user account |

> The 21 abilities above are the full CATALOG. The space-scoped pseudo-capabilities `buddynext-moderate-space` and `buddynext-manage-space` are present in `ROLE_MAP` with `null` (PermissionService.php:61-62) but are resolved by dedicated methods rather than the role map; they are not registered Abilities-API entries.

---

## Space-Scoped Resolution

For `buddynext-spaces/*` capabilities the user's space role (from `bn_space_members`) is compared instead of their site-wide role (PermissionService.php:143-146):

| Capability | Resolver | Granted to |
|------------|----------|-----------|
| `buddynext-moderate-space` | `can_moderate_space()` (PermissionService.php:169) | space `owner`, or a `moderator` whose privilege scope allows the space (`mod_scope_allows`, :212) |
| `buddynext-manage-space` | `can_manage_space()` (PermissionService.php:192) | space `owner` only |

| Space Role (`bn_space_members.role`) | Moderate Space | Manage Space Settings |
|--------------------------------------|:--------------:|:---------------------:|
| `owner` | yes | yes |
| `moderator` | yes (if scope allows) | no |
| `member` | no | no |
| `banned` / `pending` (not active) | no | no |

---

## WordPress Capability Gates (admin / elevated REST)

These standard WordPress capabilities gate admin pages and some REST permission callbacks (`require_admin`, `require_manage_options`). They are NOT BuddyNext community capabilities.

| Capability | Used In | Purpose |
|------------|---------|---------|
| `manage_options` | All 7 admin pages; `require_manage_options` REST callback (space-categories create/delete); Layer 1 of every `can()` | Site administrator gate |
| `require_admin` callback | Profile field/group admin routes, member-type create/update/delete, admin profile edits | Community admin gate |
| `require_moderator` callback | Comment pin/unpin | Moderator gate |

---

## Explicit Grants - `bn_ability_{slug}` user_meta

Each grant is a single `wp_usermeta` row. The `meta_key` is `bn_ability_` + the sanitised ability slug; `PermissionService::ability_meta_key()` (PermissionService.php:344) replaces non-alphanumeric characters with `_` (e.g. `buddynext-feed/pin-post` -> `bn_ability_buddynext_feed_pin_post`). The `meta_value` is an int unix timestamp: `0` = never expires, otherwise the expiry time (`has_active_grant`, PermissionService.php:321-331).

```php
update_user_meta(
    $user_id,
    PermissionService::ability_meta_key( 'buddynext-spaces/join-gated' ),
    0 // never expires
);
```

Use cases:
- Grant `buddynext-spaces/join-gated` to paid members.
- Temporarily elevate a member to `buddynext-feed/pin-post` without changing their role.
- Time-limited `buddynext-moderation/review-queue` access (set an expiry timestamp).

Grants and revocations also fire `buddynext_ability_granted` (includes/Outbound/AccessWebhookController.php:197) and `buddynext_ability_revoked` (:228).

---

## Developer Override

The `buddynext_user_can` filter fires at Layer 4 and can grant or deny any capability (PermissionService.php:121):

```php
add_filter( 'buddynext_user_can', function ( $result, $user_id, $capability, $context ) {
    // Custom grant/deny logic.
    return $result;
}, 10, 4 );
```
