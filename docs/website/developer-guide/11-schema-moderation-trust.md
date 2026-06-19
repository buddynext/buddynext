# Schema: Moderation and Trust

Reference for the tables that back BuddyNext's moderation, trust, and audit subsystem: `bn_reports`, `bn_mod_log`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_invites`, and `bn_activity_log`. All seven are created by `BuddyNext\Core\Installer` via `dbDelta()` and live in the site table prefix (shown below as `wp_`). This page is for developers reading, extending, or writing against these tables directly.

> **Note:** Every table in this page was verified against `includes/Core/Installer.php`. All seven exist. `bn_invites` is documented here because it carries a per-row trust token and shares the audit/lifecycle shape of the moderation tables, even though it lives under the installer's "Onboarding + Invites" section.

## Overview / Contract

The moderation surface is split into three concerns:

- **Intake** - members file reports into `bn_reports`. One reporter can file at most one report per object.
- **Action + audit** - moderators act, and every action is recorded. `bn_mod_log` is the append-only audit trail; `bn_user_strikes` and `bn_user_suspensions` are the durable state of trust actions against a user; `bn_activity_log` is the broader (non-moderation-specific) action log.
- **Recourse** - a suspended or struck user can file an appeal into `bn_appeals`, reviewed by a moderator.

Two structural rules shaped the schema:

- **Reversibility over deletion.** Strikes, suspensions, and appeals carry reversal / lift / review columns (`is_reversed`, `lifted_at`, `reviewed_by`) rather than being hard-deleted, so trust history and audit are preserved.
- **Space-scoped where relevant.** `bn_reports` and `bn_mod_log` carry a nullable `space_id` so the same tables serve both site-level moderation and per-space moderation.

## `bn_reports`

Member-filed reports against a piece of content or a user. The queue read path is by status and recency; the per-object read path checks whether a given object already has reports.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `reporter_id` | `BIGINT UNSIGNED NOT NULL` | The user who filed the report. |
| `object_type` | `VARCHAR(32) NOT NULL` | The kind of object reported, e.g. `post`, `comment`, `user`. |
| `object_id` | `BIGINT UNSIGNED NOT NULL` | The id of the reported object. |
| `reason` | `ENUM('spam','harassment','misinformation','inappropriate','fake','impersonation','other') NOT NULL DEFAULT 'other'` | The selected report reason. |
| `notes` | `TEXT NULL` | Optional free-text detail from the reporter. |
| `status` | `ENUM('pending','dismissed','escalated','resolved') NOT NULL DEFAULT 'pending'` | Queue state. |
| `resolved_by` | `BIGINT UNSIGNED NULL` | Moderator who closed the report. |
| `resolved_at` | `DATETIME NULL` | When it was closed. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When it was filed; the queue sort key. |
| `space_id` | `BIGINT UNSIGNED NULL` | The space the report belongs to, if it is space-scoped. Null for site-level reports. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `one_per_reporter` (UNIQUE) | `reporter_id, object_type, object_id` | Enforces one report per reporter per object; an attempted duplicate is rejected at the DB level. |
| `object_status` | `object_type, object_id, status` | "Does this object have open reports?" and per-object report counts. |
| `status_date` | `status, created_at` | The moderation queue: reports by status, newest first. |
| `space` | `space_id` | Per-space report queues. |

### Relationships

- `reporter_id` and `resolved_by` reference `wp_users.ID`.
- `object_type` + `object_id` is a polymorphic pointer to the reported object (`bn_posts`, `bn_comments`, a user, and so on).
- `space_id` references `bn_spaces.id` when set.

## `bn_mod_log`

Append-only audit trail of moderation actions. Every moderator action writes a row here; rows are never updated in place.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `actor_id` | `BIGINT UNSIGNED NOT NULL` | The moderator who performed the action. |
| `action` | `VARCHAR(64) NOT NULL` | Action slug, e.g. `warn`, `suspend`, `strike`, `dismiss_report`. |
| `object_type` | `VARCHAR(32) NULL` | The object the action targeted, if any. |
| `object_id` | `BIGINT UNSIGNED NULL` | The id of that object. |
| `target_user_id` | `BIGINT UNSIGNED NULL` | The user the action was taken against, when the action is user-directed. |
| `note` | `TEXT NULL` | Optional moderator note recorded with the action. |
| `space_id` | `BIGINT UNSIGNED NULL` | The space the action was scoped to, if any. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the action occurred. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `actor` | `actor_id` | "What did this moderator do?" |
| `target_user` | `target_user_id` | "What was done to this user?" - the per-user moderation history. |
| `created` | `created_at` | Chronological audit feed. |
| `space` | `space_id` | Per-space audit. |
| `object` | `object_type, object_id` | "What moderation touched this object?" |

### Relationships

- `actor_id` and `target_user_id` reference `wp_users.ID`.
- `object_type` + `object_id` is a polymorphic pointer.
- `space_id` references `bn_spaces.id` when set.

## `bn_user_strikes`

Durable record of strikes issued against a user. Strikes are reversed, not deleted, so the trust history is preserved.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | The user the strike is against. |
| `issued_by` | `BIGINT UNSIGNED NOT NULL` | The moderator who issued it. |
| `reason` | `TEXT NULL` | Why the strike was issued. |
| `is_reversed` | `TINYINT(1) NOT NULL DEFAULT 0` | Whether the strike has been reversed. Active-strike counts filter on `is_reversed = 0`. |
| `reversed_by` | `BIGINT UNSIGNED NULL` | The moderator who reversed it. |
| `reversed_at` | `DATETIME NULL` | When it was reversed. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the strike was issued. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `user_active` | `user_id, is_reversed` | Count or list a user's active strikes (the threshold check for escalation). |
| `issued_by` | `issued_by` | Strikes issued by a given moderator. |

### Relationships

- `user_id`, `issued_by`, and `reversed_by` reference `wp_users.ID`.

## `bn_user_suspensions`

Durable record of account suspensions. A suspension is active while it is not yet lifted and not yet past its expiry; it is lifted (not deleted) when a moderator ends it early.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | The suspended user. |
| `suspended_by` | `BIGINT UNSIGNED NOT NULL` | The moderator who imposed the suspension. |
| `reason` | `TEXT NULL` | Why the user was suspended. |
| `duration_days` | `INT UNSIGNED NULL` | Length in days. Null implies an open-ended suspension (no automatic expiry). |
| `hide_posts` | `TINYINT(1) NOT NULL DEFAULT 0` | Whether the user's content is hidden for the duration. |
| `expires_at` | `DATETIME NULL` | When the suspension auto-expires. Null for open-ended. |
| `lifted_at` | `DATETIME NULL` | When a moderator lifted it early. Null means it was never manually lifted. |
| `lifted_by` | `BIGINT UNSIGNED NULL` | The moderator who lifted it. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the suspension started. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `user_active` | `user_id, expires_at` | A user's suspensions ordered by expiry. |
| `active_check` | `lifted_at, expires_at, user_id` | The "is this user currently suspended?" gate: not lifted and not expired. |
| `suspended_by` | `suspended_by` | Suspensions imposed by a given moderator. |

### Relationships

- `user_id`, `suspended_by`, and `lifted_by` reference `wp_users.ID`.
- Referenced by `bn_appeals.suspension_id`.

## `bn_appeals`

A user's appeal against a suspension or strike, and the moderator's review of it.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `suspension_id` | `BIGINT UNSIGNED NOT NULL` | The suspension being appealed. |
| `strike_id` | `BIGINT NULL` | The strike being appealed, when the appeal is against a strike rather than (or as well as) a suspension. |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | The user filing the appeal. |
| `message` | `TEXT NOT NULL` | The appeal text from the user. |
| `status` | `ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending'` | Review outcome. |
| `reviewed_by` | `BIGINT UNSIGNED NULL` | The moderator who reviewed the appeal. |
| `reviewer_note` | `TEXT NULL` | The reviewer's note shown in the review context. |
| `reviewed_at` | `DATETIME NULL` | When it was reviewed. |
| `admin_note` | `TEXT NULL` | An internal admin note on the appeal. |
| `resolved_by` | `BIGINT UNSIGNED NULL` | The actor who resolved the appeal. |
| `resolved_at` | `DATETIME NULL` | When it was resolved. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the appeal was filed. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `user_status` | `user_id, status` | A user's appeals by status (e.g. "do I have a pending appeal?"). |
| `suspension` | `suspension_id` | Find the appeal(s) for a given suspension. |

### Relationships

- `user_id`, `reviewed_by`, and `resolved_by` reference `wp_users.ID`.
- `suspension_id` references `bn_user_suspensions.id`.
- `strike_id` references `bn_user_strikes.id` when set.

## `bn_invites`

Email invitations to join the community, optionally scoped to a space. Each invite carries a unique trust token consumed at registration.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `email` | `VARCHAR(200) NOT NULL` | The invited email address. |
| `first_name` | `VARCHAR(100) NULL` | Optional first name for personalising the invite email. |
| `space_id` | `BIGINT UNSIGNED NULL` | The space the invite is for. Null for a site-level invite. |
| `token` | `VARCHAR(64) NOT NULL` | Unique invitation token, used to validate the accept link. |
| `status` | `ENUM('pending','registered','bounced') NOT NULL DEFAULT 'pending'` | Invite lifecycle state. |
| `expires_at` | `DATETIME NOT NULL` | When the invite token stops being valid. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the invite was created. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `token` (UNIQUE) | `token` | Look up an invite by its accept-link token; the uniqueness guarantees one invite per token. |
| `email` | `email` | Find invites for a given address. |
| `status_expires` | `status, expires_at` | Sweep pending invites for expiry, list outstanding invites. |

### Relationships

- `space_id` references `bn_spaces.id` when set (the `space_id` column was added in schema revision 2 for space-linked invitations).
- On acceptance, the invite resolves to a new `wp_users` row; the link is by `email` + `token`, not a stored user id.

## `bn_activity_log`

A general per-user action log, broader than moderation. Used for audit and activity history across the platform.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | The user who performed the action. |
| `action` | `VARCHAR(64) NOT NULL` | Action slug. |
| `object_type` | `VARCHAR(32) NULL` | The object the action targeted, if any. |
| `object_id` | `BIGINT UNSIGNED NULL` | The id of that object. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the action occurred. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `user_action` | `user_id, action, created_at` | A user's actions of a given kind, newest first. |
| `created_at` | `created_at` | Chronological feed across all users (also the sweep key for pruning). |

### Relationships

- `user_id` references `wp_users.ID`.
- `object_type` + `object_id` is a polymorphic pointer.

## Notes / gotchas

- **`one_per_reporter` is enforced at the DB.** A second report from the same reporter on the same object is rejected by the unique key, not by application logic. Handle the duplicate-insert case rather than pre-checking.
- **Active state is computed, not stored.** A suspension is "active" when `lifted_at IS NULL AND (expires_at IS NULL OR expires_at > now)`; that is what `active_check` indexes. There is no single boolean column for it. The same reversibility pattern applies to strikes via `is_reversed`.
- **`bn_mod_log` and `bn_activity_log` are append-only.** Treat them as audit trails: insert, never update. `bn_mod_log` is moderation-specific (carries `actor_id` / `target_user_id` / `space_id`); `bn_activity_log` is the broader per-user action log.
- **Site vs space scope is the nullable `space_id`.** `bn_reports` and `bn_mod_log` both serve site-level and per-space moderation off the same table; a null `space_id` is the site-level case.
