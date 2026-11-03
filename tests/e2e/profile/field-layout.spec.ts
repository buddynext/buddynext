import { test, expect, type Page } from '@playwright/test';
import { loginAs } from '../_fixtures/actor';
import { userId, wp } from '../_fixtures/wp';
import { bnApi } from '../_fixtures/spaces-rest';

/**
 * J-998 field width + group intro (GitHub #217).
 *
 * An owner pairs short fields (Width: Half) under full-row ones and gives the
 * group an intro. Edit Profile and Sign-up lay them out that way and show the
 * intro; the About tab is a plain list with no intro; the member REST payload
 * carries both so an app can lay out its own form.
 */

const A_LOGIN = process.env.BN_TEST_USER ?? 'varundubey';
const GROUP = 'qa_e2e_217_address';
const INTRO = 'J-998 intro: these details are only visible to staff.';

async function box(page: Page, scope: string, label: string): Promise<{ x: number; y: number; w: number }> {
    return page.evaluate(([s, l]) => {
        const f = [...document.querySelectorAll(s)].find((el) => (el.querySelector('label, .bn-auth-label')?.textContent ?? '').trim().replace(/\s*\*$/, '').startsWith(l));
        const r = (f as HTMLElement).getBoundingClientRect();
        return { x: Math.round(r.left), y: Math.round(r.top), w: Math.round(r.width) };
    }, [scope, label] as const);
}

test.describe('Profile field width and group intro', () => {
    test('J-998 Half fields pair under Full ones on Edit Profile and Sign-up; the intro shows on both, not on About', async ({ page, browser }) => {
        await wp([
            'eval',
            `$p = buddynext_service( 'profiles' );
             $g = $p->create_group( array( 'group_key' => '${GROUP}', 'label' => 'QA e2e Address', 'visibility' => 'members', 'sort_order' => 95, 'intro' => '${INTRO}' ) );
             foreach ( array( array( 'phone', 'J998 Phone', 'auto', 0 ), array( 'street', 'J998 Street', 'full', 1 ), array( 'zip', 'J998 ZIP', 'half', 1 ), array( 'city', 'J998 City', 'half', 1 ) ) as $i => $d ) {
                 $p->create_field( array( 'group_id' => $g, 'field_key' => '${GROUP}_' . $d[0], 'label' => $d[1], 'type' => 'text', 'width' => $d[2], 'show_on_register' => $d[3], 'visibility' => 'members', 'sort_order' => ( $i + 1 ) * 10 ) );
             }
             wp_cache_flush(); echo 'ok';`,
        ]);
        try {
            await loginAs(page, A_LOGIN);
            const uid = await userId(A_LOGIN);

            // EFFECT: the member payload carries the width and the intro.
            const prof = await bnApi(page, 'GET', `/users/${uid}/profile`);
            expect(prof.status).toBe(200);
            const group = ((prof.data ?? {}) as { groups?: Array<{ group_key: string; intro?: string; fields?: Array<{ field_key: string; width?: string }> }> }).groups?.find((g) => g.group_key === GROUP);
            expect(group?.intro, 'the payload carries the group intro').toBe(INTRO);
            expect(group?.fields?.map((f) => f.width), 'the payload carries each width').toEqual(['auto', 'full', 'half', 'half']);

            // Edit Profile at desktop: Street a full row, ZIP and City side by side.
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.goto(`/members/${A_LOGIN}/edit/`);
            const section = page.locator('section').filter({ has: page.locator('h2', { hasText: 'QA e2e Address' }) });
            await expect(section.locator('.bn-ep-card-subtitle')).toHaveText(INTRO);
            const scope = 'section .bn-ep-field';
            const street = await box(page, scope, 'J998 Street');
            const zip = await box(page, scope, 'J998 ZIP');
            const city = await box(page, scope, 'J998 City');
            expect(zip.y, 'ZIP and City share a row').toBe(city.y);
            expect(city.x, 'City sits beside ZIP').toBeGreaterThan(zip.x);
            expect(street.w, 'Street is a full row').toBeGreaterThan(zip.w * 1.8);

            // About tab: a plain list, no intro.
            await page.goto(`/members/${A_LOGIN}/about/`);
            expect(await page.locator('.bn-pf-about-card', { hasText: 'QA e2e Address' }).filter({ hasText: INTRO }).count(), 'the intro is not profile data').toBe(0);

            // Sign-up (logged out): the intro above the group, ZIP and City paired.
            const guest = await browser.newContext({ baseURL: test.info().project.use.baseURL });
            const gp = await guest.newPage();
            await gp.setViewportSize({ width: 1440, height: 1100 });
            await gp.goto('/login/signup/');
            const grid = gp.locator('.bn-auth-profile-fields');
            await expect(grid.locator('.bn-auth-group-intro', { hasText: INTRO })).toBeVisible();
            const gZip = await box(gp, '.bn-auth-profile-fields .bn-auth-field', 'J998 ZIP');
            const gCity = await box(gp, '.bn-auth-profile-fields .bn-auth-field', 'J998 City');
            expect(gZip.y, 'sign-up pairs Half fields').toBe(gCity.y);
            // Phones: one field per row.
            await gp.setViewportSize({ width: 390, height: 900 });
            await gp.reload();
            const pZip = await box(gp, '.bn-auth-profile-fields .bn-auth-field', 'J998 ZIP');
            const pCity = await box(gp, '.bn-auth-profile-fields .bn-auth-field', 'J998 City');
            expect(pCity.y, 'one field per row on a phone').toBeGreaterThan(pZip.y);
            await guest.close();
        } finally {
            await wp([
                'eval',
                `global $wpdb; $g = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = %s", '${GROUP}' ) );
                 if ( $g ) { $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d", $g ) );
                   foreach ( $ids as $id ) { $wpdb->delete( $wpdb->prefix . 'bn_profile_values', array( 'field_id' => (int) $id ) ); }
                   $wpdb->delete( $wpdb->prefix . 'bn_profile_fields', array( 'group_id' => $g ) ); $wpdb->delete( $wpdb->prefix . 'bn_profile_groups', array( 'id' => $g ) ); }
                 wp_cache_flush(); echo 'cleaned';`,
            ]);
        }
    });
});
