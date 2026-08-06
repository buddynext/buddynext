import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import type { Page } from '@playwright/test';

/**
 * Count member cards, failing loudly when the page rendered neither cards nor an
 * empty state.
 *
 * These specs used to bail with `if (count === 0) softSkip('No member cards
 * seeded')`, which checks the very selector under test. A broken selector was
 * therefore indistinguishable from an empty site, and both reported green — the
 * reason J-27 and J-28 sat passing for so long while asserting nothing. Skipping
 * is only honest when the directory positively says it is empty.
 */
async function countMemberCards(page: Page): Promise<number> {
    const cards = await page.locator(sel.memberCard).count();
    if (cards === 0) {
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        if (!empty) {
            throw new Error(
                'members directory rendered neither member cards nor an empty state — ' +
                `the '${sel.memberCard}' selector is probably stale, not the site empty`
            );
        }
    }
    return cards;
}

/**
 * J-24-directory-members + J-27-directory-follow-from-card + J-28-directory-mute-from-card.
 */
test.describe('directory / members', () => {
    test('members directory renders cards or empty state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        await expect(page.locator(sel.app)).toBeVisible();

        // "cards OR empty state" is the real contract, and it has to be asserted
        // as one. The previous assertion was `count() >= 0`, which is true for
        // every possible page — including one where the selector matches nothing,
        // which is exactly what was happening. A spec that cannot fail is not a
        // spec; this one now fails if the directory renders neither.
        const cards = await page.locator(sel.memberCard).count();
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        expect(
            cards > 0 || empty,
            'members directory rendered neither member cards nor an empty state'
        ).toBeTruthy();
    });

    test('J-27 follow from card toggles state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const cards = await countMemberCards(page);
        if (cards === 0) {
            softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
            return;
        }

        const firstCard = page.locator(sel.memberCard).first();
        const followBtn = firstCard.locator(sel.memberCardFollow).first();
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
        if ((await countMemberCards(page)) === 0) {
            softSkip(testInfo, 'Directory is genuinely empty (empty state shown).');
            return;
        }

        const more = firstCard.locator(sel.memberCardMenu).first();
        if (!(await more.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No more-actions menu in card markup yet.');
            return;
        }
        await more.click();

        const mute = page.locator(`${sel.memberCardMute}, [role="menuitem"]:has-text("Mute")`).first();
        await expect(mute).toBeVisible({ timeout: 3_000 });
    });
});
