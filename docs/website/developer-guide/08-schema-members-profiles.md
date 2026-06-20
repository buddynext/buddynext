# Schema: Members, Profiles, and the Social Graph

This page documents the `bn_*` tables that store member profiles, the extensible profile-field system, member types/labels, and the directional/bilateral social-graph relationships (follows, connections, blocks). All tables are created in `BuddyNext\Core\Installer::schema()` via `dbDelta()`. Column types, defaults, and indexes below are read directly from that method.

![A member profile rendered from the bn_* profile, profile-field, and member-type tables on this page](../images/member-profile.webp)

![The member directory built from the same profile and social-graph schema](../images/member-directory.webp)

## Overview / Contract

The rules that shaped these tables (from the Scale-Contract):

- **Denormalized counters.** Profile-group/field membership is resolved by join, but member-type and category surfaces keep their own ordering columns (`sort_order`) so directory queries never sort by a joined table.
- **Composite covering indexes for the hot path.** The social-graph tables lead every `KEY` with the column the query filters on first (for example `following (following_id, status)` so a "who follows me, approved only" lookup is index-only).
- **Natural composite primary keys where a row is a unique pair.** `bn_follows`, `bn_blocks`, and `bn_member_type_assignments` use the participant pair as the key, which makes the relationship itself the uniqueness constraint - no surrogate `id` needed, and an idempotent `INSERT IGNORE` is the create path.
- **Profile values are a tall EAV table.** One row per user/field/repeater-entry, keyed by `(user_id, field_id, entry_index)`, so a profile with 5 filled fields is 5 rows and a repeater group with 3 work entries is 3 rows per field.

Social-graph relationship model at a glance:

| Relationship | Table | Shape | Reciprocal? |
|---|---|---|---|
| Follow | `bn_follows` | Directional (follower -> following) | No - A following B does not imply B follows A |
| Connection | `bn_connections` | Bilateral (request -> accept) | Yes - one accepted row represents a mutual link between two members |
| Block / Mute / Restrict | `bn_blocks` | Directional, typed | No - the `type` column distinguishes block, mute, and restrict |

## bn_follows

Directional follow graph. A row means `follower_id` follows `following_id`. The relationship is one-way: a reciprocal follow is a second, separate row. When follow approval is enabled, a new follow lands as `pending` and is promoted to `approved` on accept.

| Column | Type | Notes |
|---|---|---|
| `follower_id` | BIGINT(20) UNSIGNED NOT NULL | The member doing the following. Part of the primary key. |
| `following_id` | BIGINT(20) UNSIGNED NOT NULL | The member being followed. Part of the primary key. |
| `status` | ENUM('approved','pending') NOT NULL DEFAULT 'approved' | `pending` only when the target requires follow approval; otherwise `approved` on insert. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the follow was created. |

Indexes:

- PRIMARY KEY `(follower_id, following_id)` - the relationship pair is the uniqueness constraint; re-following is a no-op via `INSERT IGNORE`.
- KEY `following (following_id, status)` - "who follows this member" / follower-count lookups.
- KEY `pending_inbox (following_id, status, created_at)` - the pending follow-request inbox, ordered by recency.

Relationships: `follower_id` and `following_id` both reference `wp_users.ID`. There is no foreign key; cleanup on user deletion is handled in application code.

## bn_connections

Bilateral connection graph (the LinkedIn-style mutual relationship). One row per requester/recipient pair tracks the request through its lifecycle. An `accepted` row represents a mutual connection that applies in both directions - there is no second mirror row.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `requester_id` | BIGINT(20) UNSIGNED NOT NULL | The member who sent the connection request. |
| `recipient_id` | BIGINT(20) UNSIGNED NOT NULL | The member who received the request. |
| `status` | ENUM('pending','accepted','declined','withdrawn') NOT NULL DEFAULT 'pending' | Lifecycle: `pending` on request, then `accepted` / `declined` by the recipient, or `withdrawn` by the requester. |
| `note` | VARCHAR(280) NOT NULL DEFAULT '' | Optional message attached to the request (opt-in; empty by default). |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the request was created. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `pair (requester_id, recipient_id)` - one request row per ordered pair, preventing duplicate requests.
- KEY `recipient_lookup (recipient_id)` - incoming requests for a member.
- KEY `recipient_status (recipient_id, status)` - "my pending incoming requests".
- KEY `requester_status (requester_id, status)` - "my pending outgoing requests".

Relationships: `requester_id` and `recipient_id` reference `wp_users.ID`. Because the table is bilateral, a "my connections" query reads rows where the current user is either the requester or the recipient and `status = 'accepted'`.

> **Note:** The `pair` unique key is ordered, so it does not by itself prevent A->B and B->A request rows existing simultaneously. The service layer guards against an inverse pending request when one already exists.

## bn_blocks

Directional moderation relationship covering block, mute, and restrict in one table. A row means `blocker_id` has applied a relationship of `type` against `blocked_id`. This is how the spec models all three "stop seeing / stop being seen by" controls: the `type` ENUM is the discriminator, not three separate tables.

| Column | Type | Notes |
|---|---|---|
| `blocker_id` | BIGINT(20) UNSIGNED NOT NULL | The member applying the block/mute/restrict. Part of the primary key. |
| `blocked_id` | BIGINT(20) UNSIGNED NOT NULL | The target member. Part of the primary key. |
| `type` | ENUM('block','mute','restrict') NOT NULL DEFAULT 'block' | `block` = full two-way cut-off, `mute` = hide their content from me, `restrict` = limited interaction. Default `block`. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the relationship was created. |

Indexes:

- PRIMARY KEY `(blocker_id, blocked_id)` - one relationship row per pair. Changing type updates the row in place.
- KEY `blocked_type (blocked_id, type)` - "who has blocked/muted me" (used by send-DM and visibility checks; `mvs_can_send_message` consults this table).
- KEY `blocker_type (blocker_id, type)` - "my block list", filtered by type.

Relationships: `blocker_id` and `blocked_id` reference `wp_users.ID`. The pair primary key means a member can hold exactly one relationship type against another member at a time.

> **Verified:** block, mute, and restrict are a single ENUM column on `bn_blocks`, not separate tables. There is no `bn_mutes` or `bn_restrictions` table in the installer.

## bn_member_types

Member-type (editorial label) registry - the directory's "Contributor / Staff / ..." badges. Shares its colour/icon/visibility columns with `bn_space_categories` so the unified taxonomy editor renders both the same way. Note the `id` column is `INT UNSIGNED`, narrower than the `BIGINT` used by most other tables.

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `slug` | VARCHAR(100) NOT NULL | URL/filter-safe identifier. Unique. |
| `name` | VARCHAR(100) NOT NULL | Display label. |
| `description` | TEXT DEFAULT NULL | Optional description. |
| `color` | VARCHAR(7) NOT NULL DEFAULT '#0073aa' | Badge background hex. |
| `text_color` | VARCHAR(7) NOT NULL DEFAULT '#ffffff' | Badge text hex. |
| `icon_svg` | MEDIUMTEXT DEFAULT NULL | Optional inline SVG for the badge. |
| `sort_order` | SMALLINT NOT NULL DEFAULT 0 | Directory/filter ordering. |
| `show_in_dir` | TINYINT(1) NOT NULL DEFAULT 1 | Whether the type appears as a directory filter. |
| `self_select` | TINYINT(1) NOT NULL DEFAULT 0 | Whether members may assign this type to themselves. |
| `created_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the type was created. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `uq_slug (slug)`.
- KEY `idx_sort (sort_order)`.

Relationships: referenced by `bn_member_type_assignments.type_id`. Two starter types (`contributor`, `staff`) are seeded on a fresh install only.

## bn_member_type_assignments

Join table linking members to the member types they hold. A member may hold more than one type; each user/type pairing is one row.

| Column | Type | Notes |
|---|---|---|
| `id` | INT UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT(20) UNSIGNED NOT NULL | The member. References `wp_users.ID`. |
| `type_id` | INT UNSIGNED NOT NULL | The member type. References `bn_member_types.id`. |
| `assigned_by` | BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 | Admin/moderator who assigned it; `0` for self-select. |
| `assigned_at` | DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP | When the assignment was made. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `uq_user_type (user_id, type_id)` - prevents the same type being assigned twice to one member.
- KEY `idx_user_id (user_id)` - "this member's types".
- KEY `idx_type_id (type_id)` - "members of this type" (directory filter).

Relationships: `user_id` -> `wp_users.ID`, `type_id` -> `bn_member_types.id`. Assignment and removal fire `buddynext_member_type_assigned` / `buddynext_member_type_removed`.

## bn_profile_groups

Top-level grouping for profile fields (for example "Basic Info", "Work Experience"). A group is either `flat` (one set of values per member) or `repeater` (member can add multiple entries, like several jobs). Five system groups are seeded on install.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `group_key` | VARCHAR(100) NOT NULL | Stable machine key (for example `basic_info`). Unique. |
| `label` | VARCHAR(255) NOT NULL | Display label. |
| `type` | ENUM('flat','repeater') NOT NULL DEFAULT 'flat' | `repeater` groups allow multiple entries per member. |
| `visibility` | ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public' | Default visibility for fields in the group. |
| `is_system` | TINYINT(1) NOT NULL DEFAULT 0 | `1` for the built-in seeded groups. |
| `sort_order` | INT NOT NULL DEFAULT 0 | Display ordering. |
| `type_restriction` | VARCHAR(100) DEFAULT NULL | Optional member-type slug limiting which members see/fill the group. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `group_key (group_key)`.
- KEY `type_res (type_restriction)` - filter groups shown for a given member type.

Relationships: referenced by `bn_profile_fields.group_id`. Seeded groups: `basic_info`, `social_links` (flat), `work_experience`, `education` (repeater), `skills` (flat).

## bn_profile_fields

Individual fields within a group. Each field has a data type, optional select options (JSON), and per-field visibility/searchability/registration flags.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `group_id` | BIGINT(20) UNSIGNED NOT NULL | Owning group. References `bn_profile_groups.id`. |
| `field_key` | VARCHAR(100) NOT NULL | Stable machine key (for example `headline`). Unique. |
| `label` | VARCHAR(255) NOT NULL | Display label. |
| `type` | VARCHAR(32) NOT NULL DEFAULT 'text' | Field type: `text`, `textarea`, `url`, `date`, `number`, `boolean`, etc. |
| `options` | JSON DEFAULT NULL | Choices for select-style fields. |
| `is_required` | TINYINT(1) NOT NULL DEFAULT 0 | Whether the field must be filled. |
| `is_searchable` | TINYINT(1) NOT NULL DEFAULT 0 | Whether values feed the member directory search. |
| `show_on_register` | TINYINT(1) NOT NULL DEFAULT 0 | Whether the field appears on the registration form (added in schema rev 5). |
| `visibility` | ENUM('public','followers','connections','private') NOT NULL DEFAULT 'public' | Field-level visibility override. |
| `sort_order` | INT NOT NULL DEFAULT 0 | Display ordering within the group. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `field_key (field_key)`.
- KEY `group_idx (group_id)` - all fields in a group.

Relationships: `group_id` -> `bn_profile_groups.id`; referenced by `bn_profile_values.field_id`.

## bn_profile_values

The actual stored profile data - a tall EAV table with one row per member, per field, per repeater entry. For a flat field `entry_index` is `0`; for a repeater each added entry increments the index.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT | Primary key. |
| `user_id` | BIGINT(20) UNSIGNED NOT NULL | The member. References `wp_users.ID`. |
| `field_id` | BIGINT(20) UNSIGNED NOT NULL | The field. References `bn_profile_fields.id`. |
| `entry_index` | SMALLINT UNSIGNED NOT NULL DEFAULT 0 | Repeater-entry index; `0` for flat fields. |
| `value` | LONGTEXT DEFAULT NULL | The stored value (serialized as needed by the field type). |
| `entry_visibility` | ENUM('public','followers','connections','private') DEFAULT NULL | Optional per-entry visibility override; `NULL` inherits the field default. |

Indexes:

- PRIMARY KEY `(id)`.
- UNIQUE KEY `user_field_entry (user_id, field_id, entry_index)` - one value per member/field/entry; the upsert key.
- KEY `field_idx (field_id)` - directory queries that filter on a searchable field.
- KEY `user_idx (user_id)` - load a member's full profile.

Relationships: `user_id` -> `wp_users.ID`, `field_id` -> `bn_profile_fields.id`. A member's complete profile is the set of value rows joined back to fields and groups.

## Notes / gotchas

- **ID width differs.** `bn_member_types` and `bn_member_type_assignments` use `INT UNSIGNED` ids; every other table here uses `BIGINT(20) UNSIGNED`. Match the column type when joining or storing references.
- **No foreign keys.** All cross-table references are by id only; integrity and cascade-on-delete live in the service layer, not the schema.
- **Idempotent creates.** The pair-keyed tables (`bn_follows`, `bn_blocks`, `bn_member_type_assignments`) rely on their composite/unique keys so re-running a create is a safe `INSERT IGNORE`.
- **Seeding is fresh-install only** for starter member types and the five system profile groups/fields, so deleting them does not bring them back on the next upgrade.
- **Visibility cascades** from group -> field -> entry, with each lower level able to override (`bn_profile_values.entry_visibility = NULL` inherits upward).
