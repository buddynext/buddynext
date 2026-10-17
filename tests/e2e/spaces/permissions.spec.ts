import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, getSpace, bnApi, loginContextAs, ensureOnboarded, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-602 — Edit space permissions (who can post / who can invite).
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin
 *
 * Member action: on the space Settings → Permissions panel, change the two
 * threshold selects and save via the panel's native settings POST.
 *
 * EFFECT (not presence): after the save the page reloads server-side and the
 * selects show the PERSISTED values (round-trip); GET /spaces/{id} `fields[]`
 * carries the same two values (who_can_post / who_can_invite are core space
 * fields); and the rule is ENFORCED: a plain member's post is refused once only
 * moderators may post. A save that no-ops, or a setting nothing reads, fails here.
 * Throwaway space, deleted in `finally`.
 */
test.describe('spaces / edit permissions (J-602)', () => {
    const whoPost = 'select[name="who_can_post"]';
    const whoInvite = 'select[name="who_can_invite"]';
    const savebarSubmit = '[data-bn-savebar-submit]';
    const savedNotice = '.bn-space-settings__notice[data-tone="success"]';

    /** One registered space field's stored value, from the canonical space payload. */
    const fieldValue = async (page: import('@playwright/test').Page, id: number, key: string): Promise<unknown> => {
        const res = await getSpace(page, id);
        expect(res.status, `get space failed: ${JSON.stringify(res.data)}`).toBe(200);
        const fields = ((res.data as SpaceRow).fields as Array<{ key: string; value: unknown }> | undefined) ?? [];
        const row = fields.find((f) => f.key === key);
        expect(row, `field "${key}" not present in the space fields payload`).toBeTruthy();
        return row?.value;
    };

    test('J-602 changing who-can-post/invite persists across reload', async ({ authenticatedPage: page, browser, baseURL }) => {
        const other = process.env.BN_TEST_OTHER_USER ?? 'alice';
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `Local History Buffs ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);
        let memberPostId = 0;

        try {
            const settingsUrl = `/spaces/${space.slug}/settings/?bn_stab=permissions`;
            await page.goto(settingsUrl);

            // Defaults on a fresh space: members / mods. Move both to a new value.
            await expect(page.locator(whoPost)).toBeVisible({ timeout: 10_000 });
            await page.locator(whoPost).selectOption('mods');
            await page.locator(whoInvite).selectOption('owner');

            const submit = page.locator(savebarSubmit);
            await expect(submit).toBeVisible({ timeout: 5_000 });
            await Promise.all([
                page.waitForURL(/\/settings\//, { timeout: 15_000 }),
                submit.click(),
            ]);
            await page.waitForLoadState('load');
            await expect(page.locator(savedNotice)).toBeVisible({ timeout: 10_000 });

            // EFFECT: reloaded (server-rendered) selects carry the saved thresholds.
            await expect(page.locator(whoPost)).toHaveValue('mods');
            await expect(page.locator(whoInvite)).toHaveValue('owner');

            // Extra reload to prove it is stored, not just echoed by the POST render.
            await page.goto(settingsUrl);
            await expect(page.locator(whoPost)).toHaveValue('mods');
            await expect(page.locator(whoInvite)).toHaveValue('owner');

            // EFFECT: the canonical space payload carries the saved thresholds.
            expect(await fieldValue(page, space.id, 'who_can_post')).toBe('mods');
            expect(await fieldValue(page, space.id, 'who_can_invite')).toBe('owner');

            // EFFECT: the thresholds are enforced for a plain member, not only stored.
            await ensureOnboarded(actor.page);
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `member join failed: ${JSON.stringify(join.data)}`).toBe(200);
            const post = await bnApi(actor.page, 'POST', '/posts', {
                type: 'text',
                content: `Does anyone know when the museum reopens? ${stamp}`,
                space_id: space.id,
            });
            memberPostId = Number((post.data as { id?: number })?.id ?? 0);
            expect(post.status, 'a plain member posted into a space where only moderators may post').toBe(403);
            const memberView = (await getSpace(actor.page, space.id)).data as SpaceRow;
            expect(memberView.can_invite, 'a plain member may invite where only the owner may').toBe(false);
            // The owner is above both thresholds.
            expect(((await getSpace(page, space.id)).data as SpaceRow).can_invite).toBe(true);
        } finally {
            if (memberPostId > 0) {
                await bnApi(page, 'DELETE', `/posts/${memberPostId}`);
            }
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
