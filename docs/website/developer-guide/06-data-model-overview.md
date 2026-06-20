# Data Model Overview

BuddyNext stores its social data in dedicated custom tables rather than WordPress posts and postmeta. This page covers the table-naming convention, the Scale-Contract rules that shape every schema, and a master index of every Free and Pro table with the page that documents it. Read this before adding or querying any custom table.

![The admin dashboard backed by the custom bn_* tables catalogued in this data-model index](../images/admin-overview.webp)

![The feed UI populated from the custom social tables this page documents](../images/community-activity-feed.webp)

## Table-naming convention

All BuddyNext-owned tables use the `bn_` prefix on top of the site's `$wpdb->prefix` (so a default install gets `wp_bn_posts`, `wp_bn_follows`, and so on). Free tables are created by `BuddyNext\Core\Installer::run()` and Pro tables by `BuddyNextPro\Core\Installer::run()`, both via `dbDelta()`. The prefix is consistent across Free and Pro - there is no separate Pro prefix.

Two tables break the `bn_` convention: `jt_posts` and `jt_replies`. These are **owned by Jetonomy** (the discussions/forum plugin), not by BuddyNext. They appear in BuddyNext's table inventory because BuddyNext reads them through the Jetonomy bridge to surface discussions and replies as community content (for example, in the Explore deck as `discussion` items). BuddyNext never creates, alters, or writes to `jt_posts` / `jt_replies` - it treats them as read-only borrowed tables. If Jetonomy is not installed, those tables do not exist and the bridge stays inert.

Direct-message tables (`mvs_conversations`, `mvs_messages`, and siblings) are owned by **WPMediaVerse**, not BuddyNext. BuddyNext is the UI layer for messaging only, so those tables are out of scope for this schema reference.

> **Note:** `audit/manifest.json` in each plugin is the authoritative live table inventory. The Free manifest lists 41 tables (39 `bn_*` plus the two borrowed `jt_*` tables); the Pro manifest lists 22 entries, several of which are shared Free tables re-listed for reference (see Schema: Pro Tables). When a count in prose disagrees with the manifest, trust the manifest.

## Scale-Contract: the rules that shape every schema

Every BuddyNext table is designed against a fixed target: **100,000 sites x 100,000 members per site**. The Scale Contract (`docs/specs/SCALE-CONTRACT.md`, with conformance tracked in `docs/conformance/contract-scale.md`) is the binding ruleset. Three of its rules directly determine how each table is built.

### Denormalized counters

`COUNT(*)` across a multi-million-row table is a full scan and is never run during a page render. Instead, counts that appear in the UI live as denormalized integer columns updated on insert and delete:

- `bn_posts.reaction_count`, `bn_posts.comment_count`, `bn_posts.share_count`
- `bn_spaces.member_count`
- `bn_hashtags.post_count`

Any new table that needs a count gets a counter column from day one. Counter writes go through service helpers (for example `PostService::increment_counter()` / `decrement_counter()`) so the read path never has to aggregate.

### Index-per-WHERE

Every column used in a `WHERE`, `JOIN`, or `ORDER BY` clause has an index, and multi-column predicates get composite indexes. This is visible throughout the Free schema - for example `bn_follows` carries `KEY following (following_id, status)` and `KEY pending_inbox (following_id, status, created_at)` so the "who follows me" and "pending follow requests" queries each hit a covering index. When a new query filters on a column, an index for that column lands before the query goes live.

### Cursor columns (never OFFSET)

`OFFSET N` scans and discards N rows, so deep pagination collapses at scale. BuddyNext uses keyset (cursor) pagination instead: `WHERE created_at < ? AND id < ? ORDER BY created_at DESC, id DESC LIMIT ?`. Every paginated list follows the pattern `FeedService::cursor_where()` established. This is why list tables carry a `created_at` column alongside the auto-increment `id` - the `(created_at, id)` pair is the stable cursor key, and indexes are ordered to support descending-time reads.

Supporting rules from the same contract: no unbounded queries (REST `per_page` caps at 50), an object-cache wrapper on any read that runs on more than half of page loads, Action Scheduler for write fan-out (notifying N space members happens in batched background jobs, not synchronously), and `bn_search_index` (FULLTEXT) as the single source of truth for cross-collection search.

## Master index of all tables

Every Free and Pro table, its domain, its purpose, and the developer-guide page that documents its columns in full. "Borrowed" rows are tables BuddyNext reads but does not own. "Shared" rows are Free tables that the Pro manifest re-lists because Pro reads or extends them - they are created and owned by Free (see Schema: Pro Tables for the ownership note).

### Free tables (created by Free's Installer)

| Table | Domain | Purpose | Documented on |
|-------|--------|---------|---------------|
| `bn_follows` | Social graph | Follower / following edges with approval status | Schema: Core Tables |
| `bn_connections` | Social graph | Two-way connection requests (pending/accepted/declined/withdrawn) | Schema: Core Tables |
| `bn_blocks` | Social graph | Block / mute / restrict relationships | Schema: Core Tables |
| `bn_posts` | Activity feed | Activity posts (content, media, privacy, status, denormalized counters) | Schema: Core Tables |
| `bn_comments` | Activity feed | Threaded comments on posts | Schema: Core Tables |
| `bn_reactions` | Activity feed | Emoji reactions on posts and comments | Schema: Core Tables |
| `bn_shares` | Activity feed | Re-share / quote-share edges | Schema: Core Tables |
| `bn_bookmarks` | Activity feed | Per-user saved posts | Schema: Core Tables |
| `bn_poll_options` | Activity feed | Poll option rows attached to poll posts | Schema: Core Tables |
| `bn_poll_votes` | Activity feed | Per-user poll votes | Schema: Core Tables |
| `bn_feed_items` | Activity feed | Fan-out feed cache for very large communities | Schema: Core Tables |
| `bn_spaces` | Spaces | Space (group) definitions with denormalized `member_count` | Schema: Core Tables |
| `bn_space_members` | Spaces | Space membership rows with role | Schema: Core Tables |
| `bn_space_categories` | Spaces | Space category taxonomy | Schema: Core Tables |
| `bn_space_bans` | Spaces / moderation | Per-space user bans | Schema: Core Tables |
| `bn_hashtags` | Hashtags | Hashtag registry with denormalized `post_count` | Schema: Core Tables |
| `bn_post_hashtags` | Hashtags | Post-to-hashtag join rows | Schema: Core Tables |
| `bn_hashtag_follows` | Hashtags | Per-user followed hashtags | Schema: Core Tables |
| `bn_search_index` | Search | Unified FULLTEXT index across posts, users, spaces, hashtags | Schema: Core Tables |
| `bn_profile_groups` | Profiles | Profile field group definitions | Schema: Core Tables |
| `bn_profile_fields` | Profiles | Profile field definitions (type, options, order) | Schema: Core Tables |
| `bn_profile_values` | Profiles | Per-user profile field values | Schema: Core Tables |
| `bn_member_types` | Members | Member type definitions | Schema: Core Tables |
| `bn_member_type_assignments` | Members | User-to-member-type assignments | Schema: Core Tables |
| `bn_notifications` | Notifications | In-app notification rows | Schema: Core Tables |
| `bn_notification_prefs` | Notifications | Per-user / per-channel notification preferences | Schema: Core Tables |
| `bn_email_templates` | Email | Editable email template definitions | Schema: Core Tables |
| `bn_email_log` | Email | Sent-email delivery log | Schema: Core Tables |
| `bn_verify_tokens` | Auth | Email-verification and similar one-time tokens | Schema: Core Tables |
| `bn_invites` | Auth / members | Member invitation rows | Schema: Core Tables |
| `bn_reports` | Moderation | Content/user reports queue | Schema: Core Tables |
| `bn_mod_log` | Moderation | Moderator action audit log | Schema: Core Tables |
| `bn_user_strikes` | Moderation | Per-user strike records | Schema: Core Tables |
| `bn_user_suspensions` | Moderation | User suspension rows | Schema: Core Tables |
| `bn_appeals` | Moderation | Suspension / strike appeal workflow (Pro reuses this; no `bn_mod_appeals`) | Schema: Core Tables |
| `bn_activity_log` | Core | Internal activity / audit log | Schema: Core Tables |
| `bn_webhook_log` | Core | Incoming webhook processing log | Schema: Core Tables |
| `bn_outbound_webhooks` | Outbound | Outbound webhook endpoint registrations (Free caps at 1; Pro lifts the cap) | Schema: Core Tables |
| `bn_outbound_webhook_log` | Outbound | Outbound webhook delivery attempts | Schema: Core Tables |
| `jt_posts` | Discussions (borrowed) | Jetonomy discussion posts - read-only via the Jetonomy bridge, not BN-owned | Jetonomy bridge (integration) |
| `jt_replies` | Discussions (borrowed) | Jetonomy discussion replies - read-only via the Jetonomy bridge, not BN-owned | Jetonomy bridge (integration) |

### Pro tables (created by Pro's Installer)

| Table | Domain | Purpose | Documented on |
|-------|--------|---------|---------------|
| `bn_membership_tiers` | Membership | Tier definitions (slug, name, price, billing, entitlements) | Schema: Pro Tables |
| `bn_subscriptions` | Membership | Per-user subscription rows linked to a tier and gateway | Schema: Pro Tables |
| `bn_plan_gateway_map` | Membership | Per-gateway provisioned price ID for each plan | Schema: Pro Tables |
| `bn_invoices` | Membership | Purchase receipts (amount, tax, discount, total) | Schema: Pro Tables |
| `bn_coupons` | Membership | Discount codes (percent/fixed, redemption limits) | Schema: Pro Tables |
| `bn_tax_rules` | Membership | Local tax-rule fallback when the gateway has no native tax | Schema: Pro Tables |
| `bn_ai_signals` | AI | Behavioral signal rows for AI feed ranking | Schema: Pro Tables |
| `bn_ai_embeddings` | AI | Cached vector embeddings per object for semantic search | Schema: Pro Tables |
| `bn_push_tokens` | Push | FCM / APNs device tokens per user | Schema: Pro Tables |
| `bn_analytics_events` | Analytics | Raw analytics event stream | Schema: Pro Tables |
| `bn_email_campaigns` | Email | Broadcast campaign definitions (subject, body, segment, status) | Schema: Pro Tables |
| `bn_campaign_recipients` | Email | Per-recipient delivery plus open/click tracking | Schema: Pro Tables |
| `bn_drip_sequences` | Email | Drip sequence definitions (trigger, JSON steps) | Schema: Pro Tables |
| `bn_drip_enrollments` | Email | Per-user enrollment rows in a drip sequence | Schema: Pro Tables |
| `bn_mod_rules` | Moderation | Auto-moderation rule definitions (keyword, threshold, action) | Schema: Pro Tables |
| `bn_member_labels` | Labels | Custom member label definitions (Verified, Expert, Staff) | Schema: Pro Tables |
| `bn_member_label_assignments` | Labels | User-to-label assignment rows | Schema: Pro Tables |
| `bn_saved_searches` | Search | Per-user saved search queries and filters | Schema: Pro Tables |
| `bn_space_meta` | Spaces | Per-space brand override plus arbitrary space meta (key/value) | Schema: Pro Tables |

### Shared Free tables re-listed by the Pro manifest

The Pro manifest also lists `bn_user_suspensions`, `bn_appeals`, `bn_space_bans`, `bn_outbound_webhooks`, and `bn_outbound_webhook_log`. These are **created and owned by Free** - Pro reads or extends them but never creates a duplicate. They are documented under Schema: Core Tables. The ownership boundary is explained in detail on Schema: Pro Tables.

## Notes and gotchas

- **Pro never creates or modifies Free tables.** Pro's Installer creates only Pro-owned tables. When a Pro feature needs a new column on a Free table, that column is added by Free, not by a Pro `ALTER`. Pro adds columns only to its own tables, via `Installer::maybe_alter_tables()` guarded by an `INFORMATION_SCHEMA` existence check.
- **`bn_mod_appeals` does not exist.** The Pro spec drafts named an appeals table `bn_mod_appeals`, but Pro reuses Free's `bn_appeals`. This is a deliberate decision, not an oversight - the two names refer to the same logical table.
- **The manifest list and the Installer can diverge slightly.** The Pro Installer creates several tables (`bn_plan_gateway_map`, `bn_invoices`, `bn_coupons`, `bn_tax_rules`, `bn_space_meta`, `bn_push_tokens`, `bn_ai_embeddings`) that the snapshot `tables` array in the manifest does not enumerate. The Installer's `schema()` method is the ground truth for what is created; this index follows the Installer.
- **Borrowed tables are version-gated.** `jt_posts` / `jt_replies` exist only when Jetonomy is active. Never assume their presence - the bridge guards on the Jetonomy plugin being loaded.
