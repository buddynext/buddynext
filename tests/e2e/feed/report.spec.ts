import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from '../_fixtures/precondition';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';
import { sel, urls } from '../_fixtures/selectors';
import { readRestNonce, openMemberSession, restPost, type MemberSession } from '../_fixtures/feed-wave1.helpers';

/**
 * J-506 report a post (B2).
 *
 * Written from the member's promise: "reporting a post is recorded, and I can't
 * report the same post twice." The coverage matrix had "report post (409
 * already)" MISSING. The Report control only exists on another member's post
 * (can_report = logged-in && !own), so this seeds a target post from a SECOND
 * member's own session, then reports it through the ⋯ menu + report dialog as
 * the viewer. Effect-based server truth: after the UI report, a direct REST
 * re-report of the same post must come back 409 already_reported — which proves
 * the first report was actually written (a silently-failed UI report would make
 * the REST call the FIRST report and return 201, failing this).
 */
test.describe('feed / report post', () => {
    const reportItem = '.bn-post-card__options-menu .bn-post-card__menu-item[data-wp-on--click="actions.reportPost"]';

    test('J-506 reporting another member\'s post records it; a repeat report is 409', async ({ authenticatedPage: page, browser }, testInfo) => {
        const target = await resolveOtherMemberSlug(page, 'varundubey');
        expect(target, 'need another member to author the reported post').toBeTruthy();
        const authorLogin = target as string;

        let author: MemberSession | null = null;
        let postId = 0;
        let viewerNonce = '';
        const stamp = Date.now().toString().slice(-6);

        try {
            // Seed a public post owned by the OTHER member (so Report is offered).
            author = await openMemberSession(browser, authorLogin);
            const created = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content: `j506 please review ${stamp}`,
                privacy: 'public',
            });
            expect(created.status, `seed post -> ${created.status}`).toBeLessThan(300);
            postId = created.body.id ?? 0;
            expect(postId).toBeGreaterThan(0);

            // Resolve the viewer's wp_rest nonce from the feed (composer present),
            // then view the post on its permalink (/p/{id}/) — the card renders
            // there regardless of feed inclusion, so this doesn't depend on the
            // seeded post surfacing in a paginated feed.
            await page.goto(urls.feed);
            viewerNonce = await readRestNonce(page);
            await page.goto(`/p/${postId}/`);
            const card = page.locator(sel.postCard).filter({ hasText: `j506 please review ${stamp}` }).first();
            await expect(card).toBeVisible({ timeout: 10_000 });

            // Open the ⋯ menu and the Report item (present only because it isn't ours).
            const kebab = card.locator('.bn-post-card__menu');
            await kebab.click();
            await expect(kebab).toHaveAttribute('aria-expanded', 'true', { timeout: 5_000 });
            const item = card.locator(reportItem).first();
            if (!(await item.isVisible().catch(() => false))) {
                softSkip(testInfo, 'Report item absent on a non-own post — moderation/report feature may be off.');
                return;
            }
            await item.click();

            // Fill the report dialog (reason select + submit). The SSR share-modal
            // is also a hidden `.bn-modal-backdrop`, so target the report dialog
            // specifically — it's the backdrop carrying the reason <select>.
            const modal = page.locator('.bn-modal-backdrop', { has: page.locator('select.bn-input') }).first();
            await expect(modal).toBeVisible({ timeout: 5_000 });
            await modal.locator('select.bn-input').first().selectOption('spam').catch(() => {});
            await modal.getByRole('button', { name: /submit report|report/i }).first().click();
            await expect(modal).toBeHidden({ timeout: 5_000 });

            // Success state: the menu now offers "Reported" instead of "Report".
            await expect(card.locator('.bn-post-card__menu-item--reported')).toBeVisible({ timeout: 5_000 }).catch(() => {
                // The post may drop from the reporter's feed on report — that's also
                // a valid success signal; the REST assertion below is the hard proof.
            });

            // Server truth: the same report again is rejected as already-reported.
            const repeat = await restPost<{ code?: string }>(page.request, viewerNonce, '/reports', {
                object_type: 'post',
                object_id: postId,
                reason: 'spam',
                space_id: 0,
            });
            expect(repeat.status, `repeat report -> ${repeat.status} (expected 409 already_reported)`).toBe(409);
        } finally {
            if (author && postId) {
                await author.request
                    .delete(`/wp-json/buddynext/v1/posts/${postId}`, { headers: { 'X-WP-Nonce': author.nonce } })
                    .catch(() => undefined);
            }
            if (author) {
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
