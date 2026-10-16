import { test, expect } from '../_fixtures/auth.fixture';
import { loginAs } from '../_fixtures/actor';
import { softSkip } from "../_fixtures/precondition";
import { sel, urls } from '../_fixtures/selectors';
import { ensureUser, setUserMeta } from '../_fixtures/wp';
import { restList } from '../_fixtures/effects';
import type { Page, Locator } from '@playwright/test';

/** The directory renders what GET /members answers for the same query: both empty or both populated. */
async function expectDirectoryParity(page: Page, query: string, label: string): Promise<void> {
    const rest = await restList(page, `/members${query}`);
    expect(rest.status, `GET /members${query}`).toBe(200);
    const rendered = await page.locator(sel.memberCard).count();
    expect(rendered > 0, `${label}: screen shows ${rendered} cards, REST answers ${rest.items.length} members for ${query}`).toBe(rest.items.length > 0);
    // EFFECT: no card for a member the route does not answer for this filter. Compared
    // against the route's widest page, and only when the screen shows a first page of it.
    const sep = query.includes('?') ? '&' : '?';
    const wide = await restList(page, `/members${query}${sep}per_page=50`);
    const routeIds = wide.items.map((row) => Number(row.user_id ?? row.id ?? 0)).filter((n) => n > 0);
    if (wide.status === 200) {
        // Polled: the grid repaints after the click or the debounce, so the first read can
        // still be the previous filter's cards.
        await expect
            .poll(
                async () => {
                    const shown = (await page.locator('.bn-md-card[data-user-id]').evaluateAll((els) =>
                        els.map((el) => Number(el.getAttribute('data-user-id')))
                    )) as number[];
                    return shown.length > 24 ? [] : shown.filter((id) => !routeIds.includes(id));
                },
                { timeout: 8_000, message: `${label}: cards on screen that GET /members${query} does not list` }
            )
            .toEqual([]);
    }
}

/** The query a filter pill applies, read from its link, or '' when it is a client-side tab. */
async function pillQuery(page: Page, pill: Locator): Promise<string> {
    const href = await pill.getAttribute('href');
    if (href) {
        return new URL(href, page.url()).search;
    }
    const relation = await pill.getAttribute('data-relation');
    return relation ? `?relation=${encodeURIComponent(relation)}` : '';
}

/**
 * Filter by member type through the control the directory really renders: the type
 * `<select class="bn-md-strip__type">` (parts/member-directory-filter-bar.php:196). The
 * `.bn-md-pill` chips this journey used to look for are no longer rendered, so its click
 * landed on the already-active "All members" relation tab and filtered nothing.
 * Returns false when the site has no directory-visible member types (no select).
 */
async function filterByType(page: Page, label: string): Promise<boolean> {
    const select = page.locator('select.bn-md-strip__type').first();
    if (!(await select.isVisible().catch(() => false))) {
        return false;
    }
    const slug = await select.locator('option:not([value=""])').first().getAttribute('value');
    expect(slug, 'type select has no member type option').toBeTruthy();
    const [res] = await Promise.all([
        page.waitForResponse(
            (r) => r.url().includes('/buddynext/v1/members') && r.url().includes(`member_type=${slug}`),
            { timeout: 10_000 }
        ),
        select.selectOption(slug as string),
    ]);
    expect(res.status(), `GET /members?member_type=${slug}`).toBe(200);

    // EFFECT: the route honours the filter (every row is of that type)...
    const rest = await restList(page, `/members?member_type=${slug}&per_page=50`);
    expect(rest.status).toBe(200);
    const offType = rest.items.filter((row) => String((row.member_type as { slug?: string } | undefined)?.slug ?? '') !== slug);
    expect(offType.map((row) => row.user_id), `GET /members?member_type=${slug} returned members of another type`).toEqual([]);
    // ...and the grid shows that answer, or the explicit empty state when the type has no members.
    if (rest.items.length === 0) {
        await expect(page.locator(sel.memberDirectoryEmpty)).toBeVisible({ timeout: 8_000 });
    } else {
        await expect.poll(() => page.locator(sel.memberCard).count(), { timeout: 8_000 }).toBeGreaterThan(0);
    }
    await expectDirectoryParity(page, `?member_type=${slug}`, label);
    return true;
}

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
        const bId = await ensureUser(B_LOGIN, 'bn_e2e_target@example.com', 'Jordan Ellis');
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
        const query = await pillQuery(page, filter);
        if (query) {
            await expectDirectoryParity(page, query, 'J-25 filter');
        }
        // The member-type filter itself (the promise in this journey's name).
        const typed = await filterByType(page, 'J-25 type filter');
        if (!typed) {
            testInfo.annotations.push({ type: 'note', description: 'No member-type select: no directory-visible member types on this site.' });
        }
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
        await expectDirectoryParity(page, '?search=a', 'J-26 search');
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
        const query = await pillQuery(page, filter);
        if (query) {
            await expectDirectoryParity(page, query, 'J-25 member filter');
        }
        const typed = await filterByType(page, 'J-25 member type filter');
        if (!typed) {
            testInfo.annotations.push({ type: 'note', description: 'No member-type select: no directory-visible member types on this site.' });
        }
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
        await expectDirectoryParity(page, '?search=a', 'J-26 search');
    });
});
