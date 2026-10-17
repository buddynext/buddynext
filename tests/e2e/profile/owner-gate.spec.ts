import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { urls } from '../_fixtures/selectors';
import { resolveOtherMemberSlug, softSkip } from '../_fixtures/precondition';
import { ensureUser, setUserMeta, userId, wp } from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';
import { sessionStatus } from '../_fixtures/effects';

/**
 * J-124-profile-owner-gate.
 *
 * Regression spec for A1 — the owner-only profile actions (Edit Profile link +
 * the cover-edit pencil) must NOT render on other members' profiles, even for
 * admin viewers, since the edit link unconditionally targets the viewer's own
 * edit URL via get_edit_profile_url() and would leak unrelated UI.
 *
 * v2: the action bar is `.bn-pf-actions` and renders on every profile, but with
 * different buttons — owner gets an "Edit profile" link (href -> /edit/), other
 * viewers get Follow / Connect / Message. The owner marker is therefore the
 * edit link inside the bar (`.bn-pf-actions a[href*="/edit/"]`) plus the cover
 * pencil (`.bn-pf-cover__edit`), not a dedicated `.bn-profile-actions-bar`.
 *
 * Covers: cap-give-members-a-profile-with-custom-fields
 * Roles: admin, member
 * Note: loose fit - this is the owner-only edit-control gate, not the custom
 * fields feature itself; no closer CAPABILITIES.md row exists. The first two
 * tests use the authenticatedPage fixture (admin) viewing another member's
 * profile. The third test closes the gap those left: a plain MEMBER (B,
 * subscriber) viewing someone else's profile must not see the owner-only edit
 * link or cover pencil either — B holds no "edit anyone's profile" capability,
 * so unlike the admin-viewer test the more-menu Edit item must also be
 * entirely absent, not merely unscoped-out.
 */
test.describe('profile / owner gate', () => {
    test('Edit Profile / cover pencil do NOT render on a non-owner profile', async ({ authenticatedPage: page }, testInfo) => {
        const self        = process.env.BN_TEST_USER ?? 'varundubey';
        const otherMember = await resolveOtherMemberSlug(page, self);

        if (!otherMember) {
            softSkip(testInfo, 'No member other than the test user exists — cannot exercise the non-owner profile gate.');
            return;
        }

        await page.goto(urls.member(otherMember));
        await expect(
            page.locator('.bn-pf-hero').first(),
            `Profile hero did not render for "${otherMember}" — the gate below never ran. This is a fixture problem, not an owner-gate failure.`,
        ).toBeVisible({ timeout: 5_000 });

        // No OWNER Edit-profile link in the action bar on someone else's profile.
        //
        // Scoped past .bn-more-menu deliberately. That menu carries a separate
        // "Edit profile" item gated on $bn_pf_can_edit - the "Edit anyone's
        // profile" capability (profile-hero.php:589) - which an administrator
        // legitimately holds. The unscoped matcher caught that admin affordance
        // and failed a correct gate; this spec is about OWNER controls leaking,
        // not about removing a capability.
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]:not(.bn-more-menu-item)')).toHaveCount(0);

        // No cover-edit pencil (owner-only in templates/profile/view.php).
        await expect(page.locator('.bn-pf-cover__edit')).toHaveCount(0);
        // The authenticated actor is an administrator: the pencil is hidden on other profiles
        // as a UI choice, but the REST write is legitimately allowed (Edit member). The refusal
        // is the plain member's case below; here the owner side is read back as a sanity check.
        expect(await sessionStatus(page), 'the admin viewer has a session').toBe(200);
        // EFFECT: the profile route tells the same story the hero drew: this is not the
        // viewer's own profile (is_self is what an app keys its owner controls on).
        const otherId = Number(
            (await wp(['eval', `$u = get_user_by( 'slug', '${otherMember}' ) ?: get_user_by( 'login', '${otherMember}' ); echo $u ? (int) $u->ID : 0;`])).trim()
        );
        if (otherId > 0) {
            const other = await bnApi(page, 'GET', `/users/${otherId}/profile`);
            expect(other.status, 'GET /users/{other}/profile').toBe(200);
            expect(((other.data ?? {}) as { is_self?: boolean }).is_self, 'the route marks the profile as someone else\'s').toBe(false);
        }
    });

    test('Edit Profile DOES render on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-pf-actions').first()).toBeVisible();
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]').first()).toBeVisible();
        expect(await sessionStatus(page), 'the owner reads their own profile').toBe(200);
        // EFFECT: the route marks this profile as the viewer's own, which is what the
        // Edit link stands for.
        const ownId = await userId(user);
        expect(ownId, `actor "${user}" must exist`).toBeGreaterThan(0);
        const own = await bnApi(page, 'GET', `/users/${ownId}/profile`);
        expect(own.status, 'GET /users/{self}/profile').toBe(200);
        expect(((own.data ?? {}) as { is_self?: boolean }).is_self, 'the route marks the profile as the viewer\'s own').toBe(true);
    });

    /**
     * A plain MEMBER viewer (not admin) must not see the owner-only edit link
     * or cover pencil on someone else's profile, at phone width. Unlike the
     * admin-viewer test above, a plain member has no "edit anyone's profile"
     * capability, so unlike admin-gate the more-menu Edit item must not render
     * AT ALL for this viewer — the unscoped selector is the correct check here.
     */
    test('Edit Profile / cover pencil do NOT render on a non-owner profile for a plain MEMBER viewer (mobile 390px)', async ({ page }) => {
        const owner = process.env.BN_TEST_USER ?? 'varundubey';
        const viewerLogin = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';
        const viewerId = await ensureUser(viewerLogin, 'bn_e2e_target@example.com', 'Jordan Ellis');
        expect(viewerId, `member "${viewerLogin}" must exist`).toBeGreaterThan(0);
        await setUserMeta(viewerId, 'bn_onboarding_complete', '1');

        await page.setViewportSize({ width: 390, height: 844 });
        await loginAs(page, viewerLogin);
        await page.goto(urls.member(owner));
        await expect(
            page.locator('.bn-pf-hero').first(),
            `Profile hero did not render for "${owner}" — the gate below never ran.`,
        ).toBeVisible({ timeout: 5_000 });

        // No owner Edit-profile link in the action bar for a plain member viewer.
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]:not(.bn-more-menu-item)')).toHaveCount(0);

        // No cover-edit pencil (owner-only in templates/profile/view.php).
        await expect(page.locator('.bn-pf-cover__edit')).toHaveCount(0);

        // A plain member holds no "edit anyone's profile" capability, so —
        // unlike the admin-viewer test — the more-menu Edit item must not
        // appear at all, not merely fall outside the scoped selector above.
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]')).toHaveCount(0);
        const ownerId = await userId(owner);
        if (ownerId > 0) {
            const write = await bnApi(page, 'PUT', `/users/${ownerId}/profile`, { headline: 'Photographer and traveler' });
            expect(write.status, 'a plain member\'s write to another profile must be refused').toBeGreaterThanOrEqual(403);
            // EFFECT: the refusal was real; the owner's stored profile does not hold the probe.
            const after = await bnApi(page, 'GET', `/users/${ownerId}/profile`);
            expect(after.status, 'GET /users/{owner}/profile as the member').toBe(200);
            expect(JSON.stringify(after.data ?? null), 'the refused write changed nothing').not.toContain('Photographer and traveler');
        }
        expect(ownerId, `owner "${owner}" must resolve so the write refusal is exercised`).toBeGreaterThan(0);
    });
});
