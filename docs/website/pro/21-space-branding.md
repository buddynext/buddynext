# Per-space branding

Per-space branding lets a single space carry its own look - its own logo, accent color, font, and custom CSS - layered on top of your site-wide brand. One install can host several communities under one roof, each with its own identity, while sharing the same members, the same engine, and the same admin.

![A space's settings, where the Brand tab lets the space owner set a logo, accent color, font, and custom CSS for that space](../images/admin-spaces-settings.webp)

> **Note:** Per-space branding is part of the same Pro white-label capability as site-wide branding, offered on the Agency and Unlimited license tiers. On other Pro tiers the feature is not offered.

## What it is

Every space in BuddyNext shares the site's overall look by default. Per-space branding gives a space its own **Brand** tab inside the space's own settings, where you can override four things just for that space:

- **Logo** - a logo shown for this space instead of the site logo.
- **Accent color (hue)** - the single color value the whole interface palette is derived from. Set it for this space and every button, link, and accent rotates to match while a member is browsing that space.
- **Font** - the interface font for this space, as a CSS font-family value.
- **Custom CSS** - your own CSS rules, applied to this space only, to fine-tune anything beyond the standard settings.

Every field is optional. Any field you leave empty simply inherits the site-wide brand, so you only override what you actually want to change. A space with all four fields blank looks exactly like the rest of the site.

The accent color works the way it does everywhere in BuddyNext: the whole palette flows from a single hue, so changing one value rotates the entire color scheme for that space at once - buttons, links, highlights, and accents all shift together and stay in harmony, with no per-element editing. The color picker offers a set of preset swatches, or you can enter your own value.

## When to use it

Per-space branding is for the moment when "one community" is really several communities living together:

- **An agency hosting multiple client communities on one install.** Give each client's space its own color and logo so it feels like the client's own platform, without standing up a separate WordPress site for each one.
- **A business running distinct programs side by side.** A partner program, a customer forum, and an internal team space can each carry their own identity while sharing one member base and one admin.
- **A flagship space that deserves to stand out.** Even on a single-brand community, you might want one premium or featured space to look visually distinct from the rest.

If your whole community should look the same everywhere, you do not need per-space branding at all - set your brand once with site-wide white-label and every space inherits it.

## How it relates to site-wide white-label

Think of it as two layers:

- **Site-wide white-label** sets the brand for the entire community - name, logo, color, font, and custom CSS - across both the front-end and wp-admin. This is the base everything inherits from. See White-label branding for the full picture.
- **Per-space branding** is an optional override that sits on top of the site brand for one space. It covers the visual fields - logo, color, font, and custom CSS - for that space only. (The admin name swap is a site-wide concern and is not part of per-space branding.)

When a member opens a space that has its own brand, the space's values layer over the site brand for as long as they are in that space; anything the space leaves blank keeps showing the site value. Move to a space with no override and they see the standard site brand again.

## Who can set it

A space's brand can be managed by a **site administrator** or by **that space's own owner** - no one else. Ordinary members and moderators do not see the Brand tab and have nothing to configure; they simply see the space in whatever brand has been set. This is what makes it safe to hand a space owner control of their own corner without giving them the keys to the whole site.

## Good to know

- **Empty means inherit.** Leave any field blank and that aspect falls back to the site-wide brand. You never have to re-enter values that already match the site.
- **A live preview shows your changes before you save.** The Brand tab previews your color, font, and custom CSS together so you can see the result rather than saving blind.
- **Custom CSS is sanitized for safety.** Because space owners can use it, custom CSS has HTML tags and script-style content stripped on save, and is capped at 10,000 characters. Write plain CSS rules and they apply as expected.
- **No extra cost when unused.** A space with no overrides emits nothing extra - per-space branding only does work when a space actually carries its own brand.
- **Branding is visual, not structural.** Per-space branding changes how a space looks. It does not change the space's URL or give it a separate domain - custom domain mapping is not part of this release.

## Free vs Pro

Per-space branding is a Pro feature, part of the white-label capability offered on the Agency and Unlimited license tiers. Free BuddyNext always runs every space under the default site theme.

| | Free | Pro (Agency / Unlimited) |
|---|---|---|
| Site-wide brand (name, logo, color, font, custom CSS) | No | Yes |
| Per-space logo, color, font, custom CSS | No | Yes |
| Space-owner-managed branding | No | Yes |
| Live preview before saving | No | Yes |
