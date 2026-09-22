import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-808 profile field conditional logic (member), J-809 conditional logic builder (owner).
 *
 * Covers: none - no CAPABILITIES.md row in free or Pro names field-level
 * conditional logic. The nearest existing row, "Add advanced profile fields?",
 * names field TYPES (file, location, date-extended, multi-select, number); this
 * feature is a cross-field visibility rule on any field type, a different
 * capability. Flagging as friction rather than overclaiming that row.
 * Roles: member, admin
 *
 * Conditional logic is an OPTION on a field (Pro), not a field type: "show Beard
 * style only when Gender is Male". These journeys assert what a member and an
 * owner actually experience, with the server as the source of truth:
 *
 *   J-808  the dependent field is hidden until the answer matches, appears and
 *          hides on change with no reload, is not required while hidden, and its
 *          saved answer is removed from the database when a save hides it.
 *   J-809  the builder refuses a registration setup that could never show the
 *          field, and the Profile Fields screen lists a choice field with no
 *          options in "Some profile fields need attention" with a working Fix.
 *
 * Fixtures are created and removed through ProfileService over wp-cli; the
 * actions under test run through the real UI. Runs on every project, so the
 * mobile (iPhone 14, 390px) pass covers the member form at phone width.
 */

test.describe.configure({ mode: 'serial' });

const MEMBER = 'bn_e2e_cond_member';
const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';

type Ids = { group: number; gender: number; beard: number; empty: number; relaxed: number[] };
let ids: Ids;

/** Run PHP through wp-cli and return trimmed stdout. */
async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

/** Stored answer for a field, '' when none. */
async function storedValue(userId: number, fieldId: number): Promise<string> {
    return php(
        `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d", ${userId}, ${fieldId} ) );`
    );
}

let memberId = 0;

test.beforeAll(async () => {
    test.skip(process.env.BN_PRO !== '1', 'Conditional logic ships in BuddyNext Pro.');

    memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Cond Member');
    // A fresh member is routed to onboarding first; this journey is about the edit form.
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);
    const out = await php(`
        $ps = buddynext_service( 'profiles' );
        global $wpdb;
        foreach ( array( 'e2e_cond_gender', 'e2e_cond_beard', 'e2e_cond_empty' ) as $k ) {
            $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s", $k ) );
            if ( $id ) { $ps->delete_field( $id, true ); }
        }
        $old = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = 'e2e_cond'" );
        if ( $old ) { $ps->delete_group( $old ); }
        $g = $ps->create_group( array( 'group_key' => 'e2e_cond', 'label' => 'E2E Conditional', 'type' => 'flat', 'visibility' => 'public', 'sort_order' => 1 ) );
        $gender = $ps->create_field( array( 'group_id' => $g, 'field_key' => 'e2e_cond_gender', 'label' => 'E2E Gender', 'type' => 'select', 'options' => array( 'Male', 'Female' ), 'visibility' => 'public', 'sort_order' => 1 ) );
        $beard = $ps->create_field( array( 'group_id' => $g, 'field_key' => 'e2e_cond_beard', 'label' => 'E2E Beard style', 'type' => 'text', 'is_required' => 1, 'visibility' => 'public', 'sort_order' => 2 ) );
        $ps->update_field( $beard, array( 'options' => array( 'conditions' => array( 'field_id' => $gender, 'op' => 'in', 'values' => array( 'male' ) ) ) ) );
        // Other required fields on the site are not under test and would block the
        // member's save; relax them for the run (restored in afterAll).
        $relaxed = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE is_required = 1 AND field_key NOT LIKE 'e2e_cond_%'" ) );
        if ( $relaxed ) { $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 0 WHERE id IN (" . implode( ',', $relaxed ) . ')' ); }
        $empty = $ps->create_field( array( 'group_id' => $g, 'field_key' => 'e2e_cond_empty', 'label' => 'E2E Empty choice', 'type' => 'select', 'options' => null, 'visibility' => 'public', 'sort_order' => 3 ) );
        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();
        wp_cache_flush();
        echo wp_json_encode( array( 'group' => $g, 'gender' => $gender, 'beard' => $beard, 'empty' => $empty, 'relaxed' => $relaxed ) );
    `);
    ids = JSON.parse(out.slice(out.indexOf('{'))) as Ids;
    expect(ids.beard, 'fixture fields must be created').toBeGreaterThan(0);
});

test.afterAll(async () => {
    if (!ids) {
        return;
    }
    await php(`
        $ps = buddynext_service( 'profiles' );
        foreach ( array( ${ids.beard}, ${ids.gender}, ${ids.empty} ) as $id ) { $ps->delete_field( $id, true ); }
        $ps->delete_group( ${ids.group} );
        global $wpdb;
        $relaxed = array( ${ids.relaxed.join(', ')} );
        if ( $relaxed ) { $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 1 WHERE id IN (" . implode( ',', array_map( 'intval', $relaxed ) ) . ')' ); }
        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();
        wp_cache_flush();
    `);
});

test('J-808 dependent field shows and hides on the answer, is not required while hidden, and is cleared on save', async ({ page }) => {
    await loginAs(page, MEMBER);
    // Cookie banners are not under test and sit over the bottom save bar: this
    // member has already answered them.
    await page.evaluate(() => {
        document.cookie = 'wpconsent_preferences={"essential":true,"statistics":false,"marketing":false}; path=/';
        document.cookie = 'bn_cookie_consent=dismissed; path=/';
    });
    await page.goto(`/members/${MEMBER}/edit/`);
    await page.locator('[data-bn-cookie-consent] [data-bn-cookie-accept]').click({ timeout: 3000 }).catch(() => undefined);

    const gender = page.locator('[data-bn-field-key="e2e_cond_gender"] select[name="e2e_cond_gender"]').first();
    const beardWrap = page.locator('[data-bn-field-key="e2e_cond_beard"]').first();
    const beard = beardWrap.locator('input[name="e2e_cond_beard"]').first();
    const save = page.locator('.bn-ep-save-actions button[type="submit"]').first();
    const putProfile = () =>
        page.waitForResponse((r) => r.url().includes('/me/profile') && r.request().method() === 'PUT', { timeout: 15000 });

    await gender.scrollIntoViewIfNeeded();

    // First paint: unanswered, so hidden (for both is and is not).
    await expect(beardWrap, 'hidden until the deciding answer matches').toBeHidden();

    // Live, no reload.
    await gender.selectOption({ label: 'Male' });
    await expect(beardWrap, 'appears as soon as Male is picked').toBeVisible();
    await gender.selectOption({ label: 'Female' });
    await expect(beardWrap, 'hides again on Female').toBeHidden();

    // Required only while shown: saving as Female with the required field empty succeeds.
    let res = await Promise.all([putProfile(), save.click()]).then(([r]) => r);
    expect(res.status(), 'a hidden required field must not block the save').toBe(200);

    // Save a real answer while shown.
    await gender.selectOption({ label: 'Male' });
    await expect(beardWrap).toBeVisible();
    await beard.fill('Full beard');
    res = await Promise.all([putProfile(), save.click()]).then(([r]) => r);
    expect(res.status()).toBe(200);
    await expect.poll(() => storedValue(memberId, ids.beard), { message: 'answer stored while shown' }).toBe('Full beard');

    // Reload: server first paint shows it for the saved answer.
    await page.goto(`/members/${MEMBER}/edit/`);
    await expect(page.locator('[data-bn-field-key="e2e_cond_beard"]').first()).toBeVisible();

    // Switch away and save: the now-hidden answer is removed server-side.
    await page.locator('[data-bn-field-key="e2e_cond_gender"] select[name="e2e_cond_gender"]').first().selectOption({ label: 'Female' });
    res = await Promise.all([putProfile(), page.locator('.bn-ep-save-actions button[type="submit"]').first().click()]).then(([r]) => r);
    expect(res.status()).toBe(200);
    await expect.poll(() => storedValue(memberId, ids.beard), { message: 'hidden answer cleared on save' }).toBe('');

    // Phone width: nothing in the profile form runs past the screen. Scoped to the
    // form, because the host theme's header is not BuddyNext's to assert.
    const { overflow, offenders } = await page.evaluate(() => {
        const vw = window.innerWidth;
        const wide: string[] = [];
        document.querySelectorAll('.bn-ep-form-shell *').forEach((el) => {
            const r = el.getBoundingClientRect();
            const parent = el.parentElement?.getBoundingClientRect();
            if (r.width > 0 && r.right > vw + 1 && (!parent || parent.right <= vw + 1)) {
                wide.push(`${el.tagName.toLowerCase()}.${String((el as HTMLElement).className).slice(0, 50)} right=${Math.round(r.right)}`);
            }
        });
        const form = document.querySelector('.bn-ep-form-shell') as HTMLElement;
        return { overflow: form.getBoundingClientRect().right - vw, offenders: wide.slice(0, 5) };
    });
    expect(overflow, 'the form fits the screen').toBeLessThanOrEqual(1);
    expect(offenders, 'no form element runs past the screen').toEqual([]);
});

test('J-809 builder blocks an unreachable registration setup and the screen flags a field with no options', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');

    await loginAs(page, ADMIN);
    await page.goto('/wp-admin/admin.php?page=buddynext-members&tab=profile-fields');

    // Setup notice lists the choice field with no options; Fix opens it.
    const notice = page.locator('.bn-pf-setup-issues');
    await expect(notice, 'owner is told about the half-set-up field').toBeVisible();
    await expect(notice).toContainText('E2E Empty choice');
    await notice.locator(`[data-bn-pf-toggle-edit="bn-ef-row-${ids.empty}"]`).click();
    await expect(page.locator(`#bn-ef-row-${ids.empty}`), 'Fix opens the field panel').toBeVisible();

    // Asking the dependent field at signup without its deciding field is refused.
    const row = page.locator(`#bn-ef-row-${ids.beard}`);
    await page.locator(`[data-bn-pf-toggle-edit="bn-ef-row-${ids.beard}"]`).first().click();
    await expect(row).toBeVisible();
    await row.locator('input[name="show_on_register"]').check();
    await row.locator('button[type="submit"]').first().click();
    const error = row.locator('.bnpro-cond__error');
    await expect(error, 'save is blocked with a message at the form').toBeVisible();
    await expect(error).toContainText('E2E Gender');

    // Nothing was written.
    expect(
        await php(
            `global $wpdb; echo (int) $wpdb->get_var( "SELECT show_on_register FROM {$wpdb->prefix}bn_profile_fields WHERE id = ${ids.beard}" );`
        ),
        'the blocked save must not reach the server'
    ).toBe('0');
});
