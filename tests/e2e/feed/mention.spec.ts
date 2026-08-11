import { test, expect } from '../_fixtures/auth.fixture';
import { sel, urls } from '../_fixtures/selectors';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';
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
 * J-502 @mention in a post (B1).
 *
 * Written from the member's promise: "when I @mention someone, my post links to
 * their profile AND they are told about it." The coverage matrix had @mention
 * MISSING. The strong, non-fakeable effect here is the RECIPIENT's side: the
 * post body linkifies ANY @word (so the anchor alone proves nothing about the
 * member), but the bn.mention NOTIFICATION only fires when Handle::resolve()
 * finds a real member and mention privacy allows it. So this asserts both:
 *   1. the reloaded card renders a `.bn-mention` anchor to /members/{slug}/, and
 *   2. that member — read from her OWN logged-in session over REST — has a NEW
 *      bn.mention notification from the author that was not there before.
 *
 * The composer has no typeahead popover (verified in source: it only supports a
 * `?mention=` prefill), so the member action is typing "@handle" into the
 * composer and posting — which is exactly how a mention is authored today.
 */
test.describe('feed / @mention', () => {
    const mentionAnchor = 'a.bn-mention';

    test('J-502 mentioning a member links their profile and notifies them', async ({ authenticatedPage: page, browser }) => {
        // Resolve a real OTHER member to mention (self-mentions never notify).
        const target = await resolveOtherMemberSlug(page, 'varundubey');
        expect(target, 'need at least one other member to mention').toBeTruthy();
        const handle = target as string;

        let recipient: MemberSession | null = null;
        let createdId = 0;
        let authorNonce = '';

        try {
            // Recipient baseline: the bn.mention notifications she already has.
            recipient = await openMemberSession(browser, handle);
            const recipientId = await recipient.page.evaluate(() => {
                const el = document.querySelector('[data-wp-interactive="buddynext/post-composer"]');
                try { return el ? Number(JSON.parse(el.getAttribute('data-wp-context') ?? '{}').userId) || 0 : 0; } catch { return 0; }
            });
            const before = await restGet<NotifResponse>(recipient.request, recipient.nonce, '/me/notifications?per_page=50');
            expect(before.status).toBe(200);
            const beforeMentionIds = new Set(
                (before.body.items ?? []).filter((n) => n.type === 'bn.mention').map((n) => n.id)
            );

            // Author the mention post through the composer (the real member action).
            const stamp = Date.now().toString().slice(-6);
            const body = `j502 hey @${handle} take a look ${stamp}`;
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            authorNonce = await readRestNonce(page);
            await page.locator(sel.composerTextarea).first().fill(body);
            await page.locator(sel.composerSubmit).first().click();
            await expect(page.locator(sel.postCard).filter({ hasText: stamp }).first()).toBeVisible({ timeout: 10_000 });

            // Reload; the persisted card renders a mention anchor to the profile.
            await page.goto(urls.feed);
            const card = page.locator(sel.postCard).filter({ hasText: stamp }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });
            createdId = await postIdOfCard(page, stamp);
            const anchor = card.locator(mentionAnchor).filter({ hasText: `@${handle}` }).first();
            await expect(anchor).toBeVisible();
            await expect(anchor).toHaveAttribute('href', new RegExp(`/members/${handle}/?$|/${handle}/?$`));

            // Recipient side (server truth): a NEW bn.mention from the author lands.
            // Poll briefly — the mention fan-out is synchronous on publish but the
            // read is a separate session, so allow the row to settle.
            await expect(async () => {
                const after = await restGet<NotifResponse>(recipient!.request, recipient!.nonce, '/me/notifications?per_page=50');
                expect(after.status).toBe(200);
                const fresh = (after.body.items ?? []).filter(
                    (n) => n.type === 'bn.mention' && !beforeMentionIds.has(n.id)
                );
                // A new mention notification exists, authored by the mentioner, and
                // (when populated) pointing at the post we just created.
                const mine = fresh.find((n) => Number(n.sender_id) === 1 || Number(n.object_id) === createdId);
                expect(mine, `expected a new bn.mention for @${handle} (recipient #${recipientId})`).toBeTruthy();
            }).toPass({ timeout: 15_000 });
        } finally {
            await deletePostRest(page.request, authorNonce, createdId).catch(() => {});
            if (recipient) {
                await recipient.ctx.close().catch(() => {});
            }
        }
    });
});
