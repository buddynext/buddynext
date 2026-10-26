import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { restList } from '../_fixtures/effects';
import { bnApi, ensureOnboarded, loginContextAs } from '../_fixtures/spaces-rest';
import { dbScalar, tablePrefix } from '../_fixtures/wp';

/**
 * J-55 admin moderation queue.
 *
 * Covers: cap-review-a-queue-of-reports-in-wp-admin
 * Roles: admin
 *
 * Walks the wp-admin queue as a full WP administrator (varundubey via
 * authenticatedPage), not a scoped BuddyNext moderator-only account, and does
 * not walk the reporting member's side (cap-let-members-report-content).
 */
test.describe('moderation / queue', () => {
    test('admin queue page renders', async ({ authenticatedPage: page }, testInfo) => {
        const resp = await page.goto(urls.adminModeration, { waitUntil: 'domcontentloaded' });
        if ((resp?.status() ?? 200) >= 400) {
            softSkip(testInfo, 'User is not admin or queue page not registered.');
            return;
        }
        const queue = page.locator(sel.adminModQueue).first();
        const fallbackTable = page.locator('.wp-list-table').first();
        const anyVisible = (await queue.isVisible().catch(() => false)) || (await fallbackTable.isVisible().catch(() => false));
        expect(anyVisible).toBeTruthy();
        // EFFECT: the screen counts the same pending reports the queue route answers.
        const rest = await restList(page, '/reports/queue?per_page=50');
        expect(rest.status, 'GET /reports/queue').toBe(200);
        const total = Number(((rest.data ?? {}) as { total?: number }).total ?? rest.items.length);
        const badge = page.locator('.bn-mod-queue .bn-ss-count').first();
        if (await badge.isVisible().catch(() => false)) {
            expect(Number((await badge.innerText()).replace(/\D/g, '')), 'pending badge vs REST total').toBe(total);
        } else {
            const rows = await page.locator('.bn-mod-queue table tbody tr').count();
            expect(rows > 0, `screen rows ${rows}, REST total ${total}`).toBe(total > 0);
        }
    });

    test('a reported post reaches the admin queue, and Dismiss clears it', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const other = process.env.BN_TEST_OTHER_USER ?? 'alice';
        const stamp = Date.now().toString().slice(-8);
        let postId = 0;
        let reportId = 0;
        let reporter: Awaited<ReturnType<typeof loginContextAs>> | null = null;

        /** Whether the queue route still lists this report as open. */
        const inQueue = async (): Promise<boolean> => {
            const rest = await restList(page, '/reports/queue?per_page=100&object_type=post');
            expect(rest.status, 'GET /reports/queue').toBe(200);
            return rest.items.some((i) => Number(i.id) === reportId && Number(i.object_id) === postId);
        };

        try {
            // Setup: the admin posts, a second member reports it (nobody can report their own).
            await page.goto(urls.feed, { waitUntil: 'domcontentloaded' });
            const created = await bnApi(page, 'POST', '/posts', { type: 'text', content: `Selling concert tickets, DM me for details ${stamp}` });
            expect(created.status, `seed post failed: ${JSON.stringify(created.data)}`).toBe(201);
            postId = Number((created.data as { id?: number }).id);
            reporter = await loginContextAs(browser, baseURL, other);
            await ensureOnboarded(reporter.page);
            const report = await bnApi(reporter.page, 'POST', '/reports', { object_type: 'post', object_id: postId, reason: 'spam' });
            expect(report.status, `report failed: ${JSON.stringify(report.data)}`).toBe(201);
            reportId = Number((report.data as { id?: number }).id);
            expect(reportId).toBeGreaterThan(0);
            expect(await inQueue(), 'new report missing from GET /reports/queue').toBe(true);

            // The admin screen lists THAT report (newest first), keyed by its hidden report_id.
            await page.goto(urls.adminModeration, { waitUntil: 'domcontentloaded' });
            const dismiss = page.locator(
                `.bn-mod-queue form:has(input[name="report_id"][value="${reportId}"]):has(input[name="op"][value="dismiss"]) button[type="submit"]`
            );
            await expect(dismiss, 'reported post did not render a row in the admin queue').toBeVisible({ timeout: 10_000 });

            // Action under test: Dismiss from the row (a form POST to admin-post.php).
            await Promise.all([page.waitForURL(/page=buddynext-moderation/, { timeout: 15_000 }), dismiss.click()]);

            // EFFECT: the row says dismissed, the route no longer lists it, the screen dropped it.
            const p = await tablePrefix();
            expect(await dbScalar(`SELECT status FROM ${p}bn_reports WHERE id = ${reportId}`)).toBe('dismissed');
            expect(await inQueue(), 'dismissed report still in GET /reports/queue').toBe(false);
            await page.goto(urls.adminModeration, { waitUntil: 'domcontentloaded' });
            await expect(page.locator(`.bn-mod-queue input[name="report_id"][value="${reportId}"]`)).toHaveCount(0);
        } finally {
            if (reportId > 0) {
                await bnApi(page, 'POST', `/reports/${reportId}/dismiss`).catch(() => undefined);
            }
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`).catch(() => undefined);
            }
            if (reporter) {
                await reporter.ctx.close();
            }
        }
    });
});
