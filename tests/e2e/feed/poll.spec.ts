import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restPost } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

/**
 * J-500 poll create + vote (B5), J-501 poll results + closed state (B5).
 *
 * Written from the member's promise: "the poll I build shows the options I
 * typed, the option I vote for counts my vote after a reload, and a poll that
 * has closed will not take another vote." The coverage matrix had B5 poll
 * vote / results / closed all MISSING — the only poll spec (compose.spec.ts
 * J-14) test.fixme's out and, when it runs, asserts option fields are visible
 * (presence). These assert the EFFECT after a full reload and against REST
 * truth, so a poll write that 200s client-side but never persists — or a
 * closed-poll gate that is missing server-side — fails here.
 *
 * Effect-based: vote persistence is re-read after reload (is-voted + vote
 * total), and the closed-poll gate is proven with a REST POST /vote that must
 * be rejected. Each poll created is deleted in `finally`, so the feed is left
 * as found even on a mid-test failure.
 */
test.describe('feed / poll', () => {
    const pollWrap = '.bn-post-card__poll';
    const pollOption = '.bn-post-card__poll-option';
    const pollTotal = '.bn-post-card__poll-total';
    const pollClosed = '.bn-post-card__poll-closed';
    // The poll tool lives in the composer toolbar; scope to __tools so it never
    // resolves to the poll panel's own Cancel button (which also binds togglePoll).
    const pollTool = '.bn-composer__tools .bn-composer__tool[data-wp-on--click="actions.togglePoll"]';
    const pollOptionInput = '.bn-composer__poll-option';

    const cardWith = (page: Page, text: string) =>
        page.locator(sel.postCard).filter({ hasText: text });

    test('J-500 poll created via composer renders its options; a vote persists after reload', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const question = `j500 favourite build tool ${stamp}`;
        const opts = [`Webpack ${stamp}`, `Vite ${stamp}`, `esbuild ${stamp}`];
        let createdId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            nonce = await readRestNonce(page);

            // Type the poll question, switch to poll mode, fill the options.
            await page.locator(sel.composerTextarea).first().fill(question);
            await page.locator(pollTool).first().click();
            const panel = page.locator('.bn-composer__poll-options').first();
            await expect(panel).toBeVisible();

            const inputs = page.locator(pollOptionInput);
            for (let i = 0; i < opts.length; i++) {
                await inputs.nth(i).fill(opts[i]);
            }

            await page.locator(sel.composerSubmit).first().click();
            await expect(cardWith(page, question).first()).toBeVisible({ timeout: 10_000 });

            // Reload so the card is server-rendered from bn_posts / bn_poll_options.
            await page.goto(urls.feed);
            const card = cardWith(page, question).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, question);

            // The options I typed are the options the poll shows.
            const poll = card.locator(pollWrap).first();
            await expect(poll).toBeVisible();
            for (const label of opts) {
                await expect(poll.locator(pollOption).filter({ hasText: label })).toHaveCount(1);
            }

            // Vote the first option; the store rebinds is-voted + the running total.
            const firstOption = poll.locator(pollOption).first();
            await firstOption.click();
            await expect(poll.locator(`${pollOption}.is-voted`)).toHaveCount(1, { timeout: 8_000 });

            // Persistence: after a full reload the vote is still mine (server sends
            // my_voted_option_id -> is-voted) and the total reflects the one vote.
            await page.goto(urls.feed);
            const cardAfter = cardWith(page, question).first();
            await expect(cardAfter).toBeVisible({ timeout: 10_000 });
            await expect(cardAfter.locator(`${pollOption}.is-voted`)).toHaveCount(1);
            await expect(cardAfter.locator(pollTotal)).toContainText('1 vote');
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });

    test('J-501 a closed poll disables its options and rejects a fresh vote', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-6);
        const question = `j501 closed poll ${stamp}`;
        let createdId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);

            // Build a poll whose deadline is already in the past — the deterministic
            // way to reach the closed state (insert_poll_options accepts any valid
            // UTC datetime; the vote gate keys on end_date <= UTC_TIMESTAMP()).
            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content: question,
                type: 'poll',
                privacy: 'public',
                options: [`Yes ${stamp}`, `No ${stamp}`],
                poll_end_date: '2020-01-01 00:00:00',
            });
            expect(created.status, `create closed poll -> ${created.status}`).toBeLessThan(300);
            createdId = created.body.id ?? 0;
            expect(createdId).toBeGreaterThan(0);

            await page.goto(urls.feed);
            const card = cardWith(page, question).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Closed state: options are rendered disabled and the "Poll closed"
            // notice shows — not a live, clickable poll.
            const poll = card.locator(pollWrap).first();
            await expect(poll).toBeVisible();
            const options = poll.locator(pollOption);
            const optCount = await options.count();
            expect(optCount).toBeGreaterThanOrEqual(2);
            for (let i = 0; i < optCount; i++) {
                await expect(options.nth(i)).toBeDisabled();
            }
            await expect(poll.locator(pollClosed)).toBeVisible();

            // Server truth: the vote route itself refuses a closed poll. A closed
            // poll that "disables re-vote" only in the DOM but still accepts a
            // direct POST would pass a presence check and fail this one.
            const optionId = await options.first().getAttribute('data-option-id');
            const vote = await restPost<{ code?: string }>(page.request, nonce, `/posts/${createdId}/vote`, {
                option_id: Number(optionId),
            });
            expect(vote.status, `vote on closed poll -> ${vote.status}`).toBeGreaterThanOrEqual(400);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
