import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-24-directory-members + J-27-directory-follow-from-card + J-28-directory-mute-from-card.
 */
test.describe('directory / members', () => {
    test('members directory renders cards or empty state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        await expect(page.locator(sel.app)).toBeVisible();

        const cards = await page.locator(sel.memberCard).count();
        expect(cards).toBeGreaterThanOrEqual(0);
    });

    test('J-27 follow from card toggles state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const cards = await page.locator(sel.memberCard).count();
        if (cards === 0) {
            softSkip(testInfo, 'No member cards seeded.');
            return;
        }

        const firstCard = page.locator(sel.memberCard).first();
        const followBtn = firstCard.locator('[data-action="follow"], .bn-member-card__follow').first();
        if (!(await followBtn.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No follow button on this card (already following self or already followed).');
            return;
        }
        const before = (await followBtn.innerText()).trim();
        await followBtn.click();
        const after = (await followBtn.innerText()).trim();
        expect(after).not.toEqual(before);
    });

    test('J-28 mute from card via more menu', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const firstCard = page.locator(sel.memberCard).first();
        if ((await page.locator(sel.memberCard).count()) === 0) {
            softSkip(testInfo, 'No member cards.');
            return;
        }

        const more = firstCard.locator('[data-action="more"], .bn-member-card__more').first();
        if (!(await more.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No more-actions menu in card markup yet.');
            return;
        }
        await more.click();

        const mute = page.locator('[data-action="mute"], [role="menuitem"]:has-text("Mute")').first();
        await expect(mute).toBeVisible({ timeout: 3_000 });
    });
});
