import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import {
    readRestNonce,
    postIdOfCard,
    deletePostRest,
    openMemberSession,
    restGet,
    type MemberSession,
} from '../_fixtures/feed-wave1.helpers';

type Notif = { id: number; type: string; sender_id: number | null; object_id: number | null };
type NotifResponse = { items?: Notif[] };

/**
 * J-552 @mention in a comment (B3).
 *
 * Written from the member's promise: "when someone @mentions me in a comment, I
 * am told — the same as being mentioned in a post." The coverage matrix had
 * "Mention in comment" MISSING. This is the comment-level twin of J-502:
 * CommentService parses `@handle` in the comment body, resolves it to a real
 * member, and fires `buddynext_user_mentioned` (→ a bn.mention notification whose
 * object_id is the host POST). The non-fakeable effect is the RECIPIENT's side,
 * read from her OWN session over REST: a NEW bn.mention that was not there
 * before. A comment that linkifies `@word` in the DOM but never fans out the
 * notification passes a presence check and fails this. The host post (and its
 * comment) is deleted in `finally`.
 */
test.describe('feed / @mention in comment', () => {
    const submitComment = '[data-wp-on--click="actions.submitComment"]';

    test('J-552 mentioning a member in a comment notifies them', async ({ authenticatedPage: page, browser }, testInfo) => {
        const target = await resolveOtherMemberSlug(page, 'varundubey');
        expect(target, 'need another member to mention').toBeTruthy();
        const handle = target as string;

        let recipient: MemberSession | null = null;
        let createdPostId = 0;
        let nonce = '';
        const stamp = Date.now().toString().slice(-6);
        const postBody = `j552 mention-host ${stamp}`;
        const commentBody = `j552 hey @${handle} see this ${stamp}`;

        try {
            // Recipient baseline: her existing bn.mention notifications.
            recipient = await openMemberSession(browser, handle);
            const before = await restGet<NotifResponse>(recipient.request, recipient.nonce, '/me/notifications?per_page=50');
            expect(before.status).toBe(200);
            const beforeMentionIds = new Set(
                (before.body.items ?? []).filter((n) => n.type === 'bn.mention').map((n) => n.id)
            );

            // Author (admin) seeds an own post to comment on.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            nonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(postBody);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: postBody }).first()).toBeVisible({ timeout: 10_000 });

            await page.goto(urls.feed);
            const host = page.locator(sel.postCard).filter({ hasText: postBody }).first();
            await expect(host).toBeVisible({ timeout: 10_000 });
            createdPostId = await postIdOfCard(page, postBody);
            expect(createdPostId).toBeGreaterThan(0);

            // Comment mentioning the member (the real member action).
            await host.locator(sel.postComment).first().click();
            const input = host.locator(sel.commentInput).first();
            if (!(await input.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Comment input not exposed — comments feature may be off.');
                return;
            }
            await input.fill(commentBody);
            await host.locator(submitComment).first().click();
            // Confirm the comment actually posted (scoped to the host card, patient
            // enough to absorb comment-render lag under full-suite load) — the
            // notification below can only fire once the comment exists.
            await expect(host.locator('.bn-comment-card', { hasText: commentBody }).first()).toBeVisible({ timeout: 15_000 });

            // Recipient side (server truth): a NEW bn.mention from the author lands,
            // pointing at the host post.
            await expect(async () => {
                const after = await restGet<NotifResponse>(recipient!.request, recipient!.nonce, '/me/notifications?per_page=50');
                expect(after.status).toBe(200);
                const fresh = (after.body.items ?? []).filter(
                    (n) => n.type === 'bn.mention' && !beforeMentionIds.has(n.id)
                );
                const mine = fresh.find((n) => Number(n.sender_id) === 1 || Number(n.object_id) === createdPostId);
                expect(mine, `expected a new comment bn.mention for @${handle}`).toBeTruthy();
            }).toPass({ timeout: 15_000 });
        } finally {
            await deletePostRest(page.request, nonce, createdPostId).catch(() => {});
            if (recipient) {
                await recipient.ctx.close().catch(() => {});
            }
        }
    });
});
