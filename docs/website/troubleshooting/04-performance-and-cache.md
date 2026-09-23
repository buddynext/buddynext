# Performance and Cache Issues

Problems that show up as a community grows: slow lists, stale-looking data, and weak rate limits.

## The feed, member directory, or notification counts feel slow as the community grows

**Symptom:** Pages that were fast with a few hundred members get noticeably slower as membership climbs into the thousands.

**Likely cause:** There is no persistent object cache installed. Without one, `wp_cache_*` values are request-local - they're computed, used once, and thrown away, so the same expensive lookups (unread counts, the member directory, the first page of the feed) are recomputed on every single request instead of being reused.

**Fix:**
1. Check whether you have one: **BuddyNext > Platform > Tools > Object cache** reports the status directly (and will warn you past a few thousand members if one is missing); or check **Tools > Site Health > Info > Caching** in wp-admin; or run `wp eval 'var_dump( wp_using_ext_object_cache() );'` - `true` means you have one, `false` means you don't.
2. Install a persistent object cache: **Redis Object Cache** if your host offers Redis (the common choice), **Memcached** if your host offers that instead, **SQLite Object Cache** if your host offers neither (still a real improvement over nothing), or **APCu** only on a genuine single-server setup.

This is a recommendation, not a strict requirement - BuddyNext works without one, it's just slower, and the gap widens with member count. See [Object Cache at Scale](../getting-started/09-object-cache-at-scale.md).

## Rate limits (comment flooding, sign-up throttling) don't seem to be working

**Symptom:** A member seems to be able to post far more comments per minute, or create far more accounts per hour, than the configured limit allows.

**Likely cause:** Rate limiting counts actions **across requests**, which requires a **persistent** object cache to share counters between them. Without one, there is nothing to count into between requests, so limits are far weaker than the configured number suggests - this is a correctness issue, not just a speed one.

**Fix:** Install a persistent object cache (see above). This is the same underlying cause as general slowness, but it's worth calling out separately because a weak rate limit can look like "the settings didn't take" rather than "there's no cache."

See [Object Cache at Scale](../getting-started/09-object-cache-at-scale.md).

## The notification bell or "new posts" pill is slow to update

**Symptom:** A member has to wait, or manually reload, to see a new notification or a new post appear.

**Explanation, not a bug (on Free):** The free plugin uses polling, not a live push connection. The notification bell checks every 30 seconds while idle (5 seconds for the first minute after the member takes an action), and the feed checks for new posts every 60 seconds. A delay within those windows is expected behavior, not a fault.

**Fix, if instant delivery matters:** BuddyNext Pro's **Real-time WebSocket** feature replaces polling with a live connection (via a Pusher-compatible server such as Sockudo) so notifications, feed activity, reactions, comments, and messages update the instant they happen. If Pro's realtime is enabled but still feels like polling, use the **Test connection** button on the Realtime settings tab to confirm the Host, App ID, Key, and Secret are correct and the server is reachable - when the server is unreachable, BuddyNext quietly falls back to polling with no error shown to members.

See [Near-Real-Time Updates](../engagement/03-realtime-updates.md) and [Real-time WebSocket](../pro/18-realtime-websocket.md).

## A companion plugin (SEO tool, page builder, forms plugin) slows down community pages specifically

**Symptom:** A plugin unrelated to the community loads and adds overhead specifically on the feed, spaces, or profile pages, even though it does nothing there.

**Fix:** Use **Plugin Isolation** (BuddyNext > Platform > Plugin isolation) to skip that plugin on community routes only - it is off by default and, once turned on, keeps every plugin loading until you explicitly tick one to skip, so nothing is silently disabled. This is a performance tool, not a security boundary, and it changes nothing on the rest of the site. See [Plugin Isolation](../getting-started/08a-plugin-isolation.md).

## Data still looks stale after switching Include in search off and back on for an integration

**Symptom:** Turning a companion integration's "Include in search" switch back on doesn't seem to bring old content back into search immediately.

**Explanation, not a bug:** Switching search off for an integration removes the content already in the search index, not just new content - that's deliberate, so a search switch that left old results behind wouldn't actually work as "off." Switching it back on re-indexes content as it is created or updated going forward, not instantly in bulk. If you need existing content re-indexed immediately after re-enabling search, check **Platform > Tools** for a reindex action, or wait for members to naturally interact with (update) that content.

See [Integrations Overview](../integrations/01-overview.md).

## Related

- [Object Cache at Scale](../getting-started/09-object-cache-at-scale.md)
- [Plugin Isolation](../getting-started/08a-plugin-isolation.md)
- [Near-Real-Time Updates](../engagement/03-realtime-updates.md)
- [Real-time WebSocket (Pro)](../pro/18-realtime-websocket.md)
