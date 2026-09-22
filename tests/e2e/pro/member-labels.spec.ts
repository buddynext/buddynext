import { test, expect } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';

/**
 * J-978 admin assigns a member label (VIP) from the edit-member screen, and it
 * shows on the member's own public profile.
 *
 * Covers: cap-label-members-staff-founder-vip
 * Roles: admin, member
 *
 * Labels are defined once (LabelService — buddynext-pro/includes/Members/LabelService.php)
 * and assigned per member from a checkbox list inside the SAME edit-member form
 * MemberMembershipPanel uses (MemberLabelsAdmin::render_member_labels_field(),
 * hooked on buddynext_edit_member_sections; saves with Save Profile, no form of
 * its own). The effect under test is the real one: the checkbox persists a row via
 * LabelAssignmentService, and ProfileLabelInjector renders it as a `.bn-badge`
 * chip in the profile hero (hero_badges_filter() on buddynext_part_profile_hero_after)
 * — the same chip markup the directory card and post byline reuse.
 */
test.describe.configure({ mode: 'serial' });

const MEMBER = 'bn_e2e_label_member';
const ADMIN = process.env.BN_TEST_USER ?? 'varundubey';
const LABEL_SLUG = 'e2e-vip';
const LABEL_NAME = 'VIP';

/** Run PHP through wp-cli and return trimmed stdout. */
async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

type Ids = { labelId: number; relaxed: number[] };
let ids: Ids | null = null;
let memberId = 0;

test.beforeAll(async () => {
    test.skip(process.env.BN_PRO !== '1', 'Member labels ship in BuddyNext Pro.');

    memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Label Member');
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);

    const out = await php(`
        $labels   = new \\BuddyNextPro\\Members\\LabelService();
        $existing = $labels->get_label_by_slug( '${LABEL_SLUG}' );
        if ( $existing ) {
            ( new \\BuddyNextPro\\Members\\LabelAssignmentService( $labels ) )->delete_by_label( (int) $existing->id );
            $labels->delete_label( (int) $existing->id );
        }
        $label_id = $labels->create_label( '${LABEL_SLUG}', '${LABEL_NAME}', '#8b5cf6' );

        // The admin edit-member form saves the WHOLE profile in one submit
        // (MemberMembershipPanel's own docblock explains why); a required custom
        // field left blank on this brand-new member would otherwise block that
        // save for reasons unrelated to labels. Relaxed here, restored in afterAll
        // — same safety net conditional-logic.spec.ts uses for the same reason.
        global $wpdb;
        $relaxed = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE is_required = 1" ) );
        if ( $relaxed ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 0 WHERE id IN (" . implode( ',', $relaxed ) . ')' );
        }
        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();

        echo wp_json_encode( array( 'labelId' => $label_id, 'relaxed' => $relaxed ) );
    `);

    ids = JSON.parse(out.slice(out.indexOf('{'))) as Ids;
    expect(ids.labelId, 'the VIP label must be created').toBeGreaterThan(0);
});

test.afterAll(async () => {
    if (!ids) {
        return;
    }
    await php(`
        $labels = new \\BuddyNextPro\\Members\\LabelService();
        ( new \\BuddyNextPro\\Members\\LabelAssignmentService( $labels ) )->unassign_label( ${memberId}, ${ids.labelId} );
        $labels->delete_label( ${ids.labelId} );

        global $wpdb;
        $relaxed = array( ${ids.relaxed.join(', ')} );
        if ( $relaxed ) {
            $wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_required = 1 WHERE id IN (" . implode( ',', array_map( 'intval', $relaxed ) ) . ')' );
        }
        \\BuddyNext\\Profile\\ProfileService::flush_definition_cache();
    `);
});

test('J-978 admin assigns the VIP label from the member edit screen', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop', 'Owner admin journey runs at desktop.');
    if (!ids) {
        return;
    }

    await loginAs(page, ADMIN);
    await page.goto(`/wp-admin/admin.php?page=buddynext-members&view=edit-member&user_id=${memberId}`, {
        waitUntil: 'domcontentloaded',
    });

    const choice = page.locator('.bn-member-label-choice', { hasText: LABEL_NAME });
    await expect(choice, 'the defined label appears as an assignable choice on this member').toBeVisible();
    await choice.locator('input[type="checkbox"]').check();
    await page.locator('.bn-save-bar button[type="submit"]').click();
    await page.waitForLoadState('domcontentloaded');

    // Effect, not an optimistic checkbox: the assignment actually persisted
    // server-side through LabelAssignmentService.
    const stored = await php(`
        $assign = new \\BuddyNextPro\\Members\\LabelAssignmentService( new \\BuddyNextPro\\Members\\LabelService() );
        $ids    = array_map( static fn( $l ) => (int) $l->id, $assign->get_user_labels( ${memberId} ) );
        echo in_array( ${ids.labelId}, $ids, true ) ? '1' : '0';
    `);
    expect(stored, 'the label is actually assigned to the member, server-side').toBe('1');

    // Reload confirms the checkbox reflects saved state rather than a stale form.
    await page.goto(`/wp-admin/admin.php?page=buddynext-members&view=edit-member&user_id=${memberId}`, {
        waitUntil: 'domcontentloaded',
    });
    await expect(page.locator('.bn-member-label-choice', { hasText: LABEL_NAME }).locator('input[type="checkbox"]')).toBeChecked();
});

test("J-978 the label shows as a badge on the member's own public profile", async ({ page }, testInfo) => {
    await loginAs(page, MEMBER);
    await page.goto(`/members/${MEMBER}/`, { waitUntil: 'domcontentloaded' });

    const chip = page.locator('.bn-member-labels .bn-badge', { hasText: LABEL_NAME });
    await expect(chip, 'the assigned label renders as a badge in the profile hero').toBeVisible();

    if (testInfo.project.name === 'mobile') {
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, 'the profile hero fits the screen at 390px with the label chip').toBeLessThanOrEqual(1);
    }
});
