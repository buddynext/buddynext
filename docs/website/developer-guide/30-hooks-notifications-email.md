# Hooks: Notifications and Email

The action and filter seams for the notification lifecycle and the email system. This page is for developers adding a notification channel (push, SMS), suppressing or deferring notifications, capturing outbound email, or registering a new notification type that emails its recipient. Everything here is fired by BuddyNext Free; Pro push is the reference consumer.

## Overview / Contract

Every notification fans in through one method: `NotificationService::create( array $data )`. That method enforces a single, ordered pipeline so each notification passes the same gates regardless of which feature raised it.

```
NotificationService::create( $data )
  1. in-app preference check  -> buddynext_notification_force_on_site (filter, escape hatch)
  2. should-send gate         -> buddynext_notification_should_send   (filter, return false to drop)
  3. deferral resolution      -> buddynext_notification_send_at       (filter, schedule for later)
  4. write row (insert OR merge into an unread group row)
  5. fire                     -> buddynext_notification_created       (action)
       |- EmailDispatchListener -> EmailSender::send() -> email channel
       |- Pro PushDispatcher    -> push channel (priority 20)
```

Key contract rules:

- **One creation path, one created action.** Both the fresh-insert path and the group-merge path resolve the gate and deferral filters and then fire `buddynext_notification_created` with the same payload shape. A listener does not need to know whether a row was inserted or merged.
- **`$data` is the payload everywhere.** The `$data` array passed to `create()` is the same array handed to `buddynext_notification_should_send`, `buddynext_notification_send_at`, and the third argument of `buddynext_notification_created`. It carries at least `recipient_id` and `type`, plus optional `sender_id`, `object_type`, `object_id`, `group_key`, and a nested `data` array.
- **Returning 0 means nothing was sent.** If a preference suppresses the notification, the should-send gate returns false, or the write fails, `create()` returns `0` and never fires the created action. Channels (email, push) only run when a row actually exists.
- **Channels are listeners, not core branches.** The email channel is `EmailDispatchListener` hooked on `buddynext_notification_created` at priority 10. Pro's push channel is a separate listener on the same action at priority 20. Adding a channel means adding a listener, not editing core.

## Notification lifecycle hooks

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_notification_should_send` | filter | Before any write, deciding whether to persist the notification | `bool $should, array $payload` |
| `buddynext_notification_send_at` | filter | After the should-send gate passes, resolving deferred delivery | `?string $send_at, array $payload` |
| `buddynext_notification_force_on_site` | filter | When checking the recipient's in-app preference, to force-send a critical type | `bool $force, int $recipient_id, string $type, array $data` |
| `buddynext_notification_created` | action | After a notification row is inserted or merged into an unread group | `int $notification_id, int $recipient_id, array $data` |

Details:

- `buddynext_notification_should_send` defaults to `true`. Return `false` to drop the notification silently before it reaches the database or any channel. Pro AI fatigue detection uses this to suppress low-signal notifications.
- `buddynext_notification_send_at` defaults to `null` (send now). Return a MySQL / ISO 8601 datetime string to mark the notification for deferred delivery. Free stores the value in the row's `data` JSON; Pro acts on it for quiet-hours and digest batching.
- `buddynext_notification_force_on_site` defaults to `false`. Unknown or system types already default to on-site = true, so critical notices are never suppressed by an absent preference; this filter is the escape hatch for forcing a normally-opt-out type through.
- `buddynext_notification_created` is the canonical "a notification happened" signal. The `$type` lives at `$data['type']`. Note the parameter order: `$notification_id, $recipient_id, $data` (the data array, not a bare type string).

## Preference hooks

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_notification_prefs` | filter | Resolving a user's full preference map | `array[] $prefs, int $user_id` |
| `buddynext_notification_prefs_catalogue` | filter | Building the list of notification types shown on the preferences screen | `array $catalogue` |
| `buddynext_notification_prefs_before` | action | Rendering the top of the notification preferences template | `int $user_id` |
| `buddynext_notification_prefs_after` | action | Rendering the bottom of the notification preferences template | `int $user_id` |

`buddynext_notification_prefs` is how a channel plugin injects its own preference rows without modifying Free. The map is keyed by notification type; each entry follows Free's shape (at minimum `{ on_site: bool, email_freq: string }`) plus any channel keys your plugin owns. Pro push attaches here:

```php
// Pro PushPrefService registers on Free's filter.
add_filter( 'buddynext_notification_prefs', function ( array $prefs, int $user_id ): array {
    foreach ( $prefs as $type => &$row ) {
        $row['push_enabled'] = my_push_pref( $user_id, $type ); // bool
    }
    return $prefs;
}, 10, 2 );
```

## Email hooks

The email channel is driven by `EmailSender`. Event emails render from a `bn_email_templates` row keyed by notification type; a composed email (a campaign or drip step that carries its own subject and body) bypasses the template row. Both paths converge on one `wp_mail()` call wrapped by the same identity and shell.

| Hook | Type | Fired when | Parameters |
|---|---|---|---|
| `buddynext_email_payload` | filter | Immediately before `wp_mail()`, after subject/body/headers are assembled | `array $payload, string $template_slug, array $context` |
| `buddynext_email_shell` | filter | Wrapping the email body in the branded HTML shell | `string $html` (and shell context) |
| `buddynext_queue_email_digest` | action | A notification is routed to a digest queue instead of an immediate send | `int $user_id, string $notification_type, array $data` |
| `buddynext_send_notification_email` | action | Action Scheduler callback to send a notification email asynchronously | `int $user_id, string $notification_type, array $data` |

Details:

- `buddynext_email_payload` receives `$payload` with keys `to`, `subject`, `body`, `headers`. Return the array to modify recipients, subject, or body. Return an array with `'send' => false` to suppress the `wp_mail()` call entirely - Pro broadcast and drip use this to capture the message for batched campaign delivery rather than sending inline. `$template_slug` is the notification type (matches `bn_email_templates.type`); `$context` is the original `$data` array.
- Sender identity is centralized: `EmailSender::from_name()` and `from_address()` fall back to the site name and admin email when Settings -> Email is blank, and `build_identity_headers()` applies the configured Reply-To as a per-message header so it survives any `wp_mail_from` override.
- The digest path (`buddynext_queue_email_digest`) appends to a per-user, per-frequency user-meta queue (`buddynext_digest_queue_{freq}`) that a daily or weekly cron later batches. A member's per-type `email_freq` preference (`immediate`, `daily`, `weekly`, `off`) decides whether an event emails immediately, queues for digest, or is suppressed.

## Examples

### Register a new notification type with its own listener

Raise a notification through the canonical service so it inherits the full pipeline (preferences, gating, grouping, email + push channels). You do not register a listener to *receive* the created action for your own type; you call `create()` and the existing channel listeners deliver it. Add an email template row for the type if you want it to email.

```php
// 1. Raise the notification from wherever your event commits.
add_action( 'my_plugin_mentor_assigned', function ( int $mentee_id, int $mentor_id ): void {
    buddynext_service( 'notification_service' )->create( [
        'recipient_id' => $mentee_id,
        'sender_id'    => $mentor_id,
        'type'         => 'mentor_assigned',      // your custom type slug
        'object_type'  => 'user',
        'object_id'    => $mentor_id,
        'group_key'    => 'mentor_assigned_' . $mentee_id, // optional: merge bursts
        'data'         => [ 'mentor_id' => $mentor_id ],
    ] );
}, 10, 2 );

// 2. (Optional) Provide the human-readable copy for in-app + email + push.
add_filter( 'buddynext_notification_message', function ( $message, string $type, array $row ) {
    if ( 'mentor_assigned' === $type ) {
        $message['title'] = __( 'You have a new mentor', 'my-plugin' );
        $message['body']  = __( 'A mentor was assigned to you.', 'my-plugin' );
    }
    return $message;
}, 10, 3 );
```

Because the notification flows through `create()`, the in-app row, the email (if a template or message exists), and Pro push all dispatch from the single `buddynext_notification_created` action - no per-channel wiring on your side.

### Suppress a notification with buddynext_notification_should_send

Drop a notification before it is written or emailed. This runs in both the insert and group-merge paths, so a suppressed type never leaks through grouping.

```php
add_filter( 'buddynext_notification_should_send', function ( bool $should, array $payload ): bool {
    // Stop low-signal 'reaction' notifications during a recipient's quiet window.
    if ( 'reaction' === ( $payload['type'] ?? '' ) && my_is_in_quiet_hours( (int) $payload['recipient_id'] ) ) {
        return false; // create() returns 0; no row, no email, no push.
    }
    return $should;
}, 10, 2 );
```

To defer rather than drop, return a future datetime from `buddynext_notification_send_at` instead:

```php
add_filter( 'buddynext_notification_send_at', function ( ?string $send_at, array $payload ): ?string {
    if ( my_is_in_quiet_hours( (int) $payload['recipient_id'] ) ) {
        return gmdate( 'Y-m-d H:i:s', strtotime( 'tomorrow 8:00' ) );
    }
    return $send_at;
}, 10, 2 );
```

## Notes / gotchas

- **`buddynext_notification_created` carries the data array, not a type string.** The third argument is the full `$data` payload; read the type from `$data['type']`. Some older integration snippets pass a bare `$type` as the third arg - the current signature is `( int $notification_id, int $recipient_id, array $data )`.
- **Channels run at distinct priorities.** Email dispatch is priority 10, Pro push is priority 20, both on `buddynext_notification_created`. Pick a priority that does not collide if you add another channel.
- **Suppression vs deferral are different filters.** `buddynext_notification_should_send` drops permanently; `buddynext_notification_send_at` schedules. Do not use a should-send return of false expecting a retry.
- **Composed emails bypass template rows.** A disabled `bn_email_templates` row suppresses its own event email but never a composed campaign or drip email, which carries its own subject and body through `buddynext_email_payload`. Suppressing campaign mail is the `'send' => false` return, not a template toggle.
- **Free vs Pro.** Free fires every hook on this page and ships the email channel. Pro push (`PushDispatcher` on `buddynext_notification_created`, `PushPrefService` on `buddynext_notification_prefs`) is the reference example of adding a channel without touching Free. For the social and engagement actions that typically raise notifications, see Hooks: Members, Profiles, and Social Graph.
