# Notification Messages — Catalogue

> Single source of truth for every notification type fired by BuddyNext and the
> human-readable copy each one renders on the in-app list, the nav dropdown,
> and the transactional email body.
>
> Implementation: every entry below is encoded in
> `BuddyNext\Notifications\NotificationMessageService::compose()`. Adding a new
> notification type means adding a row here AND a case in the switch — no
> exceptions. The exhaustive switch makes the fallback "sent you a notification"
> string a bug indicator, not a default.
>
> i18n contract: every template is wrapped in `__()` with text domain
> `buddynext` and uses positional placeholders (`%1$s`, `%2$d`, ...) so
> translators can re-order tokens without breaking the message.

## Group-collapse rule

When `group_count > 1` the row is collapsed using the n-aware template:

> `{actor} and {N - 1} others {verb_phrase}.`

(e.g. "Aiko Tanaka and 3 others started following you.") Only the types listed
in the table with a `group` column ✓ collapse — singleton-by-nature types
(suspension, appeal resolved, badge awarded, etc.) ignore `group_count`.

## Live DB enumeration

Snapshot from `buddynext-dev.local` on 2026-05-22 confirming which types are
actually in the wild on the staging site:

| type slug                  | rows |
|----------------------------|-----:|
| `bn.space_join`            | 4    |
| `bn.new_follower`          | 3    |
| `bn.connection_requested`  | 2    |
| `bn.connection_accepted`   | 1    |
| `bn.post_reacted`          | 1    |
| `bn.post_commented`        | 1    |
| `bn.space_join_requested`  | 1    |
| `bn.space_request_approved`| 1    |
| `bn.strike_issued`         | 1    |
| `bn.test`                  | 1    |

Before this spec landed, only `bn.space_join_requested` rendered with real
copy on `/notifications/`. Everything else fell through to the fallback
`memberX sent you a notification.` string. F5 in
`docs/qa/PRODUCTION-READINESS.md` flagged this as a presentation-grade gap.

## Catalogue

The `template` column is the i18n key; the rendered string drops the
placeholders. The `link` column is what the row deep-links to when the user
clicks the row. The `email` column is the `wp_bn_email_templates.type` slug the
EmailDispatchListener will hand to EmailSender — `—` means the type is in-app
only.

### Social graph

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.new_follower` | `buddynext_user_followed` | `%1$s started following you.` | actor profile | `user-plus` | `bn.new_follower` | ✓ |
| `bn.connection_requested` | `buddynext_connection_requested` | `%1$s sent you a connection request.` | actor profile | `user-check` | `bn.connection_requested` |  |
| `bn.connection_accepted` | `buddynext_connection_accepted` | `%1$s accepted your connection request.` | actor profile | `users` | `bn.connection_accepted` |  |
| `bn.connection_declined` | `buddynext_connection_declined` | `%1$s declined your connection request.` | actor profile | `user-x` | `bn.connection_declined` |  |

### Feed activity

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.post_reacted` | `buddynext_reaction_added` | `%1$s reacted %2$s to your post.` | post permalink | `heart` | `bn.post_reacted` | ✓ |
| `bn.post_commented` | `buddynext_comment_created` | `%1$s commented on your post.` | post permalink | `bn.post_commented` | `message-circle` | ✓ |
| `bn.comment_reply` | `buddynext_comment_reply_created` | `%1$s replied to your comment.` | post permalink | `corner-down-right` | `bn.comment_reply` |  |
| `bn.post_shared` | `buddynext_post_shared` | `%1$s shared your post.` | post permalink | `repeat-2` | `bn.post_shared` | ✓ |
| `bn.mention` | `buddynext_user_mentioned` | `%1$s mentioned you in a post.` | post permalink | `at-sign` | `bn.mention` |  |
| `bn.bookmark_milestone` | `buddynext_post_bookmark_milestone` | `Your post has been bookmarked %1$d times.` | post permalink | `bookmark` | — |  |

### Spaces

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.space_join` | `buddynext_space_member_joined` | `%1$s joined %2$s.` | space home | `users` | `bn.space_join` | ✓ |
| `bn.space_invite` | `buddynext_space_member_invited` | `%1$s invited you to %2$s.` | space home | `mail-plus` | `bn.space_invite` |  |
| `bn.space_join_requested` | `buddynext_space_join_requested` | `%1$s requested to join %2$s.` | space settings | `door-open` | `bn.space_join_requested` |  |
| `bn.space_request_approved` (a.k.a. `bn.space_join_approved`) | `buddynext_space_join_approved` | `Your request to join %1$s was approved.` | space home | `check-circle` | `bn.space_join_approved` |  |
| `bn.space_join_declined` | `buddynext_space_join_declined` | `Your request to join %1$s was declined.` | space directory | `x-circle` | `bn.space_join_declined` |  |
| `bn.space_new_post` | `buddynext_post_created` (in space) | `%1$s posted in %2$s.` | post permalink | `home` | `bn.space_new_post` | ✓ |
| `bn.space_role_changed` | `buddynext_space_member_role_changed` | `Your role in %1$s changed to %2$s.` | space home | `shield` | `bn.space_role_changed` |  |
| `bn.bulk_invite` | `buddynext_bulk_invite_sent` | `You were invited to %1$d new spaces.` | spaces directory | `mail-plus` | — | ✓ |

### Messages

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.new_message` | bridge (WPMediaVerse / DM) | `%1$s sent you a message.` | conversation | `mail` | `bn.new_message` | ✓ |

### Moderation

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.user_warned` | `buddynext_user_warned` | `A moderator issued a warning about your activity.` | profile | `alert-triangle` | `bn.strike_warning` |  |
| `bn.strike_warning` | `buddynext_strike_warning_issued` | `You are close to an account strike. Please review the community guidelines.` | community guidelines | `alert-triangle` | `bn.strike_warning` |  |
| `bn.strike_issued` | `buddynext_strike_issued` | `Your account received a strike for a community guideline breach.` | profile | `alert-triangle` | `bn.strike_issued` |  |
| `bn.member_suspended` (a.k.a. `bn.suspension`) | `buddynext_member_suspended` | `Your account has been suspended.` | profile | `lock` | `bn.member_suspended` |  |
| `bn.user_unsuspended` | `buddynext_user_unsuspended` | `Your account has been reinstated.` | profile | `unlock` | `bn.user_unsuspended` |  |
| `bn.user_shadow_banned` | `buddynext_user_shadow_banned` | `Your account is under review. Some actions may be limited.` | profile | `eye-off` | — |  |
| `bn.appeal_submitted` | `buddynext_appeal_submitted` | `Your appeal was received and is under review.` | profile | `mail` | — |  |
| `bn.appeal_resolved` | `buddynext_appeal_resolved` | `Your appeal was reviewed: %1$s.` | profile | `check-circle` | `bn.appeal_resolved` |  |
| `bn.report_resolved` | `buddynext_report_resolved` | `Your report was reviewed. Thank you for keeping the community safe.` | profile | `shield-check` | — |  |

### Growth / system

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.badge_awarded` | `buddynext_badge_awarded` | `You earned a new badge: %1$s.` | profile | `award` | `bn.badge_awarded` |  |
| `bn.level_up` | `buddynext_level_up` | `You reached level %1$d.` | profile | `trending-up` | `bn.level_up` |  |
| `bn.onboarding_nudge` | `buddynext_onboarding_nudge` | `Finish setting up your profile to get the most out of the community.` | onboarding | `sparkles` | `bn.onboarding_nudge` |  |
| `bn.daily_digest` | `buddynext_daily_digest_ready` | `Your daily digest is ready.` | activity feed | `inbox` | `bn.daily_digest` |  |
| `bn.weekly_digest` | `buddynext_weekly_digest_ready` | `Your weekly digest is ready.` | activity feed | `inbox` | `bn.weekly_digest` |  |
| `bn.media_favorited` | bridge (WPMediaVerse favourites) | `%1$s favourited your media.` | post permalink | `heart` | `bn.media_favorited` | ✓ |
| `bn.jetonomy_reply` | bridge (Jetonomy) | `%1$s replied to your discussion.` | discussion permalink | `message-circle` | `bn.jetonomy_reply` |  |

### Development-only

| Type | Trigger hook | Template | Link | Icon | Email | Group |
|---|---|---|---|---|---|---|
| `bn.test` | `wp eval` / dev tooling | `Test notification.` | — | `bell` | — |  |

## Group-collapse copy

Groups use the same template family with an `_n()` plural so the second
clause is grammatically correct for any locale. Encoded inside
`NotificationMessageService::compose_grouped()`:

```
bn.new_follower    → "%1$s and %2$d others started following you."
bn.post_reacted    → "%1$s and %2$d others reacted to your post."
bn.post_commented  → "%1$s and %2$d others commented on your post."
bn.post_shared     → "%1$s and %2$d others shared your post."
bn.space_join      → "%1$s and %2$d others joined %3$s."
bn.space_new_post  → "%1$s and %2$d others posted in %3$s."
bn.new_message     → "%1$s and %2$d others messaged you."
bn.media_favorited → "%1$s and %2$d others favourited your media."
bn.bulk_invite     → "You were invited to %1$d new spaces."
```

## Sample previews

```
# Single
Aiko Tanaka started following you. — 2 min

# Grouped
Aiko Tanaka and 3 others started following you. — 14 min

# Reaction with emoji
Maria Lopez reacted love to your post. — 1h

# Space join (collapsed)
Aiko Tanaka and 2 others joined Open Discussion. — 3h

# Suspension
Your account has been suspended. — 2d
```

## Adding a new type

1. Add a row in the relevant table above with `template`, `link`, `icon`,
   `email` (or `—`), and `group` flag.
2. Add a case to `NotificationMessageService::compose_single()` and, if
   `group` is checked, `compose_grouped()`.
3. If the type fires a transactional email, add a row to
   `wp_bn_email_templates` keyed on the type slug.
4. Add a test in `tests/Notifications/NotificationMessageServiceTest.php`
   asserting the message + URL + icon.
5. Wire the trigger hook in `NotificationListener::register()` (or the
   relevant bridge listener) using `NotificationService::create()`.
