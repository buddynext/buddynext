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
do_action( 'buddynext_post_created', int $post_id, int $user_id, array $data )
// $data keys: type, content, privacy, space_id, media_ids, link_url

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

// Inject items into main navigation
apply_filters( 'buddynext_nav_items', array $items )

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
