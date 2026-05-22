import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-20-ai-smart-reply. Pro P2.4. Requires BN_PRO=1.
 */
test.describe('feed / AI smart-reply chips (Pro P2.4)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'AI smart-reply chips are a Pro feature. Set BN_PRO=1 to run.');

    test('clicking a smart-reply chip drops text into comment composer', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.feed);
        const firstCard = page.locator(sel.postCard).first();
        if ((await page.locator(sel.postCard).count()) === 0) {
            softSkip(testInfo, 'Feed empty.');
            return;
        }

        const chip = firstCard.locator('.bn-smart-reply-chip, [data-smart-reply]').first();
        await expect(chip).toBeVisible();
        const chipText = (await chip.innerText()).trim();
        await chip.click();

        const input = page.locator(sel.commentInput).first();
        await expect(input).toBeVisible();
        const value = await input.inputValue().catch(() => input.innerText());
        expect(value).toContain(chipText.slice(0, 8));
    });
});
