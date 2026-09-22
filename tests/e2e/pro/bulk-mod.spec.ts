import { test, expect } from '../_fixtures/auth.fixture';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';
import { urls } from '../_fixtures/selectors';
import { readRestNonce, restPost, openMemberSession, type MemberSession } from '../_fixtures/feed-wave1.helpers';

/**
 * J-955 act on many reports at once (Pro).
 *
 * Written from the admin's promise: "I select several reports and one Apply
 * clears every one of them — I don't dismiss a queue of hundreds one row at a
 * time." Built at Moderation -> Bulk (`BulkModAdmin`, tab `moderation:bulk`,
 * page `buddynext-moderation&tab=bulk`): checkboxes
 * `input[name="bnpro_report_ids[]"]`, the `#bnpro_bulk_action` select, and
 * `[data-bn-bulk-apply]` submit to `admin-post.php?action=bnpro_bulk_dismiss` ->
 * `BulkModService::dispatch('dismiss', $ids, ...)`. The Apply click is gated by
 * the shared JS confirm dialog (`bn-admin-dialogs.js`), which this test accepts
 * via its own `.bn-dialog__ok`, not a native `window.confirm`.
 *
 * Effect-based: TWO real reports, on two different posts by another member, are
 * seeded via `POST /reports` (the same endpoint the report dialog itself calls).
 * Both are selected together and dismissed in ONE Apply click; the proof of
 * "many at once" (not "the first of many") is that `ModerationService::get_queue()`
 * — which filters `status IN ('pending','escalated')` — no longer lists EITHER
 * id afterward, not just the one a single-row action would have caught. Both
 * seeded posts are deleted in `finally`.
 *
 * Covers: cap-act-on-many-reports-at-once
 * Roles: admin
 */
test.describe('pro / bulk moderation actions', () => {
    test.fixme(process.env.BN_PRO !== '1', 'Bulk moderation is a Pro feature. Set BN_PRO=1 to run.');

    const bulkAdminUrl = '/wp-admin/admin.php?page=buddynext-moderation&tab=bulk';
    const reportCheckbox = (id: number) => `input[name="bnpro_report_ids[]"][value="${id}"]`;
    const reportRow = (id: number) => `tr:has(${reportCheckbox(id)})`;

    test('J-955 dismissing two selected reports together clears both from the queue', async ({ authenticatedPage: page }) => {
        const target = await resolveOtherMemberSlug(page, 'varundubey');
        expect(target, 'need another member to author the reported posts').toBeTruthy();
        const authorLogin = target as string;

        let author: MemberSession | null = null;
        let postIdA = 0;
        let postIdB = 0;
        let adminNonce = '';
        let reportIdA = 0;
        let reportIdB = 0;
        const stamp = Date.now().toString().slice(-6);

        try {
            author = await openMemberSession(page.context().browser()!, authorLogin);
            const postA = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content: `j955a bulk-mod target ${stamp}`,
                privacy: 'public',
            });
            const postB = await restPost<{ id?: number }>(author.request, author.nonce, '/posts', {
                content: `j955b bulk-mod target ${stamp}`,
                privacy: 'public',
            });
            postIdA = postA.body.id ?? 0;
            postIdB = postB.body.id ?? 0;
            expect(postIdA, 'seed post A').toBeGreaterThan(0);
            expect(postIdB, 'seed post B').toBeGreaterThan(0);

            await page.goto(urls.feed);
            adminNonce = await readRestNonce(page);

            const reportA = await restPost<{ id?: number }>(page.request, adminNonce, '/reports', {
                object_type: 'post',
                object_id: postIdA,
                reason: 'spam',
                space_id: 0,
            });
            const reportB = await restPost<{ id?: number }>(page.request, adminNonce, '/reports', {
                object_type: 'post',
                object_id: postIdB,
                reason: 'spam',
                space_id: 0,
            });
            reportIdA = reportA.body.id ?? 0;
            reportIdB = reportB.body.id ?? 0;
            expect(reportA.status, `seed report A -> ${reportA.status}`).toBeLessThan(300);
            expect(reportB.status, `seed report B -> ${reportB.status}`).toBeLessThan(300);
            expect(reportIdA, 'report A id').toBeGreaterThan(0);
            expect(reportIdB, 'report B id').toBeGreaterThan(0);

            // Both reports are visible in the default (pending/escalated) queue.
            await page.goto(bulkAdminUrl);
            await expect(page.locator(reportRow(reportIdA))).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(reportRow(reportIdB))).toBeVisible({ timeout: 10_000 });

            // Select BOTH, one bulk action, one Apply.
            await page.locator(reportCheckbox(reportIdA)).check();
            await page.locator(reportCheckbox(reportIdB)).check();
            await page.locator('#bnpro_bulk_action').selectOption('bnpro_bulk_dismiss');
            await page.locator('[data-bn-bulk-apply]').click();

            const dialog = page.locator('.bn-dialog-backdrop');
            await expect(dialog).toBeVisible({ timeout: 5_000 });
            await dialog.locator('.bn-dialog__ok').click();

            // Effect: NEITHER report is in the default queue anymore — proving the
            // bulk dispatch acted on both ids in one call, not just the first row.
            await page.goto(bulkAdminUrl);
            await expect(page.locator(reportRow(reportIdA))).toHaveCount(0, { timeout: 10_000 });
            await expect(page.locator(reportRow(reportIdB))).toHaveCount(0);
        } finally {
            if (author) {
                if (postIdA) {
                    await author.request
                        .delete(`/wp-json/buddynext/v1/posts/${postIdA}`, { headers: { 'X-WP-Nonce': author.nonce } })
                        .catch(() => undefined);
                }
                if (postIdB) {
                    await author.request
                        .delete(`/wp-json/buddynext/v1/posts/${postIdB}`, { headers: { 'X-WP-Nonce': author.nonce } })
                        .catch(() => undefined);
                }
                await author.ctx.close().catch(() => {});
            }
        }
    });
});
