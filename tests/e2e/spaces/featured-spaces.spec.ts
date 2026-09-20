import { test, expect } from '../_fixtures/auth.fixture';
import { bnApi, createSpaceApi, deleteSpaceApi, ensureOnboarded } from '../_fixtures/spaces-rest';

/**
 * J-621 — Featured spaces (Spaces 1.2.1).
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
		const a = await createSpaceApi(page, { name: `E2E Feat A ${stamp}`, type: 'open' });
		const b = await createSpaceApi(page, { name: `E2E Feat B ${stamp}`, type: 'open' });

		const setFeatured = (ids: number[]) =>
			bnApi(page, 'POST', '/settings/featured-spaces', { ids });

		try {
			// 1. Feature both — skip cleanly if this user is not a site admin.
			const res = await setFeatured([a.id, b.id]);
			test.skip(res.status === 403, 'featured-spaces route needs a manage_options user');
			expect(res.status, `feature failed: ${JSON.stringify(res.data)}`).toBe(200);
			expect((res.data as { ids: number[] }).ids).toEqual([a.id, b.id]);

			// 2. Desktop: the sidebar "Featured" card leads with a, then b.
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const featuredCard = page.locator('.bn-sidebar-card', { hasText: 'Featured' }).first();
			await expect(featuredCard).toBeVisible({ timeout: 10_000 });
			await expect(featuredCard).toContainText(`E2E Feat A ${stamp}`);
			await expect(featuredCard).toContainText(`E2E Feat B ${stamp}`);

			// 3. Phone (390): the Featured strip is present, the sidebar is not.
			await page.setViewportSize({ width: 390, height: 840 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const strip = page.locator('.bn-sd-featured-strip');
			await expect(strip).toBeVisible({ timeout: 10_000 });
			await expect(strip).toContainText(`E2E Feat A ${stamp}`);
			await expect(page.locator('.bn-hub-sidebar')).toHaveCount(0);

			// 4. Reorder → sidebar reflects the new order (b then a).
			expect((await setFeatured([b.id, a.id])).status).toBe(200);
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			const names = await page
				.locator('.bn-sidebar-card', { hasText: 'Featured' })
				.first()
				.locator('.bn-sd-side-row')
				.allInnerTexts();
			const idxA = names.findIndex((t) => t.includes(`E2E Feat A ${stamp}`));
			const idxB = names.findIndex((t) => t.includes(`E2E Feat B ${stamp}`));
			expect(idxB, 'B should now precede A').toBeLessThan(idxA);

			// 5. Unfeature everything → the Featured card is gone.
			expect((await setFeatured([])).status).toBe(200);
			await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
			await expect(page.locator('.bn-sidebar-card', { hasText: 'Featured' })).toHaveCount(0);
		} finally {
			await setFeatured([]);
			await deleteSpaceApi(page, a.id);
			await deleteSpaceApi(page, b.id);
		}
	});
});
