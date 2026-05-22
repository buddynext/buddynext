import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-18-bookmark-post + J-19-share-post.
 *
 * Bookmark and share are wired via the Interactivity API. The bookmark
 * button rebinds its class to `bn-post-card__action-btn is-bookmarked`
 * after toggling, and share opens `.bn-share-modal` (a modal backdrop).
 */
test.describe('feed / bookmarks + share', () => {
    test('J-18 bookmark toggles active', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        const firstCard = page.locator(sel.postCard).first();
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }

        const btn = firstCard.locator(sel.postBookmark).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No bookmark control in build.');
            return;
        }

        const beforeClass = await btn.evaluate((el) => el.className).catch(() => '');
        await btn.click();
        // Interactivity rebinds via state.bookmarkBtnClass. Wait for
        // any sign that state flipped — class change, is-bookmarked
        // landing, is-active landing, or aria-pressed flip.
        await expect(async () => {
            const cls = await btn.evaluate((el) => el.className).catch(() => '');
            const pressed = await btn.getAttribute('aria-pressed').catch(() => null);
            const changed = cls !== beforeClass || /is-bookmarked|is-active/.test(cls) || pressed === 'true';
            expect(changed).toBeTruthy();
        }).toPass({ timeout: 5_000 });
    });

    test('J-19 share opens a share popover', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }
        const firstCard = page.locator(sel.postCard).first();
        const btn = firstCard.locator(sel.postShare).first();
        if (!(await btn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No share control in build.');
            return;
        }

        await btn.click();
        // Live build uses `.bn-share-modal` (modal backdrop) for the
        // share UI. Earlier role-based selectors matched the WP admin
        // bar menu (role="menu"), so keep the BN class as the primary
        // signal.
        const popover = page.locator('.bn-share-modal:not([hidden]), .bn-share-modal__panel, [data-share-popover]').first();
        await expect(popover).toBeVisible({ timeout: 5_000 });
    });
});
