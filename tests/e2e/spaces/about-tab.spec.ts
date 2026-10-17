import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi, getSpace, bnApi, type SpaceRow } from '../_fixtures/spaces-rest';

/**
 * J-664 — Space About tab RENDER (C4).
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin
 *
 * Proven by effect, not presence: a space is
 * created with a UNIQUE seeded description, and the About tab is asserted to paint
 * exactly that text. A blank panel, a "No description yet." fallback, or the wrong
 * space's copy all fail here.
 *
 * The About panel is server-rendered per active tab (SpaceNav::render_about_panel
 * → parts/space-about-panel.php, `.bn-sh-about__desc`) and reached via the clean
 * tab URL /spaces/{slug}/about/. About is a PUBLIC tab (SpaceVisibility keeps a
 * space's identity — name/description/rules — readable to non-members), so no
 * membership or bridge is required to prove it. Throwaway space torn down in
 * `finally`.
 *
 * Discussions and Media tabs are deliberately NOT asserted here: their content is
 * bridge-gated (Jetonomy discussions, WPMediaVerse media) and cannot be proven by
 * a description effect on a stock install — see the honest-gaps note in
 * docs/journeys/spaces.md.
 */
test.describe('spaces / About tab render (J-664)', () => {
    const DESC_SELECTOR = '.bn-sh-about__desc';

    test('J-664 the About tab paints the space description', async ({
        authenticatedPage: page,
    }) => {
        const stamp = Date.now().toString().slice(-8);
        // A distinctive, unmistakable description string so the assertion cannot
        // pass on boilerplate or another space's copy.
        const description = `Gentle morning flow on the lawn, all levels welcome ${stamp} - bring a mat and a friend.`;
        const space = await createSpaceApi(page, {
            name: `Sunrise Yoga Circle ${stamp}`,
            type: 'open',
            description,
        });

        try {
            await page.goto(`/spaces/${space.slug}/about/`, { waitUntil: 'domcontentloaded' });

            // EFFECT: the seeded description text is painted in the About panel.
            const desc = page.locator(DESC_SELECTOR).first();
            await expect(desc).toBeVisible({ timeout: 10_000 });
            await expect(desc).toContainText(description, { timeout: 10_000 });

            // EFFECT: the panel paints the stored description, per the routes web and app
            // both read (by id and by slug), not a cached or neighbouring space's copy.
            const byId = await getSpace(page, space.id);
            expect(byId.status).toBe(200);
            expect(String((byId.data as SpaceRow).description ?? '')).toBe(description);
            const bySlug = await bnApi(page, 'GET', `/spaces/slug/${space.slug}`);
            expect(bySlug.status, `GET /spaces/slug/${space.slug}`).toBe(200);
            expect(Number((bySlug.data as SpaceRow).id)).toBe(space.id);
            expect(String((bySlug.data as SpaceRow).description ?? '')).toBe(description);
        } finally {
            await deleteSpaceApi(page, space.id);
        }
    });
});
