import { test, expect } from '../_fixtures/auth.fixture';
import { resolveOtherMemberSlug } from '../_fixtures/precondition';
import { restJson } from '../_fixtures/effects';
import { loginAs } from '../_fixtures/actor';
import { userId, getUserMeta, setUserMeta, deleteUserMeta } from '../_fixtures/wp';

/**
 * J-984 Edit cover and Edit avatar are one click away on the profile.
 *
 * The owner clicks Edit cover (or the avatar's edit badge) on their own profile and
 * the file picker opens from that click; choosing a file uploads it and the profile
 * route then serves the new image. Before 1.2.5 both links went to the edit page,
 * where a second click opened the picker. Another member's profile shows neither
 * control.
 *
 * The account's cover and avatar meta are restored afterwards.
 *
 * Covers: cap-set-your-own-display-name-avatar-cover-photo-and-headline
 * Roles: member (owner), member (visitor of another profile)
 */

const LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
// A valid 4x4 PNG, the same one J-740 uploads.
const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAQAAAAECAIAAAAmkwkpAAAADklEQVR4nGNoQAIMxHEAcFIYAYPG8BkAAAAASUVORK5CYII=',
    'base64'
);

type Profile = { cover_url?: string; avatar_url?: string };

test.describe('profile / one-click photo', () => {
    test('J-984 Edit cover and Edit avatar open the file picker in one click and the new image is served', async ({ authenticatedPage: page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        test.setTimeout(120_000); // two uploads with reloads, plus finding another member who reaches the feed
        const uid = await userId(LOGIN);
        const keys = ['buddynext_cover_url', 'buddynext_cover_focal', 'buddynext_avatar_url'];
        const before: Record<string, string> = {};
        for (const k of keys) before[k] = await getUserMeta(uid, k);

        try {
            for (const kind of ['cover', 'avatar'] as const) {
                await loginAs(page, LOGIN);
                await page.goto(`/members/${LOGIN}/`);
                const control = page.locator(kind === 'cover' ? '.bn-pf-cover__edit' : '.bn-pf-avatar-edit').first();
                await expect(control, `owner sees the Edit ${kind} control`).toBeVisible();
                const prior = (await restJson(page, '/wp-json/buddynext/v1/me/profile')).data as Profile;

                // One click: the picker must open from this click, no navigation to the edit page.
                const [chooser] = await Promise.all([page.waitForEvent('filechooser', { timeout: 5_000 }), control.click()]);
                expect(page.url(), 'the click stayed on the profile').not.toContain('#' + kind);
                const upload = page.waitForResponse((r) => r.url().includes(`/me/${kind}`) && r.request().method() === 'POST');
                await chooser.setFiles({ name: `j984-${kind}.png`, mimeType: 'image/png', buffer: PNG });
                expect((await upload).status(), `POST /me/${kind}`).toBeLessThan(300);

                // EFFECT: the profile route serves a new uploaded image.
                // Success reloads the profile with a toast, so a read can land mid-navigation: retry it.
                await expect.poll(async () => {
                    await page.waitForLoadState('load');
                    const now = (await restJson(page, '/wp-json/buddynext/v1/me/profile').catch(() => ({ data: null }))).data as Profile | null;
                    if (!now) return false;
                    const url = String((kind === 'cover' ? now.cover_url : now.avatar_url) ?? '');
                    return /^https?:\/\//.test(url) && url !== String((kind === 'cover' ? prior.cover_url : prior.avatar_url) ?? '');
                }, { timeout: 10_000, message: `GET /me/profile serves the new ${kind}` }).toBe(true);
            }

            // Negative twin: another member's profile offers neither control.
            const other = await resolveOtherMemberSlug(page, LOGIN);
            expect(other, 'another member to visit').toBeTruthy();
            await page.goto(`/members/${other}/`);
            await expect(page.locator('.bn-pf-head')).toBeVisible();
            await expect(page.locator('.bn-pf-cover__edit, .bn-pf-avatar-edit')).toHaveCount(0);
        } finally {
            for (const k of keys) {
                if (before[k]) await setUserMeta(uid, k, before[k]);
                else await deleteUserMeta(uid, k);
            }
        }
    });
});
