# Hooks: Feed and Content

The action and filter seams for the activity feed and everything members post into it: posts, comments, reactions, polls, shares, bookmarks, and the composer template. This page is for developers building gamification plugins, moderation integrations, notification bridges, or theme extensions that react to feed activity or modify content before it is written. Every hook below is fired or applied by BuddyNext Free, so it is available without Pro. Several of them are the documented Free-to-Pro extension seams (`buddynext_post_pin_limit`, `buddynext_reaction_types`, `buddynext_reaction_meta`).

![The activity feed whose post, comment, reaction, and composer hooks are documented on this page](../images/community-activity-feed.png)

## Overview / Contract

- **Actions are notifications, not callbacks.** BuddyNext fires actions after the database write has committed. Listeners that need the full record should re-fetch by ID (for example `buddynext_service( 'post_service' )->get( $post_id )`); the actions pass IDs, not hydrated rows.
- **`*_before_save` filters transform or reject.** They run after the built-in safeguard checks and receive the candidate data array. Return a modified array to alter what is written, or return a `WP_Error` to reject the write outright. Only known columns are persisted; extra keys are ignored.
- **Actor vs recipient.** For engagement that targets someone else's content, BuddyNext fires an actor-perspective event (who did the thing) and a recipient-mirror alongside it (the content author). Recipient mirrors fire only when the actor is not the author. Gamification systems usually award the recipient.
- **Idempotent events fire once.** Bookmarks, shares, and votes use `INSERT IGNORE` against a uniqueness key, and their actions fire only when a row was actually created or changed. Duplicate calls are silent no-ops.
- **Render and `_part_*` template hooks echo at the call site.** Filters that return HTML (for example `buddynext_composer_tools`) default to an empty string, so BuddyNext renders nothing unless a plugin hooks. Hooked plugins must return escaped HTML.

## Post lifecycle

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_post_before_save` | filter | A post is about to be written on create or update, after safeguard checks | `array $data, int $user_id, int|null $post_id` (`$post_id` is null on create) |
| `buddynext_post_created` | action | A new post goes live (also fired for a share, with `$type = 'share'`) | `int $post_id, int $user_id, string $type` |
| `buddynext_post_updated` | action | A post is edited | `int $post_id, int $user_id, array $fields` (columns written this update) |
| `buddynext_post_deleted` | action | A post is deleted by its owner | `int $post_id, int $user_id` |
| `buddynext_post_approved` | action | A held post is approved by a moderator | `int $post_id, int $author` |
| `buddynext_post_rejected` | action | A held post is rejected by a moderator | `int $post_id, int $author, string $reason` |
| `buddynext_post_pin_limit` | filter | A user pins a post, to read the per-scope pin cap | `int $limit, int|null $space_id, int $user_id` (default `1`) |
| `buddynext_user_mentioned` | action | An `@username` mention in a post (or comment) resolves to a real user | `int $mentioned_user_id, int $mentioner_id, int $post_id` |

> **Note:** `buddynext_post_created` is the catch-all "a post exists now" event and fires for every post type, including shares. If you specifically want the share action with the original post ID, listen to `buddynext_post_shared` instead.

## Comments

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_comment_before_save` | filter | A comment is about to be written on create or update | `array $data, int $user_id, int|null $comment_id` (`$comment_id` is null on create; on update `$data` carries only `content`) |
| `buddynext_comment_created` | action | A new comment is created | `int $comment_id, string $object_type, int $object_id, int $user_id` |
| `buddynext_post_comment_received` | action | The author's post receives a comment from someone else (recipient mirror) | `int $comment_id, int $post_id, int $author_id, int $commenter_id` |
| `buddynext_comment_updated` | action | A comment is edited | `int $comment_id, int $user_id` |
| `buddynext_comment_deleted` | action | A comment is deleted | `int $comment_id, int $user_id` |

`$object_type` is the thing the comment is attached to (`'post'`, `'media'`, etc.). The post-counter and recipient-mirror logic only run when `$object_type === 'post'`.

## Reactions

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_reaction_added` | action | A reaction is added to an object | `string $object_type, int $object_id, int $user_id, string $emoji` |
| `buddynext_post_reaction_received` | action | The author's post receives a reaction from someone else (recipient mirror) | `int $post_id, int $author_id, int $reactor_id, string $emoji` |
| `buddynext_reaction_removed` | action | A reaction is removed from an object | `string $object_type, int $object_id, int $user_id, string $emoji` |
| `buddynext_reaction_types` | filter | The allowed reaction-type list is resolved | `string[] $types` (the owner-enabled subset of the canonical six) |
| `buddynext_reaction_meta` | filter | A reaction label/char/colour is resolved | `array $meta, string $slug` (`$meta` has `label`, `char`, `color`) |
| `buddynext_reactors_limit` | filter | The reactors list for an object is queried | `int $max, string $object_type, int $object_id` (default `100`) |

The canonical six reaction slugs are `like`, `love`, `haha`, `wow`, `sad`, `angry`. The site owner can disable a subset through Settings, and `buddynext_reaction_types` runs after that, so the list you receive is already the owner-enabled set. See the example below for adding a custom type.

## Polls, shares, and bookmarks

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_poll_vote_before_save` | filter | A poll vote is about to be written | `array $data, int $user_id` (`$data` has `user_id`, `post_id`, `option_id`) |
| `buddynext_poll_voted` | action | A poll vote is cast or switched (not on toggle-off) | `int $post_id, int $option_id, int $user_id` |
| `buddynext_share_before_save` | filter | A share is about to be written | `array $data, int $user_id` (`$data` has `user_id`, `post_id`, `content`) |
| `buddynext_post_shared` | action | A post is shared | `int $share_id, int $original_post_id, int $user_id` |
| `buddynext_post_bookmarked` | action | A post is bookmarked (first time only) | `int $post_id, int $user_id` |
| `buddynext_post_unbookmarked` | action | A bookmark is removed (only when a row was deleted) | `int $post_id, int $user_id` |

> **Note:** `buddynext_poll_voted` does not fire when a member clicks the same option again to remove their vote (toggle-off returns early). A vote switch to a different option fires once, after the new vote is recorded.

## Feed query and rendering

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_feed_query_args` | filter | Before a feed query runs (home, profile, space, explore) | `array $args, string $scope, int $viewer_id` (`$args` has `per_page`, `cursor`, `user_id`) |
| `buddynext_feed_order_by` | filter | The home feed `ORDER BY` clause is built | `string $order_by, int $user_id, array $query_args` |
| `buddynext_feed_items` | filter | A feed page is about to be returned | `array $items, string $scope, int $viewer_id, array $args` |
| `buddynext_post_impression` | action | Each item on a served feed page is rendered | `int $post_id, int $viewer_id, string $surface` (`home_feed`, `profile_feed`, `space_feed`, `explore_feed`) |

> **Warning:** `buddynext_post_impression` fires once per item per served page and can run dozens of times per request. Keep listeners cheap: defer counting work to Action Scheduler rather than writing to the database inside the hook.

## Composer template seams

The composer partial exposes wrapper hooks for adding tools and modals to the post box. These are template-level and run wherever the composer is rendered.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_part_composer_args` | filter | The composer args are assembled | `array $args` (`user_id`, `space_id`, and render flags) |
| `buddynext_part_composer_before` | action | Immediately before the composer markup | `array $args` |
| `buddynext_composer_tools` | filter | The composer toolbar row is rendered | `string $html, array $args` (return escaped HTML to append a tool button) |
| `buddynext_composer_modals` | action | After the composer, where tool modals belong | `array $args` |
| `buddynext_part_composer_after` | action | Immediately after the composer markup | `array $args` |

> **Note:** Every feed template partial (`post-actions`, `post-body`, `post-byline`, `post-comment-form`, and so on) also exposes the standard `buddynext_part_<name>_before`/`_after` actions and `buddynext_part_<name>_args`/`_classes` filters. They follow the same convention as the composer hooks and are the safe seams for theme overlays. See Hooks: Overview for the template-part naming contract.

## Examples

### Add a custom reaction type

`buddynext_reaction_types` adds the slug; `buddynext_reaction_meta` supplies its label (and optional emoji char / colour). The slug must be lowercase alphanumeric, and for a complete UI it needs an icon at `assets/icons/reaction-{slug}.svg` and a `--bn-reaction-{slug}` colour token. Adding the slug without the icon and CSS leaves the picker broken, so ship both.

```php
// 1. Register the slug.
add_filter( 'buddynext_reaction_types', function ( array $types ): array {
    $types[] = 'celebrate';
    return $types;
} );

// 2. Give it a human label (and optionally a fallback emoji char + colour).
add_filter( 'buddynext_reaction_meta', function ( array $meta, string $slug ): array {
    if ( 'celebrate' === $slug ) {
        $meta['label'] = __( 'Celebrate', 'my-addon' );
        $meta['char']  = '🎉';
        $meta['color'] = '#f5a623';
    }
    return $meta;
}, 10, 2 );
```

### Raise the pin cap for premium members

Free allows one pinned post per scope (a profile or a single space). Return a higher integer to lift the cap. `$space_id` is null for profile pins.

```php
add_filter( 'buddynext_post_pin_limit', function ( int $limit, ?int $space_id, int $user_id ): int {
    // Members in the 'premium' role may pin up to five posts per scope.
    if ( user_can( $user_id, 'bn_premium_member' ) ) {
        return 5;
    }
    return $limit;
}, 10, 3 );
```

### Award the post author when their post is reacted to

Listen to the recipient mirror, not the actor event, so you award the author rather than the reactor.

```php
add_action( 'buddynext_post_reaction_received', function ( int $post_id, int $author_id, int $reactor_id, string $emoji ): void {
    my_gamification_award_points( $author_id, 5, 'reaction_received' );
}, 10, 4 );
```

### Reject a post before it is written

Return a `WP_Error` from `buddynext_post_before_save` to block the write. The error surfaces in the REST response body.

```php
add_filter( 'buddynext_post_before_save', function ( $data, int $user_id, ?int $post_id ) {
    if ( null === $post_id && str_contains( (string) ( $data['content'] ?? '' ), 'banned-phrase' ) ) {
        return new WP_Error( 'blocked', __( 'That phrase is not allowed.', 'my-addon' ), [ 'status' => 422 ] );
    }
    return $data;
}, 10, 3 );
```

## Notes / gotchas

- **Free vs Pro.** Every hook on this page is fired by Free. The Pro-facing extension seams are `buddynext_post_pin_limit` (raise pin caps for tiers), `buddynext_reaction_types` / `buddynext_reaction_meta` (add reaction types), and the `buddynext_feed_query_args` / `buddynext_feed_order_by` / `buddynext_feed_items` trio (tier-based feed filtering, reranking, sponsored injection).
- **Re-fetch, do not assume.** Action listeners receive IDs. Hydrate via the relevant service (`post_service`, `comment_service`) instead of caching the data you wish had been passed.
- **`buddynext_post_created` fires for shares too.** Branch on `$type` if you only want native posts; use `buddynext_post_shared` for the share-specific signal with the original post ID.
- **Counter writes already happen.** BuddyNext updates `reaction_count`, `comment_count`, and `share_count` itself before firing these actions. Do not re-increment denormalised counters from a listener.
