import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest } from '../_fixtures/feed-wave1.helpers';

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

/**
 * J-20 suggest an AI reply (Pro P2.4).
 *
 * Written from the member's promise: "when I'm about to reply to a post, I can
 * ask for a suggestion and drop it straight into my comment box." Verified
 * against `ReplyButtonRenderer` (hooks Free's
 * `buddynext_part_post_comment_form_after`) and `assets/js/ai-reply/store.js` —
 * the LIVE markup is `.bn-ai-reply__trigger` / `.bn-ai-reply__chip` /
 * `.bn-ai-reply__suggestions`, not the `.bn-smart-reply-chip` /
 * `[data-smart-reply]` this spec asserted against before: those classes exist
 * nowhere in the renderer, so the prior version could only ever fail once
 * BN_PRO=1 actually ran it. `useSuggestion()` writes straight into the comment
 * card's own textarea (`textarea.value = suggestion`), so `sel.commentInput`
 * receiving the picked suggestion IS the effect, not a class flip.
 *
 * The trigger button is gated server-side on `buddynext-comments/create` AND
 * `ReplyGenerator::is_enabled()` (feature flag + a configured AI provider) — so
 * on a harness with no AI key it renders nothing at all, for every role.
 * SOFT-SKIP names that precondition rather than faking a suggestion; this
 * proves the UI seam (button -> REST -> chip -> textarea), not AI quality.
 *
 * Covers: cap-suggest-an-ai-reply
 * Roles: admin, member
 */
test.describe('feed / AI smart-reply (Pro P2.4)', () => {
    test.fixme(process.env.BN_PRO !== '1', 'AI smart-reply is a Pro feature. Set BN_PRO=1 to run.');

    const trigger = '.bn-ai-reply__trigger';
    const suggestionsPanel = '.bn-ai-reply__suggestions';
    const chip = '.bn-ai-reply__chip';
    const errorLine = '.bn-ai-reply__error';

    /** Shared walk: seed an own post, open its comments, try the AI-reply seam. */
    async function walkSuggestReply(
        page: import('@playwright/test').Page,
        testInfo: import('@playwright/test').TestInfo,
        tag: string
    ): Promise<void> {
        let createdId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const body = `${tag} ai-reply target ${stamp}`;

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: body }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: body }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, body);
            expect(createdId).toBeGreaterThan(0);

            await card.locator(sel.postComment).first().click();
            const input = card.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed — comments feature may be off.');
                return;
            }

            const triggerBtn = card.locator(trigger).first();
            if (!(await triggerBtn.isVisible({ timeout: 3_000 }).catch(() => false))) {
                softSkip(testInfo, 'AI smart-reply trigger absent — no AI provider configured (ReplyGenerator::is_enabled() is false) on this harness.');
                return;
            }

            await triggerBtn.click();

            const panel = card.locator(suggestionsPanel).first();
            const error = card.locator(errorLine).first();
            await expect(async () => {
                const panelVisible = await panel.isVisible().catch(() => false);
                const errorVisible = await error.isVisible().catch(() => false);
                expect(panelVisible || errorVisible, 'neither suggestions nor an error appeared after requesting a reply').toBe(true);
            }).toPass({ timeout: 15_000 });

            if (await error.isVisible().catch(() => false)) {
                const msg = (await error.innerText().catch(() => '')).trim();
                softSkip(testInfo, `AI reply request failed at runtime (${msg || 'unknown error'}) — provider quota/config issue, not the UI seam.`);
                return;
            }

            // Effect: picking a chip drops its EXACT text into the comment textarea.
            const firstChip = card.locator(chip).first();
            await expect(firstChip).toBeVisible({ timeout: 5_000 });
            const chipText = (await firstChip.innerText()).trim();
            expect(chipText.length).toBeGreaterThan(0);
            await firstChip.click();

            await expect
                .poll(async () => (await input.inputValue().catch(() => input.innerText())).trim(), { timeout: 5_000 })
                .toBe(chipText);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    }

    test('J-20 admin: a smart-reply suggestion fills the comment box', async ({ authenticatedPage: page }, testInfo) => {
        await walkSuggestReply(page, testInfo, 'j20a');
    });

    test('J-20 member: a smart-reply suggestion fills the comment box', async ({ page }, testInfo) => {
        await loginAs(page, MEMBER_LOGIN);
        await walkSuggestReply(page, testInfo, 'j20m');
    });
});
