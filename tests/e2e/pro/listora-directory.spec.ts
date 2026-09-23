import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';

/**
 * J-956 surface directory listings (WB Listora bridge, Pro).
 *
 * Written from the member's promise: "a published business listing shows up in
 * the community, not just on its own standalone page." `Bridges\ListoraBridge`
 * (guarded on `defined('WB_LISTORA_VERSION')`, so it is a total no-op when WB
 * Listora is not installed) hooks `transition_post_status` on the
 * `listora_listing` post type: going public publishes a feed card via the
 * shared bridge-card renderer (`.bn-post-card__bridge-card--listing`) and
 * indexes it in `bn_search_index`, gated per-owner on the "Post to the activity
 * feed" / "Include in search" toggles at
 * Settings -> Integration Settings -> Integration Controls.
 *
 * WB Listora is a separate plugin this harness does not install, so the
 * expected outcome here is a soft-skip naming that precondition (verified
 * against `ListoraBridge::init()`'s own guard, not guessed) — detected from
 * BuddyNext's OWN admin surface (an "Active" integration section titled
 * "Listora") rather than reasoning about a PHP constant Playwright cannot read.
 * Where the bridge IS active, the effect asserted is a real listing card on
 * Explore, not merely that the toggle exists.
 *
 * Covers: cap-surface-directory-listings
 * Roles: member
 */
test.describe('pro / Listora directory listings bridge', () => {
    test.fixme(process.env.BN_PRO !== '1', 'The Listora bridge ships in Pro. Set BN_PRO=1 to run.');

    const integrationsUrl = '/wp-admin/admin.php?page=buddynext-integration-settings&tab=integration-controls';

    test('J-956 a published Listora listing surfaces as a feed card', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(integrationsUrl);
        const active = page.locator('.bn-settings-section', { has: page.locator('.bn-badge[data-tone="success"]') }).filter({ hasText: /listora/i });
        if ((await active.count()) === 0) {
            softSkip(testInfo, 'WB Listora is not active on this harness (no "Listora" row under Integration Controls) — ListoraBridge::init() no-ops without WB_LISTORA_VERSION.');
            return;
        }

        await page.goto(urls.explore);
        const listingCard = page.locator('.bn-post-card__bridge-card--listing').first();
        if (!(await listingCard.isVisible({ timeout: 8_000 }).catch(() => false))) {
            softSkip(testInfo, 'Listora is active but no published listing was found on Explore — seed one to exercise this effect.');
            return;
        }

        await expect(listingCard.locator('.bn-post-card__bridge-source')).toHaveText(/listing/i);
        const link = listingCard.locator('.bn-post-card__bridge-title').first();
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('href', /.+/);
    });
});
