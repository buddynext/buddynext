# BuddyNext — Action Hook Reference

**Status:** Locked
**Last updated:** 2026-03-20
**Audience:** Addon developers (WBGamification, Jetonomy, WPMediaVerse, Career Board, custom integrations)

---

## How to Use This Reference

BuddyNext fires actions after every significant platform event. Addon plugins hook these to award points, sync data, send external notifications, or extend functionality.

**BuddyNext never calls addon code directly.** All integration is through hooks.

---

## Social Graph

```php
do_action( 'buddynext_follow', int $follower_id, int $following_id )
do_action( 'buddynext_unfollow', int $follower_id, int $following_id )

do_action( 'buddynext_connection_requested', int $connection_id, int $requester_id, int $recipient_id )
do_action( 'buddynext_connection_accepted',  int $connection_id, int $user_id_1,    int $user_id_2 )
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

do_action( 'buddynext_post_bookmarked',  int $post_id, int $user_id )
do_action( 'buddynext_post_unbookmarked',int $post_id, int $user_id )

do_action( 'buddynext_post_shared',     int $share_id, int $original_post_id, int $user_id )

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
do_action( 'buddynext_space_join_rejected',  int $space_id, int $user_id, int $by_user_id )
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
do_action( 'buddynext_report_created',  int $report_id, string $object_type, int $object_id, int $reporter_id )
do_action( 'buddynext_content_removed', string $object_type, int $object_id, int $by_user_id )
do_action( 'buddynext_user_warned',     int $user_id, int $by_user_id, string $reason )
do_action( 'buddynext_user_suspended',  int $user_id, int $by_user_id, string $reason )
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
//
// Examples (fired by BuddyNext bridges, not by addon plugins):
//   buddynext_index_hashtags( 'mvs_media',      $media_id,      $title . ' ' . $description )
//   buddynext_index_hashtags( 'jt_discussion',  $discussion_id, $title . ' ' . $body )
//   buddynext_index_hashtags( 'job',            $job_id,        $description )
//   buddynext_index_hashtags( 'post',           $post_id,       $content )   // BuddyNext core fires this itself
```

---

## Filters Provided by BuddyNext

```php
// Inject tabs into a space
apply_filters( 'buddynext_space_tabs', array $tabs, int $space_id )
// Each tab: [ 'id', 'label', 'icon', 'callback' ]

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
// BuddyNext bridge: add_filter( 'mvs_buddynext_active', '__return_true', 15 );

// BuddyNext injects bn_blocks check before a message can be sent
apply_filters( 'mvs_can_send_message', bool $allowed, int $sender_id, int $recipient_id )
// BuddyNext bridge returns false if $sender_id or $recipient_id is in bn_blocks

// BuddyNext Pro verifies WebSocket availability for real-time DM
apply_filters( 'mvs_messaging_transport', object $transport )
```

---

## WPMediaVerse Actions (BuddyNext bridges these)

```php
do_action( 'mvs_message_sent', int $message_id, int $conversation_id, int $sender_id, array $recipient_ids )
// BuddyNext bridge: creates bn_notifications entry (type: 'bn.new_message') for each recipient

do_action( 'mvs_media_uploaded', int $media_id, int $user_id, array $media_data )
// BuddyNext bridge: creates bn_posts entry (type: 'media') + fires buddynext_index_hashtags

do_action( 'mvs_media_deleted', int $media_id )
// BuddyNext bridge: removes the bn_posts entry

do_action( 'mvs_reaction_added',   int $media_id, int $user_id, string $emoji )
do_action( 'mvs_comment_created',  int $comment_id, int $media_id, int $user_id )
do_action( 'mvs_favorite_toggled', int $media_id, int $user_id, string $action ) // action: 'added'|'removed'
do_action( 'mvs_mentions_created', array $mentioned_user_ids, string $context_type, int $context_id )
// BuddyNext bridge: all of these create bn_notifications entries
```

---

## Notes for Addon Developers

- **WBGamification** — hook `buddynext_post_created`, `buddynext_follow`, `buddynext_reaction_added`, `buddynext_space_member_joined` to award points.
- **Jetonomy** — hook `buddynext_space_created` to optionally auto-create a linked forum. Bridge fires `buddynext_index_hashtags` on `jetonomy_discussion_created`.
- **Career Board** — bridge fires `buddynext_index_hashtags` on `wp_cb_job_published`.
- **Custom addons** — do NOT hook `buddynext_index_hashtags` to fire it; that hook is fired BY bridges, not received by external code. To make your content's hashtags work, open a bridge PR to add your content type to `includes/Bridges/`.
