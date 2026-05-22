import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-10-logout.
 *
 * The Astra theme exposes the standard WP admin bar to logged-in users,
 * which holds the logout link with a fresh nonce. We click it instead of
 * trying to forge a nonce ourselves.
 */
test.describe('auth / logout', () => {
    test('logout clears the login cookie', async ({ authenticatedPage: page }) => {
        await page.goto(urls.feed);

        // Prefer the admin-bar logout link (varundubey has the admin bar on).
        // Fall back to ANY logout link (e.g. a theme menu item).
        let logoutLink = page.locator(sel.wpAdminBarLogout).first();
        if (!(await logoutLink.count())) {
            logoutLink = page.locator('a[href*="action=logout"]').first();
        }

        const href = await logoutLink.getAttribute('href').catch(() => null);
        expect(href, 'Expected a logout link with nonce somewhere on the page').toBeTruthy();

        await page.goto(href as string, { waitUntil: 'domcontentloaded' });
        // wp-login.php?loggedout=true is the canonical landing; some BN
        // builds redirect to /login/ instead.
        expect(/loggedout|wp-login|login|auth/.test(page.url())).toBeTruthy();

        // After logout the WP login cookie must be gone.
        const cookies = await page.context().cookies();
        const stillLoggedIn = cookies.some((c) => c.name.startsWith('wordpress_logged_in'));
        expect(stillLoggedIn).toBeFalsy();

        // /activity/ is publicly browseable, so don't assert a redirect.
        // Instead confirm the admin bar (which only renders for logged-in
        // users) is gone after re-visiting the feed.
        await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
        const adminBarVisible = await page.locator(sel.wpAdminBar).count();
        expect(adminBarVisible).toBe(0);
    });
});
