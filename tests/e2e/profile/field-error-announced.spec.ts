import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { restJson } from '../_fixtures/effects';
import { satisfyRequiredFields } from '../_fixtures/wp';

/**
 * J-985 a profile field the server refuses is announced with its error.
 *
 * The member types a web address the browser accepts but the server refuses
 * (a host with no dot) and saves. The Website field is marked aria-invalid, its
 * aria-describedby names a visible error that is a live region (role=alert), and
 * the error says what is wrong. Saving again with a valid address clears the
 * invalid state. Effect: the refused value is not stored (GET /me/profile).
 *
 * Covers: cap-give-members-a-profile-with-custom-fields
 * Roles: member (owner)
 */

const LOGIN = process.env.BN_TEST_USER ?? 'varundubey';

test.describe('profile / field error announced', () => {
    test('J-985 a refused Website value marks the field invalid and announces why', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        await satisfyRequiredFields(LOGIN); // a missing required field would be reported first
        await loginAs(page, LOGIN);
        await page.goto(`/members/${LOGIN}/edit/`);
        const field = page.locator('.bn-ep-form-shell [name="website"]');
        await expect(field, 'the Website field is on the edit screen').toBeVisible();
        const original = await field.inputValue();
        const submit = page.locator('.bn-ep-form-shell button[type="submit"]').first();

        try {
            await field.fill('https://localhost');
            const refused = page.waitForResponse((r) => r.url().includes('/me/profile') && r.request().method() === 'PUT');
            await submit.click();
            expect((await refused).status(), 'the server refuses the value').toBe(422);

            await expect(field).toHaveAttribute('aria-invalid', 'true');
            const describedBy = (await field.getAttribute('aria-describedby')) ?? '';
            expect(describedBy, 'the field points at its error').not.toBe('');
            const error = page.locator(`[id="${describedBy}"]`);
            await expect(error).toBeVisible();
            await expect(error).toHaveText(/valid URL/i);
            const live = await error.evaluate((el) => el.getAttribute('role') === 'alert' || !!el.closest('[aria-live]'));
            expect(live, 'the error is a live region').toBe(true);

            // EFFECT: the refused value never reached the profile.
            const stored = await restJson(page, '/wp-json/buddynext/v1/me/profile');
            expect(stored.status).toBe(200);
            expect(JSON.stringify(stored.data), 'GET /me/profile').not.toContain('https://localhost');

            // A valid value clears the invalid state on the next save.
            await field.fill('https://example.com');
            const saved = page.waitForResponse((r) => r.url().includes('/me/profile') && r.request().method() === 'PUT');
            await submit.click();
            expect((await saved).status()).toBeLessThan(300);
            await expect(field).not.toHaveAttribute('aria-invalid', 'true');
        } finally {
            // Put the member's own Website back exactly as it was.
            await page.goto(`/members/${LOGIN}/edit/`);
            const again = page.locator('.bn-ep-form-shell [name="website"]');
            if ((await again.inputValue()) !== original) {
                await again.fill(original);
                const back = page.waitForResponse((r) => r.url().includes('/me/profile') && r.request().method() === 'PUT');
                await page.locator('.bn-ep-form-shell button[type="submit"]').first().click();
                await back;
            }
        }
    });
});
