# Per-space branding

A space can have its own brand colour. Nothing else about a space is branded separately. The logo, font and custom CSS overrides that older Pro versions offered per space were removed in BuddyNext Pro 1.0.7.

This page explains what you can brand today, who can change it, and how it behaves next to your theme and white-label settings.

> **Changed in 1.0.7:** Pro no longer has per-space branding. The one per-space choice that remains is a brand colour, and it is a free BuddyNext setting. You do not need Pro to use it.

## What you can brand

| Item | Per space? | Where it comes from |
|---|---|---|
| Brand colour | Yes | Free BuddyNext |
| Logo | No | Removed in Pro 1.0.7 |
| Font | No | Removed in Pro 1.0.7 |
| Custom CSS | No | Removed in Pro 1.0.7 |

Every other part of a space follows your active theme and the site-wide brand colour you set under BuddyNext appearance settings.

## Where to set the colour

The colour is set by the space itself, not from wp-admin.

1. Open the space.
2. Go to the space's settings and choose the **General** panel.
3. Find **Brand colour**.
4. Tick **Use a custom brand colour for this space**.
5. Pick a colour with the colour picker.
6. Save the settings.

To go back to the default, untick **Use a custom brand colour for this space** and save. The picker is greyed out while the box is unticked, because a colour picker cannot hold an empty value. The checkbox is what clears it.

## Who can change it

Only the owner of the space can change the brand colour. Moderators cannot, and neither can ordinary members. The setting is owner-only in the form, and the save is checked again on the server, so the restriction does not depend on what the page shows.

Members can see the colour, because it is visible to anyone who can see the space.

## How it shows to members

The colour is applied to the space's header, the hero area at the top of the space:

- The emblem for the space takes the brand colour, with a tinted background and a ring in the same colour.
- A soft wash of the colour appears across the header's cover area.

A space with no custom colour shows no wash at all. It uses the site's accent colour instead. The colour picker also shows that site colour as its starting swatch, so you see what the space currently renders.

The colour is stored as a space field. It is available through the space-field REST API and appears in the space data that BuddyNext returns.

## Interaction with your theme

Per-space branding sits on top of your theme, not in place of it. Your theme still controls fonts, layout and the rest of the page. Only the space header picks up the colour.

- The site-wide brand colour (set during setup or under appearance settings) remains the default for every space.
- A space's colour overrides that default for the header of that one space only.

For the theme-level settings, see [Appearance and Branding](../getting-started/07-appearance-and-branding.md).

## Interaction with white-label

White-label branding and per-space branding are separate.

White-label (a Pro feature) changes the brand name and logo shown in wp-admin and in BuddyNext emails. It does not touch the community front end. It does not read or change a space's brand colour.

The reverse is also true. A space owner choosing a colour does not affect the admin name, the admin logo or your emails.

If you upgraded from a version before 1.0.7 and had set up per-space logos, fonts or CSS, those overrides no longer apply. The space displays with your normal theme, like every other space.

## Limits

- **One colour per space.** There is no second colour, gradient control or per-tab colour.
- **Header only.** The colour is not applied to every element in the space.
- **No logo, font or CSS per space.** If you need a different look for one space, change it at theme level for the whole site.
- **Not a Pro setting.** Turning Pro or White-label off does not remove or change a space's colour.

## Free vs Pro

| | Free | Pro |
|---|---|---|
| Per-space brand colour (set by the space owner) | Yes | Included in Free, no Pro setting |
| Per-space logo, font, custom CSS | No | No (removed in 1.0.7) |

## Related

- [White-label Branding](20-white-label.md) - the admin brand name and logo.
- [Appearance and Branding](../getting-started/07-appearance-and-branding.md) - the theme-driven front-end branding.
