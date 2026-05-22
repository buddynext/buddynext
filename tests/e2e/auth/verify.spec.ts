import { test, expect } from '@playwright/test';
import { urls } from '../_fixtures/selectors';

/**
 * J-05-email-verify.
 *
 * Verifying a fresh account requires a server-issued token that we don't
 * have access to from the test runner without WP-CLI seeding. Marked
 * fixme until a `db.fixture.ts` helper exists to seed a known token.
 */
test.describe('auth / verify', () => {
    test.fixme('verify token completes account activation', async ({ page }) => {
        // TODO: once db.fixture seedVerifyToken() is implemented, switch this
        // to an active test that hits /auth/?bn_verify=<seeded_token>.
        await page.goto(urls.auth + '?bn_verify=placeholder');
        await expect(page).toHaveURL(/auth|verify|onboarding/);
    });
});
