import { test as base, expect, type Page } from '@playwright/test';
import { settleOverlays, satisfyRequiredFields } from './wp';

/**
 * Custom Playwright fixture: `authenticatedPage`.
 *
 * Provides a `Page` that's already logged in as `varundubey`.
 *
 * Login strategy:
 *   1. Try `?autologin=1` (per CLAUDE.md: "http://buddynext-dev.local?autologin=1
 *      is the test base URL"). If a `wordpress_logged_in_*` cookie shows up
 *      after that GET, we're done.
 *   2. Otherwise fall back to POST /wp-login.php with credentials from env
 *      (BN_TEST_USER / BN_TEST_PASS) defaulting to varundubey / password.
 *
 * Pro-only specs gate themselves with `test.fixme(process.env.BN_PRO !== '1', ...)`.
 */
type AuthFixtures = {
    authenticatedPage: Page;
};

const TEST_USER = process.env.BN_TEST_USER ?? 'varundubey';
const TEST_PASS = process.env.BN_TEST_PASS ?? 'password';

async function tryAutologin(page: Page): Promise<boolean> {
    // The mu-plugin accepts a login name. `autologin=1` always meant user ID 1, so with a
    // BN_TEST_USER other than that account every "own profile" spec acted as the wrong
    // member (the 2026-10-12 gate: Edit link "missing", follow rows for the wrong actor,
    // the space owner not on their own roster).
    await page.goto(`/?autologin=${encodeURIComponent(TEST_USER)}`, { waitUntil: 'domcontentloaded' });
    const cookies = await page.context().cookies();
    return cookies.some((c) => c.name.startsWith('wordpress_logged_in'));
}

async function loginViaForm(page: Page): Promise<void> {
    await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', TEST_USER);
    await page.fill('#user_pass', TEST_PASS);
    await Promise.all([
        page.waitForLoadState('domcontentloaded'),
        page.click('#wp-submit'),
    ]);
}

export const test = base.extend<AuthFixtures>({
    authenticatedPage: async ({ page }, use) => {
        const autologged = await tryAutologin(page);
        if (!autologged) {
            await loginViaForm(page);
        }

        const cookies = await page.context().cookies();
        const hasLoginCookie = cookies.some((c) => c.name.startsWith('wordpress_logged_in'));
        expect(
            hasLoginCookie,
            `Login fixture expected a wordpress_logged_in_* cookie. Set BN_TEST_USER / BN_TEST_PASS env vars if defaults don't match.`
        ).toBeTruthy();

        await settleOverlays(page);
        // A member must satisfy the site's required profile fields before any full save.
        await satisfyRequiredFields(TEST_USER);
        await use(page);
    },
});

export { expect };
