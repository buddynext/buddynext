import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip, resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import {
    readRestNonce,
    deletePostRest,
    openMemberSession,
    restPost,
    restGet,
    type MemberSession,
} from '../_fixtures/feed-wave1.helpers';

type AnnouncementList = { announcements?: Array<{ id: number }> };

/**
 * J-550 dismiss a site-wide announcement (B2).
 *
 * Written from the member's promise: "when I dismiss an announcement it stays
 * gone — for me, after a reload." The coverage matrix had "End/Dismiss
 * announcement" MISSING. The site-wide announcement is prepended to page 1 of
 * every member's home feed (FeedService::active_announcement); the normal feed
 * query excludes `type='announcement'` (FeedService line 475), so the ONLY way
 * the card is on the page is that injection — which keys on the viewer's
 * per-user dismissal meta.
 *
 * Effect-based, from the RECIPIENT's side: an admin seeds a site-wide
 * announcement; a NON-admin member sees its card, dismisses it through the card's
 * own Dismiss control (`POST /feed/announcements/{id}/dismiss`), and after a full
 * reload the announcement is gone from the member's feed AND from the endpoint
 * the app/badge reads (`GET /feed/announcements`). A dismiss that flips the DOM
 * but never persists the user_meta re-injects the card on reload and fails here.
 * The announcement post is deleted in `finally`.
 */
test.describe('feed / dismiss announcement', () => {
    const annCard = '.bn-post-card--announcement';
    const annDismiss = '.bn-post-card--announcement .bn-post-card__ann-dismiss';

    test('J-550 a dismissed announcement stays gone for the member after reload', async ({ authenticatedPage: page, browser }, testInfo) => {
        const memberSlug = await resolveOtherMemberSlug(page, 'varundubey');
        if (!memberSlug) {
            softSkip(testInfo, 'No non-admin member to dismiss the announcement as.');
            return;
        }

        let adminNonce = '';
        let annId = 0;
        let member: MemberSession | null = null;
        const stamp = Date.now().toString().slice(-6);
        const content = `j550 site announcement ${stamp}`;

        try {
            // Admin (varundubey) seeds a site-wide announcement.
            await page.goto(urls.feed);
            await expect(page.locator(sel.composer).first()).toBeVisible();
            adminNonce = await readRestNonce(page);
            const created = await restPost<{ id?: number; code?: string }>(page.request, adminNonce, '/posts', {
                content,
                type: 'announcement',
                privacy: 'public',
            });
            if (created.status === 403) {
                softSkip(testInfo, 'Announcements feature disabled or not permitted — cannot seed one.');
                return;
            }
            expect(created.status, `seed announcement -> ${created.status}`).toBeLessThan(300);
            annId = created.body.id ?? 0;
            expect(annId).toBeGreaterThan(0);

            // Member session: the announcement is served to them (feed + endpoint).
            // Poll the endpoint — under load the just-created announcement can lag a
            // beat behind the create in the announcement-id cache; this still
            // requires the member to actually be served MY announcement.
            member = await openMemberSession(browser, memberSlug);
            const served = member as MemberSession;
            await expect
                .poll(async () => {
                    const res = await restGet<AnnouncementList>(served.request, served.nonce, '/feed/announcements');
                    return (res.body.announcements ?? []).some((a) => a.id === annId);
                }, { timeout: 10_000 })
                .toBe(true);

            await member.page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
            const card = member.page.locator(annCard).filter({ hasText: content }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Dismiss through the card's own control.
            await member.page.locator(annDismiss).first().click();
            // Optimistic removal from the DOM.
            await expect(member.page.locator(annCard).filter({ hasText: content })).toHaveCount(0, { timeout: 8_000 });

            // Persistence: after a full reload the announcement is still gone from
            // the feed AND from the endpoint the app reads.
            await member.page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
            await expect(member.page.locator(annCard).filter({ hasText: content })).toHaveCount(0);
            const after = await restGet<AnnouncementList>(member.request, member.nonce, '/feed/announcements');
            expect(after.status).toBe(200);
            expect(
                (after.body.announcements ?? []).some((a) => a.id === annId),
                'the dismissed announcement must not be served to the member after reload'
            ).toBe(false);
        } finally {
            await deletePostRest(page.request, adminNonce, annId).catch(() => {});
            if (member) {
                await member.ctx.close().catch(() => {});
            }
        }
    });
});
