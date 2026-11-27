import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * Opening a post's three-dot menu with Enter lands focus on the first VISIBLE item.
 *
 * Above 640px the menu's Follow button is hidden, so focusing "the first button"
 * left focus on the trigger and the arrow keys did nothing. Needs a feed card
 * whose menu has a hidden item ahead of the visible ones (another member's post).
 *
 * J-77-post-edit-delete.
 *
 * Roles: member
 */
test.describe('feed / post menu keyboard focus', () => {
    test('Enter on the post menu focuses a visible item at desktop width', async ({ authenticatedPage: page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await page.goto(urls.home);

        const trigger = page.locator('.bn-post-card__menu-wrap [data-wp-on--click="actions.toggleOptionsMenu"]').first();
        test.skip(!(await trigger.count()), 'no post menu on the feed to open');

        await trigger.focus();
        await page.keyboard.press('Enter');

        const focused = await page.evaluate(() => {
            const el = document.activeElement as HTMLElement | null;
            return !!el && !!el.closest('.bn-post-card__options-menu') && el.getClientRects().length > 0;
        });
        expect(focused, 'focus must move into the menu, onto a shown item').toBe(true);

        await page.keyboard.press('ArrowDown');
        const stillInMenu = await page.evaluate(() => !!document.activeElement?.closest('.bn-post-card__options-menu'));
        expect(stillInMenu).toBe(true);
    });
});
