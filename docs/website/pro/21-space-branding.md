# Per-space branding (removed in 1.0.7)

Per-space branding - giving an individual space its own logo, accent color, font, and custom CSS on top of the site-wide brand - has been removed from BuddyNext Pro as of version 1.0.7. It was out of scope for what white-label branding is meant to do.

## What changed

White-label branding is now backend-only: a brand name and a logo shown in the WordPress admin and in BuddyNext emails. It no longer touches the community front end at all, for the whole site or for any individual space. Every space now displays with your site's active theme. The one per-space choice that remains is a brand colour, and it comes from free BuddyNext, not from Pro: the owner of a space can tick **Use a custom brand colour for this space** under the space's General settings and pick a colour for that space's header. There is no per-space logo, font, or custom CSS.

If you configured a per-space brand before upgrading to 1.0.7, that override no longer applies - the space displays using your site's normal theme, the same as every other space.

## Where branding lives now

For the current scope of white-label branding - the brand name and logo shown in wp-admin and in outgoing emails - see White-label branding.

## Free vs Pro

Per-space logo, font, and custom CSS are not available in either Free or Pro as of 1.0.7. A per-space brand colour is a free BuddyNext space setting.

| | Free | Pro |
|---|---|---|
| Per-space logo, font, custom CSS | No | No (removed in 1.0.7) |
| Per-space brand colour (space owner sets it) | Yes | Not needed, included in Free |

## Related

- [White-label Branding](20-white-label.md) - where branding lives now.
- [Appearance and Branding](../getting-started/07-appearance-and-branding.md) - the theme-driven front-end branding.
