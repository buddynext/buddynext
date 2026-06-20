# BuddyNext Free - Code Flows

**Generated:** 2026-06-07 | **Version:** 0.2.0

Major request flows traced from HTTP entry point through controllers and services to storage. Class::method names and hook sites are taken from `audit/manifest.json` (rest.endpoints, services, hooks_fired) and verified against `includes/**`.

---

## Flow 1: Feed Compose (Create Post)

**Trigger:** `POST /buddynext/v1/posts` with `{type, content, privacy, space_id?, media_ids?, options?, scheduled_at?}`

```
REST request (permission_callback: require_auth)
  -> Feed\PostController::create_post(WP_REST_Request)
      -> sanitises type/content (wp_kses_post)/privacy/space_id/media/options/scheduled_at
      -> buddynext_service('post_service') -> Feed\PostService::create($user_id, $data)
          -> SafeguardService::check($content, ...)                # includes/Feed/PostService.php:116
              -> apply_filters('buddynext_safeguard_check', $result, ...)   # SafeguardService.php:75
              -> on 'pending_review' / flag -> returns WP_Error (no DB write)
          -> INSERT into bn_posts
          -> do_action('buddynext_post_created', $post_id, $user_id, $type)  # PostService.php:198
              -> Hashtags\HashtagListener (on post_created)
                  -> HashtagService::extract + index
                      -> INSERT/UPDATE bn_hashtags ; INSERT bn_post_hashtags
                      -> do_action('buddynext_hashtag_used', ...)            # HashtagListener.php:152
              -> Search\SearchIndexListener (on post_created)
                  -> Action Scheduler async (or inline) -> SearchService::index_post
                      -> INSERT/UPDATE bn_search_index
              -> Notifications listener -> mentions parsed (@user)
                  -> NotificationService::create(type='bn.mention', ...)     # see Flow 5
          -> for each @mention: do_action('buddynext_user_mentioned', ...)   # PostService.php:224
      -> PostService::get($post_id)
      -> WP_REST_Response(201, post_data)
```

Scheduled posts (`scheduled_at` set, cap `buddynext-feed/schedule-post`) are published later by cron `buddynext_publish_scheduled` (buddynext_1min), which also fires `buddynext_post_created` from includes/Core/CronService.php:316.

---

## Flow 2: Spaces (Join)

**Trigger:** `POST /buddynext/v1/spaces/{id}/join`

```
REST request (require_auth)
  -> Spaces\SpaceController::join_space($space_id)
      -> resolves space row; PermissionService::can($uid, 'buddynext-spaces/join', ['space_id'=>$id])
          -> Layer 1 hard-deny if is_space_banned()                # PermissionService.php:89
      -> Spaces\SpaceMemberService::join($space_id, $user_id)      # SpaceMemberService.php:58
          -> apply_filters('buddynext_can_join_space', true, $space, $uid, 'join')  # :77
          -> public space:
              -> INSERT IGNORE bn_space_members (role='member', status='active')      # :123
              -> do_action('buddynext_space_member_joined', $space_id, $uid, 'member') # :145
                  -> Notifications listener notifies space owner/mods
          -> private space: SpaceMemberService::request_join()
              -> apply_filters('buddynext_can_join_space', true, $space, $uid, 'request') # :178
              -> INSERT IGNORE bn_space_members (status='pending')   # :208
              -> do_action('buddynext_space_join_requested', $space_id, $uid)  # :223
                  -> Notifications listener notifies space owner/mods of request
      -> WP_REST_Response(membership_data)
```

Approval path: `POST /spaces/{id}/members/{user_id}/approve` -> `SpaceController::approve_request` -> `SpaceMemberService` fires `buddynext_space_join_approved` (:354) then `buddynext_space_member_joined` (:363).

---

## Flow 3: Profile (Update Own Profile)

**Trigger:** `PUT /buddynext/v1/me/profile`

```
REST request (require_auth)
  -> Profile\ProfileController::update_profile(WP_REST_Request)
      -> PermissionService::can($uid, 'buddynext-profile/edit-own')   # Abilities.php:25 / PermissionService.php:39
      -> Profile\ProfileService::save(...)
          -> per field: apply_filters('buddynext_field_sanitize', ...)   # FieldType.php:654
          -> UPSERT bn_profile_values (against bn_profile_fields / bn_profile_groups)
          -> recompute completion
              -> do_action('buddynext_profile_completion_changed', $uid, $pct)  # ProfileService.php:805
      -> ProfileController fires buddynext_index_user for search        # ProfileController.php:646
      -> WP_REST_Response(profile)
```

Admin edits a different user via `PUT /users/{id}/profile` -> `ProfileController::admin_update_profile` gated by `require_admin` (cap `buddynext-profile/edit-any`, Abilities.php:26). Avatar reads flow through `apply_filters('buddynext_avatar_url', ...)` (AvatarService.php:99). Profile views fire `buddynext_profile_viewed` (ProfileController.php:492).

---

## Flow 4: Social Graph (Follow a User)

**Trigger:** `POST /buddynext/v1/users/{id}/follow`

```
REST request (require_auth)
  -> SocialGraph\FollowController::toggle_follow($target_id)
      -> PermissionService::can($uid, 'buddynext-connections/follow')   # Abilities.php:40
      -> SocialGraph\FollowService::toggle($uid, $target_id)
          -> already following -> DELETE bn_follows row
              -> CounterService decrement followers
              -> do_action('buddynext_user_unfollowed', $uid, $target_id)   # FollowService.php:215
          -> not following, public target -> INSERT bn_follows
              -> do_action('buddynext_user_followed', $uid, $target_id)     # :143
              -> do_action('buddynext_follower_gained', $target_id, $uid)   # :155
              -> first ever follow -> do_action('buddynext_user_followed_first_time', ...) # :178
                  -> Notifications listener -> NotificationService::create(type='bn.new_follower')
          -> private target -> INSERT pending follow request
              -> do_action('buddynext_follow_requested', $uid, $target_id)  # :131
      -> WP_REST_Response({following, followers_count})
```

Connection (mutual) flow: `POST /users/{id}/connect` -> `ConnectionController::request_connection` -> `ConnectionService` INSERT bn_connections (pending) + `buddynext_connection_requested` (ConnectionService.php:123); acceptance via `/connect/accept` fires `buddynext_connection_accepted` (:178).

---

## Flow 5: Notification Dispatch

**Trigger:** any domain `do_action('buddynext_..._created/...')` whose listener calls `NotificationService::create()`.

```
Notifications\NotificationService::create($data)              # NotificationService.php:54
  -> dedupe check: if an equivalent unread notification exists
       -> do_action('buddynext_notification_created', $existing_id, $recipient_id, $data)  # :89
       -> return
  -> $should_send = apply_filters('buddynext_notification_should_send', true, $data)  # :107
       -> false -> abort (no row)
  -> $send_at = apply_filters('buddynext_notification_send_at', null, $data)          # :129
       -> non-null -> store deferred send_at on the row
  -> INSERT bn_notifications
  -> do_action('buddynext_notification_created', $notif_id, $recipient_id, $data)     # :168
       -> Notifications\EmailSender / EmailDispatchListener:
            -> NotificationPrefService::get_pref (SELECT bn_notification_prefs)
                 -> apply_filters('buddynext_notification_prefs', ...)   # NotificationPrefService.php:167
            -> immediate email:
                 -> apply_filters('buddynext_notification_message', ...)  # NotificationMessageService.php:366
                 -> apply_filters('buddynext_email_payload', ...)         # EmailSender.php:158
                 -> SELECT bn_email_templates ; wp_mail() ; INSERT bn_email_log
            -> digest pref:
                 -> do_action('buddynext_queue_email_digest', ...)        # EmailSender.php:70
```

Digests are flushed by cron `buddynext_daily_digest` (daily) and `buddynext_weekly_digest` (weekly).

---

## Flow 6: Moderation (Report Content)

**Trigger:** `POST /buddynext/v1/reports` with `{content_type, content_id, reason}`

```
REST request (require_auth)
  -> Moderation\ModerationController::create_report(WP_REST_Request)
      -> PermissionService::can($uid, 'buddynext-moderation/report')   # Abilities.php:42
      -> Moderation\ModerationService::create_report($data)
          -> INSERT bn_reports
          -> do_action('buddynext_report_created', $report_id, ...)          # ModerationService.php:121
              -> ModerationListener:
                  -> apply_filters('buddynext_moderation_auto_actions', [], ...)  # :138
                  -> auto 'hide_content':
                      -> do_action('buddynext_content_removed', $content_id, ...)  # :156
                  -> threshold reached -> escalate (UPDATE bn_reports status)
                  -> NotificationService::create for moderators (see Flow 5)
      -> WP_REST_Response(201, {report_id})
```

Downstream moderator actions reuse ModerationService: strike (`/users/{id}/warn`, `/strikes/...` -> `buddynext_strike_issued` :324), shadow-ban (`buddynext_user_shadow_banned` :715), appeals (`buddynext_appeal_submitted` :880 / `buddynext_appeal_resolved` :937). Suspensions are issued from admin Members (`buddynext_user_suspended`, includes/Admin/Members.php:303). Queue health is monitored by cron `buddynext_daily_queue_check`.

---

## Flow 7: Auth (Register + Verify)

**Trigger:** `POST /buddynext/v1/auth/register` (permission `__return_true`)

```
REST request (public)
  -> Auth\AuthController::register(WP_REST_Request)              # AuthController.php:358
      -> guard get_option('users_can_register')
      -> validate email / user_login / password / terms_agreed (422 on field errors)
      -> wp_create_user($login, $password, $email)
      -> Auth\VerificationService::create_token($user_id)        # AuthController.php:422
          -> INSERT bn_verify_tokens
          -> do_action('buddynext_send_verification_email', ...) # VerificationService.php:59
      -> wp_set_current_user($user_id)   # auto sign-in
      -> WP_REST_Response(user payload)

Verification click (token consumed):
  -> VerificationService verifies token
      -> do_action('buddynext_user_verified', $user_id)          # VerificationService.php:126
      -> Auth\VerificationListener: do_action('buddynext_email_verified', $user_id)  # VerificationListener.php:92
```

Login uses `AuthController::login` -> `wp_signon($creds, is_ssl())` (AuthController.php:327). Expired tokens are purged by cron `buddynext_cleanup_tokens` (daily).

---

## Flow 8: REST Permission Gate (every authenticated endpoint)

```
register_rest_route(..., 'permission_callback' => 'require_auth' | 'require_admin' | 'require_moderator' | 'require_manage_options')

ControllerBase::require_auth(WP_REST_Request)
  -> is_user_logged_in()
       -> false -> WP_Error(401)
  -> if a capability is mapped for the route:
       -> PermissionService::can(get_current_user_id(), $capability, $context)   # PermissionService.php:85
            -> Layer 1: $user->has_cap('manage_options') -> true                 # :95
            -> space-scoped: can_moderate_space() / can_manage_space()           # :97-102, :169
            -> Layer 2: passes_role_check() vs ROLE_MAP / ROLE_HIERARCHY         # :132
                 -> space context -> role from bn_space_members ; else bn_community_role usermeta
            -> Layer 3: has_active_grant() reads bn_ability_{slug} usermeta       # :321 (0 = never expires)
            -> Layer 4: apply_filters('buddynext_user_can', $result, $uid, $cap, $ctx)  # :121
       -> false -> WP_Error(403)
  -> return true
```

---

## Flow 9: Bridge Load

**Trigger:** `plugins_loaded:25` via `do_action('buddynext_load_bridges')` (fired from includes/Core/Plugin.php:286).

```
Plugin::init() schedules do_action('buddynext_load_bridges') at plugins_loaded:25
  -> Bridges\BuddyXBridge        (class_exists guard) -> avatar/nav/template overrides
  -> Bridges\WPMediaVerseBridge  (class_exists guard) -> media + DM
        -> on DM: do_action('buddynext_dm_sent'/'buddynext_dm_received', ...)   # WPMediaVerseBridge.php:407/447
  -> Bridges\GamificationBridge + GamificationBridgeListener
        -> community actions -> wb_gamification_event
        -> badge/level-up events -> NotificationService::create (BN notification)
  -> Bridges\JetonomyBridge      (class_exists guard) -> Discussions tab
        -> indexes Jetonomy posts -> do_action('buddynext_jetonomy_post_indexed', ...)  # JetonomyBridge.php:165
        -> mentions -> do_action('buddynext_user_mentioned', ...)               # :127
  -> Bridges\CareerBoardBridge   (class_exists guard) -> Career Board nav item
```

All bridges no-op when the companion plugin is inactive.

---

## Flow 10: Search Query + Index

**Trigger:** `GET /buddynext/v1/search?q=...&type=posts,users,spaces,hashtags`

```
REST request (public)
  -> Search\SearchController::search(WP_REST_Request)
      -> sanitises q ; resolves $types
      -> Search\SearchService::search($q, $types, $page, $per_page)
          -> per type: FULLTEXT MATCH(...) AGAINST on bn_search_index (LIKE fallback)
          -> merge + rank
          -> apply_filters('buddynext_search_results', $results, ...)   # SearchService.php:350
      -> WP_REST_Response({results, total, pages})

Index maintenance:
  -> Search\SearchIndexListener on buddynext_post_created / buddynext_index_user
      -> Action Scheduler async (or inline) -> SearchService::index_post / index_user
          -> UPSERT bn_search_index
  -> full rebuild: cron buddynext_reindex_all_cron -> SearchService::reindex_all_cron
      -> on completion: do_action('buddynext_reindex_complete')   # SearchIndexListener.php:341
```
