# Object Cache at Scale

Why a persistent object cache matters once your community grows, what runs slower without one, and how to tell whether your site has one.

## Overview / Contract

BuddyNext caches the expensive things - unread counts, the member directory, the first page of the feed, rate-limit counters - through the WordPress object cache.

Without a **persistent** object cache, `wp_cache_*` is request-local. Every cached value is computed, used once, and thrown away at the end of the request. The caching code still runs and is still correct; it just never gets to save you a query on the *next* request.

That is fine for a small community and expensive for a large one.

**This is a recommendation, not a requirement.** BuddyNext works without a persistent object cache. It is simply slower, and the gap widens with the number of active members.

## The number that makes the case

Unread notification counts are polled while a member has the site open. At 100,000 members polling every 30 seconds, that is roughly **3,300 `COUNT` queries per second** from unread counts alone - every one of them a query that a persistent object cache would have answered from memory.

Nothing about that is unique to BuddyNext. It is what any community platform looks like at that size without shared caching.

## What is affected without one

| Area | Without a persistent cache |
|---|---|
| Unread counts | Recomputed on every request, for every member |
| Member directory | Result sets re-queried instead of reused |
| Feed page 1 | The hottest page in the product, recomputed per request |
| Rate limiting | Degrades - counters cannot be shared between requests |

The rate-limiting row is worth calling out, because it is a correctness point rather than a speed one. Rate limits count actions across requests. With a request-local cache there is nothing to count in, so limits are far weaker than they look. `Core/RateLimiter` checks for a persistent cache in several places for exactly this reason.

## Do I have one?

**In BuddyNext:** Settings → Tools → Object cache. The panel reports the status directly, and past a few thousand members it will tell you if one is missing.

**In WordPress:** Tools → Site Health → Info → Caching.

**With WP-CLI:**

```bash
wp eval 'var_dump( wp_using_ext_object_cache() );'
```

`true` means a persistent object cache is installed. `false` means WordPress is using its request-local fallback.

A persistent object cache requires a `object-cache.php` drop-in in `wp-content/`, which the plugins below install for you.

## Choosing one

| Option | Good for |
|---|---|
| **Redis Object Cache** | The common choice. Needs a Redis server - most managed WordPress hosts offer one, often as a toggle |
| **Memcached** (`memcached` / `w3-total-cache`) | Equally fine where your host provides Memcached rather than Redis |
| **SQLite Object Cache** | Small and mid-size sites on hosting with no Redis or Memcached. Slower than Redis, dramatically better than nothing |
| **APCu** | Single-server setups. Not suitable if your site runs on more than one web node, since the cache is per-process |

If your host offers Redis, take it. If it does not, SQLite Object Cache is a real improvement over no persistent cache at all.

## An argument worth stating plainly

BuddyNext requires PHP 8.2 and WordPress 6.9. Those floors already exclude most hosting that could not offer an object cache.

So we do not hedge: **at scale, a persistent object cache is the expected setup.** Designing caching around hosts that cannot provide one would mean designing for a host that also cannot meet our PHP and WordPress requirements. Below a few thousand members it genuinely does not matter, and BuddyNext will not nag you about it.

## Related

- [Tools and Maintenance](08-tools-and-maintenance.md) - where the object cache panel lives, alongside the other health checks.
