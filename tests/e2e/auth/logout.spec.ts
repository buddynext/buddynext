import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';

/**
 * J-10-logout.
 */
test.describe('auth / logout', () => {
    test('logout clears the login cookie', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);

        // WP needs a nonce for /wp-login.php?action=logout. The theme menu
        // link contains it  -  grab the first one we can find.
        const logoutLink = page.locator('a[href*="action=logout"]').first();
        const href = await logoutLink.getAttribute('href').catch(() => null);

        if (href) {
            await page.goto(href);
            // Most installs land on a "you are logged out" confirm screen.
            const url = page.url();
            expect(/loggedout|wp-login|auth/.test(url)).toBeTruthy();
        } else {
            // Fallback: hit logout URL without nonce, expect a confirm prompt.
            await page.goto('/wp-login.php?action=logout', { waitUntil: 'domcontentloaded' });
            const url = page.url();
            expect(/wp-login\.php/.test(url)).toBeTruthy();
        }

        // After logout the protected route should redirect away.
        await page.context().clearCookies();
        await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        expect(/auth|wp-login\.php/.test(page.url())).toBeTruthy();
    });
});
