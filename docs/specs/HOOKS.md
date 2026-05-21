# BuddyNext — Action Hook Reference

**Status:** Locked
**Last updated:** 2026-03-21
**Audience:** Addon developers (WBGamification, Jetonomy, WPMediaVerse, Career Board, custom integrations)

---

## How to Use This Reference

BuddyNext fires actions after every significant platform event. Addon plugins hook these to award points, sync data, send external notifications, or extend functionality.

**BuddyNext never calls addon code directly.** All integration is through hooks.

---

## Hook Implementation Status

| Feature area | Status |
|---|---|
| Follow / Unfollow | ✅ Done |
| Connections (request, accept, decline, withdraw) | ✅ Done |
| Block / Unblock | ✅ Done |
| Post created / deleted | ✅ Done |
| Reaction added / removed | ✅ Done |
| Comment created / updated / deleted | ✅ Done |
| Space created / deleted | ✅ Done |
| Space member joined / left / removed | ✅ Done |
| Space join requested / approved | ✅ Done |
| Notification created | ✅ Done |
| Member suspended / unsuspended | ✅ Done |
| Member warned | 🔲 Pending — needs ModerationService::warn() (BLOCK 2) |
| Shadow ban / unshadow | 🔲 Pending — needs ModerationService (BLOCK 2) |
| Appeal submitted / resolved | 🔲 Pending — needs ModerationService (BLOCK 2) |
| Report created | ✅ Done |
| Onboarding completed | ✅ Done |
| Search index | ✅ Done |
| Hashtag indexing | ✅ Done |

---

## Social Graph

```php
do_action( 'buddynext_user_followed',  int $follower_id, int $following_id )
do_action( 'buddynext_user_unfollowed', int $follower_id, int $following_id )

do_action( 'buddynext_connection_requested', int $connection_id, int $requester_id, int $recipient_id )
do_action( 'buddynext_connection_accepted',  int $connection_id, int $requester_id, int $recipient_id )
do_action( 'buddynext_connection_rejected',  int $connection_id, int $requester_id, int $recipient_id )
do_action( 'buddynext_connection_withdrawn', int $connection_id, int $requester_id )

do_action( 'buddynext_block',   int $blocker_id, int $blocked_id )
do_action( 'buddynext_unblock', int $blocker_id, int $blocked_id )
```

---

## Members

```php
do_action( 'buddynext_member_registered',    int $user_id )
do_action( 'buddynext_member_updated',       int $user_id )
do_action( 'buddynext_member_suspended',     int $user_id, int $by_user_id )
do_action( 'buddynext_member_unsuspended',   int $user_id, int $by_user_id )
do_action( 'buddynext_onboarding_completed', int $user_id )
```

---

## Activity Feed

```php
do_action( 'buddynext_post_created', int $post_id, int $user_id, string $type )
// $type is one of: 'text', 'photo', 'file', 'link', 'poll', 'announcement',
// 'activity', 'media', 'discussion', 'job', 'share'. Listeners that need more
// post fields (content, privacy, space_id, etc.) should re-fetch by ID via
// buddynext_service('post_service')->get( $post_id ).

do_action( 'buddynext_post_updated', int $post_id, int $user_id )
do_action( 'buddynext_post_deleted', int $post_id, int $user_id )

do_action( 'buddynext_post_pinned',    int $post_id, int $user_id, string $context ) // context: 'profile'|'space'
do_action( 'buddynext_post_unpinned',  int $post_id, int $user_id, string $context )

do_action( 'buddynext_post_scheduled',   int $post_id, int $user_id, string $scheduled_at )
do_action( 'buddynext_post_published',   int $post_id, int $user_id ) // fires when scheduled post goes live

do_action( 'buddynext_post_bookmarked',   int $post_id, int $user_id )
do_action( 'buddynext_post_unbookmarked', int $post_id, int $user_id )

do_action( 'buddynext_post_shared', int $share_id, int $original_post_id, int $user_id )

do_action( 'buddynext_poll_voted', int $post_id, int $option_id, int $user_id )
```

---

## Reactions + Comments

```php
do_action( 'buddynext_reaction_added',   string $object_type, int $object_id, int $user_id, string $emoji )
do_action( 'buddynext_reaction_removed', string $object_type, int $object_id, int $user_id )
// $object_type: 'post' | 'comment' | 'mvs_media' | 'jt_discussion'

do_action( 'buddynext_comment_created', int $comment_id, string $object_type, int $object_id, int $user_id )
do_action( 'buddynext_comment_updated', int $comment_id, int $user_id )
do_action( 'buddynext_comment_deleted', int $comment_id, int $user_id )
```

---

## Spaces

```php
do_action( 'buddynext_space_created', int $space_id, int $creator_id )
do_action( 'buddynext_space_updated', int $space_id )
do_action( 'buddynext_space_deleted', int $space_id )

do_action( 'buddynext_space_member_joined',       int $space_id, int $user_id, string $role )
do_action( 'buddynext_space_member_left',         int $space_id, int $user_id )
do_action( 'buddynext_space_member_removed',      int $space_id, int $user_id, int $by_user_id )
do_action( 'buddynext_space_member_role_changed', int $space_id, int $user_id, string $old_role, string $new_role )

do_action( 'buddynext_space_join_requested', int $space_id, int $user_id )
do_action( 'buddynext_space_join_approved',  int $space_id, int $user_id, int $by_user_id )
do_action( 'buddynext_space_member_invited', int $user_id, int $space_id, int $inviter_id ) // ✅ Implemented
do_action( 'buddynext_space_join_rejected',  int $space_id, int $user_id, int $by_user_id )
// 🔲 Not yet fired — SpaceMemberService does not call this on rejection
```

---

## Notifications

```php
do_action( 'buddynext_notification_created', int $notification_id, int $recipient_id, string $type, array $data )
// $type examples: 'follow', 'connection_request', 'reaction', 'comment', 'mention', 'space_invite',
//                 'bn.new_message' (fired by WPMediaVerse bridge)
```

---

## Moderation

```php
do_action( 'buddynext_report_created',       int $report_id, string $object_type, int $object_id, int $reporter_id )
do_action( 'buddynext_content_removed',      string $object_type, int $object_id, int $by_user_id )
do_action( 'buddynext_user_warned',          int $user_id, int $by_user_id, string $reason )
do_action( 'buddynext_user_suspended',       int $user_id, int $by_user_id, string $reason )
do_action( 'buddynext_user_shadow_banned',   int $user_id, int $by_user_id )
do_action( 'buddynext_user_shadow_unbanned', int $user_id, int $by_user_id )
do_action( 'buddynext_appeal_submitted',     int $appeal_id, int $user_id )
do_action( 'buddynext_appeal_resolved',      int $appeal_id, int $user_id, string $decision )
```

---

## Search

```php
do_action( 'buddynext_index_object', string $object_type, int $object_id )
// Fired async via Action Scheduler. BuddyNext core handles indexing into bn_search_index.
// Addon bridges fire this after creating/updating addon content.

do_action( 'buddynext_index_hashtags', string $object_type, int $object_id, string $content )
// Fired by BuddyNext bridges after addon content is saved.
// BuddyNext core extracts #hashtags from $content and populates bn_post_hashtags.
// Bridges fire this — addon plugins do NOT call this directly.
```

---

## Pro Extension Filters (added 2026-05-20)

These filters expose the seams that BuddyNext Pro attaches to without re-implementing Free code.

### Feed

```php
// Filter paginated feed items before returning (all three feed methods + explore).
// Use: AI reranking, sponsored-post injection.
apply_filters( 'buddynext_feed_items', array $items, string $scope, int $viewer_id, array $args )
// $scope: 'home' | 'profile' | 'space'
// $args keys vary by scope: per_page, cursor, user_id | profile_user_id | space_id

// Filter query args before SQL is built (home, profile, space feeds).
// Use: Pro tier-based filtering.
apply_filters( 'buddynext_feed_query_args', array $args, string $scope, int $viewer_id )

// Filter the ORDER BY clause used by the home feed SQL.
// Use: Pro AI Feed ranking — swap chronological order for an affinity-weighted ordering.
// IMPORTANT: the returned string is embedded directly into SQL. Callers MUST return only
// hardcoded column references + direction keywords. Never include user-supplied data.
apply_filters( 'buddynext_feed_order_by', string $order_by, int $user_id, array $query_args )
// Default: 'created_at DESC, id DESC'

// Filter the max pinned-post count per scope.
// Use: Pro premium members get more pins.
apply_filters( 'buddynext_post_pin_limit', int $limit, ?int $space_id, int $user_id )
// Default: 1

// Filter the allowed reaction type slugs.
// Use: Pro adds custom reaction types.
apply_filters( 'buddynext_reaction_types', string[] $types )
// Default: ['like', 'love', 'haha', 'wow', 'sad', 'angry']
// Call via ReactionService::reaction_types() — never reference the const directly.
```

### Spaces

```php
// Filter whether a user may join or request membership in a space.
// Return false to block access (Pro gated spaces).
apply_filters( 'buddynext_can_join_space', bool $can, array $space, int $user_id, string $action )
// $action: 'join' | 'request'
// Default: true
```

### Notifications + Email

```php
// Filter whether a notification should be persisted at all.
// Return false to suppress silently (Pro AI fatigue detection).
apply_filters( 'buddynext_notification_should_send', bool $should, array $payload )
// Default: true

// Filter the deferred send time for a notification.
// Return an ISO timestamp string to schedule for later (Pro quiet-hours / digest).
// Free stores the value in the data payload but does not actively delay the insert.
apply_filters( 'buddynext_notification_send_at', ?string $send_at, array $payload )
// Default: null (send immediately)

// Filter the wp_mail() payload before dispatch.
// Return ['send' => false] to suppress (Pro broadcast capture).
apply_filters( 'buddynext_email_payload', array $payload, string $template_slug, array $context )
// $payload keys: to, subject, body, headers
```

### Moderation

```php
// Filter the final SafeguardService result.
// Return WP_Error to block (Pro keyword blocklist / ML scoring).
apply_filters( 'buddynext_safeguard_check', true|WP_Error $result, int $user_id, string $content, string $link_url )

// Filter the list of auto-actions to apply after a report row is inserted.
// Free returns [] (no auto-actions). Pro rules engine populates this.
apply_filters( 'buddynext_moderation_auto_actions', array $actions, array $report )
// $report keys: report_id, reporter_id, object_type, object_id, reason, space_id, notes
// Action shapes:
//   ['action' => 'remove',  'reason' => string]
//   ['action' => 'warn',    'user_id' => int, 'reason' => string]
//   ['action' => 'suspend', 'user_id' => int, 'reason' => string, 'duration_days' => int]

// Filter the logical columns advertised by the moderation queue UI.
// Free's v2 card layout does not iterate this list itself; the filter
// exists so Pro plugins that build parallel tabular admin tables (bulk
// moderation, exports) stay aligned with the canonical column set.
apply_filters( 'buddynext_mod_queue_columns', array<string, string> $columns )
// Default keys: reporter, reported, reason, severity, created, actions

// Fires inside each moderation-queue row's action cluster, before Free's
// built-in action buttons. Pro hooks here to inject bulk-select
// checkboxes or extra inline actions.
//
// Output is rendered verbatim inside a .bn-report-row__actions container —
// handlers MUST escape on output.
do_action( 'buddynext_mod_queue_row_actions', object $report )
// $report is the raw row from bn_reports (id, object_type, object_id,
// reason, report_count, strikes_count, created_at, suspended, ...).
```

### Profile

```php
// Filter the allowed profile field type slugs.
// Use: Pro adds custom field types (file, video, map, etc.).
apply_filters( 'buddynext_profile_field_types', string[] $types )
// Default: 15 built-in types (text, textarea, email, phone, url, social, number,
//           date, daterange, select, multiselect, radio, checkbox, toggle, rating)
// Call via ProfileFieldsManager::field_types() — never reference the const directly.

// Filter the human-readable labels for profile field types in the admin
// field builder. Pair with buddynext_profile_field_types so registered
// Pro slugs appear with a friendly name in the type dropdown.
apply_filters( 'buddynext_profile_field_type_labels', array<string, string> $labels )
// Default: 16 entries covering all 15 built-in types (Short Text, Long Text, ...).

// Filter the rendered HTML for a single profile-field value (front-end /
// block view). Free's default HTML is already escaped — handlers must
// return safe HTML. The block wraps the result in wp_kses_post() before
// emission, so allowed tags are limited to the WordPress post-content set.
//
// Use: Pro AdvancedFieldRenderer overrides the default escaped value with
// custom markup for date_extended, location, file, multi_select_advanced,
// number_advanced, and conditional types.
apply_filters( 'buddynext_profile_field_render', string $html, string $type, array $field, mixed $value, int $user_id )
// $field keys: id, field_key, label, type, options, is_required, visibility, value, group_name, ...

// Validate a profile-field value before persistence. Default: true (pass).
// Return a WP_Error to skip persisting that value (other fields in the
// same save_profile() call are unaffected).
//
// Use: Pro AdvancedFieldValidator enforces date format, location JSON
// shape, file MIME / size, number min/max, conditional trigger contracts.
apply_filters( 'buddynext_profile_field_validate', true|WP_Error $result, string $type, mixed $value, array $field, int $user_id )
// Fired in ProfileService::save_profile() for both flat and repeater fields.
```

```php
// Fires inside the per-type options block of the admin field-builder form,
// once for the edit-field panel (with the existing $field row) and once
// for the add-field panel (with $type='' and $field=[]).
//
// Use: Pro AdvancedFieldsAdmin emits <tr> rows for type-specific config —
// allowed MIME / max size for the `file` type, unit / min / max for
// `number_advanced`, trigger field / value for `conditional`, etc.
//
// Output is rendered verbatim — handlers MUST escape on output.
do_action( 'buddynext_profile_field_type_options', string $type, array $field )
```

### Search

```php
// Filter search query args before SQL is built.
// Complements buddynext_search_results (results side).
apply_filters( 'buddynext_search_query_args', array $args, string $query, int $viewer_id )
// Base $args keys: per_page, page, type, viewer_id
//
// Pro AdvancedSearchFilters injects the following optional keys (all consumed
// by Free's SearchService when present and type === 'user' / 'member'). Free
// only emits EXISTS subqueries for the keys that are present in the filtered
// args — Pro's normalization strips empty / invalid values before SearchService
// builds its SQL:
//   tier_slug          (string) — active subscription in bn_membership_tiers.slug
//   space_id           (int)    — active membership in bn_space_members
//   member_label       (string) — assignment in bn_member_label_assignments
//   joined_after       (Y-m-d)  — wp_users.user_registered >= %s
//   active_within_days (int)    — actor_id in bn_analytics_events within the window
//
// When Pro is inactive, none of these keys ever populate $args, so Free
// never references Pro-owned tables.
```

### Outbound

```php
// Filter the maximum number of outbound webhook endpoints a site may register.
// Free: 1. Pro sets PHP_INT_MAX.
apply_filters( 'buddynext_outbound_webhook_limit', int $limit )
// Default: 1
```

### White-label

```php
// Filter the plugin brand name shown in the UI.
// Call via Plugin::brand_name().
apply_filters( 'buddynext_brand_name', string $name )
// Default: 'BuddyNext'

// Filter the plugin brand logo URL shown in the UI.
// Call via Plugin::brand_logo_url().
apply_filters( 'buddynext_brand_logo_url', ?string $url )
// Default: null (use text / default icon)
```

### Real-time transport

```php
// Filter the active real-time transport.
// Call via TransportFactory::current() — never instantiate transports directly.
// The returned value must implement BuddyNext\Realtime\RealtimeTransport.
// A non-conforming return silently falls back to PollingTransport.
apply_filters( 'buddynext_realtime_transport', RealtimeTransport $transport )
// Default: new PollingTransport() (no-op — clients poll via REST)
//
// Pro use: Returns a WebSocket-backed transport (Soketi / Ratchet) so events
// are pushed to connected clients instantly instead of waiting for a poll cycle.
//
// Example — Pro WebSocket transport registration:
//   add_filter(
//       'buddynext_realtime_transport',
//       static fn() => new \BuddyNextPro\Realtime\WebSocketTransport( $config )
//   );
```

## Pro Extension Actions (added 2026-05-20)

```php
// Fires after a user's profile page is served to a different viewer.
// Does NOT fire for self-views. Use: Pro reach analytics.
do_action( 'buddynext_profile_viewed', int $profile_user_id, int $viewer_id )

// Fires for each post item in every feed response (home, profile, space, explore).
// Does NOT fire when viewer_id === 0 (anonymous visitors). Use: Pro post-reach stats.
do_action( 'buddynext_post_impression', int $post_id, int $viewer_id, string $surface )
// $surface: 'home_feed' | 'profile_feed' | 'space_feed' | 'explore_feed'

// Fires after SearchService::search() computes results and buddynext_search_results runs.
// Use: Pro saved searches, AI relevance signals.
do_action( 'buddynext_search_performed', string $query, int $viewer_id, array $args, array $results )
// $args keys: per_page, page, type, viewer_id
// $results keys: items[], total
```

---

## Filters Provided by BuddyNext

```php
// Inject tabs into a space nav bar.
apply_filters( 'buddynext_space_tabs', array $tabs, int $space_id )
// $tabs is a key→value map. Two value formats are supported:
//   string  — built-in BuddyNext tab; rendered as an internal ?bn_tab=<key> link.
//   array   — external-link tab; must contain 'label' (string) and 'url' (string).
//
// Example — add an external Forum tab when Jetonomy is linked:
//   add_filter( 'buddynext_space_tabs', function( $tabs, $space_id ) {
//       $tabs['forum'] = [ 'label' => 'Forum', 'url' => 'https://example.com/community/s/general/' ];
//       return $tabs;
//   }, 10, 2 );

// Inject extra stat blocks into the profile header stat row.
apply_filters( 'buddynext_profile_extra_data', array $extra, int $user_id )
// Each entry: [ 'label' => string, 'value' => string|int ]
// Entries with a missing 'label' or unset 'value' are silently skipped.
//
// Example — add a Discussions count from Jetonomy:
//   add_filter( 'buddynext_profile_extra_data', function( $extra, $user_id ) {
//       $count    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE author_id = %d AND status = 'publish'", $user_id ) );
//       $extra[]  = [ 'label' => 'Discussions', 'value' => $count ];
//       return $extra;
//   }, 10, 2 );

// Inject items into the main community nav bar (after Feed / Members / Spaces).
apply_filters( 'buddynext_nav_items', array $items )
// Each item: [ 'label' => string, 'url' => string, 'icon' => string (raw SVG), 'active' => bool ]
// 'label' and 'url' are required. Items missing either are silently skipped.
// 'active' sets aria-current="page" and the bn-nav-active CSS class.
//
// Example — Jetonomy Forum link (auto-active on any /community/* URL):
//   add_filter( 'buddynext_nav_items', function( $items ) {
//       $items[] = [ 'label' => 'Forum', 'url' => home_url( '/community/' ), 'active' => false ];
//       return $items;
//   } );

// Inject extra tabs into the per-space settings template.
apply_filters( 'buddynext_space_settings_tabs', array $nav_items, int $space_id )
// Each entry is keyed by tab slug. Value: [ 'icon' => string (Lucide slug in assets/icons/), 'label' => string ].
// Pair this filter with the `buddynext_space_settings_tab_content` action to render the tab body.
//
// Example — Pro adds a Brand tab:
//   add_filter( 'buddynext_space_settings_tabs', function( $tabs, $space_id ) {
//       $tabs['brand'] = [ 'icon' => 'palette', 'label' => 'Brand' ];
//       return $tabs;
//   }, 10, 2 );

// Render the body of the active per-space settings tab.
do_action( 'buddynext_space_settings_tab_content', string $active_tab, int $space_id )
// Listeners MUST guard on $active_tab to avoid leaking markup into Free's built-in tabs.

// Extend unified search results
apply_filters( 'buddynext_search_results', array $results, string $query, array $args )

// Deferral — addon sets its features as inactive (BuddyNext mode)
apply_filters( 'buddynext_deferral_flags', array $flags )
// e.g. WPMediaVerse bridge adds: $flags['mvs_follow'] = true; $flags['mvs_activity'] = true;
```

---

## WPMediaVerse Integration Filters (BuddyNext hooks these)

These filters live in WPMediaVerse. BuddyNext hooks them to integrate DM — listed here for completeness.

```php
// BuddyNext sets this to true → WPMediaVerse suppresses its own chat panel + nav link
apply_filters( 'mvs_buddynext_active', bool $active )

// BuddyNext injects bn_blocks check before a message can be sent
apply_filters( 'mvs_can_send_message', bool $allowed, int $sender_id, int $recipient_id )

// BuddyNext Pro verifies WebSocket availability for real-time DM
apply_filters( 'mvs_messaging_transport', object $transport )
```

---

## WPMediaVerse Actions (BuddyNext bridges these)

```php
do_action( 'mvs_message_sent',     int $message_id, int $conversation_id, int $sender_id, array $recipient_ids )
do_action( 'mvs_media_uploaded',   int $media_id, int $user_id, array $media_data )
do_action( 'mvs_media_deleted',    int $media_id )
do_action( 'mvs_reaction_added',   int $media_id, int $user_id, string $emoji )
do_action( 'mvs_comment_created',  int $comment_id, int $media_id, int $user_id )
do_action( 'mvs_favorite_toggled', int $media_id, int $user_id, string $action ) // action: 'added'|'removed'
do_action( 'mvs_mentions_created', array $mentioned_user_ids, string $context_type, int $context_id )
```

---

## Notes for Addon Developers

- **WBGamification** — hook `buddynext_post_created`, `buddynext_user_followed`, `buddynext_reaction_added`, `buddynext_space_member_joined` to award points.
- **Jetonomy** — hook `buddynext_space_created` to optionally auto-create a linked forum. Bridge fires `buddynext_index_hashtags` on `jetonomy_discussion_created`.
- **Career Board** — bridge fires `buddynext_index_hashtags` on `wp_cb_job_published`.
- **Custom addons** — do NOT hook `buddynext_index_hashtags` to fire it; that hook is fired BY bridges, not received by external code. To make your content's hashtags work, open a bridge PR to add your content type to `includes/Bridges/`.
