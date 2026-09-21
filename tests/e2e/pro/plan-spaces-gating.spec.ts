import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

import { wp, ensureUser } from '../_fixtures/wp';
import { createSpaceApi, deleteSpaceApi, ensureOnboarded } from '../_fixtures/spaces-rest';

/**
 * J-821 — one plan opens several spaces; a space is opened by several plans.
 *
 * Card 10167121142. The owner sets, per plan, which spaces it unlocks (Phase 2b's
 * "Spaces this plan unlocks" picker on the plan-edit screen), and access follows:
 * a Basic member enters the space Basic opens but is gated on the Premium-only one,
 * while a Premium member enters both. This proves the whole loop end to end — the
 * new admin picker writes the same gate_tier rows the Paywall screen edits, and the
 * gate reads them — which the SpacePlanAccess unit tests cover only at the data
 * layer and the reflection save-seam test only below the form.
 *
 * Two owner-set plans (bn_e2e_basic, bn_e2e_premium) and two spaces (A, B), so the
 * run never disturbs the site's own free/pro plans. Needs Pro active; self-skips.
 */
test.describe('pro / one plan many spaces (J-821)', () => {
	test.fixme(process.env.BN_PRO !== '1', 'Plan-based space gating only exists when Pro is active.');

	// Plan slugs allow only lowercase letters, digits and hyphens (no underscores).
	const BASIC = 'bn-e2e-basic';
	const PREMIUM = 'bn-e2e-premium';
	const M_BASIC = 'bn_e2e_plan_basic_member';
	const M_PREMIUM = 'bn_e2e_plan_premium_member';

	let spaceA = { id: 0, slug: '', name: '' };
	let spaceB = { id: 0, slug: '', name: '' };

	test.beforeAll(async ({ browser }) => {
		// Two owner-set plans, cheapest first so the paywall names Basic before Premium.
		await wp([
			'eval',
			`$svc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
				` foreach ( array( array( '${BASIC}', 'E2E Basic', 5.0 ), array( '${PREMIUM}', 'E2E Premium', 10.0 ) ) as $p ) {` +
				`   if ( ! $svc->get_tier_by_slug( $p[0] ) ) {` +
				`     $svc->create_tier( $p[0], $p[1], '', 0, array( 'status' => 'active', 'price' => $p[2], 'billing_type' => 'recurring', 'billing_interval' => 'month' ) );` +
				`   }` +
				` }`,
		]);

		// Two members, each on one plan via a real subscription (the path that grants
		// the tier ability the gate reads — a bare user-meta grant is overridden by
		// Pro's subscription-backed capability resolution). Then finish onboarding, or
		// /spaces/{slug}/ 302s them to the wizard and the access checks test the
		// redirect instead of the gate.
		await ensureUser(M_BASIC, `${M_BASIC}@example.test`, 'E2E Basic Member');
		await ensureUser(M_PREMIUM, `${M_PREMIUM}@example.test`, 'E2E Premium Member');
		// Resolve the members BY LOGIN inside PHP (never interpolate a JS id, which
		// can arrive NaN and silently subscribe user 0 — the member then exists but
		// holds no plan and is denied everywhere).
		await wp([
			'eval',
			`$sub = new \\BuddyNextPro\\Membership\\SubscriptionService();` +
				` $tsvc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
				` $exp = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );` +
				` foreach ( array( '${M_BASIC}' => '${BASIC}', '${M_PREMIUM}' => '${PREMIUM}' ) as $login => $plan ) {` +
				`   $u = get_user_by( 'login', $login );` +
				`   if ( ! $u ) { continue; }` +
				`   $sub->create_subscription( (int) $u->ID, (int) ( $tsvc->get_tier_by_slug( $plan )['id'] ?? 0 ), 'manual', $exp, '' );` +
				`   ( new \\BuddyNext\\Onboarding\\OnboardingService() )->finish( (int) $u->ID );` +
				` }` +
				` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
		]);

		// Spaces created through the API as the admin, so they are real rows.
		const admin = await browser.newPage();
		await login(admin);
		await ensureOnboarded(admin);
		const stamp = Date.now().toString().slice(-8);
		const nameA = `E2E Plan A ${stamp}`;
		const nameB = `E2E Plan B ${stamp}`;
		spaceA = { ...(await createSpaceApi(admin, { name: nameA, type: 'open' })), name: nameA };
		spaceB = { ...(await createSpaceApi(admin, { name: nameB, type: 'open' })), name: nameB };

		// Basic opens A (seeded like the app writes it — rows + required_ability
		// mirror). Premium is set through the UI in the first test.
		await gate(BASIC, [spaceA.id]);
		await admin.close();
	});

	test.afterAll(async () => {
		// Self-cleaning: drop the throwaway plans (rows + tier), their subscriptions,
		// and the test members, all resolved by slug/login so nothing accumulates
		// across CI runs (the global qa-reset does not remove membership tiers).
		await wp([
			'eval',
			`$tsvc = new \\BuddyNextPro\\Membership\\MembershipTierService();` +
				` global $wpdb;` +
				` foreach ( array( '${BASIC}', '${PREMIUM}' ) as $slug ) {` +
				`   \\BuddyNextPro\\Membership\\SpacePlanAccess::forget_plan( $slug );` +
				`   $t = $tsvc->get_tier_by_slug( $slug );` +
				`   if ( $t ) { $wpdb->delete( $wpdb->prefix . 'bn_subscriptions', array( 'tier_id' => (int) $t['id'] ) ); $tsvc->delete_tier( (int) $t['id'] ); }` +
				` }` +
				` require_once ABSPATH . 'wp-admin/includes/user.php';` +
				` foreach ( array( '${M_BASIC}', '${M_PREMIUM}' ) as $login ) { $u = get_user_by( 'login', $login ); if ( $u ) { wp_delete_user( (int) $u->ID ); } }` +
				` \\BuddyNextPro\\Membership\\MembershipCapabilities::flush();`,
		]);
		// Best-effort: drop the throwaway spaces.
		const admin = await browserFromNothing();
		if (admin) {
			await login(admin.page);
			await deleteSpaceApi(admin.page, spaceA.id);
			await deleteSpaceApi(admin.page, spaceB.id);
			await admin.context.close();
		}
	});

	// ── Owner sets Premium → A + B through the plan-edit picker ─────────────────
	test('J-821a the plan-edit picker gates spaces, and the Paywall screen agrees', async ({
		page,
	}) => {
		await login(page);

		const tierId = Number(
			await wp([
				'eval',
				`$t = ( new \\BuddyNextPro\\Membership\\MembershipTierService() )->get_tier_by_slug( '${PREMIUM}' ); echo (int) ( $t['id'] ?? 0 );`,
			])
		);
		expect(tierId, 'the Premium test plan should exist').toBeGreaterThan(0);

		await page.setViewportSize({ width: 1280, height: 900 });
		await page.goto(`/wp-admin/admin.php?page=buddynext-monetization&tab=tiers&edit=${tierId}`, {
			waitUntil: 'domcontentloaded',
		});

		const picker = page.locator('[data-bnpro-plan-spaces]');
		await expect(picker, 'the "Spaces this plan unlocks" picker should render').toBeVisible();

		// A fresh test plan has no all-access, so the search is enabled. If a future
		// default changes that, untick it first.
		const allAccess = page.locator('#bnpro-ent-spaces-gated_access');
		if ((await allAccess.count()) && (await allAccess.isChecked())) {
			await allAccess.uncheck();
		}

		await addSpaceViaPicker(page, spaceA.name, spaceA.id);
		await addSpaceViaPicker(page, spaceB.name, spaceB.id);

		await page.locator('.bnpro-plan-form__save').first().click();
		await page.waitForLoadState('domcontentloaded');

		// The rows persisted: Premium now opens both spaces.
		const premiumSpaces = (
			await wp([
				'eval',
				`echo implode( ',', \\BuddyNextPro\\Membership\\SpacePlanAccess::spaces_for_plan( '${PREMIUM}' ) );`,
			])
		)
			.split(',')
			.filter(Boolean)
			.map(Number)
			.sort((a, b) => a - b);
		expect(premiumSpaces).toEqual([spaceA.id, spaceB.id].sort((a, b) => a - b));

		// The other screen (Paywall > Per-Space Overrides) reads the same rows: B is
		// opened by Premium only, A by Basic and Premium.
		await page.goto(
			`/wp-admin/admin.php?page=buddynext-monetization&tab=paywall&space_q=${encodeURIComponent('E2E Plan')}`,
			{ waitUntil: 'domcontentloaded' }
		);
		const overrides = page.locator('.bnpro-paywall-overrides');
		if (await overrides.count()) {
			await expect(overrides).toContainText('E2E Premium');
		}
	});

	// ── Access follows: Basic enters A only; Premium enters both ────────────────
	for (const width of [1280, 390]) {
		test(`J-821b access follows the plan at ${width}px`, async ({ page }) => {
			await page.setViewportSize({ width, height: 900 });

			// Premium set is done by J-821a; if this test runs alone, ensure it
			// (rows + required_ability mirror, the way the admin screen writes it).
			await gate(PREMIUM, [spaceA.id, spaceB.id]);

			// Premium member enters BOTH spaces (no plan gate).
			await page.goto(`/?autologin=${M_PREMIUM}`, { waitUntil: 'domcontentloaded' });
			await expect(await gateOnSpace(page, spaceA.slug), 'Premium should enter A').toBe(false);
			await expect(await gateOnSpace(page, spaceB.slug), 'Premium should enter B').toBe(false);

			// Basic member enters A (Basic opens it) but is gated on B (Premium only),
			// and the paywall names the plan that would let them in.
			await page.goto(`/?autologin=${M_BASIC}`, { waitUntil: 'domcontentloaded' });
			await expect(await gateOnSpace(page, spaceA.slug), 'Basic should enter A').toBe(false);
			expect(await paywallText(page, spaceB.slug), 'Basic should see the Premium paywall on B').toContain(
				'E2E Premium'
			);
		});
	}
});

// ── helpers ──────────────────────────────────────────────────────────────────

const TEST_USER = process.env.BN_TEST_USER ?? 'varundubey';
const TEST_PASS = process.env.BN_TEST_PASS ?? 'password';

async function login(page: Page): Promise<void> {
	await page.goto('/?autologin=1', { waitUntil: 'domcontentloaded' });
	const cookies = await page.context().cookies();
	if (!cookies.some((c) => c.name.startsWith('wordpress_logged_in'))) {
		await page.goto('/wp-login.php', { waitUntil: 'domcontentloaded' });
		await page.fill('#user_login', TEST_USER);
		await page.fill('#user_pass', TEST_PASS);
		await Promise.all([page.waitForLoadState('domcontentloaded'), page.click('#wp-submit')]);
	}
}

/** Type a query into the picker, wait for the debounced result, click the id. */
async function addSpaceViaPicker(page: Page, query: string, spaceId: number): Promise<void> {
	const search = page.locator('[data-bnpro-plan-spaces-search]');
	await search.fill(query);
	const option = page.locator(`[data-bnpro-plan-spaces-results] [role="option"][data-space-id="${spaceId}"]`);
	await expect(option, `search "${query}" should surface space ${spaceId}`).toBeVisible({ timeout: 5_000 });
	await option.click();
	await expect(
		page.locator(`[data-bnpro-plan-spaces-list] [data-space-id="${spaceId}"]`),
		`space ${spaceId} should become a chip`
	).toBeVisible();
}

/**
 * Gate a set of spaces behind a plan the way the admin screens do: write the
 * gate_tier rows AND mirror required_ability (the one-release read fallback) for
 * each space, through SpaceService::update as the admin so caches bust. Seeding
 * raw rows without the mirror leaves the space ungated on the read path.
 */
async function gate(slug: string, ids: number[]): Promise<void> {
	const list = ids.join(', ');
	await wp([
		'eval',
		`\\BuddyNextPro\\Membership\\SpacePlanAccess::set_spaces_for_plan( '${slug}', array( ${list} ) );` +
			` foreach ( array( ${list} ) as $sid ) {` +
			`   $plans = \\BuddyNextPro\\Membership\\SpacePlanAccess::plans_for_space( (int) $sid );` +
			`   $raw = empty( $plans ) ? '' : ( 'tier:' . $plans[0] );` +
			`   buddynext_service( 'spaces' )->update( (int) $sid, 1, array( 'required_ability' => $raw ) );` +
			` }`,
	]);
}

/** True when the space page shows the plan paywall (viewer denied), false when the content renders. */
async function gateOnSpace(page: Page, slug: string): Promise<boolean> {
	await page.goto(`/spaces/${slug}/`, { waitUntil: 'domcontentloaded' });
	return (await page.locator('.bn-paywall').count()) > 0;
}

/** The visible plan paywall text on a gated space (empty when not gated). */
async function paywallText(page: Page, slug: string): Promise<string> {
	await page.goto(`/spaces/${slug}/`, { waitUntil: 'domcontentloaded' });
	const paywall = page.locator('.bn-paywall');
	return (await paywall.count()) > 0 ? ((await paywall.first().textContent()) ?? '') : '';
}

/** afterAll cleanup needs a page but has no fixture; make a throwaway context. */
async function browserFromNothing(): Promise<{ page: Page; context: import('@playwright/test').BrowserContext } | null> {
	const { chromium } = await import('@playwright/test');
	const baseURL = process.env.BN_BASE_URL ?? 'http://buddynext-dev.local';
	try {
		const context = await chromium.launch().then((b) => b.newContext({ baseURL }));
		const page = await context.newPage();
		return { page, context };
	} catch {
		return null;
	}
}
