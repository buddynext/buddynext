import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, ensureOnboarded, getSpace, loginContextAs } from '../_fixtures/spaces-rest';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';

/**
 * J-620 — Shareable space invite link (Spaces 1.2.1).
 *
 * Covers: cap-group-content-into-spaces, cap-invite-people-to-a-space-with-a-shareable-link
 * Roles: admin, member
 *
 * Member journey, driven through the REAL UI:
 *   1. The owner opens a PRIVATE space's Settings → Invite link tab and clicks
 *      "Create invite link" (actions.createInviteLink → POST /invite-link). The
 *      readonly URL field appears with a usable link.
 *   2. A second member opens that link and clicks "Join space" — the hero shows
 *      the direct-join CTA (not "Request to join"), and the join is immediate.
 *   3. EFFECT (REST truth): the second member is now an ACTIVE member of the
 *      private space, with no approval step.
 *   4. The owner's Members page flags that member "Joined via invite link"
 *      (owner/moderator-only meta).
 *   5. The owner clicks "Reset link" — the token changes, so the old link is
 *      dead (old-token rejection + expiry + cap are covered exhaustively by the
 *      PHPUnit suite SpaceInviteLinkFlowTest; so is the signed-out signup →
 *      onboarding → return-to-space leg, which needs open registration + email).
 *
 * Runs under every configured project (desktop + mobile) — selectors are
 * layout-agnostic. The throwaway space is deleted in `finally`.
 */
test.describe('spaces / invite link (J-620)', () => {
	const panel = '[data-bn-invite-panel]';
	const createBtn = 'button[data-wp-on--click="actions.createInviteLink"]';
	const resetBtn = 'button[data-wp-on--click="actions.resetInviteLink"]';
	const urlField = '[data-bn-invite-url]';
	const joinCta = 'button[data-current-state="join"]';

	test('J-620 owner creates a link, a member joins directly, and is flagged joined-via-link', async ({
		authenticatedPage: page,
		browser,
	}, testInfo) => {
		await ensureOnboarded(page);

		const stamp = Date.now().toString().slice(-8);
		const space = await createSpaceApi(page, { name: `E2E Invite ${stamp}`, type: 'private' });
		let otherCtx: import('@playwright/test').BrowserContext | null = null;

		try {
			// 1. Owner creates the link through the settings UI.
			await page.goto(`/spaces/${space.slug}/settings/?bn_stab=invite`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator(panel)).toBeVisible({ timeout: 10_000 });
			await page.locator(createBtn).click();

			// The panel reloads into the link-display state with a readonly URL.
			const field = page.locator(urlField);
			await expect(field).toBeVisible({ timeout: 15_000 });
			const inviteUrl = await field.inputValue();
			expect(inviteUrl, 'invite URL field was empty').toContain('invite=');

			// 2. A second, onboarded member opens the link.
			const otherLogin = await resolveOtherMemberSlug(page, process.env.BN_TEST_USER ?? 'varundubey');
			test.skip(!otherLogin, 'no usable second member on this site to accept the invite');

			const second = await loginContextAs(browser, testInfo.project.use.baseURL, otherLogin as string);
			otherCtx = second.ctx;
			await ensureOnboarded(second.page);

			await second.page.goto(inviteUrl, { waitUntil: 'domcontentloaded' });

			// The hero offers a DIRECT join (not "Request to join") because the link
			// unlocked it — click it and the join is immediate.
			const cta = second.page.locator(joinCta).first();
			await expect(cta, 'expected a direct "Join space" CTA on the invite-linked space').toBeVisible({ timeout: 10_000 });
			await cta.click();

			// 3. EFFECT: REST truth — the second member is now an active member.
			await expect
				.poll(async () => (await getSpace(second.page, space.id)).status, { timeout: 10_000 })
				.toBe(200);
			const asMember = await getSpace(second.page, space.id);
			const role = (asMember.data as { membership_role?: string }).membership_role ?? '';
			expect(role, 'invite-link join did not make the visitor an active member').not.toBe('');

			// 4. The owner sees the "Joined via invite link" flag on the roster.
			await page.goto(`/spaces/${space.slug}/members/`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByText('Joined via invite link').first()).toBeVisible({ timeout: 10_000 });

			// 5. Reset issues a fresh token, killing the old link.
			await page.goto(`/spaces/${space.slug}/settings/?bn_stab=invite`, { waitUntil: 'domcontentloaded' });
			await expect(page.locator(resetBtn)).toBeVisible({ timeout: 10_000 });
			await page.locator(resetBtn).click();
			// Confirm the reset in the shared confirm dialog.
			const confirm = page.locator('.bn-modal-backdrop button[data-variant="danger"], .bn-modal-backdrop button:has-text("Reset link")').first();
			await expect(confirm).toBeVisible({ timeout: 5_000 });
			await confirm.click();

			await expect(page.locator(urlField)).toBeVisible({ timeout: 15_000 });
			const resetUrl = await page.locator(urlField).inputValue();
			expect(resetUrl, 'reset did not issue a new token').not.toBe(inviteUrl);
		} finally {
			await deleteSpaceApi(page, space.id);
			if (otherCtx) {
				await otherCtx.close();
			}
		}
	});
});
