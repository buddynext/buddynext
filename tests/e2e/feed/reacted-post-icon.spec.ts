import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * A post the member has reacted to keeps a visible reaction icon next to its label.
 *
 * The reacted state hides the idle heart and draws the reaction's own mark; a
 * missing lookup left a blank gap beside "Like". Reacts to the first post, reads
 * the icon, then takes the reaction back.
 *
 * J-16-react-to-post.
 *
 * Roles: member
 */
test.describe('feed / reacted post icon', () => {
    test('a reacted post shows its reaction icon, not a blank gap', async ({ authenticatedPage: page }) => {
        await page.goto(urls.home);

        const react = page.locator('.bn-post-card__action-btn--react').first();
        test.skip(!(await react.count()), 'no reactable post on the feed');

        const wasReacted = await react.evaluate((el) => el.classList.contains('is-reacted'));
        if (!wasReacted) {
            await react.click();
            await page.locator('.bn-post-card__emoji-btn[data-reaction-type="like"]').first().click();
        }
        await expect(react).toHaveClass(/is-reacted/);

        const mark = await react.evaluate((el) => {
            const icon = el.querySelector('span') as HTMLElement;
            const box = icon.getBoundingClientRect();
            return { image: getComputedStyle(icon).backgroundImage, glyph: !!icon.querySelector('.bn-reaction-glyph:not([hidden])'), width: box.width };
        });
        expect(mark.width, 'the icon box keeps its size').toBeGreaterThan(0);
        expect(mark.image !== 'none' || mark.glyph, 'a reaction image or glyph is drawn').toBe(true);

        if (!wasReacted) {
            await react.click();
            await page.locator('.bn-post-card__emoji-btn[data-reaction-type="like"]').first().click();
        }
    });
});
