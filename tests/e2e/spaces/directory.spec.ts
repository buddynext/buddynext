import { test, expect } from '../_fixtures/auth.fixture';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import {
    createSpaceApi,
    deleteSpaceApi,
    bnApi,
    loginContextAs,
    ensureOnboarded,
    type SpaceRow,
} from '../_fixtures/spaces-rest';

/**
 * J-37 spaces directory, J-38 category filter, J-39 search.
 *
 * Covers: cap-group-content-into-spaces, cap-categorise-spaces, cap-search-members-spaces-and-posts
 * Roles: admin, member
 */
test.describe('spaces / directory', () => {
    const other = process.env.BN_TEST_OTHER_USER ?? 'alice';

    // The card name lives in `.bn-sd-card__name` (space-directory-card.php:136).
    const CARD_NAME = '.bn-sd-card__name';

    test('J-37 directory renders a specific seeded space card by name', async ({ authenticatedPage: page }) => {
        // EFFECT (not presence): seed a throwaway space with a unique name, then
        // prove the directory actually renders THAT card — a `count() >= 0` or a
        // "some card exists" check passes on a directory that never listed the
        // space under test. The directory reads `bn_search` server-side
        // (templates/spaces/directory.php:43 → SpaceService::search), so filtering
        // to the unique name pins the assertion to the seeded row regardless of
        // how many spaces already exist or how the default page paginates.
        const stamp = Date.now().toString().slice(-8);
        const name = `Bike Commuters Downtown ${stamp}`;
        const space = await createSpaceApi(page, { name, type: 'open' });

        try {
            await page.goto(`${urls.spaces}?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            await expect(page.locator(sel.app)).toBeVisible();

            const card = page.locator(sel.spaceCard).filter({ hasText: name }).first();
            await expect(card, `directory did not render the seeded space card "${name}"`).toBeVisible({
                timeout: 10_000,
            });
            await expect(card.locator(CARD_NAME)).toContainText(name);

            // EFFECT: the grid is the list route's answer for the same search: the seeded
            // space, and exactly as many cards as the route returns rows.
            const list = await bnApi(page, 'GET', `/spaces?search=${encodeURIComponent(name)}&per_page=50`);
            expect(list.status, `list spaces failed: ${JSON.stringify(list.data)}`).toBe(200);
            const ids = ((list.data as SpaceRow[]) ?? []).map((r) => Number(r.id));
            expect(ids).toContain(space.id);
            expect(await page.locator(sel.spaceCard).count(), 'cards on screen vs rows from GET /spaces').toBe(ids.length);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-38 category filter toggles active state', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        const filter = page.locator(sel.spaceFilter).first();
        if (!(await filter.isVisible().catch(() => false))) {
            softSkip(testInfo, 'No category filter rendered.');
            return;
        }
        // Pick a chip that is NOT already selected, so "it became selected" is a
        // real state change rather than something that was true on arrival.
        //
        // Then pin it by category slug. A locator built from [aria-selected="false"]
        // is re-resolved on every assertion, so the moment the click succeeds it
        // stops matching the chip we clicked and silently re-targets the next
        // unselected one — the assertion then fails against correct behaviour.
        const unselected = page.locator(`${sel.spaceFilter}[aria-selected="false"]`).first();
        if (!(await unselected.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Only one category chip; nothing to switch to.');
            return;
        }
        const slug = await unselected.getAttribute('data-bn-cat-slug');
        const target = slug
            ? page.locator(`${sel.spaceFilter}[data-bn-cat-slug="${slug}"]`)
            : unselected;
        await target.click();

        // The chips are role="tab" in a role="tablist", so the state lives on
        // aria-selected — not aria-pressed, and not a class. Asserting a class
        // failed against markup that was working correctly.
        await expect(target).toHaveAttribute('aria-selected', 'true', { timeout: 3_000 });

        // Exactly one chip lit at a time is the stated contract in the template.
        await expect
            .poll(async () => page.locator(`${sel.spaceFilter}[aria-selected="true"]`).count(), { timeout: 3_000 })
            .toBe(1);

        // EFFECT: the grid under the lit chip is that category's answer from the list route:
        // both empty or both populated, and when the route's rows fit one page, every card
        // on screen is one of them.
        const catId = Number((await target.getAttribute('data-bn-cat-id')) ?? 0);
        if (catId > 0) {
            const list = await bnApi(page, 'GET', `/spaces?category_id=${catId}&per_page=50`);
            expect(list.status, `list by category failed: ${JSON.stringify(list.data)}`).toBe(200);
            const rows = (list.data as SpaceRow[]) ?? [];
            await expect
                .poll(async () => (await page.locator(sel.spaceCard).count()) > 0, { timeout: 8_000 })
                .toBe(rows.length > 0);
            if (rows.length > 0 && rows.length < 50) {
                const names = rows.map((r) => String(r.name));
                await expect
                    .poll(
                        async () =>
                            (await page.locator(`${sel.spaceCard} ${CARD_NAME}`).allInnerTexts())
                                .map((t) => t.trim())
                                .filter((t) => !names.some((n) => t.includes(n) || n.includes(t))),
                        { timeout: 8_000, message: 'cards on screen outside the selected category' }
                    )
                    .toEqual([]);
            }
        }
    });

    test('J-39 search updates space list', async ({ authenticatedPage: page }, testInfo) => {
        await page.goto(urls.spaces);
        // Was an inline '.bn-spaces__search input' copy that matched nothing.
        // Spaces and members share templates/parts/filter-strip.php.
        const search = page.locator(sel.spaceSearch).first();
        if (!(await search.isVisible().catch(() => false))) {
            softSkip(testInfo, 'Spaces search not exposed.');
            return;
        }
        // Search for a space that only this run knows about: the one card that comes back
        // must be it. Searching a word like "test" on a seeded site matched everything and
        // proved nothing.
        const stamp = Date.now().toString().slice(-8);
        const needle = `Needle${stamp}`;
        const space = await createSpaceApi(page, { name: `Quiet Reading Nook ${needle}`, type: 'open' });
        try {
            await page.goto(urls.spaces);
            const box = page.locator(sel.spaceSearch).first();
            await box.fill(needle);
            // The filter strip is a form: the search applies on Enter (server round trip).
            await Promise.all([page.waitForLoadState('domcontentloaded'), box.press('Enter')]);
            await expect(page.locator(sel.spaceCard).filter({ hasText: needle }).first()).toBeVisible({ timeout: 10_000 });
            await expect.poll(async () => page.locator(sel.spaceCard).count(), { timeout: 5_000 }).toBe(1);
            // EFFECT: the one card is the one row the list route answers for that search.
            const list = await bnApi(page, 'GET', `/spaces?search=${encodeURIComponent(needle)}&per_page=50`);
            expect(list.status).toBe(200);
            expect(((list.data as SpaceRow[]) ?? []).map((r) => Number(r.id))).toEqual([space.id]);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-38 member: filtering the directory by category shows only that category\'s space', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }, testInfo) => {
        const stamp = Date.now().toString().slice(-8);

        // Setup (owner-only: category CRUD needs manage_options) — not the action
        // under test. The category is fresh, so it starts with exactly one space.
        const catRes = await bnApi(page, 'POST', '/space-categories', { name: `Outdoor Hobbies ${stamp}` });
        expect(catRes.status, `create category failed: ${JSON.stringify(catRes.data)}`).toBe(201);
        const category = catRes.data as { id: number; slug: string };
        expect(category.id, 'created category carried no id').toBeGreaterThan(0);

        const inName = `Sourdough Bakers Guild ${stamp}`;
        const outName = `Vintage Synth Collectors ${stamp}`;
        const inCat = await bnApi(page, 'POST', '/spaces', { name: inName, type: 'open', category_id: category.id });
        expect(inCat.status, `create categorized space failed: ${JSON.stringify(inCat.data)}`).toBe(201);
        const inCatId = Number((inCat.data as SpaceRow).id);
        const outCat = await createSpaceApi(page, { name: outName, type: 'open' });

        const actor = await loginContextAs(browser, baseURL, other);

        try {
            await ensureOnboarded(actor.page);

            // Member action: open the directory as a NON-owner viewer, at phone
            // width, then click the new category's chip.
            await actor.page.setViewportSize({ width: 390, height: 844 });
            await actor.page.goto(urls.spaces, { waitUntil: 'domcontentloaded' });

            const chip = actor.page.locator(`${sel.spaceFilter}[data-bn-cat-slug="${category.slug}"]`);
            if (!(await chip.isVisible().catch(() => false))) {
                softSkip(testInfo, `Category chip for "${category.slug}" did not render on this harness.`);
                return;
            }
            await chip.click();
            await expect(chip).toHaveAttribute('aria-selected', 'true', { timeout: 5_000 });

            // EFFECT: the categorized space is in the filtered result, the
            // uncategorized one is not — not "a chip lit up", the actual list.
            await expect(
                actor.page.locator(sel.spaceCard).filter({ hasText: inName }),
                `directory filtered by category did not show "${inName}"`
            ).toBeVisible({ timeout: 10_000 });
            await expect(
                actor.page.locator(sel.spaceCard).filter({ hasText: outName }),
                `directory filtered by category still showed "${outName}"`
            ).toHaveCount(0);

            // EFFECT: the list route filtered by that category answers the same set to this
            // member: the categorized space only (the category is new, so nothing else is in it).
            const list = await bnApi(actor.page, 'GET', `/spaces?category_id=${category.id}&per_page=50`);
            expect(list.status, `list by category failed: ${JSON.stringify(list.data)}`).toBe(200);
            expect(((list.data as SpaceRow[]) ?? []).map((r) => Number(r.id))).toEqual([inCatId]);
            await expect.poll(() => actor.page.locator(sel.spaceCard).count(), { timeout: 5_000 }).toBe(1);
            // The space row itself carries the category the chip filtered by.
            const row = (await bnApi(actor.page, 'GET', `/spaces/${inCatId}`)).data as SpaceRow;
            expect(Number(row.category_id)).toBe(category.id);
        } finally {
            await actor.ctx.close();
            await deleteSpaceApi(page, inCatId);
            await deleteSpaceApi(page, outCat.id);
            await bnApi(page, 'DELETE', `/space-categories/${category.id}`);
        }
    });
});
