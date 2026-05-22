import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-16-react-to-post.
 *
 * Reaction flow (Interactivity API):
 *   1. Click `.bn-post-card__action-btn--react` — opens the emoji picker.
 *   2. Click an emoji from `.bn-post-card__emoji-btn` (e.g. "like").
 *   3. The react button gains `is-reacted` (or `is-active`) class.
 */
test.describe('feed / reactions', () => {
    test('clicking react button toggles active state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        await expect(page.locator(sel.app)).toBeVisible();

        const cardCount = await page.locator(sel.postCard).count();
        if (cardCount === 0) {
            softSkip(testInfo, 'Feed empty — react spec needs at least one post. Reseed before running.');
            return;
        }

        const firstCard = page.locator(sel.postCard).first();
        const reactBtn = firstCard.locator(sel.postReact).first();
        await expect(reactBtn).toBeVisible();

        // Open the picker.
        await reactBtn.click();

        // Pick the first emoji (typically "like").
        const emoji = firstCard.locator(sel.postReactEmoji).first();
        await expect(emoji).toBeVisible({ timeout: 5_000 });
        await emoji.click();

        // After picking, the react button class is rebound via state.reactBtnClass.
        // Accept any of: is-reacted, is-active, aria-pressed flip, or the
        // reaction-summary count becomes visible (proves the click reached
        // the store).
        await expect(async () => {
            const cls = await reactBtn.evaluate((el) => el.className).catch(() => '');
            const pressed = await reactBtn.getAttribute('aria-pressed').catch(() => null);
            const summaryVisible = await firstCard.locator(sel.postReactCount).first().isVisible().catch(() => false);
            const reacted = /is-reacted|is-active/.test(cls) || pressed === 'true' || summaryVisible;
            expect(reacted).toBeTruthy();
        }).toPass({ timeout: 5_000 });
    });
});
