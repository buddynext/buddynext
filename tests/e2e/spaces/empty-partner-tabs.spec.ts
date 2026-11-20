import { test, expect } from '../_fixtures/auth.fixture';
import { createSpaceApi, deleteSpaceApi } from '../_fixtures/spaces-rest';
import { wp } from '../_fixtures/wp';

/**
 * J-913-empty-partner-tabs.
 *
 * A Space's Events tab (BuddyNext Pro, Eventonomy bridge) shows when the Space has an
 * event or the viewer may add one, so an empty tab does not sit on every Space for people
 * who cannot fill it: a guest sees no Events or Businesses tab on a new Space, the person
 * who may create events does.
 *
 * Covers: cap-group-content-into-spaces
 * Roles: admin, guest
 *
 * Needs Eventonomy active; skipped without it.
 */
async function eventonomyActive(): Promise<boolean> {
    try {
        await wp(['plugin', 'is-active', 'eventonomy']);
        return true;
    } catch {
        return false;
    }
}

test.describe('spaces / empty partner tabs (J-913)', () => {
    test('a guest sees no Events or Businesses tab on a new Space; the organiser does', async ({ authenticatedPage: page, browser, baseURL }) => {
        test.skip(!(await eventonomyActive()), 'Eventonomy is not active on this site');
        const space = await createSpaceApi(page, { name: `Empty tabs ${Date.now().toString().slice(-6)}` });
        const guest = await browser.newContext({ baseURL });
        const guestPage = await guest.newPage();
        const tab = (p: typeof page, name: string) => p.locator(`a[href$="/spaces/${space.slug}/${name}/"]`);

        try {
            await guestPage.goto(`/spaces/${space.slug}/`);
            await expect(tab(guestPage, 'events')).toHaveCount(0);
            await expect(tab(guestPage, 'businesses')).toHaveCount(0);

            await page.goto(`/spaces/${space.slug}/`);
            await expect(tab(page, 'events').first()).toBeAttached();
        } finally {
            await guest.close();
            await deleteSpaceApi(page, space.id);
        }
    });
});
