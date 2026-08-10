import { test, expect } from '../_fixtures/auth.fixture';
import {
    getSpace,
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    ensureOnboarded,
    loginContextAs,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-605 — Leave a space (member). Counterpart of the existing join spec (J-40).
 *
 * A second actor (a seeded member, not the owner) joins an owner-created space,
 * then leaves it through the REAL hero control (the "Joined" button →
 * actions.leaveSpace → DELETE /spaces/{id}/join).
 *
 * EFFECT (not presence): after leaving, a reload shows the hero back to a "Join"
 * control (data-current-state="join"), and a REST GET as that member reports
 * membership_status !== 'active'. An optimistic label flip over a failed DELETE
 * fails here. Throwaway space + second context both torn down in `finally`.
 */
test.describe('spaces / leave (J-605)', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';
    const joinedBtn = '.bn-sh-hero__actions [data-current-state="joined"]';
    const joinBtn = '.bn-sh-hero__actions [data-current-state="join"]';

    test('J-605 a member leaving loses membership and regains the Join control', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const space = await createSpaceApi(page, { name: `E2E Leave ${stamp}`, type: 'open' });
        const actor = await loginContextAs(browser, baseURL, other);

        try {
            // Un-gate the second actor so BuddyNext space routes render for them
            // (a not-yet-onboarded member is bounced to /onboarding/).
            await ensureOnboarded(actor.page);

            // Setup: the member joins (real endpoint) and is active.
            const join = await bnApi(actor.page, 'POST', `/spaces/${space.id}/join`);
            expect(join.status, `join failed: ${JSON.stringify(join.data)}`).toBe(200);
            let res = await getSpace(actor.page, space.id);
            expect((res.data as SpaceRow).membership_status).toBe('active');

            // Action under test: LEAVE via the hero "Joined" button.
            await actor.page.goto(`/spaces/${space.slug}/`);
            const joined = actor.page.locator(joinedBtn).first();
            await expect(joined).toBeVisible({ timeout: 10_000 });
            actor.page.once('dialog', (d) => void d.accept()); // some builds confirm
            await joined.click();

            // EFFECT: membership is gone in REST...
            await expect
                .poll(
                    async () => {
                        const g = await getSpace(actor.page, space.id);
                        return (g.data as SpaceRow)?.membership_status ?? '';
                    },
                    { timeout: 10_000 }
                )
                .not.toBe('active');

            // ...and the reloaded hero offers the Join control again.
            await actor.page.goto(`/spaces/${space.slug}/`);
            await expect(actor.page.locator(joinBtn).first()).toBeVisible({ timeout: 10_000 });
            await expect(actor.page.locator(joinedBtn)).toHaveCount(0);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
