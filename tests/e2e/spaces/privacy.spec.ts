import { test, expect } from '../_fixtures/auth.fixture';
import {
    getSpace,
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    loginContextAs,
    ensureOnboarded,
    type SpaceRow,
} from '../_fixtures/spaces-rest';
import { sel, urls } from '../_fixtures/selectors';

/**
 * J-601 — Edit space privacy (public <-> private).
 *
 * Covers: cap-make-a-space-private-or-secret, cap-group-content-into-spaces
 * Roles: admin, member
 *
 * Member action: on the space Settings → Privacy panel, change the "Space
 * visibility" select and save it through the space's native settings POST (the
 * same write the sticky save bar performs).
 *
 * EFFECT (not presence): after each save the page reloads server-side, so the
 * select reflects the PERSISTED value (round-trip), and a REST GET of the space
 * reports the new `type`. Both directions are exercised (open→private→open) so a
 * one-way write bug can't hide. Throwaway space, deleted in `finally`.
 *
 * The MEMBER leg below is the highest-value one: it proves the promise from the
 * other side of the fence. Making a space private/secret is worthless if a
 * non-member can still read it, so a second actor (never the owner) checks a
 * secret space is undiscoverable (SpaceVisibility::can_view_space — 404, not
 * merely absent from a list) and a private space is LISTED but its content is
 * refused (can_view_content — the `.bn-sh-gate` card, and a 403 on its feed)
 * until they actually join, at which point both open up.
 */
test.describe('spaces / edit privacy (J-601)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';
    const typeSelect = 'select[name="space_type"]';
    const settingsForm = 'form[data-bn-settings-general-form]';
    const savebarSubmit = '[data-bn-savebar-submit]';
    const savedNotice = '.bn-space-settings__notice[data-tone="success"]';

    const saveVisibility = async (page: import('@playwright/test').Page, value: string): Promise<void> => {
        const select = page.locator(typeSelect);
        await expect(select).toBeVisible({ timeout: 10_000 });
        await select.selectOption(value);
        // The change event marks the settings form dirty and reveals the save bar.
        const submit = page.locator(savebarSubmit);
        await expect(submit).toBeVisible({ timeout: 5_000 });
        await Promise.all([
            page.waitForURL(/\/settings\//, { timeout: 15_000 }),
            submit.click(),
        ]);
        await page.waitForLoadState('load');
        await expect(page.locator(savedNotice)).toBeVisible({ timeout: 10_000 });
        void settingsForm;
    };

    test('J-601 changing visibility persists across reload and in REST', async ({ authenticatedPage: page }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Privacy ${stamp}`, type: 'open' });

        try {
            const settingsUrl = `/spaces/${space.slug}/settings/?bn_stab=privacy`;

            // open → private.
            await page.goto(settingsUrl);
            await expect(page.locator(typeSelect)).toHaveValue('open');
            await saveVisibility(page, 'private');
            await expect(page.locator(typeSelect)).toHaveValue('private'); // reloaded value
            let res = await getSpace(page, space.id);
            expect(res.status).toBe(200);
            expect((res.data as SpaceRow).type).toBe('private');

            // private → back to open (counterpart direction).
            await saveVisibility(page, 'open');
            await expect(page.locator(typeSelect)).toHaveValue('open');
            res = await getSpace(page, space.id);
            expect((res.data as SpaceRow).type).toBe('open');
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-601 member: a secret space is undiscoverable and a private space hides its content until joined', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const secretName = `E2E Privacy Secret ${stamp}`;
        const privateName = `E2E Privacy Private ${stamp}`;
        const secretSpace = await createSpaceApi(page, { name: secretName, type: 'secret' });
        const privateSpace = await createSpaceApi(page, { name: privateName, type: 'private' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);
            await actor.page.setViewportSize({ width: 390, height: 844 }); // phone width

            // ── SECRET: a non-member cannot even learn it exists ────────────────
            const secretGet = await bnApi(actor.page, 'GET', `/spaces/${secretSpace.id}`);
            expect(secretGet.status, 'secret space leaked its existence to a non-member').toBe(404);

            await actor.page.goto(`${urls.spaces}?bn_search=${encodeURIComponent(secretName)}`, {
                waitUntil: 'domcontentloaded',
            });
            await expect(
                actor.page.locator(sel.spaceCard).filter({ hasText: secretName }),
                'a secret space must not appear in directory search for a non-member'
            ).toHaveCount(0);

            // ── PRIVATE: listed, but content is refused until the viewer joins ──
            const privBefore = await getSpace(actor.page, privateSpace.id);
            expect(privBefore.status, 'a private space must still be listed to a non-member').toBe(200);

            const feedBefore = await bnApi(actor.page, 'GET', `/spaces/${privateSpace.id}/feed`);
            expect(feedBefore.status, 'a non-member must not read a private space feed').toBe(403);

            await actor.page.goto(`/spaces/${privateSpace.slug}/`, { waitUntil: 'domcontentloaded' });
            await expect(
                actor.page.locator('.bn-sh-gate'),
                'private space content gate did not render for a non-member (390px)'
            ).toBeVisible({ timeout: 10_000 });

            // Request to join, owner approves — the same round trip J-607 proves.
            const req = await bnApi(actor.page, 'POST', `/spaces/${privateSpace.id}/join`);
            expect(req.status, `request failed: ${JSON.stringify(req.data)}`).toBe(200);
            const queue = await bnApi(page, 'GET', `/spaces/${privateSpace.id}/pending-requests`);
            const uid = Number(
                ((queue.data as { items?: { user_id: number }[] }).items ?? [])[0]?.user_id ?? 0
            );
            expect(uid, 'owner does not see the pending request').toBeGreaterThan(0);
            const approve = await bnApi(page, 'POST', `/spaces/${privateSpace.id}/members/${uid}/approve`);
            expect(approve.status, `approve failed: ${JSON.stringify(approve.data)}`).toBe(200);

            // EFFECT: the joined member now reads the space's content.
            const feedAfter = await bnApi(actor.page, 'GET', `/spaces/${privateSpace.id}/feed`);
            expect(feedAfter.status, 'a joined member must read the private space feed').toBe(200);

            await actor.page.goto(`/spaces/${privateSpace.slug}/`, { waitUntil: 'domcontentloaded' });
            await expect(
                actor.page.locator('.bn-sh-gate'),
                'content gate still showing for a joined member (390px)'
            ).toHaveCount(0);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, secretSpace.id);
            await deleteSpaceApi(page, privateSpace.id);
        }
    });
});
