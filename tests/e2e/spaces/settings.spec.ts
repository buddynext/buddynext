import { test, expect } from '../_fixtures/auth.fixture';
import { getSpace, createSpaceApi, deleteSpaceApi, bnApi, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-52 space general settings save + J-54 moderation queue — UPGRADED to
 * effect-based (Wave-4).
 *
 * The Wave-1 versions only asserted that the settings form / mod tab was
 * VISIBLE on a hard-coded env space (and soft-skipped when the admin didn't own
 * it). Both now seed their own throwaway space and assert a real consequence:
 *   - J-52: editing the name + description through the real Settings → General
 *     form persists — the reloaded inputs hold the new values AND REST GET of
 *     the space returns them. Mirrors the native save path proven by J-601.
 *   - J-54: a genuinely-reported post surfaces in the space moderation queue —
 *     the exact report card renders (keyed by its reportId) and REST
 *     GET /reports/queue lists it. The report is dismissed + torn down after.
 *
 * Space tabs use CLEAN URLs; the settings sub-tabs are `?bn_stab=<tab>`.
 */
test.describe('spaces / settings + moderation', () => {
    test('J-52 general settings save name + description persists', async ({ authenticatedPage: page }) => {
        // Per-spec selectors (not the shared dict): the general settings form.
        const nameInput = '#space_name';
        const descInput = '#space_description';
        const savebarSubmit = '[data-bn-savebar-submit]';
        const savedNotice = '.bn-space-settings__notice[data-tone="success"]';

        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Settings ${stamp}`, type: 'open' });
        const newName = `Renamed Space ${stamp}`;
        const newDesc = `Rewritten description ${stamp} — persisted by J-52.`;

        try {
            await page.goto(`/spaces/${space.slug}/settings/?bn_stab=general`, {
                waitUntil: 'domcontentloaded',
            });

            const name = page.locator(nameInput);
            const desc = page.locator(descInput);
            await expect(name, 'general settings form not visible (owner-only)').toBeVisible({
                timeout: 10_000,
            });

            await name.fill(newName);
            await desc.fill(newDesc);

            // The change marks the form dirty and reveals the sticky save bar; its
            // submit performs the space's native settings POST (server reload),
            // exactly as J-601 drives the privacy panel.
            const submit = page.locator(savebarSubmit);
            await expect(submit).toBeVisible({ timeout: 5_000 });
            await Promise.all([
                page.waitForURL(/\/settings\//, { timeout: 15_000 }),
                submit.click(),
            ]);
            await page.waitForLoadState('load');
            await expect(page.locator(savedNotice)).toBeVisible({ timeout: 10_000 });

            // EFFECT 1: reloaded inputs hold the saved values (server round-trip).
            await expect(page.locator(nameInput)).toHaveValue(newName);
            await expect(page.locator(descInput)).toHaveValue(newDesc);

            // EFFECT 2: REST truth — the canonical space row carries the new copy.
            const res = await getSpace(page, space.id);
            expect(res.status).toBe(200);
            const row = res.data as SpaceRow;
            expect(row.name).toBe(newName);
            expect(String(row.description ?? '')).toBe(newDesc);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-54 moderation queue lists a genuinely reported post', async ({ authenticatedPage: page }) => {
        // Per-spec selectors: the space moderation report card + empty state.
        const reportCard = 'article.bn-space-mod__report';
        const emptyState = '.bn-space-mod__empty';

        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Mod ${stamp}`, type: 'open' });
        let postId = 0;
        let reportId = 0;

        try {
            // Seed a real post in the space, then file a real report against it —
            // the exact write path post-card.js uses (object_type=post + space_id).
            const created = await bnApi(page, 'POST', '/posts', {
                type: 'text',
                content: `reportable post ${stamp}`,
                space_id: space.id,
            });
            expect(created.status, `seed post failed: ${JSON.stringify(created.data)}`).toBe(201);
            postId = Number((created.data as { id?: number }).id);
            expect(postId).toBeGreaterThan(0);

            const report = await bnApi(page, 'POST', '/reports', {
                object_type: 'post',
                object_id: postId,
                reason: 'spam',
                space_id: space.id,
            });
            expect(report.status, `report failed: ${JSON.stringify(report.data)}`).toBe(201);
            reportId = Number((report.data as { id?: number }).id);
            expect(reportId).toBeGreaterThan(0);

            // EFFECT 1: REST truth — the report is in the space-scoped queue.
            const queue = await bnApi(page, 'GET', `/reports/queue?per_page=100&object_type=post`);
            expect(queue.status).toBe(200);
            const items = (queue.data as { items?: Array<{ id: number; object_id: number }> }).items ?? [];
            expect(
                items.some((i) => Number(i.id) === reportId && Number(i.object_id) === postId),
                'seeded report not found in GET /reports/queue'
            ).toBe(true);

            // EFFECT 2: the moderation UI renders THAT report card (keyed by its
            // reportId in data-wp-context) and is NOT the "No open reports" state.
            await page.goto(`/spaces/${space.slug}/moderation/?bn_mtab=reports`, {
                waitUntil: 'domcontentloaded',
            });
            const mine = page.locator(`${reportCard}[data-wp-context*='"reportId":${reportId}']`);
            await expect(mine, 'seeded report card did not render in the moderation queue').toBeVisible({
                timeout: 10_000,
            });
            await expect(page.locator(emptyState)).toHaveCount(0);
        } finally {
            if (reportId > 0) {
                await bnApi(page, 'POST', `/reports/${reportId}/dismiss`);
            }
            if (postId > 0) {
                await bnApi(page, 'DELETE', `/posts/${postId}`);
            }
            await deleteSpaceApi(page, space.id);
        }
    });
});
