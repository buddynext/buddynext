# Recipe: Rename Members and Spaces

**Goal:** call your spaces Clubs, Chapters or Teams (or your members Players, Alumni or Students) in the address bar and on the page.
**You'll need:** BuddyNext Free. A translation plugin such as Loco Translate for the on-page words.
**Time:** about 20 minutes.

## Before you start

- Pick the new word and its address, for example **Clubs** at `/clubs/`.
- Renaming is two separate jobs: the **address** (the URL slug) and the **words** members read. Changing one does not change the other.
- The **Label** on Settings > Navigation renames only that one menu item. It does not change the address or any other text.

## Steps

1. **Change the address.** Go to **BuddyNext > Settings > Pages & URLs** and type the new slug in the hub's **URL slug** field, for example `clubs` for Spaces. Leave the field empty to use the default. Save.
   The hub's WordPress page is renamed with it, and every old address keeps working: `/spaces/design-team/members/` now sends visitors to `/clubs/design-team/members/` with a permanent (301) redirect, so bookmarks, links in old emails and search results still land in the right place. If you rename again later, both earlier addresses keep redirecting. Renaming back to an old slug simply makes it the address again.

2. **Change the words.** Install a translation plugin (Loco Translate is free), open **BuddyNext**, and add a custom translation for your site's language, including English. Search for `Space` and `Spaces` (or `Member` and `Members`) and enter your word. Several hundred strings mention spaces or members, so search for each form: singular, plural, and lower case.
   Save the translation in the plugin's **Custom** location so a BuddyNext update does not overwrite it.

3. **Check the menu.** If you set your own label on **Settings > Navigation**, update it to the new word too.

## What your members see

Members see your word everywhere they read it and in every address, and links they saved before the rename still open the right page.

## Good to know

- Redirects only apply to addresses nothing else answers. If you later publish a page at an old address, that page wins.
- A page cache or CDN can keep serving the old "not found" answer for a while after you rename. Clear it once after saving.
- Old addresses are remembered on the hub's WordPress page. If you set the hub to no page at all, renaming it cannot redirect the old address.

## Related

- [Make It Fully Your Own](07-white-label-your-community.md) - branding and white-label
- [Settings Reference](../getting-started/04b-settings-reference.md) - where every setting lives
