import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-40 join open space + J-41 request to join private space.
 */
test.describe('spaces / join + request', () => {
    test('J-40 join open space toggles button to Joined', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        const join = page.locator(sel.spaceJoin).first();
        if (!(await join.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No join button visible  -  user may already be in every open space.');
            return;
        }
        const before = (await join.innerText()).trim();
        await join.click();
        await expect.poll(async () => (await join.innerText()).trim(), { timeout: 5_000 }).not.toEqual(before);
    });

    test('J-41 private space request toggles to Requested', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        const request = page.locator('.bn-space-card [data-action="request"], .bn-space-card button:has-text("Request")').first();
        if (!(await request.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No private space card with Request button visible.');
            return;
        }
        const before = (await request.innerText()).trim();
        await request.click();
        await expect.poll(async () => (await request.innerText()).trim(), { timeout: 5_000 }).not.toEqual(before);
    });
});
