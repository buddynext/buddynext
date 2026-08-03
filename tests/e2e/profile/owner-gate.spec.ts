import { test, expect } from '../_fixtures/auth.fixture';
import { urls } from '../_fixtures/selectors';
import { resolveOtherMemberSlug, softSkip } from '../_fixtures/precondition';

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
    });

    test('Edit Profile DOES render on own profile', async ({ authenticatedPage: page }) => {
        const user = process.env.BN_TEST_USER ?? 'varundubey';
        await page.goto(urls.member(user));
        await expect(page.locator('.bn-pf-hero').first()).toBeVisible({ timeout: 5_000 });
        await expect(page.locator('.bn-pf-actions').first()).toBeVisible();
        await expect(page.locator('.bn-pf-actions a[href*="/edit/"]').first()).toBeVisible();
    });
});
