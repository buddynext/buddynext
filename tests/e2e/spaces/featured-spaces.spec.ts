import { test, expect } from '../_fixtures/auth.fixture';
import { bnApi, createSpaceApi, deleteSpaceApi, ensureOnboarded } from '../_fixtures/spaces-rest';

/**
 * J-621 — Featured spaces (Spaces 1.2.1).
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin
 *
 * The site owner curates featured spaces; members see them first. Effect-based:
 *   1. Owner features two spaces (POST /settings/featured-spaces).
 *   2. The directory sidebar "Featured" card lists them in owner order (desktop).
 *   3. At 390px the phone Featured strip lists them (the sidebar is hidden).
 *   4. Reordering the option reorders the sidebar.
 *   5. Unfeaturing removes the Featured card.
 *
 * Needs an admin (manage_options) test user for the featured-spaces route; the
 * spec self-skips otherwise. The onboarding-first ordering and the auto-join
 * fallback are covered exhaustively by the PHPUnit FeaturedSpacesTest. Runs
 * under every configured project (desktop + mobile).
 */
test.describe('spaces / featured (J-621)', () => {
	test('J-621 featured spaces lead the directory sidebar + phone strip, in owner order', async ({
		authenticatedPage: page,
	}) => {
		await ensureOnboarded(page);

		const stamp = Date.now().toString().slice(-8);
		const a = await createSpaceApi(page, { name: `Rooftop Gardeners ${stamp}`, type: 'open' });
		const b = await createSpaceApi(page, { name: `Night Market Foodies ${stamp}`, type: 'open' });

		const setFeatured = (ids: number[]) =>
			bnApi(page, 'POST', '/settings/featured-spaces', { ids });
		/** The stored featured order, per the settings route and the public featured route. */
		const storedIds = async (): Promise<number[]> => {
			const r = await bnApi(page, 'GET', '/settings/featured-spaces');
			expect(r.status, `GET /settings/featured-spaces: ${JSON.stringify(r.data)}`).toBe(200);
			return ((r.data as { ids?: Array<number | string> }).ids ?? []).map(Number);
		};
		const publicIds = async (): Promise<number[]> => {
			const r = await bnApi(page, 'GET', '/spaces/featured');
			expect(r.status, `GET /spaces/featured: ${JSON.stringify(r.data)}`).toBe(200);
			return (Array.isArray(r.data) ? (r.data as Array<{ id: number }>) : []).map((s) => Number(s.id));
		};
		// What the owner had featured before this run, restored in finally (the old cleanup
		// set [] and wiped the site's real featured list on every run).
		let original: number[] | null = null;

		try {
			// 1. Feature both — skip cleanly if this user is not a site admin.
			const before = await bnApi(page, 'GET', '/settings/featured-spaces');
			test.skip(before.status === 403, 'featured-spaces route needs a manage_options user');
			original = ((before.data as { ids?: Array<number | string> }).ids ?? []).map(Number);
			const res = await setFeatured([a.id, b.id]);
			test.skip(res.status === 403, 'featured-spaces route needs a manage_options user');
			expect(res.status, `feature failed: ${JSON.stringify(res.data)}`).toBe(200);
			expect((res.data as { ids: number[] }).ids).toEqual([a.id, b.id]);
			// EFFECT: stored in owner order, and served in that order to members.
			expect(await storedIds()).toEqual([a.id, b.id]);
			expect(await publicIds()).toEqual([a.id, b.id]);

			// 2. Desktop: the sidebar "Featured" card leads with a, then b.
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const featuredCard = page.locator('.bn-sidebar-card', { hasText: 'Featured' }).first();
			await expect(featuredCard).toBeVisible({ timeout: 10_000 });
			await expect(featuredCard).toContainText(`Rooftop Gardeners ${stamp}`);
			await expect(featuredCard).toContainText(`Night Market Foodies ${stamp}`);

			// 3. Phone (390): the Featured strip is present, the sidebar is not.
			await page.setViewportSize({ width: 390, height: 840 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const strip = page.locator('.bn-sd-featured-strip');
			await expect(strip).toBeVisible({ timeout: 10_000 });
			await expect(strip).toContainText(`Rooftop Gardeners ${stamp}`);
			// The right sidebar is `aside.bn-app__right` (shell/right-sidebar.php:49). The old
			// `.bn-hub-sidebar` exists nowhere, so `toHaveCount(0)` passed whatever rendered.
			await expect(page.locator('.bn-app__right .bn-sidebar-card', { hasText: 'Featured' }).first()).toBeHidden();
			// EFFECT: the strip lists exactly the featured ids, in owner order.
			const stripIds = (await strip.locator('.bn-sd-featured-strip__card[data-space-id]').evaluateAll((els) =>
				els.map((el) => Number(el.getAttribute('data-space-id')))
			)) as number[];
			expect(stripIds).toEqual([a.id, b.id]);

			// 4. Reorder → sidebar reflects the new order (b then a).
			expect((await setFeatured([b.id, a.id])).status).toBe(200);
			expect(await storedIds()).toEqual([b.id, a.id]);
			expect(await publicIds()).toEqual([b.id, a.id]);
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const names = await page
				.locator('.bn-sidebar-card', { hasText: 'Featured' })
				.first()
				.locator('.bn-sd-side-row')
				.allInnerTexts();
			const idxA = names.findIndex((t) => t.includes(`Rooftop Gardeners ${stamp}`));
			const idxB = names.findIndex((t) => t.includes(`Night Market Foodies ${stamp}`));
			expect(idxB, 'B should now precede A').toBeLessThan(idxA);

			// 5. Unfeature everything → the Featured card is gone.
			expect((await setFeatured([])).status).toBe(200);
			expect(await storedIds()).toEqual([]);
			// With nothing curated the public route may fall back to the auto-join spaces
			// (SpaceService::featured_spaces), so assert what unfeaturing promises: these two are out.
			const afterClear = await publicIds();
			expect(afterClear).not.toContain(a.id);
			expect(afterClear).not.toContain(b.id);
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			await expect(page.locator('.bn-sidebar-card', { hasText: 'Featured' })).toHaveCount(0);
		} finally {
			await setFeatured(original ?? []);
			await deleteSpaceApi(page, a.id);
			await deleteSpaceApi(page, b.id);
		}
	});
});
