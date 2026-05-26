# BuddyNext Free - Code Flows

**Generated:** 2026-05-20 | **Version:** 0.2.0

Major request flows traced from HTTP entry point through services to storage.

---

## Flow 1: Post Creation

**Trigger:** `POST /buddynext/v1/posts` with `{content, type, space_id?}`

```
REST request
  → PostController::create_post()
      → SafeguardService::check($content)          # keyword filter, rate limit
          → apply_filters('buddynext_safeguard_check', result, context)
      → PermissionService::can($user_id, 'buddynext-feed/create-post')
          → checks WP manage_options (layer 1)
          → checks community role >= 'member' (layer 2)
          → checks bn_ability_{slug} user_meta for explicit grant (layer 3)
          → apply_filters('buddynext_user_can', result, user_id, capability) (layer 4)
      → PostService::create($data)
          → INSERT into bn_posts
          → HashtagListener::on_post_created()
              → HashtagService::extract_and_index($post_id, $content)
                  → INSERT/UPDATE bn_hashtags
                  → INSERT bn_post_hashtags
          → do_action('buddynext_post_created', $post_id, $user_id)
              → NotificationListener::on_post_created()
                  → NotificationService::create(type='bn.mention') for @mentions
                  → do_action('buddynext_notification_created', $notification_id, $data)
                      → EmailDispatchListener::on_notification_created()
                          → NotificationPrefService::should_send($user_id, 'bn.mention')
                          → EmailSender::send($user_id, 'bn.mention', $payload)
              → SearchIndexListener::on_post_created()
                  → dispatch async to Action Scheduler (or inline if AS absent)
                  → SearchService::index_post($post_id)
                      → INSERT/UPDATE bn_search_index
      → WP_REST_Response(201, post_data)
```

---

## Flow 2: Follow a User

**Trigger:** `POST /buddynext/v1/users/{id}/follow`

```
REST request
  → FollowController::toggle_follow($user_id, $target_id)
      → PermissionService::can($user_id, 'buddynext-connections/follow')
      → FollowService::toggle($user_id, $target_id)
          → SELECT bn_follows WHERE follower_id=? AND followee_id=?
          → if exists: DELETE row (unfollow)
              → CounterService::decrement('followers', $target_id)
              → do_action('buddynext_user_unfollowed', $user_id, $target_id)
          → if absent: INSERT row (follow)
              → CounterService::increment('followers', $target_id)
              → do_action('buddynext_user_followed', $user_id, $target_id)
                  → NotificationListener::on_user_followed()
                      → NotificationService::create(type='bn.new_follower', $target_id)
                      → EmailDispatchListener dispatches email if prefs allow
      → WP_REST_Response(200, {following: bool, followers_count: int})
```

---

## Flow 3: Space Join

**Trigger:** `POST /buddynext/v1/spaces/{id}/join`

```
REST request
  → SpaceController::join($space_id, $user_id)
      → SpaceService::get($space_id)     # ensure space exists
      → PermissionService::can($user_id, 'buddynext-spaces/join')
      → apply_filters('buddynext_can_join_space', true, context)   # gated-space hook
      → SpaceService::get_privacy($space_id)
      → if privacy = 'public':
          → SpaceMemberService::add_member($space_id, $user_id, 'member')
              → INSERT bn_space_members
              → do_action('buddynext_space_member_joined', $space_id, $user_id)
                  → NotificationListener notifies space admins
      → if privacy = 'private':
          → SpaceMemberService::create_join_request($space_id, $user_id)
              → INSERT bn_space_members (status='pending')
              → do_action('buddynext_space_join_requested', $space_id, $user_id)
                  → NotificationListener notifies space admins of new request
      → WP_REST_Response(200|201, membership_data)
```

---

## Flow 4: REST Permission Gate

**How every authenticated REST endpoint resolves permission:**

```
register_rest_route(..., 'permission_callback' => [$this, 'require_auth'])

ControllerBase::require_auth(WP_REST_Request $request)
  → is_user_logged_in()
      → false: return WP_Error(401, 'rest_forbidden')
      → true:
          → $capability = $this->get_required_capability()    # e.g. 'buddynext-feed/create-post'
          → if $capability:
              → PermissionService::can(get_current_user_id(), $capability, $context)
                  → Layer 1: current_user_can('manage_options') → return true
                  → Layer 2: ROLE_MAP[$capability] → check community role from bn_space_members / wp_usermeta
                  → Layer 3: get_user_meta(user_id, bn_ability_{slug}) → check expires_ts (0 = never, else compare against time())
                  → Layer 4: apply_filters('buddynext_user_can', $result, $user_id, $capability)
              → false: return WP_Error(403, 'rest_forbidden')
          → return true
```

---

## Flow 5: Notification Dispatch

**Trigger:** `do_action('buddynext_post_created', $post_id, $user_id)`

```
NotificationListener::on_post_created($post_id, $user_id)
  → PostService::get_mentions($post_id)       # parse @username from content
  → foreach $mentioned_user:
      → NotificationService::create([
            'type'        => 'bn.mention',
            'user_id'     => $mentioned_user_id,
            'actor_id'    => $user_id,
            'object_id'   => $post_id,
            'object_type' => 'post',
        ])
          → apply_filters('buddynext_notification_should_send', true, $data)
          → if should_send:
              → $send_at = apply_filters('buddynext_notification_send_at', now(), $data)
              → INSERT bn_notifications
              → do_action('buddynext_notification_created', $id, $data)

do_action('buddynext_notification_created', $id, $data)
  → EmailDispatchListener::on_notification_created($id, $data)
      → NotificationPrefService::get_pref($user_id, 'bn.mention')
          → SELECT bn_notification_prefs WHERE user_id=? AND event_type=?
      → if pref allows immediate email:
          → EmailSender::send($user_id, $data['type'], $payload)
              → apply_filters('buddynext_email_payload', $payload, $type)
              → SELECT bn_email_templates WHERE type=?
              → render template with Mustache-style {{vars}}
              → wp_mail($to, $subject, $body)
              → INSERT bn_email_log
      → if pref is 'digest':
          → do_action('buddynext_queue_email_digest', $user_id)
```

---

## Flow 6: Search

**Trigger:** `GET /buddynext/v1/search?q=foo&type=posts,users`

```
SearchController::search(WP_REST_Request $request)
  → $query = sanitize_text_field($request->get_param('q'))
  → $types = $request->get_param('type') ?? ['posts','users','spaces','hashtags']
  → SearchService::search($query, $types, $page, $per_page)
      → foreach $type in $types:
          → run FULLTEXT MATCH($query) AGAINST on bn_search_index WHERE content_type=?
          → OR fallback to LIKE search on title if FULLTEXT unavailable
      → $results = merge and rank by relevance score
      → apply_filters('buddynext_search_results', $results, $query)
  → WP_REST_Response(200, {results: [...], total: int, pages: int})

Search Index Update (triggered by post creation):
  → SearchIndexListener::on_post_created($post_id)
      → if Action Scheduler available:
          → as_schedule_single_action(time(), 'bn_async_index_post', [$post_id])
      → else:
          → SearchService::index_post($post_id) synchronously
              → INSERT INTO bn_search_index
                  (content_type='post', object_id=$post_id, title=..., content=..., meta=JSON)
                  ON DUPLICATE KEY UPDATE ...
```

---

## Flow 7: Hashtag Indexing

**Trigger:** `do_action('buddynext_post_created', $post_id, $user_id)`

```
HashtagListener::on_post_created($post_id, $user_id)
  → PostService::get_content($post_id)
  → HashtagService::extract_and_index($post_id, $content)
      → preg_match_all('/#([a-zA-Z0-9_\x{0080}-\x{FFFF}]+)/u', $content, $matches)
      → foreach $tag_slug:
          → SELECT bn_hashtags WHERE slug=?
          → if not exists: INSERT bn_hashtags (slug, post_count=1)
          → if exists: UPDATE bn_hashtags SET post_count = post_count + 1
          → INSERT IGNORE bn_post_hashtags (post_id, hashtag_id)
      → do_action('buddynext_jetonomy_post_indexed', $post_id)   # notify Jetonomy bridge
```

---

## Flow 8: Moderation Report

**Trigger:** `POST /buddynext/v1/reports` with `{content_type, content_id, reason}`

```
ModerationController::create_report($request)
  → PermissionService::can($user_id, 'buddynext-moderation/report')
  → ModerationService::create_report($data)
      → SafeguardService::check_report_rate($user_id)   # rate-limit check
      → INSERT bn_reports
      → do_action('buddynext_report_created', $report_id, $data)
          → ModerationListener::on_report_created()
              → apply_filters('buddynext_moderation_auto_actions', [], $report_data)
              → if auto_actions include 'hide_content':
                  → PostService::mark_pending_review($content_id)
                  → do_action('buddynext_content_removed', $content_id, 'pending_review')
              → SELECT COUNT(*) FROM bn_reports WHERE content_id=? and status='open'
              → if count > threshold:
                  → auto-escalate (update bn_reports SET status='escalated')
              → NotificationService::notify_moderators($report_id)
  → WP_REST_Response(201, {report_id: int})
```

---

## Flow 9: Bridge Load

**Trigger:** `plugins_loaded:25` via `buddynext_load_bridges` action

```
Plugin::init() registers:
  add_action('plugins_loaded', fn() => do_action('buddynext_load_bridges'), 25)

do_action('buddynext_load_bridges')
  → BuddyXBridge::init()
      → class_exists('BuddyX\...') check
      → if active: register BuddyX-specific avatar filters, template hooks
  → WPMediaVerseBridge::init()
      → class_exists('WPMediaVerse\Core\Plugin') check
      → if active: add Media page to BuddyNext nav
  → GamificationBridge::init()
      → class_exists check for gamification plugin
      → if active: wire community actions to wb_gamification_event
  → GamificationBridgeListener::register()
      → add_action('wb_gamification_badge_awarded', ...) → create BuddyNext notification
      → add_action('wb_gamification_level_up', ...) → create BuddyNext notification
  → JetonomyBridge::init()
      → class_exists('Jetonomy\Jetonomy') check
      → if active: add Discussions tab to BuddyNext nav
  → JetonomyBridgeListener::register()
      → add_action('jetonomy_post_created', ...) → index in BuddyNext search
  → CareerBoardBridge::init()
      → class_exists check
      → if active: add Career Board to nav
```
