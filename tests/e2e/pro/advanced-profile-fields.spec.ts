import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-979 admin defines a "Number (advanced)" profile field with a unit and
 * range; a member fills it in and it renders on their profile with that unit.
 *
 * Covers: cap-add-advanced-profile-fields
 * Roles: admin, member
 *
 * `number_advanced` is one of Pro's four shipped advanced types
 * (AdvancedFieldTypes::PRO_TYPES — the other three are date_extended, location and
 * multi_select_advanced; `file` exists in the enum but is withheld from the admin
 * picker because it has no upload pipeline end to end). number_advanced is chosen
 * here because its render/validate/display path (AdvancedFieldRenderer,
 * AdvancedFieldValidator) is fully self-contained — no external geocoding
 * dependency the way `location` has — so the journey is deterministic.
 *
 * The field is created via the same service the admin's own "Add field" form
 * calls (buddynext_service('profiles')->create_field()), matching
 * conditional-logic.spec.ts's precedent: the field TYPE is fixture setup, not the
 * thing under test — what is under test is that the admin-only type renders as a
 * real <input type="number"> with the configured min/max, that a member's answer
 * round-trips through the server, and that it displays with its unit appended.
 */
test.describe.configure({ mode: 'serial' });

const MEMBER = 'bn_e2e_advfield_member';
const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';
const FIELD_KEY = 'e2e_adv_height';
const FIELD_LABEL = 'E2E Height';
const GROUP_KEY = 'e2e_adv';

/** Run PHP through wp-cli and return trimmed stdout. */
async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

type Ids = { group: number; field: number; relaxed: number[] };
let ids: Ids | null = null;
let memberId = 0;

test.beforeAll(async () => {
    test.skip(process.env.BN_PRO !== '1', 'Advanced profile field types ship in BuddyNext Pro.');

    memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Adv Field Member');
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);

    const out = await php(`
        $ps = buddynext_service( 'profiles' );
        global $wpdb;

        $old_field = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s", '${FIELD_KEY}' ) );
        if ( $old_field ) { $ps->delete_field( $old_field, true ); }
        $old_group = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = %s", '${GROUP_KEY}' ) );
        if ( $old_group ) { $ps->delete_group( $old_group ); }

        $group = $ps->create_group( array(
            'group_key'  => '${GROUP_KEY}',
            'label'      => 'E2E Advanced',
            'type'       => 'flat',
            'visibility' => 'public',
            'sort_order' => 1,
        ) );
        $field = $ps->create_field( array(
            'group_id'   => $group,
            'field_key'  => '${FIELD_KEY}',
            'label'      => '${FIELD_LABEL}',
            'type'       => 'number_advanced',
            'options'    => array( 'unit' => 'cm', 'min' => 0, 'max' => 300 ),
            'visibility' => 'public',
            'sort_order' => 1,
        ) );

        // Other required fields on the site are not under test and would block the
        // member's save; relax them for the run, restored in afterAll — same
        // pattern as conditional-logic.spec.ts's fixture.
        $relaxed = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE is_required = 1 AND field_key != '${FIELD_KEY}'" ) );
        if ( $relaxed ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 0 WHERE id IN (" . implode( ',', $relaxed ) . ')' );
        }

        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();
        wp_cache_flush();

        echo wp_json_encode( array( 'group' => $group, 'field' => $field, 'relaxed' => $relaxed ) );
    `);

    ids = JSON.parse(out.slice(out.indexOf('{'))) as Ids;
    expect(ids.field, 'the number_advanced fixture field must be created').toBeGreaterThan(0);
});

test.afterAll(async () => {
    if (!ids) {
        return;
    }
    await php(`
        $ps = buddynext_service( 'profiles' );
        $ps->delete_field( ${ids.field}, true );
        $ps->delete_group( ${ids.group} );

        global $wpdb;
        $relaxed = array( ${ids.relaxed.join(', ')} );
        if ( $relaxed ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 1 WHERE id IN (" . implode( ',', array_map( 'intval', $relaxed ) ) . ')' );
        }
        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();
        wp_cache_flush();
    `);
});

test('J-979 the Profile Fields admin screen offers and applies the advanced type', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');
    if (!ids) {
        return;
    }

    await loginAs(page, ADMIN);
    await page.goto('/wp-admin/admin.php?page=buddynext-members&tab=profile-fields', { waitUntil: 'domcontentloaded' });

    const row = page.locator('tr', { has: page.locator('.bn-pf-field-name', { hasText: FIELD_LABEL }) });
    await expect(row, 'the field the fixture created is listed').toBeVisible();
    await expect(row.locator('.bn-badge'), 'the admin-only "advanced" type reads as its own label, not the raw slug').toContainText(
        'Number (advanced)'
    );
});

test('J-979 a member fills the advanced field and it renders with its unit on their profile', async ({
    page,
}, testInfo) => {
    if (!ids) {
        return;
    }

    await loginAs(page, MEMBER);
    await page.evaluate(() => {
        // Cookie banners are not under test and sit over the bottom save bar; this
        // member has already answered them (mirrors conditional-logic.spec.ts).
        document.cookie = 'wpconsent_preferences={"essential":true,"statistics":false,"marketing":false}; path=/';
        document.cookie = 'bn_cookie_consent=dismissed; path=/';
    });
    await page.goto(`/members/${MEMBER}/edit/`, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-bn-cookie-consent] [data-bn-cookie-accept]').click({ timeout: 3000 }).catch(() => undefined);

    const wrap = page.locator(`[data-bn-field-key="${FIELD_KEY}"]`).first();
    const input = wrap.locator(`input[name="${FIELD_KEY}"]`).first();
    await input.scrollIntoViewIfNeeded();

    await expect(input, 'the type renders a real number control, not a plain text box').toHaveAttribute('type', 'number');
    await expect(input).toHaveAttribute('min', '0');
    await expect(input).toHaveAttribute('max', '300');

    await input.fill('175');
    const putProfile = () =>
        page.waitForResponse((r) => r.url().includes('/me/profile') && r.request().method() === 'PUT', { timeout: 15000 });
    const res = await Promise.all([putProfile(), page.locator('.bn-ep-save-actions button[type="submit"]').first().click()]).then(
        ([r]) => r
    );
    expect(res.status()).toBe(200);

    if (testInfo.project.name === 'mobile') {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, 'the profile edit form fits the screen at 390px').toBeLessThanOrEqual(1);
    }

    // Server truth: the raw number is stored, unit is a display-only concern.
    const stored = await php(
        `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d", ${memberId}, ${ids.field} ) );`
    );
    expect(stored, 'the raw value is stored without the unit baked in').toBe('175');

    // The public "About" panel renders the value WITH its configured unit.
    await page.goto(`/members/${MEMBER}/about/`, { waitUntil: 'domcontentloaded' });
    const detail = page.locator('.bn-pf-detail', { has: page.locator('dt', { hasText: FIELD_LABEL }) });
    await expect(detail, 'About shows the labelled row for the advanced field').toBeVisible();
    await expect(detail.locator('dd')).toHaveText('175 cm');

    if (testInfo.project.name === 'mobile') {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, 'the About panel fits the screen at 390px').toBeLessThanOrEqual(1);
    }
});
