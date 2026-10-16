import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, postIdOfCard, deletePostRest, restPost, restGet } from '../_fixtures/feed-wave1.helpers';
import type { Page } from '@playwright/test';

type PollOption = { id: number; option_text: string; vote_count: number };

/** The poll's options and tallies, per the poll route (the same rows the card totals from). */
async function pollResults(page: Page, nonce: string, postId: number): Promise<PollOption[]> {
    const r = await restGet<{ results?: PollOption[] }>(page.request, nonce, `/posts/${postId}/poll`);
    expect(r.status, 'GET /posts/{id}/poll').toBe(200);
    return r.body.results ?? [];
}

/** The option this member voted for, per the my-vote route (0 when none). */
async function myVote(page: Page, nonce: string, postId: number): Promise<number> {
    return Number((await restGet<{ option_id?: number | null }>(page.request, nonce, `/posts/${postId}/my-vote`)).body.option_id ?? 0);
}

/**
 * EFFECT: one vote, on the option clicked, and nowhere else. The is-voted class and the
 * "1 vote" label are painted client-side; these are the rows behind them.
 */
async function expectSingleVote(page: Page, nonce: string, postId: number, optionId: number): Promise<void> {
    await expect.poll(() => myVote(page, nonce, postId), { timeout: 8_000 }).toBe(optionId);
    const results = await pollResults(page, nonce, postId);
    expect(results.find((o) => o.id === optionId)?.vote_count, 'votes on the option I picked').toBe(1);
    expect(results.reduce((sum, o) => sum + Number(o.vote_count), 0), 'votes across the whole poll').toBe(1);
}

const MEMBER_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'alice';

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
 *
 * Covers: cap-post-text-links-images-video-and-polls
 * Roles: admin, member
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
            // EFFECT: the poll route holds the options I typed, in order, with no votes yet.
            const fresh = await pollResults(page, nonce, createdId);
            expect(fresh.map((o) => o.option_text), 'stored options').toEqual(opts);
            expect(fresh.map((o) => Number(o.vote_count)), 'votes on a fresh poll').toEqual([0, 0, 0]);
            expect(await myVote(page, nonce, createdId), 'my vote before voting').toBe(0);

            // Vote the first option; the store rebinds is-voted + the running total.
            const firstOption = poll.locator(pollOption).first();
            const votedOptionId = Number(await firstOption.getAttribute('data-option-id'));
            expect(votedOptionId, 'option id on the first option').toBeGreaterThan(0);
            await firstOption.click();
            await expect(poll.locator(`${pollOption}.is-voted`)).toHaveCount(1, { timeout: 8_000 });
            await expectSingleVote(page, nonce, createdId, votedOptionId);

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

    /**
     * J-500 member leg. The admin walk above proves the poll write + vote
     * round-trip works for a site owner; a member is the one actually building
     * and voting on polls day to day. Same effect bar: options I typed are the
     * options the poll shows, and my vote survives a full reload.
     */
    test('J-500 member  -  a member-created poll renders its options; a member vote persists after reload', async ({ page }) => {
        await loginAs(page, MEMBER_LOGIN);
        const stamp = Date.now().toString().slice(-6);
        const question = `j500m member poll ${stamp}`;
        const opts = [`Red ${stamp}`, `Blue ${stamp}`, `Green ${stamp}`];
        let createdId = 0;
        let nonce = '';

        try {
            await page.goto(urls.feed);
            const composer = page.locator(sel.composer).first();
            await expect(composer).toBeVisible();
            nonce = await readRestNonce(page);

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

            const poll = card.locator(pollWrap).first();
            await expect(poll).toBeVisible();
            for (const label of opts) {
                await expect(poll.locator(pollOption).filter({ hasText: label })).toHaveCount(1);
            }
            // EFFECT: the poll route holds the member's options, in order, with no votes yet.
            const fresh = await pollResults(page, nonce, createdId);
            expect(fresh.map((o) => o.option_text), 'stored options').toEqual(opts);
            expect(fresh.map((o) => Number(o.vote_count)), 'votes on a fresh poll').toEqual([0, 0, 0]);

            const firstOption = poll.locator(pollOption).first();
            const votedOptionId = Number(await firstOption.getAttribute('data-option-id'));
            expect(votedOptionId, 'option id on the first option').toBeGreaterThan(0);
            await firstOption.click();
            await expect(poll.locator(`${pollOption}.is-voted`)).toHaveCount(1, { timeout: 8_000 });
            await expectSingleVote(page, nonce, createdId, votedOptionId);

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

            // EFFECT: the refusal left no trace. No vote row for me, no tally moved.
            expect(await myVote(page, nonce, createdId), 'my vote on the closed poll').toBe(0);
            const tallies = await pollResults(page, nonce, createdId);
            expect(tallies.length, 'options on the closed poll').toBe(2);
            expect(tallies.reduce((sum, o) => sum + Number(o.vote_count), 0), 'votes on the closed poll').toBe(0);
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });

    /**
     * J-982 a visitor who cannot vote still sees the real results (card 10405808074).
     *
     * The server paints the right percentages; the page script then redraws each option
     * from its own context. An option rendered without that context (every option a
     * visitor sees) was redrawn as 0% with an empty bar while the total still read
     * "N votes". So this reads the numbers AFTER the script has run, as a logged-out
     * visitor on the post's own page, against the poll route's tallies.
     */
    test('J-982 a logged-out visitor sees the real poll percentages after the page script runs', async ({ authenticatedPage: page, browser }) => {
        test.fail(true, 'card 10405808074: options a visitor cannot vote on hydrate to 0%');
        const stamp = Date.now().toString().slice(-6);
        let createdId = 0;
        let nonce = '';
        try {
            await page.goto(urls.feed);
            nonce = await readRestNonce(page);
            const created = await restPost<{ id?: number }>(page.request, nonce, '/posts', {
                content: `j982 visitor poll ${stamp}`,
                type: 'poll',
                privacy: 'public',
                options: [`Yes ${stamp}`, `No ${stamp}`],
            });
            expect(created.status, `create poll -> ${created.status}`).toBeLessThan(300);
            createdId = created.body.id ?? 0;
            const options = await pollResults(page, nonce, createdId);
            const yes = options.find((o) => o.option_text === `Yes ${stamp}`)?.id ?? 0;
            const vote = await restPost(page.request, nonce, `/posts/${createdId}/vote`, { option_id: yes });
            expect(vote.status, 'vote').toBe(200);
            // EFFECT the visitor must see: one vote, all on Yes.
            expect((await pollResults(page, nonce, createdId)).find((o) => o.id === yes)?.vote_count).toBe(1);

            const guest = await browser.newContext();
            try {
                const visitor = await guest.newPage();
                await visitor.goto(`/p/${createdId}/`);
                const poll = visitor.locator('.bn-post-card__poll').first();
                await expect(poll).toBeVisible({ timeout: 10_000 });
                await visitor.waitForLoadState('networkidle');
                const yesOption = poll.locator(pollOption).filter({ hasText: `Yes ${stamp}` });
                // Read what is on screen after hydration, not the server's first paint.
                await expect(yesOption.locator('.bn-post-card__poll-pct')).toHaveText('100%');
                const width = await yesOption.locator('.bn-post-card__poll-fill').evaluate((el) => (el as HTMLElement).style.width);
                expect(width, 'the Yes bar is drawn full width').toBe('100%');
                // Negative twin: the option nobody chose stays at 0%.
                await expect(poll.locator(pollOption).filter({ hasText: `No ${stamp}` }).locator('.bn-post-card__poll-pct')).toHaveText('0%');
            } finally {
                await guest.close();
            }
        } finally {
            await deletePostRest(page.request, nonce, createdId).catch(() => {});
        }
    });
});
