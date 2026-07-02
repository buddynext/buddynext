# Schema: Spaces

Reference for the `bn_*` tables behind Spaces - the sub-communities that members create and join. Covers the space record, its membership rows, the space-category taxonomy, and the per-space ban list. All tables are created in `BuddyNext\Core\Installer` via `dbDelta()` and are prefixed with the site table prefix (shown here as `bn_`, e.g. `wp_bn_spaces`).

![A Space home rendered from the bn_spaces, membership, category, and ban tables documented here](../images/space-home.webp)

## Overview / Contract

These tables obey the BuddyNext Scale Contract:

- **Denormalized counter.** `bn_spaces.member_count` holds the member total so a space card never runs `COUNT(*)` against `bn_space_members`. It is maintained on join/leave/remove.
- **Indexes on every WHERE / JOIN / ORDER BY column.** Slug lookup, owner lookup, category filter, and archived filter each have a backing index.
- **Privacy and roles are column-level enums**, so visibility and capability checks read off a single row rather than a separate ACL table.

A fresh install seeds one starter "Open Discussion" space (owned by the first administrator, with that admin as an active owner-member) plus three deletable starter categories - General, Announcements, Introductions - so the directory is not empty on day one.

## bn_spaces

The space record. One row per space. Spaces can nest (`parent_id`), belong to a category, and be archived.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `name` | VARCHAR(255) | Display name. |
| `slug` | VARCHAR(200) | URL slug. Unique. |
| `description` | TEXT, nullable | Space description. |
| `category_id` | BIGINT UNSIGNED, nullable | Owning category, or NULL. |
| `parent_id` | BIGINT UNSIGNED, nullable | Parent space for sub-spaces, or NULL. |
| `type` | ENUM(`open`,`private`,`secret`), default `open` | Visibility/join model. `open` = anyone joins; `private` = request to join; `secret` = invite-only and hidden. |
| `owner_id` | BIGINT UNSIGNED | Owning member (WordPress user ID). |
| `member_count` | INT UNSIGNED, default 0 | Denormalized member total. Maintained on join/leave. |
| `cover_image_url` | VARCHAR(500), nullable | Cover image URL. |
| `avatar_url` | VARCHAR(500), nullable | Avatar/icon URL. |
| `rules` | TEXT, nullable | Space rules text. |
| `required_ability` | VARCHAR(64), nullable | Ability slug a member must hold to access the space. Read by Pro (paywall/entitlement integration); ships in the Free schema so Pro never alters the table. |
| `accent_color` | VARCHAR(16), nullable | Per-space accent color override. |
| `description_layout` | VARCHAR(32), nullable, default `standard` | Layout variant for the description block. |
| `is_archived` | TINYINT(1), default 0 | Archived flag. |
| `archived_at` | DATETIME, nullable | When it was archived. |
| `created_at` | DATETIME, default CURRENT_TIMESTAMP | Creation time. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)` - slug routing and dedup.
- `KEY owner (owner_id)` - "spaces I own".
- `KEY category (category_id)` - directory filtering by category.
- `KEY is_archived (is_archived)` - excluding archived spaces from the directory.

Relationships: `owner_id` references a WordPress user; `category_id` references `bn_space_categories.id`; `parent_id` is a self-reference to `bn_spaces.id`. Membership rows live in `bn_space_members`; bans live in `bn_space_bans`. Posts reference a space via `bn_posts.space_id`.

> **Note:** `required_ability` is part of the Free `CREATE TABLE` even though only Pro reads it. This is deliberate - keeping the column in the Free schema means Pro never has to `ALTER` the table, and the Free/Pro contract stays stable.

## bn_space_members

Membership rows: who belongs to which space, in what role, with what status and notification preference. The composite primary key `(space_id, user_id)` makes membership a single row per pair.

| Column | Type | Notes |
|--------|------|-------|
| `space_id` | BIGINT UNSIGNED | The space. Part of PK. |
| `user_id` | BIGINT UNSIGNED | The member. Part of PK. |
| `role` | ENUM(`owner`,`moderator`,`member`), default `member` | Member's role in the space. |
| `status` | ENUM(`active`,`pending`,`invited`,`banned`), default `active` | Membership state. `pending` = requested to join; `invited` = invite outstanding. |
| `notification_pref` | ENUM(`all`,`mentions_only`,`none`), default `all` | Per-space notification level. |
| `joined_at` | DATETIME, default CURRENT_TIMESTAMP | When the member joined (or the row was created). |

Key indexes:

- `PRIMARY KEY (space_id, user_id)` - one membership row per (space, member); serves the membership lookup.
- `KEY user_role (user_id, role)` - "spaces where I am owner/moderator".
- `KEY user_status (user_id, status)` - "my active spaces" / pending requests.

Relationships: `space_id` references `bn_spaces.id`; `user_id` references a WordPress user. Inserting an `active` row increments `bn_spaces.member_count`; removing one decrements it. Role and status changes are routed through `SpaceMemberService`, which also fires the membership hooks (`buddynext_space_member_joined`, `buddynext_space_member_left`, `buddynext_space_member_removed`).

## bn_space_categories

The space-category taxonomy. One row per category. Shares its presentation columns (color/icon/directory-visibility) with the member-type taxonomy so a single unified editor manages both.

| Column | Type | Notes |
|--------|------|-------|
| `id` | BIGINT UNSIGNED, AUTO_INCREMENT | Primary key. |
| `name` | VARCHAR(100) | Display name. |
| `slug` | VARCHAR(100) | Lookup slug. Unique. |
| `description` | TEXT, nullable | Category description. |
| `color` | VARCHAR(7), default `#0073aa` | Badge background color. |
| `text_color` | VARCHAR(7), default `#ffffff` | Badge text color. |
| `icon_svg` | MEDIUMTEXT, nullable | Inline SVG icon markup. |
| `show_in_dir` | TINYINT(1), default 1 | Whether the category shows in the directory filter. |
| `sort_order` | INT, default 0 | Ordering in lists. |

Key indexes:

- `PRIMARY KEY (id)`
- `UNIQUE KEY slug (slug)` - lookup and dedup.

Relationships: referenced by `bn_spaces.category_id`. The `color`, `text_color`, `icon_svg`, and `show_in_dir` columns were added in schema revision 4 for parity with `bn_member_types`; on existing installs they are back-filled by an idempotent column ALTER.

## bn_space_bans

Per-space ban list. Records members banned from a specific space (distinct from a `bn_space_members` row whose `status` is `banned`; this is the standalone ban record). This table is referenced in the application code but is not declared in the Free `CREATE TABLE` set shown by the installer schema - see the gotcha below.

| Column | Type | Notes |
|--------|------|-------|
| `space_id` | BIGINT UNSIGNED | The space the ban applies to. |
| `user_id` | BIGINT UNSIGNED | The banned member. |
| `banned_by` | BIGINT UNSIGNED | Moderator/owner who issued the ban (stored as 0 when not attributable). |
| `created_at` | DATETIME | When the ban was issued; ban lists order by this column. |

> **Warning:** The exact column set for `bn_space_bans` could not be verified against a `CREATE TABLE` statement - the installer schema does not emit one under this name, and the live shape should be confirmed against `SpaceMemberService` / `ModerationService` before relying on it. What is verified from the code: bans order by `created_at` (not `id`), and `banned_by` is stored as `0` rather than NULL when the actor is unknown (the column is NOT NULL). Treat the column list above as indicative until confirmed against the running schema.

Relationships: `space_id` references `bn_spaces.id`; `user_id` and `banned_by` reference WordPress users. Ban/unban operations fire `buddynext_space_user_banned` / `buddynext_space_user_unbanned`.

## Counting sub-spaces - visible vs structural

A space can nest (`bn_spaces.parent_id`). There are two distinct sub-space counts on `SpaceService`, and using the wrong one leaks secret children:

| Method | Scope | Use for |
|---|---|---|
| `count_visible_subspaces( int $parent_id, int $viewer_id = 0, bool $is_admin = false )` | Visibility-scoped: applies the same scope as `get_subspaces()`, so the "N sub-spaces" a member sees matches the list they can actually open. A site admin counts every child. | Anything member-facing - a "N sub-spaces" badge or header count. |
| `count_subspaces( int $parent_id )` | Unscoped structural count of all non-archived children. | Move / nesting-cap validation, where you need the true child total regardless of who is looking. |

> **Never show `count_subspaces()` to a member.** It includes secret/unlisted children the viewer cannot open, which would leak their existence. Use `count_visible_subspaces()` with the viewer for any displayed count.

## Per-space settings: bn_space_meta and the Pro paywall

Per-space attributes are stored as metadata rows in **`bn_space_meta`** (not new columns, not autoloaded options), reachable through the native WP metadata API wired to the `bn_space` meta type - or the thin Free wrappers `get_space_meta()` / `add_space_meta()` / `update_space_meta()` / `delete_space_meta()`. Registered, typed space fields go through `buddynext_register_space_field()` and read back via `buddynext_get_space_field()`.

Pro reuses this Free storage rather than adding its own table: the per-space paywall copy is written to `bn_space_meta` under the keys `buddynextpro_paywall_cta_url`, `buddynextpro_paywall_cta_label`, and `buddynextpro_paywall_description` (via `update_space_meta()`). This is the Free/Pro contract in action - Pro never alters the Free schema.

## Notes / gotchas

- **`member_count` must be maintained.** Every join, leave, and removal adjusts `bn_spaces.member_count`. Never derive the member total with `COUNT(*)` in a page render - read the column. The daily recount job reconciles any drift.
- **Three space types, three join models.** `open` joins immediately (`status = active`), `private` creates a `pending` row that an owner/moderator approves, `secret` is invite-only (`status = invited`) and hidden from the directory.
- **Status `banned` vs `bn_space_bans`.** A `bn_space_members.status = 'banned'` row and a `bn_space_bans` record both exist in the moderation flow; confirm which one a given query should read when extending ban logic.
- **`required_ability` is a Free column read only by Pro.** Do not gate Free behavior on it. It exists in the Free schema purely so Pro's entitlement/paywall integration can read it without altering the table.
- **Categories double as a unified taxonomy.** `bn_space_categories` and `bn_member_types` share the same presentation columns and editor; keep changes to one in mind for the other.
- See also the Schema: Content and Feed page for `bn_posts` (which references `bn_spaces.id` via `space_id`).
