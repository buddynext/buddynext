import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-16-react-to-post.
 */
test.describe('feed / reactions', () => {
    test('clicking react button toggles active state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const firstCard = page.locator(sel.postCard).first();
        const cardCount = await page.locator(sel.postCard).count();
        if (cardCount === 0) {
            softSkip(testInfo, 'Feed empty  -  react spec needs at least one post. Reseed before running.');
            return;
        }

        const reactBtn = firstCard.locator(sel.postReact).first();
        await expect(reactBtn).toBeVisible();

        const before = await reactBtn.getAttribute('aria-pressed').catch(() => null);
        await reactBtn.click();

        // Either aria-pressed flips, or a `is-active` class lands, or
        // the visible count changes. Accept any of those signals.
        const after = await reactBtn.getAttribute('aria-pressed').catch(() => null);
        const isActiveClass = await reactBtn.evaluate((el) => el.classList.contains('is-active')).catch(() => false);
        expect(before !== after || isActiveClass).toBeTruthy();
    });
});
