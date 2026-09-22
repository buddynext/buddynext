import { test, expect } from '../_fixtures/auth.fixture';
import {
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    getSpace,
    loginContextAs,
    ensureOnboarded,
    type SpaceRow,
} from '../_fixtures/spaces-rest';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-631 — Archive then restore a space (C2, counterpart pair).
 *
 * Covers: cap-group-content-into-spaces, cap-archive-and-restore-a-space-or-create-a-sub-space-under-it
 * Roles: admin, member
 *
 * The owner archives a space (POST /spaces/{id}/archive) and restores it
 * (DELETE /spaces/{id}/archive).
 *
 * EFFECT (not presence): GET /spaces/{id} reports is_archived truthy after
 * archive and falsy after restore — the persisted column, not an optimistic
 * label. Throwaway space torn down in `finally`.
 *
 * The MEMBER leg proves what "archived" means to someone other than the owner:
 * per SpaceService::archive_scope(), "everyone else, including its members,
 * sees a retired space only by URL" — it drops out of an existing member's own
 * directory search AND refuses new posts from that member, and restoring
 * reverses both.
 */
test.describe('spaces / archive + restore (J-631)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    const isArchived = async (page: import('@playwright/test').Page, id: number): Promise<boolean> => {
        const res = await getSpace(page, id);
        expect(res.status, `get space failed: ${JSON.stringify(res.data)}`).toBe(200);
        return Boolean((res.data as SpaceRow).is_archived);
    };

    test('J-631 archive sets is_archived; restore clears it', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Archive ${stamp}`, type: 'open' });

        try {
            // Baseline: a fresh space is not archived.
            expect(await isArchived(page, space.id)).toBe(false);

            // ── ARCHIVE ────────────────────────────────────────────────────────
            const arch = await bnApi(page, 'POST', `/spaces/${space.id}/archive`);
            expect(arch.status, `archive failed: ${JSON.stringify(arch.data)}`).toBe(200);
            expect((arch.data as { archived?: boolean }).archived).toBe(true);
            expect(await isArchived(page, space.id)).toBe(true);

            // ── RESTORE (counterpart) ──────────────────────────────────────────
            const restore = await bnApi(page, 'DELETE', `/spaces/${space.id}/archive`);
            expect(restore.status, `restore failed: ${JSON.stringify(restore.data)}`).toBe(200);
            expect((restore.data as { archived?: boolean }).archived).toBe(false);
            expect(await isArchived(page, space.id)).toBe(false);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-631 member: an archived space disappears from a member\'s own directory and refuses their posts', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `E2E ArchiveMember ${stamp}`;
        const space = await createSpaceApi(page, { name, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);

            await actor.page.setViewportSize({ width: 390, height: 844 }); // phone width

            // Baseline: the member finds it before archiving.
            await actor.page.goto(`${urls.spaces}?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            await expect(actor.page.locator(sel.spaceCard).filter({ hasText: name })).toBeVisible({
                timeout: 10_000,
            });

            // Owner archives.
            const arch = await bnApi(page, 'POST', `/spaces/${space.id}/archive`);
            expect(arch.status, `archive failed: ${JSON.stringify(arch.data)}`).toBe(200);

            // EFFECT 1: an ACTIVE member's own directory search no longer surfaces
            // it — archiving retires the space for everyone but its owner, members
            // included.
            await actor.page.goto(`${urls.spaces}?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            await expect(
                actor.page.locator(sel.spaceCard).filter({ hasText: name }),
                'an active member should not find an archived space in their own directory search'
            ).toHaveCount(0);

            // EFFECT 2: the member cannot post to it — archived is read-only, not
            // merely delisted.
            const post = await bnApi(actor.page, 'POST', '/posts', {
                type: 'text',
                content: `archived member post ${stamp}`,
                space_id: space.id,
            });
            expect(post.status, 'a member should not be able to post into an archived space').toBe(403);
            expect((post.data as { code?: string }).code).toBe('space_archived');

            // Restore (counterpart) — both effects reverse for the member.
            const restore = await bnApi(page, 'DELETE', `/spaces/${space.id}/archive`);
            expect(restore.status, `restore failed: ${JSON.stringify(restore.data)}`).toBe(200);

            await actor.page.goto(`${urls.spaces}?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            await expect(actor.page.locator(sel.spaceCard).filter({ hasText: name })).toBeVisible({
                timeout: 10_000,
            });
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
