import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-21-explore-trending + J-22-hashtag-follow.
 */
test.describe('explore / trending', () => {
    test('trending page renders posts + hashtag chips', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.explore);
        await expect(page.locator(sel.app)).toBeVisible();

        const posts = await page.locator(sel.postCard).count();
        const chips = await page.locator(sel.hashtagChip).count();
        // At least one of the two surfaces must be present.
        expect(posts > 0 || chips > 0).toBeTruthy();
    });

    test('clicking a hashtag follow chip toggles its state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.explore);
        const followBtn = page.locator(sel.hashtagFollow).first();
        if (!(await followBtn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No hashtag follow control on this page yet.');
            return;
        }
        const before = (await followBtn.innerText()).trim();
        await followBtn.click();

        // Either text changes (Follow -> Following) or aria-pressed flips.
        const after = (await followBtn.innerText()).trim();
        const ariaAfter = await followBtn.getAttribute('aria-pressed').catch(() => null);
        expect(before !== after || ariaAfter === 'true').toBeTruthy();
    });
});
