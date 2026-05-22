import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-46 new thread + J-47 request accept. Both blocked on WPMediaVerse bridge.
 */
test.describe('messages / thread', () => {
    test.fixme(
        !process.env.BN_WPMEDIAVERSE,
        'J-46 / J-47  -  DM thread interactions require the WPMediaVerse bridge. Set BN_WPMEDIAVERSE=1 once it is active.',
    );

    test('thread surface accepts a message send', async ({ authenticatedPage: page }) => {
        await page.goto(urls.messages);
        const thread = page.locator(sel.dmThread).first();
        await expect(thread).toBeVisible();

        const input = page.locator(sel.dmInput).first();
        await input.fill('hi from playwright');
        await input.press('Enter');
        await expect(thread).toContainText('hi from playwright', { timeout: 5_000 });
    });
});
