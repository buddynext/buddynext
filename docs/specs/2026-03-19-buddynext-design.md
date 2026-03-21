# BuddyNext — Product Design Spec

**Date:** 2026-03-19
**Status:** In progress — feature-by-feature review
**Domain:** buddynext.com
**Author:** Wbcom Designs

---

## Core Architectural Principle

> **Minimal by default, extensible by design.**
> Ship the essential feature. Cover it with hooks. Let the ecosystem add layers.

---

## 1. Product Model

### Editions

| Edition | What's Included |
|---------|----------------|
| **BuddyNext Free** | Fully functional social core — no artificial feature gates. Complete enough to run a real community. |
| **BuddyNext Pro** | Power + scale features: AI ranking, Stripe membership, real-time WebSocket, native mobile app, advanced analytics, white-label |

### Free is fully functional — Pro is power/scale
- DM → free (REST polling). Real-time WebSocket → Pro
- Feed → free (chronological + connections-first). AI ranking → Pro
- Spaces → free (all types). Paywall gating → Pro
- Mobile → free (responsive web). Native app (React Native) → Pro
- All Gutenberg blocks → free. Membership gate blocks → Pro

### Pro Bundles

| Bundle | Plugins |
|--------|---------|
| Community Bundle | BuddyNext Pro + Jetonomy Pro + WPMediaVerse Pro + WBGamification |
| Complete Stack | Community Bundle + WP Career Board Pro + BuddyX Pro |
| Agency Stack | Complete Stack + Reign Theme |

### Out of scope
- Courses / LMS → separate plugin, future
- Live streaming → YouTube/Zoom embed only
- Email sequences → FluentCRM/Mailchimp hooks provided

---

## 2. Technical Stack

| Layer | Technology |
|-------|-----------|
| PHP | 8.1+ |
| WordPress | 6.9+ (Abilities API required from day 1) |
| Architecture | DI Service Container (same pattern as WPMediaVerse) |
| Extension surface | WordPress Abilities API — every feature is an ability |
| Frontend reactivity | WordPress Interactivity API |
| Blocks | Gutenberg (all core blocks free) |
| Async jobs | Action Scheduler |
| Autoloader | Composer PSR-4 |
| REST API | Single source of truth — browser + mobile hit same endpoints |
| Real-time transport | Swappable via `buddynext_messaging_transport` filter (REST polling free → WebSocket Pro) |
| Mobile app | React Native / Expo, white-labelable (Pro only) |
| Payments | Stripe SDK direct — no WooCommerce dependency |

### Bootstrap chain

```
plugins_loaded → BuddyNext\Core\Plugin::init() → fires buddynext_loaded
                                                            ↓
                                        buddynext-pro hooks via buddynext_loaded
                                        JetonomyBridge (if jetonomy active)
                                        WPMediaVerseBridge (if wpmediaverse active)
                                        WBGamificationBridge (if wb-gamification active)
                                        CareerBoardBridge (if wp-career-board active)
```

### REST namespaces
- `buddynext/v1` — core free
- `buddynext-pro/v1` — Pro features

---

## 3. Social Graph ✅ LOCKED

### Three relationship types

**Follow** (asymmetric, no approval)
- Follow / unfollow anyone
- Followers list, following list, counts
- Powers feed — "show me posts from people I follow"
- Follow = feed access

**Connection** (mutual, request → accept)
- Send / accept / decline / withdraw request
- Pending inbox (received + sent)
- Connection list + count
- Mutual connections count shown on profiles
- Connection degree (1st, 2nd) shown in directory
- Connection = private content access

**Block / Mute**
- Block — hard. Can't see content, can't message, removed from feed
- Mute — soft. Stays connected but invisible in feed. They never know.

### Privacy model (driven by graph)
```
Public      → everyone, indexed by Google
Followers   → people who follow you
Connections → mutual connections only
Private     → just you
```

### Follow suggestions ("People You May Know") — v1
- People followed by people you follow
- Shown in directory + sidebar widget

### DB tables
```
bn_follows      (follower_id, following_id, created_at)
bn_connections  (id, requester_id, recipient_id, status: pending|accepted|declined, created_at)
bn_blocks       (blocker_id, blocked_id, type: block|mute, created_at)
```

### Developer hooks
```php
do_action( 'buddynext_user_followed', $follower_id, $following_id );
do_action( 'buddynext_user_unfollowed', $follower_id, $following_id );
do_action( 'buddynext_connection_requested', $requester_id, $recipient_id );
do_action( 'buddynext_connection_accepted', $user1_id, $user2_id );
do_action( 'buddynext_user_blocked', $blocker_id, $blocked_id );
apply_filters( 'buddynext_can_view', true, $viewer_id, $owner_id, $visibility );
```

### WPMediaVerse bridge sync
`mvs_user_followed` → sync to `bn_follows` and `buddynext_user_followed` → sync to `mvs_follows`. Loop-safe (checks origin before firing).

---

## 4. Activity Feed ✅ LOCKED

### Feed scopes
| Scope | Shows |
|-------|-------|
| Home | Posts from followed users + joined spaces |
| Profile | Single user's posts |
| Space | Posts within a space |
| Explore | Public posts — no login needed, indexed by Google |

### Post types
| Type | Notes |
|------|-------|
| Text | Rich text, @mentions, #hashtags |
| Link | URL auto-unfurl via oEmbed/meta tags |
| Poll | 2-5 options, vote inline, results shown after vote |
| Activity item | System-generated: "X followed Y", "X joined Space" |
| Photo / Video | WPMediaVerse only — no standalone media upload in BuddyNext |

Bridge-powered (only when plugin active):
- Jetonomy discussion card
- Career Board job card

### Per-post privacy
Public / Followers / Connections / Space members / Private

### Post interactions
React, Comment (threaded, one level deep), Share (repost with note), Bookmark (private), Report, Delete

### Feed behavior
- Cursor-based pagination
- Connections-first ordering (free) — algorithmic AI ranking (Pro)
- "New posts" indicator bar — no auto-scroll hijack
- Infinite scroll

### v1 includes (not deferred)
- Scheduled posts
- Post edit (last edited timestamp)
- Pinned posts (per profile + per space)
- Trending / hashtag explore

### DB tables
```
bn_posts        (id, user_id, type, content, meta_json, privacy, space_id, scheduled_at, edited_at, is_pinned, created_at)
bn_bookmarks    (user_id, post_id, created_at)
bn_shares       (id, user_id, post_id, content, created_at)
```

### Developer hooks
```php
apply_filters( 'buddynext_feed_post_types', $types );
apply_filters( 'buddynext_feed_query_args', $args, $scope, $user_id );
do_action( 'buddynext_post_created', $post_id, $user_id, $type );
do_action( 'buddynext_post_deleted', $post_id, $user_id );
```

---

## 5. Spaces / Groups ✅ LOCKED

### Space types
| Type | Join model |
|------|-----------|
| Open | Anyone joins instantly |
| Private | Request to join — approved by moderator |
| Secret | Invite only — hidden from directory |

### Sub-spaces (one level deep)
```
Photography (Space)
├── Portraits     (Sub-space)
├── Landscapes    (Sub-space)
└── Gear Talk     (Sub-space)
```
- Sub-space has own feed + member roster (subset of parent)
- Inherits parent privacy + paywall by default, can override
- `bn_spaces.parent_id` nullable — null = top-level

### Categories
- Admin-defined categories for space discovery (no tags)
- Extensible via `buddynext_space_categories` filter

### Roles
| Role | Permissions |
|------|------------|
| Owner | Everything including delete space |
| Moderator | Manage members, delete posts, pin posts |
| Member | Post, react, comment |

Extensible via `buddynext_space_roles` filter.

### Space management settings
General (name, slug, description, avatar, cover), Privacy, Members (invite/remove/ban/promote), Moderation (pre-mod, banned words), Notifications, Integrations (Jetonomy forum tab, WPMediaVerse media tab), Danger zone (archive/delete)

Pro additions: Analytics tab, webhooks, export members

### Paywall (Pro)
**Model A — Tier-gated:** Requires BuddyNext membership tier subscription
**Model B — Space-own price:** Own Stripe price, independent of site tiers
Both support: free trial days, grandfathered members

### DB tables
```
bn_spaces (
    id, parent_id, slug, name, description, type,
    category_id, owner_id, member_count, avatar_url, cover_url,
    required_tier_id, stripe_price_id, trial_days, grandfathered_before,
    created_at
)
bn_space_members  (space_id, user_id, role, joined_at, notification_pref)
bn_space_categories (id, name, slug, order)
```

### Developer hooks
```php
apply_filters( 'buddynext_can_join_space', true, $user_id, $space_id );
apply_filters( 'buddynext_space_types', $types );
apply_filters( 'buddynext_space_roles', $roles, $space_id );
do_action( 'buddynext_space_created', $space_id, $user_id );
do_action( 'buddynext_member_joined_space', $user_id, $space_id );
```

---

## 6. Member Directory + Search ✅ LOCKED

### Member Directory
Dedicated page with real-time filters:
- Name / username (text search)
- Location (from profile fields)
- Skills (multi-select)
- Space membership
- Connection status (my connections / 2nd degree / everyone)
- Online now (toggle)
- Any profile field marked as searchable → auto-appears as filter

Sort: Newest / Most active / Alphabetical / Mutual connections
Card view + list view. Follow + connect inline on cards.

### Unified search — grouped results
```
Search: "photography"

Members (3)        Spaces (2)           Posts (12)         Discussions (5)
───────────        ──────────           ──────────         ───────────────
@jane_photo        Photography Club     "Best lenses..."   "Canon vs Nikon"
@photo_bob         Street Photography   "Golden hour..."   "Film is back..."
@lens_master       → See all            → See all          → See all
```
Top 3-5 per group. Click group → full filtered results.

### Search architecture (built for scale)

**Dedicated search index — async updated, never blocks:**
```
bn_search_index (
    id, object_type, object_id,
    title, content,        ← FULLTEXT INDEX on these two
    meta_json,             ← searchable profile fields, space_id, etc
    author_id, space_id, visibility,
    indexed_at
)
```

- Index updated async via Action Scheduler on content create/update/delete
- Privacy-aware — visibility column filtered against viewer's graph
- Re-index batch job on activation for existing content
- Pluggable driver: MySQL FULLTEXT default → swap to ElasticSearch/Algolia/Typesense for 100k+ communities

```php
apply_filters( 'buddynext_search_driver', $driver );
apply_filters( 'buddynext_directory_filters', $filters );
apply_filters( 'buddynext_search_post_types', $types );
do_action( 'buddynext_index_object', $object_type, $object_id );
```

---

## 7. User Profiles ✅ LOCKED

### Field architecture — 2 tables, developer-friendly

```
bn_profile_fields  (id, group_slug, type, label, slug, options_json, is_repeater, is_required, is_searchable, privacy_default, sort_order)
bn_profile_values  (user_id, field_id, entry_index, value)
```

`entry_index` handles repeaters — 0,1,2 = three Work Experience entries. No separate entries table.

### Built-in field groups
| Group | Type | Fields |
|-------|------|--------|
| Basic Info | Flat | Bio, location, website, pronouns |
| Social Links | Flat | Twitter/X, LinkedIn, GitHub, Instagram, YouTube (icon-type) |
| Work Experience | Repeater | Company, title, location, daterange, currently working, description |
| Education | Repeater | Institution, degree, field of study, daterange, currently attending |
| Skills | Flat | Tag-based multi-select |

Admin adds custom groups (flat or repeater) from UI. Developers register via filter.

### Field types
`text`, `textarea`, `url`, `social`, `select`, `multiselect`, `date`, `daterange`, `checkbox`, `number`

### Developer API
```php
add_filter( 'buddynext_profile_groups', function( $groups ) {
    $groups['portfolio'] = [
        'label'    => 'Portfolio',
        'repeater' => true,
        'fields'   => [
            [ 'slug' => 'project_name', 'type' => 'text', 'label' => 'Project Name' ],
            [ 'slug' => 'project_url',  'type' => 'url',  'label' => 'URL' ],
        ],
    ];
    return $groups;
} );

buddynext_get_profile_field( $user_id, 'location' );
buddynext_get_profile_entries( $user_id, 'work_experience' );
```

Searchable flat fields denormalized to `wp_usermeta` on save (keyed `bn_field_{slug}`) for `WP_User_Query` filtering without joins.

---

## 8. WPMediaVerse Reuse Map

Patterns to copy (BuddyNext uses own `bn_*` tables — no duplicate data):

| WPMediaVerse | BuddyNext equivalent | Notes |
|--------------|---------------------|-------|
| `Social/FollowService.php` | `Graph/FollowService.php` | Same pattern, `bn_follows`, INSERT IGNORE |
| `Social/ActivityService.php` | `Feed/ActivityService.php` | Extend with more verbs |
| `Social/NotificationService.php` | `Notifications/NotificationService.php` | Same caching pattern |
| `Core/ServiceContainer.php` | `Core/ServiceContainer.php` | Copy as-is |
| Pro `Messaging/TransportInterface.php` | Pro `Messaging/TransportInterface.php` | Same interface |
| Pro `Messaging/RestPollingTransport.php` | Pro `Messaging/RestPollingTransport.php` | Own REST namespace |

---

## 9. Features Pending Discussion

| # | Feature | Status |
|---|---------|--------|
| 6 | Notifications | Pending |
| 7 | Direct Messaging | Pending |
| 8 | Reactions + Comments | Pending |
| 9 | Moderation | Pending |
| 10 | Onboarding + Setup Wizard | Pending |
| 11 | Gutenberg Blocks | Pending |
| 12 | WBGamification Bridge | Pending |
| 13 | Jetonomy Bridge | Pending |
| 14 | WPMediaVerse Bridge | Pending |
| 15 | Career Board Bridge | Pending |
| 16 | Admin Settings | Pending |
| Pro | Stripe Membership | Pending |
| Pro | AI Engine | Pending |
| Pro | Mobile App | Pending |
| Pro | Analytics | Pending |
| Pro | White-label | Pending |

---

## 10. Database Tables Summary

| Table | Feature |
|-------|---------|
| `bn_follows` | Social graph — follows |
| `bn_connections` | Social graph — mutual connections |
| `bn_blocks` | Social graph — blocks + mutes |
| `bn_posts` | Activity feed posts |
| `bn_bookmarks` | Feed — saved posts |
| `bn_shares` | Feed — reposts |
| `bn_spaces` | Spaces + sub-spaces |
| `bn_space_members` | Space membership + roles |
| `bn_space_categories` | Space discovery categories |
| `bn_profile_fields` | Profile field definitions |
| `bn_profile_values` | Profile field values + repeater entries |
| `bn_search_index` | Unified search index (async, FULLTEXT) |
| *(pending)* | Notifications, DMs, Reactions, Comments |

Pro tables *(pending)*: `bn_membership_tiers`, `bn_subscriptions`, `bn_ai_signals`, `bn_analytics_events`

---

## 11. Recent Changes

| Date | What |
|------|------|
| 2026-03-19 | Initial spec — product model, stack, all locked features |
