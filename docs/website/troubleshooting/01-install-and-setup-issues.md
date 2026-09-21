# Install and Setup Issues

Problems you might hit activating BuddyNext, running the Setup Wizard, or getting the plugin working alongside your theme.

## A community link shows "Page not found" right after activation

**Symptom:** The feed, members, spaces, or another BuddyNext page 404s immediately after activating the plugin.

**Likely cause:** WordPress's rewrite rules have not picked up BuddyNext's new page routes yet, or the site is set to Plain permalinks.

**Fix:**
1. Go to **Settings > Permalinks** in wp-admin.
2. Confirm the setting is anything other than **Plain**.
3. Click **Save Changes** once, even without changing anything - this regenerates the rewrite rules.
4. Reload the community link.

See [Installing BuddyNext](../getting-started/02-installation.md).

## The site runs out of memory ("Allowed memory size exhausted") on a community page

**Symptom:** A white screen or a memory-exhaustion error appears on the feed, spaces, or another BuddyNext page, especially once Pro and several companion plugins are active.

**Likely cause:** PHP's default 128 MB memory limit is too low for BuddyNext, its Pro layer, and the media/integration plugins running together.

**Fix:**
1. Raise `memory_limit` to at least 512 MB, either in `php.ini` or by adding `define( 'WP_MEMORY_LIMIT', '512M' );` to `wp-config.php`.
2. Check **Tools > Site Health** in wp-admin - it flags a recommendation when your limit is below 512 MB.

See [Installing BuddyNext](../getting-started/02-installation.md).

## The Setup Wizard doesn't appear, or I need to run it again

**Symptom:** You want to revisit the wizard's choices, but there's no link to it in wp-admin anymore.

**Likely cause:** The wizard hides its wp-admin notice link once setup is marked complete - that's expected, not a bug.

**Fix:** Open `wp-admin/admin.php?page=buddynext-setup` directly. Re-running the wizard is safe: it detects pages, categories, and profile groups that already exist and leaves them untouched instead of duplicating them.

See [Admin Setup Wizard](../getting-started/03-admin-setup-wizard.md).

## BuddyNext shows registration as open, but nobody can sign up

**Symptom:** Registration Mode is set to Open in BuddyNext, but every sign-up attempt is refused.

**Likely cause:** WordPress's own "Anyone can register" setting (Settings > General) disagrees with BuddyNext's registration mode - another plugin or a hosting default can flip it independently.

**Fix:** BuddyNext watches for this mismatch and shows an admin notice with a one-click fix when the two settings disagree. Act on it as soon as you see it - it means real visitors are being turned away. You can also check **Settings > General > Membership** directly and make sure "Anyone can register" matches what you intend.

See [Registration](../accounts-access/01-registration.md).

## A page or button looks wrong, or a feature is missing, only on BuddyNext's own pages

**Symptom:** Something that works fine on a normal WordPress page - a form, a widget, a plugin's own feature - disappears or misbehaves specifically on the feed, a space, a profile, or another community page.

**Likely cause:** **Plugin isolation** (Platform > Plugin isolation) is turned on and configured to skip that plugin on community routes.

**Fix:**
1. Go to **BuddyNext > Platform > Plugin isolation**.
2. Find the plugin in the list and make sure it is **not** ticked to skip.
3. Save and reload the community page.

If the missing feature belongs to one of BuddyNext's own integration partners (WPMediaVerse, Jetonomy, WB Gamification, and the rest), it is protected from isolation already, so look at that integration's own settings instead. See [Plugin Isolation](../getting-started/08a-plugin-isolation.md).

## The community looks fine on one theme but different (or broken) on another

**Symptom:** Switching themes changes more than expected, or a header/footer element clashes with the community layout.

**Likely cause:** BuddyNext renders its own full community interface regardless of theme - your theme only supplies the outer chrome (header, footer, base fonts, site-wide colors). A theme that wraps every page in an unusually narrow or styled container can visually clash with that chrome.

**Fix:** BuddyNext is tested against BuddyX, BuddyX Pro, and Reign. If you're on a different theme and see a layout clash, try one of those three to confirm whether the issue is theme-specific, then check your theme's page-width or container settings. Your community content, members, and settings are untouched by a theme switch. See [Choosing Your Theme](../getting-started/02a-choosing-a-theme.md).

## Installing BuddyNext Pro doesn't seem to unlock anything

**Symptom:** Pro is installed and active, but expected Pro features or admin sections don't appear.

**Likely cause:** The Pro license key has not been entered yet, or a required companion plugin (for Career Board, Listora, Learnomy, or Eventonomy surfacing) is missing or inactive.

**Fix:**
1. Confirm Pro is active on the **Plugins** screen.
2. Go to **BuddyNext > Get Started > License**, paste your Pro license key, and activate it. (The key gates updates, not features, but activating it is still the expected step after installing Pro.)
3. For Career Board, Listora, Learnomy, or Eventonomy surfacing specifically, confirm that companion plugin is installed and active alongside Pro - the community surfacing needs both.

See [Installing BuddyNext](../getting-started/02-installation.md) and [Integrations Overview](../integrations/01-overview.md).

## Related

- [Installing BuddyNext](../getting-started/02-installation.md)
- [Admin Setup Wizard](../getting-started/03-admin-setup-wizard.md)
- [Plugin Isolation](../getting-started/08a-plugin-isolation.md)
- [Choosing Your Theme](../getting-started/02a-choosing-a-theme.md)
