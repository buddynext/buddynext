# Page Cache and Optimisation Plugins

How BuddyNext works with page-cache and optimisation plugins such as WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache and Autoptimize, what was tested, and the two things an owner should still do.

## Overview / Contract

A page cache stores a finished page and serves the same copy to the next visitor. That suits pages that look the same for everyone. It does not suit a community page a member is logged in on: their feed, inbox, notifications, profile actions and onboarding step are built for them alone.

**Since 1.2.2, BuddyNext marks those pages as uncacheable itself. There is nothing to configure and no exclusion list to maintain.**

| Page | Logged-in member | Guest |
|---|---|---|
| Every BuddyNext page (activity, explore, members and profiles, spaces, messages, notifications, onboarding, settings, single posts) | Never cached | Cached, if your cache caches guests |
| Login, sign-up and password pages | Never cached | Never cached |

Guests still get cached copies of public pages (Explore, open spaces, public profiles and posts), which is where a page cache speeds a community up the most.

BuddyNext does this with the WordPress-standard `DONOTCACHEPAGE` signal, plus `Cache-Control: no-store` headers for reverse proxies and browsers.

WP Rocket normally skips caching any page whose address differs from its page's permalink, which would have left every profile, post and sub-page (such as Explore) uncached for guests. BuddyNext tells WP Rocket these are its own valid pages, so guests get them cached and optimised like any other page.

## What was tested

Each cache was set to its riskiest setting for a community: **caching logged-in visitors too**. A Redis persistent object cache was running throughout.

| Plugin | Setting tested | Result |
|---|---|---|
| WP Super Cache | Cache logged-in visitors (known users) | Members always get fresh pages; guests get cached pages |
| W3 Total Cache | Page cache on (Disk), "Don't cache pages for logged in users" off | Members always get fresh pages; guests get cached pages |
| WP Rocket | User Cache add-on on | Members always get fresh pages; guests get cached pages, including profiles and sub-pages |
| LiteSpeed Cache on OpenLiteSpeed | Cache on, "Cache Logged-in Users" on | Members always get fresh pages; guests get cached pages |
| Autoptimize | Optimise JS and CSS, aggregate JS, include inline JS, optimise for logged-in users | Onboarding and the post composer work; no script errors |
| LiteSpeed Cache page optimisation | JS minify, "JS Combine", "Load JS Deferred" set to Delayed, CSS minify and combine, for logged-in users too | Onboarding and the post composer work; no script errors |
| WP Rocket file optimisation | Minify JavaScript and CSS files, Load JavaScript deferred, Delay JavaScript execution | Guest pages work after the first tap, scroll or key press (Load more on Explore tested); no script errors. Member pages are not optimised, because they are never cached |

BuddyNext loads its interactive scripts as JavaScript modules. Autoptimize and LiteSpeed leave them alone. WP Rocket's Delay JavaScript execution holds them back on guest pages until the visitor first interacts, then loads them, and they work. No optimiser needs BuddyNext in its exclusion list.

**Not tested:** Cloudflare APO and hand-written Varnish or proxy rules. Any cache works with BuddyNext if it either honours `DONOTCACHEPAGE` or skips requests that carry the `wordpress_logged_in_` cookie. If yours does neither, turn off its caching for logged-in users.

## What to do

1. **Purge all caches after every BuddyNext or BuddyNext Pro update.** A cached guest page or a combined script file from the old version can outlive the update. This is standard for any plugin update on a cached site.
2. **On BuddyNext 1.2.1 or earlier**, turn off your cache's "cache logged-in users" option, or add your onboarding page (by default `/onboarding/`) to its exclusion list. Without that, a member who presses Continue after choosing interests can land back on step 1, because the wizard reloads and the cache serves the first step again. 1.2.2 fixes this.

## Where the settings live

| Plugin | Caching for logged-in users | Script exclusions |
|---|---|---|
| WP Rocket | Settings > WP Rocket > Add-ons > User Cache | File Optimization > Excluded JavaScript Files (minify), and Excluded JavaScript Files under Delay JavaScript execution |
| LiteSpeed Cache | Cache > Cache > Cache Logged-in Users | Page Optimization > Tuning > JS Excludes, and JS Deferred / Delayed Excludes |
| W3 Total Cache | Performance > Page Cache > Don't cache pages for logged in users | Performance > Minify > Never minify the following JS files |
| WP Super Cache | Settings > WP Super Cache > Advanced > Disable caching for logged in visitors | (no script optimisation) |
| Autoptimize | (no page cache) | Settings > Autoptimize > JS, CSS & HTML > Exclude scripts from Autoptimize |

## Troubleshooting script optimisation

If a community page stops responding to clicks after you change an optimisation setting that was not covered above:

1. Exclude `wp-content/plugins/buddynext/` and `wp-content/plugins/buddynext-pro/` from JS combine, minify, defer and delay.
2. Purge the cache and reload.

If that fixes it, keep the exclusion and tell us which plugin and setting it was. If it does not, the optimisation plugin is not the cause.

## Related

- [Object Cache at Scale](09-object-cache-at-scale.md) - BuddyNext's own caching, which is a separate layer from a page cache
- [Tools and Maintenance](08-tools-and-maintenance.md)
- [Member Onboarding](../accounts-access/06-member-onboarding.md)
