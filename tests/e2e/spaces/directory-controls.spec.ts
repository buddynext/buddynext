import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, bnApi, getSpace, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-670..J-672 — Spaces DIRECTORY controls (C1), Wave-4 NEW, effect-based.
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin, anon
 *
 * These cover the directory's viewer-state and failure surfaces the earlier
 * waves left uncovered:
 *   - J-670: the inline error block + Retry. The directory client-fetches
 *     `GET /buddynext/v1/spaces?…` on a filter change (store.js executeSpacesFilter);
 *     a forced 500 flips it to the error state; Retry re-requests and — once the
 *     failure is lifted — the grid recovers with the seeded card. Real failure →
 *     real recovery, no swallow.
 *   - J-671: the owner "Manage" affordance on a card actually navigates to that
 *     space's settings.
 *   - J-672: a logged-OUT visitor's card shows a "Log in to join" CTA that points
 *     at the auth flow with a redirect back to the space (join is gated behind
 *     login, not silently broken).
 *
 * Each seeds its own throwaway space (filtered to by unique name via the
 * server-side `bn_search`) and deletes it in `finally`.
 */
test.describe('spaces / directory controls (J-670..J-672)', () => {
    // The PWA service worker answers REST fetches on WebKit, and requests it handles
    // never reach page.route(): the forced failure below would not be forced.
    test.use({ serviceWorkers: 'block' });

    // Per-spec selectors (not the shared dict).
    const search = 'input[name="bn_search"]';
    const errorBlock = '[data-bn-error]';
    const retryBtn = '[data-bn-error] button[data-wp-on--click="actions.applyFilter"]';
    const grid = '[data-bn-sd-grid]';
    const card = '.bn-sd-card';
    const cardFoot = '.bn-sd-card__foot';
    const listReq = /\/buddynext\/v1\/spaces\?/; // directory list fetch (has a query string); a glob's ? is not a literal ?

    test('J-670 a failed directory load shows the error block; Retry recovers it', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `Farmers Market Regulars ${stamp}`;
        const space = await createSpaceApi(page, { name, type: 'open' });

        try {
            await page.goto('/spaces/', { waitUntil: 'domcontentloaded' });
            await expect(page.locator(search)).toBeVisible({ timeout: 10_000 });

            // Force EVERY directory list fetch to fail (only the list GET carries a
            // query string; POST /spaces and GET /spaces/{id} are untouched).
            await page.route(listReq, (route) =>
                route.fulfill({
                    status: 500,
                    contentType: 'application/json',
                    body: JSON.stringify({ code: 'e2e_forced_error', message: 'forced' }),
                })
            );

            // Typing fires the debounced reactive fetch (store.js input listener).
            await page.locator(search).fill(name);

            // EFFECT: the failure surfaces as the inline error block, not a silent
            // empty grid.
            await expect(page.locator(errorBlock)).toBeVisible({ timeout: 10_000 });

            // Lift the failure, then Retry — it must re-request and recover.
            await page.unroute(listReq);
            await page.locator(retryBtn).click();

            // EFFECT: error clears AND the seeded card is rendered by the recovered
            // fetch (Retry genuinely re-ran the request).
            await expect(page.locator(errorBlock)).toBeHidden({ timeout: 10_000 });
            await expect(
                page.locator(card).filter({ hasText: name }).first()
            ).toBeVisible({ timeout: 10_000 });
            await expect(page.locator(grid)).toBeVisible();

            // EFFECT: the recovered grid is the list route's answer for this search: the
            // seeded space, and as many cards as the route returns rows.
            const list = await bnApi(page, 'GET', `/spaces?search=${encodeURIComponent(name)}&per_page=50`);
            expect(list.status, `list spaces failed: ${JSON.stringify(list.data)}`).toBe(200);
            const ids = ((list.data as SpaceRow[]) ?? []).map((r) => Number(r.id));
            expect(ids).toContain(space.id);
            await expect.poll(() => page.locator(card).count(), { timeout: 5_000 }).toBe(ids.length);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-671 owner "Manage" on a card opens that space settings', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `Open Mic Night ${stamp}`;
        // Admin creates it → is the owner (active) → the card shows "Manage".
        const space = await createSpaceApi(page, { name, type: 'open' });

        try {
            await page.goto(`/spaces/?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            const mine = page.locator(card).filter({ hasText: name }).first();
            await expect(mine).toBeVisible({ timeout: 10_000 });

            const manage = mine.locator(`${cardFoot} a.bn-btn`, { hasText: 'Manage' });
            await expect(manage, 'owner card did not expose a Manage link').toBeVisible();
            await expect(manage).toHaveAttribute('href', new RegExp(`/spaces/${space.slug}/settings/?`));
            // EFFECT: the card offers Manage because the route says this viewer manages it.
            const row = (await getSpace(page, space.id)).data as SpaceRow;
            expect(row.can_manage, 'card shows Manage but GET /spaces/{id} says the viewer cannot manage').toBe(true);
            expect(row.membership_role).toBe('owner');

            // EFFECT: clicking Manage lands on the space settings screen.
            await Promise.all([
                page.waitForURL(new RegExp(`/spaces/${space.slug}/settings/?`), { timeout: 15_000 }),
                manage.click(),
            ]);
            await expect(page.locator('#space_name')).toBeVisible({ timeout: 10_000 });
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });

    test('J-672 a logged-out card shows a "Log in to join" CTA to the auth flow', async ({
        authenticatedPage: page,
        browser,
        baseURL,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        const name = `Street Food Explorers ${stamp}`;
        const space = await createSpaceApi(page, { name, type: 'open' });

        // A fresh context with NO autologin cookie = a genuine guest.
        const guest = await browser.newContext({ baseURL });
        const gp = await guest.newPage();
        try {
            await gp.goto(`/spaces/?bn_search=${encodeURIComponent(name)}`, {
                waitUntil: 'domcontentloaded',
            });
            const guestCard = gp.locator(card).filter({ hasText: name }).first();
            await expect(guestCard, 'guest could not see the open-space card').toBeVisible({
                timeout: 10_000,
            });

            const cta = guestCard.locator(`${cardFoot} a.bn-btn`, { hasText: 'Log in to join' });
            await expect(cta, 'guest card did not show a login-to-join CTA').toBeVisible();

            // EFFECT: the CTA routes to the auth flow with a redirect back to the
            // space — join is gated behind login, not a dead control.
            const href = (await cta.getAttribute('href')) ?? '';
            expect(href, `unexpected CTA href: ${href}`).toContain('redirect_to=');
            expect(decodeURIComponent(href)).toContain(`/spaces/${space.slug}`);

            // EFFECT: the gate is real on the server, not only a label: a guest's join is
            // refused, and the space still has only its owner.
            const join = await gp.request.post(`/wp-json/buddynext/v1/spaces/${space.id}/join`);
            expect([401, 403], `guest join answered ${join.status()}`).toContain(join.status());
            const after = (await getSpace(page, space.id)).data as SpaceRow;
            expect(Number(after.member_count), 'a logged-out join changed the member count').toBe(1);
        } finally {
            await guest.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
