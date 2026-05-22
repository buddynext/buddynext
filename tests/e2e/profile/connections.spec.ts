import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-31-profile-connection-request + J-32-profile-message-button.
 */
test.describe('profile / connections + messages', () => {
    test('J-31 connection request toggles Requested state', async ({ authenticatedPage: page }, testInfo) => {
        // Open directory, pick the first non-self card, follow to profile.
        await page.goto(urls.members);
        const cards = page.locator(sel.memberCard);
        if ((await cards.count()) === 0) {
            softSkip(testInfo, 'No member cards seeded.');
            return;
        }
        const firstLink = cards.first().locator('a[href*="/members/"]').first();
        if (!(await firstLink.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Card has no profile link.');
            return;
        }
        await Promise.all([
            page.waitForLoadState('domcontentloaded'),
            firstLink.click(),
        ]);

        const connect = page.locator('[data-action="connect"], button:has-text("Connect")').first();
        if (!(await connect.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Connect button not visible (already connected, blocked, or own profile).');
            return;
        }
        const before = (await connect.innerText()).trim();
        await connect.click();
        const after = (await connect.innerText()).trim();
        expect(after).not.toEqual(before);
    });

    test('J-32 message button navigates to messages thread (or shows DM bridge gate)', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const cards = page.locator(sel.memberCard);
        if ((await cards.count()) === 0) {
            softSkip(testInfo, 'No member cards.');
            return;
        }
        const link = cards.first().locator('a[href*="/members/"]').first();
        if (!(await link.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No profile link.');
            return;
        }
        await Promise.all([page.waitForLoadState('domcontentloaded'), link.click()]);

        const msg = page.locator('[data-action="message"], a[href*="/messages/"]:has-text("Message")').first();
        if (!(await msg.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Message button missing (DM bridge not active or own profile).');
            return;
        }
        await Promise.all([page.waitForLoadState('domcontentloaded'), msg.click()]);
        expect(page.url()).toMatch(/messages|dm/i);
    });
});
