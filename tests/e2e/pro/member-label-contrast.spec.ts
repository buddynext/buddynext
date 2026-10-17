import { test, expect, type Page } from '@playwright/test';

import { loginAs } from '../_fixtures/actor';
import { wp, ensureUser } from '../_fixtures/wp';
import { restJson } from '../_fixtures/effects';

/**
 * J-989 member label text is readable on its colour, light and dark (Pro).
 *
 * A member label is drawn in the owner's colour with a text colour the plugin
 * picks. 1.2.5 darkened the three shipped colours (Verified, Expert, Staff) so
 * white text passes and picks the text colour for an owner's own colour by real
 * contrast. On a member's profile, every label chip's text reads at 4.5:1 against
 * its chip, for the shipped colours and for owner colours from very light to
 * mid-tone, in the theme's light and dark mode.
 *
 * Effect: the labels the chips stand for are read back from the Pro labels route
 * (GET /users/{id}/labels) and each chip is drawn in its stored colour.
 *
 * Covers: cap-label-members-staff-founder-vip
 * Roles: member (viewer), admin (setup)
 */

const MEMBER = 'bn_e2e_label_contrast';
const VIEWER = process.env.BN_TEST_USER ?? 'varundubey';
// Three chips per batch: a profile shows three before "+N".
const BATCHES: Array<Array<[string, string]>> = [
    [['e2e-c-verified', '#1971c2'], ['e2e-c-expert', '#0b7285'], ['e2e-c-staff', '#047857']],
    [['e2e-c-light', '#ffe066'], ['e2e-c-violet', '#8b5cf6'], ['e2e-c-orange', '#e8590c']],
];
const FLOOR = 4.5;

async function php(code: string): Promise<string> {
    return (await wp(['eval', code])).trim();
}

/** Replace the member's labels with this batch; returns the member id. */
async function assign(memberId: number, batch: Array<[string, string]>): Promise<void> {
    const rows = batch.map(([slug, color]) => `array( '${slug}', '${color}' )`).join(',');
    const out = await php(`
        $labels = new \\BuddyNextPro\\Members\\LabelService();
        $assign = new \\BuddyNextPro\\Members\\LabelAssignmentService( $labels );
        foreach ( $assign->get_user_labels( ${memberId} ) as $l ) { $assign->unassign_label( ${memberId}, (int) $l->id ); }
        $n = 0;
        foreach ( array( ${rows} ) as $r ) {
            $old = $labels->get_label_by_slug( $r[0] );
            if ( $old ) { $assign->delete_by_label( (int) $old->id ); $labels->delete_label( (int) $old->id ); }
            $id = $labels->create_label( $r[0], strtoupper( substr( $r[0], 6 ) ), $r[1] );
            if ( ! is_wp_error( $id ) && $assign->assign_label( ${memberId}, (int) $id, 1 ) ) { $n++; }
        }
        echo $n;
    `);
    expect(out.slice(-1), 'labels created and assigned').toBe(String(batch.length));
}

async function cleanup(memberId: number): Promise<void> {
    const slugs = BATCHES.flat().map(([s]) => `'${s}'`).join(',');
    await php(`
        $labels = new \\BuddyNextPro\\Members\\LabelService();
        $assign = new \\BuddyNextPro\\Members\\LabelAssignmentService( $labels );
        foreach ( array( ${slugs} ) as $s ) {
            $l = $labels->get_label_by_slug( $s );
            if ( $l ) { $assign->delete_by_label( (int) $l->id ); $labels->delete_label( (int) $l->id ); }
        }
    `);
    void memberId;
}

async function chips(page: Page): Promise<Array<{ slug: string; bg: string; ratio: number }>> {
    return page.locator('.bn-pf-head .bn-member-label[data-label-slug]').evaluateAll((els) => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d', { willReadFrequently: true })!;
        const rgb = (css: string) => {
            ctx.clearRect(0, 0, 1, 1);
            ctx.fillStyle = '#000';
            ctx.fillStyle = css;
            ctx.fillRect(0, 0, 1, 1);
            return Array.from(ctx.getImageData(0, 0, 1, 1).data.slice(0, 3));
        };
        const lum = (c: number[]) => {
            const f = (v: number) => (v / 255 <= 0.03928 ? v / 255 / 12.92 : Math.pow((v / 255 + 0.055) / 1.055, 2.4));
            return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
        };
        return els.map((el) => {
            const s = getComputedStyle(el);
            const bg = rgb(s.backgroundColor);
            const [hi, lo] = [lum(rgb(s.color)), lum(bg)].sort((a, b) => b - a);
            const hex = '#' + bg.map((v) => v.toString(16).padStart(2, '0')).join('');
            return { slug: el.getAttribute('data-label-slug') ?? '', bg: hex, ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100 };
        });
    });
}

let memberId = 0;

test.beforeAll(async () => {
    test.skip(process.env.BN_PRO !== '1', 'Member labels ship in BuddyNext Pro.');
    memberId = await ensureUser(MEMBER, `${MEMBER}@example.test`, 'Ruby Hughes');
    await wp(['user', 'meta', 'update', String(memberId), 'bn_onboarding_complete', '1']);
});

test.afterAll(async () => {
    if (memberId) await cleanup(memberId);
});

for (const mode of ['light', 'dark'] as const) {
    test(`J-989 every member label chip reads at 4.5:1 on its colour (${mode})`, async ({ page }, testInfo) => {
        test.skip(testInfo.project.name !== 'desktop', 'Desktop journey.');
        await page.addInitScript((m) => {
            try {
                window.localStorage.setItem('bx-color-mode', m);
            } catch {
                // Storage blocked: the mode check below skips the dark leg.
            }
        }, mode);
        await loginAs(page, VIEWER);
        const low: string[] = [];
        for (const batch of BATCHES) {
            await assign(memberId, batch);

            // EFFECT: the labels route serves the colours the chips must be drawn in.
            const served = await restJson(page, `/wp-json/buddynext-pro/v1/users/${memberId}/labels`);
            expect(served.status, 'GET /users/{id}/labels').toBe(200);
            const stored = new Map(((served.data ?? []) as Array<{ slug?: string; color?: string }>).map((l) => [l.slug ?? '', (l.color ?? '').toLowerCase()]));
            for (const [slug, color] of batch) expect(stored.get(slug), `${slug} served with its colour`).toBe(color);

            await page.goto(`/members/${MEMBER}/`);
            await page.waitForLoadState('load');
            if (mode === 'dark') {
                const active = await page.evaluate(() => document.documentElement.getAttribute('data-bx-mode'));
                test.skip(active !== 'dark', 'The active theme has no colour mode of its own to switch to dark.');
            }
            const drawn = await chips(page);
            expect(drawn.map((c) => c.slug).sort(), 'every assigned label is drawn').toEqual(batch.map(([s]) => s).sort());
            for (const c of drawn) {
                expect(c.bg, `${c.slug} chip is drawn in its stored colour`).toBe(stored.get(c.slug));
                if (c.ratio < FLOOR) low.push(`${c.slug} ${c.bg} ${c.ratio}:1`);
            }
        }
        expect(low, `label text under ${FLOOR}:1 (${mode})`).toEqual([]);
    });
}
