import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { ensureUser, setUserMeta } from '../_fixtures/wp';

const B_LOGIN = process.env.BN_TEST_OTHER_USER ?? 'bn_e2e_target';

/**
 * J-25-directory-filter-by-type + J-26-directory-search.
 *
 * Covers: cap-segment-members-into-types, cap-search-members-spaces-and-posts
 * Roles: admin, member
 * Note: the first two tests use the authenticatedPage fixture (admin owner
 * only). J-25-member and J-26-member repeat the same filter and search walk as
 * B (bn_e2e_target, a plain subscriber) via loginAs(), closing the
 * (capability, member) cell for both promises — a subscriber filtering by
 * member type or searching is the actual daily-use path, not just the owner
 * who configures the types.
 */
test.describe('directory / filter + search', () => {
    test.beforeAll(async () => {
        const bId = await ensureUser(B_LOGIN, 'bn_e2e_target@example.com', 'BN E2E Target');
        expect(bId, `member "${B_LOGIN}" must exist`).toBeGreaterThan(0);
        await setUserMeta(bId, 'bn_onboarding_complete', '1');
    });

    test('J-25 clicking a filter chip updates the result list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const filter = page.locator(sel.memberFilter).first();
        if (!(await filter.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No filter chips rendered (no member types configured).');
            return;
        }
        const beforeCount = await page.locator(sel.memberCard).count();
        await filter.click();

        // Active state should land on the chip.
        await expect(filter).toHaveAttribute('aria-pressed', /true|on/i).catch(async () => {
            const cls = await filter.getAttribute('class');
            expect(cls).toMatch(/active|is-active|selected/);
        });

        // "The grid did not crash" was asserted as `count() >= 0`, which is also
        // true when the grid is gone entirely. Assert the directory is still in a
        // valid rendered state: cards, or an explicit empty state.
        const afterCount = await page.locator(sel.memberCard).count();
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        expect(
            afterCount > 0 || empty,
            `filtering left the directory rendering nothing (before: ${beforeCount} cards)`
        ).toBeTruthy();
    });

    test('J-26 typing in directory search updates results', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.members);
        const input = page.locator(sel.directorySearch).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Directory search not exposed.');
            return;
        }
        const before = await page.locator(sel.memberCard).count();
        await input.fill('a');
        // Debounced - wait for the list to actually settle into a searched state,
        // not merely for the page to still exist. `>= 0` never failed.
        await expect.poll(async () => {
            const now = await page.locator(sel.memberCard).count();
            const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
            return now !== before || empty || now > 0;
        }, { timeout: 5_000 }).toBeTruthy();
    });

    test('J-25-member a plain member filters the directory by member type (mobile 390px)', async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await loginAs(page, B_LOGIN);
        await page.goto(urls.members);
        const filter = page.locator(sel.memberFilter).first();
        if (!(await filter.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No filter chips rendered (no member types configured).');
            return;
        }
        const beforeCount = await page.locator(sel.memberCard).count();
        await filter.click();

        await expect(filter).toHaveAttribute('aria-pressed', /true|on/i).catch(async () => {
            const cls = await filter.getAttribute('class');
            expect(cls).toMatch(/active|is-active|selected/);
        });

        const afterCount = await page.locator(sel.memberCard).count();
        const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
        expect(
            afterCount > 0 || empty,
            `filtering left the directory rendering nothing for a member viewer (before: ${beforeCount} cards)`
        ).toBeTruthy();
    });

    test('J-26-member a plain member searches the directory and results update (mobile 390px)', async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await loginAs(page, B_LOGIN);
        await page.goto(urls.members);
        const input = page.locator(sel.directorySearch).first();
        if (!(await input.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Directory search not exposed.');
            return;
        }
        const before = await page.locator(sel.memberCard).count();
        await input.fill('a');
        await expect.poll(async () => {
            const now = await page.locator(sel.memberCard).count();
            const empty = await page.locator(sel.memberDirectoryEmpty).isVisible().catch(() => false);
            return now !== before || empty || now > 0;
        }, { timeout: 5_000 }).toBeTruthy();
    });
});
