# Caching Standard (Object Cache + Transients)

> Portable foundation for **every Wbcom plugin**. Nothing here is BuddyNext-specific
> except the reference file paths in the last section. Drop this into any plugin repo
> and follow it so caching stays **one convention, not ten**.
>
> Reference implementation + rationale: [`../plans/scale-readiness-100k.md`](../plans/scale-readiness-100k.md).
>
> **This standard codifies the pattern ~90% of BuddyNext services already use** (verified
> at code level: 25+ services declare `CACHE_GROUP`/`CACHE_TTL` constants; 71 inline
> `wp_cache_*` call sites). It is descriptive of the good code already here, not a
> migration mandate. Following it costs nothing for conformant services and tells new
> code exactly what to do.

## 1. The one principle

**One per-service caching shape, one naming convention, one invalidation discipline.**
A read path is either cached the canonical way or not cached at all — never a private
`wp_cache_*` dialect with a drifting group name. At 100k users any engineer must be able
to look at any service and know its group, its key, and where it gets busted.

## 2. The canonical pattern — per-service constants + inline `wp_cache_*`

This is the established shape. New services copy it verbatim.

```php
final class FollowService {
    private const CACHE_GROUP = 'buddynext_follows';   // buddynext_<domain>
    private const CACHE_TTL   = 600;                    // seconds, explicit

    public function follower_count( int $user_id ): int {
        $key = "follower_count_{$user_id}";
        $hit = wp_cache_get( $key, self::CACHE_GROUP );
        if ( false !== $hit ) {                          // false = miss (WP convention)
            return (int) $hit;
        }
        $count = $this->query_follower_count( $user_id );
        wp_cache_set( $key, $count, self::CACHE_GROUP, self::CACHE_TTL );
        return $count;
    }

    public function follow( int $follower_id, int $following_id ): void {
        $this->persist_follow( $follower_id, $following_id );
        // Bust EVERY key whose value this write changed, by explicit key:
        wp_cache_delete( "follower_count_{$following_id}", self::CACHE_GROUP );
        wp_cache_delete( "following_count_{$follower_id}", self::CACHE_GROUP );
        wp_cache_delete( "is_following_{$follower_id}_{$following_id}", self::CACHE_GROUP );
    }
}
```

- `false !== $hit` is the miss check (WordPress returns `false` on a miss).
- One service owns one group. The group is a namespace, not a flush handle (see §5).

> **Note on the central `CacheService`.** `includes/Core/CacheService.php` exists but is
> **not** the path services use (`remember()` has 0 callers). It plays two real,
> narrow roles only — keep it for those, don't route service caching through it:
> (1) the generic ad-hoc helper (`get`/`set`/`delete`/`remember( $key, $ttl, $callback )`
> — note the TTL-in-the-middle signature) injected into `MemberTypeService`, and
> (2) the **admin "Clear cache"** action (`ToolsTab` → `forget_group()`). Its typed
> methods (`get_notification_count()`, `get_trending_hashtags()`, …) are **dead
> duplicates** of each service's own cache and are slated for removal (see §8).

## 2b. Cache by access frequency, not by existence of a read path

**A read method is not a reason to cache.** Cache is justified by how often a value is
actually read *and re-hit* on a large/heavy site — not because a query exists. Caching a
value that is read once per request and is unique per user (a per-user share list, a
saved search) just fills the object cache with single-use keys: low hit rate, memory
churn, and eviction of the genuinely hot keys you wanted. That makes a heavy site *worse*,
not better.

Before caching any read, classify its access pattern (count the call sites — don't guess):

| Pattern | Example | Decision |
|---|---|---|
| **Global / shared** — same value for all users, re-read across requests | space categories, label catalogue, drip-sequence defs, trending | **CACHE (object cache, cross-request)** — high hit rate, pays off |
| **Hot aggregate** — expensive + repeated on a dashboard/refresh | analytics counts, profile-view stats | **CACHE (medium TTL)** even if per-admin |
| **Read many times in one request, unique per user/entity** | `can()` called repeatedly per page for the same (user,ability) | **MEMOIZE** — request-scoped static var; object cache adds little (won't be re-hit before TTL) |
| **Read once per request, rarely re-hit / admin-rare / one-shot** | a user's own share list on their profile, an admin list screen | **SKIP** — caching is pure overhead |

Rules that follow:
- **Global/shared > per-user** for object-cache value. Prefer caching one shared list over
  N per-user entries that each get one hit.
- **Within-request repetition → memoize** (static property), don't reach for `wp_cache_*`
  unless the value is also re-hit across requests.
- **Per-user single-use reads → don't cache.** If it's read once and won't be asked again
  before the TTL expires, the cache entry is wasted.
- When unsure, **measure the call count** (grep callers; is it in a loop / hot path / polled?)
  before adding a cache. A cache with no measured hit path doesn't ship.

## 3. Two mechanisms, one rule for choosing

| Mechanism | Use it for | Survives without Redis/Memcached? |
|---|---|---|
| **Object cache** (`wp_cache_*`) | Hot per-user / per-object reads served repeatedly (unread count, profile, feed page-1, follow state) | No — request-local on a vanilla install |
| **Transient** (`*_transient`) | Cross-request data that MUST survive without a persistent cache: rate-limit buckets, OAuth/FCM tokens, throttle gates, login/2FA codes | Yes — falls back to the options table |

**Choosing rule:** if losing the value between requests is *harmless* (it just recomputes),
use object cache. If losing it is *unsafe* (a rate limit resets, a token re-issues), use a
transient. **Never cache the same datum in both.** If a hot read also must survive without a
persistent cache, use the two-layer pattern in
[`BACKGROUND-JOBS.md` §3.1](./BACKGROUND-JOBS.md) (transient = source of truth, object cache
= within-request accelerator) — ONE store with two tiers, not two competing stores.

> Verified drift this kills: trending hashtags are cached in `HashtagService`
> (group `buddynext_hashtags`) **and** in `CacheService` (group `buddynext`,
> `get/set_trending_hashtags()`) — two stores, two TTLs. `HashtagService` is canonical;
> the `CacheService` copy is dead and gets removed.

## 4. Naming convention — non-negotiable

```
group:  buddynext_<domain>        (free)     e.g. buddynext_follows, buddynext_notifications
        buddynextpro_<domain>     (pro)      e.g. buddynextpro_membership
key:    <entity>_<id>[_<variant>]            e.g. follower_count_<id>, unread_<id>
ttl:    private const CACHE_TTL = <seconds>  (explicit, never a bare literal at the call)
```

- **Free is already uniform** (`buddynext_*`). Keep it.
- **Pro must converge** on `buddynextpro_<domain>`. Verified outliers to migrate:
  `SubscriptionService` GROUP `'buddynextpro'` (no domain), `EmbeddingProvider`
  `'buddynext_pro_embeddings'` (underscore variant), and any `'buddynext-pro'` (hyphen) or
  reuse of Free's `'buddynext'` group from Pro code. Migrating a group name is low-risk
  (cache is ephemeral) **but** the group constant and every `wp_cache_delete` in that
  service must change **together** in one commit, or invalidation silently breaks.
- One service owns one group. Never write into Free's `buddynext` group from Pro.

## 5. Invalidation — key-based, on every write path

- **Always delete by explicit key on write.** Do not rely on group flush for
  correctness: `wp_cache_flush_group()` only exists on Redis/Memcached and is a **silent
  no-op** on the default object cache. (`CacheService::forget_group()` wraps it for the
  admin "Clear cache" button only — that is a manual operator action, not a per-write
  invalidation path.)
- Every write method (create / update / delete) busts **every** key whose value it
  changed — list them explicitly (count keys, "is_*" keys, "*_by_type" keys, list keys).
- TTL is a backstop, not a substitute for invalidation. Analytics aggregates may lean on a
  short TTL with no explicit bust (document it); user-facing state may not.

## 6. Object cache is a hard requirement at scale — declare it

The unread-count cache, directory result cache, and feed page-1 cache only protect the
database when a **persistent object cache (Redis/Memcached)** is installed. Without one,
`wp_cache_*` is request-local and every poll/page hits the DB (≈3,300 unread COUNTs/sec at
100k users polling every 30s).

- **Document Redis/Memcached as a requirement** for communities past a few thousand active
  users.
- **Surface it in the Tools health check**: `wp_using_ext_object_cache()` — green when
  present, a clear warning when absent on a large install.

## 7. Per-read checklist (gate before shipping any cached read)

1. Is this a hot read (served repeatedly)? If not, don't cache it.
2. Object cache (recomputable) or transient (must-survive)? Never both.
3. `private const CACHE_GROUP = 'buddynext_<domain>'` / `'buddynextpro_<domain>'` + an
   explicit `CACHE_TTL`?
4. Inline `wp_cache_*` with `false !== $hit` miss check (the house pattern)?
5. Does every write path that changes this data `wp_cache_delete()` the exact keys?
6. Key-based bust (not group-flush) for correctness?
7. Degrades gracefully with no persistent object cache present?

## 8. BuddyNext reference implementation

| Pattern | File |
|---|---|
| Canonical per-service shape (copy this) | `includes/SocialGraph/FollowService.php`, `includes/Profile/ProfileService.php` |
| Per-user count cache + invalidate on write | `includes/Notifications/NotificationService.php` (`CACHE_GROUP buddynext_notifications`, TTL 30) |
| Two-layer (transient + object cache) hot read | `includes/Hashtags/HashtagService.php::get_trending()` |
| Generic helper + admin flush (narrow role) | `includes/Core/CacheService.php` (`remember($key,$ttl,$cb)`, `forget_group()`) |
| Pro convergence target (group + invalidation) | `buddynext-pro/includes/Membership/MembershipTierService.php`, `SubscriptionService.php` |
| Full audit, drift list + decisions | `docs/plans/scale-readiness-100k.md` |

### Known drift to retire (tracked in the plan doc)

- **Remove dead duplicate methods from `CacheService`** (`get/set/invalidate_notification_count`,
  `*_trending_hashtags`, `*_space_member_count`, `*_follow_counts`, `*_hashtag_autocomplete`)
  — every one is re-implemented in its owning service with that service's own group; the
  `CacheService` copies have no callers. Keep only the generic `get/set/delete/remember` +
  `forget_group`. (Verify "no callers" per method before deleting.)
- **Converge Pro group names** on `buddynextpro_<domain>` (3 verified outliers above).
- **Route currently-uncached hot reads** through the canonical pattern: `Core/CounterService`,
  `Core/PermissionService`, `Feed/PollService`, `Feed/ShareService`; Pro
  `AI/AiRankedFeedService`, `Analytics/AnalyticsService`, `Members/LabelService`.
