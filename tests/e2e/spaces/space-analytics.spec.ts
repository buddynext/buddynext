import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, ensureOnboarded } from '../_fixtures/spaces-rest';

/**
 * J-820 — Space-owner "Last 30 days" analytics row.
 *
 * Free adds the `buddynext_space_admin_after_stats` seam on the space admin page;
 * Pro renders a "Last 30 days" row there, gated by the
 * `buddynextpro_space_owner_stats` setting (off by default). This proves the seam
 * + setting end to end: with the setting ON the row appears on a space the viewer
 * manages, with it OFF the row is gone. The four numbers, the empty-state line,
 * and the filter are unit-covered (SpaceOwnerStatsTest); this is the wiring.
 *
 * Needs a site admin (to flip the setting via Engagement > Insights) with Pro +
 * Analytics active; the spec self-skips otherwise. Runs desktop + mobile.
 */
test.describe('spaces / space analytics (J-820)', () => {
	const SECTION = '.bn-space-admin__analytics';

	// Flip the "Show space owners their stats" toggle in wp-admin. Returns false
	// when the control is not reachable (not an admin, or Pro/Analytics inactive).
	async function setToggle(page: import('@playwright/test').Page, on: boolean): Promise<boolean> {
		await page.goto('/wp-admin/admin.php?page=buddynext-engagement&tab=insights', {
			waitUntil: 'domcontentloaded',
		});
		const box = page.locator('.bn-analytics-owner-toggle input[type="checkbox"]');
		if ((await box.count()) === 0) {
			return false;
		}
		if ((await box.isChecked()) !== on) {
			await box.setChecked(on);
			await page.locator('.bn-analytics-owner-toggle button[type="submit"]').click();
			await page.waitForLoadState('domcontentloaded');
		}
		return true;
	}

	test('J-820 the Last 30 days row follows the setting on a managed space', async ({
		authenticatedPage: page,
	}) => {
		await ensureOnboarded(page);

		const canToggle = await setToggle(page, true);
		test.skip(!canToggle, 'needs a site admin with Pro + Analytics active');

		const stamp = Date.now().toString().slice(-8);
		const space = await createSpaceApi(page, { name: `E2E Analytics ${stamp}`, type: 'open' });
		const adminUrl = `/spaces/${space.slug}/admin/`;

		try {
			// Setting ON → the section renders for the manager (owner/admin).
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });
			const section = page.locator(SECTION);
			await expect(section).toBeVisible({ timeout: 10_000 });
			await expect(section).toContainText('Last 30 days');
			// A brand-new space has no 30-day events → the honest empty line, not zeros.
			await expect(section).toContainText('No activity in the last 30 days');

			// Mobile: the row is still present (its tiles stack to one column).
			await page.setViewportSize({ width: 390, height: 900 });
			await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });
			await expect(page.locator(SECTION)).toBeVisible();

			// Setting OFF (the default) → the section is gone entirely.
			expect(await setToggle(page, false)).toBe(true);
			await page.setViewportSize({ width: 1280, height: 900 });
			await page.goto(adminUrl, { waitUntil: 'domcontentloaded' });
			await expect(page.locator(SECTION)).toHaveCount(0);
		} finally {
			await setToggle(page, false);
			await deleteSpaceApi(page, space.id);
		}
	});
});
